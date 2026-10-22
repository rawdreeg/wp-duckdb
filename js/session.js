import * as duckdb from '@duckdb/duckdb-wasm';
import { assertDuckDbType, quoteIdent } from './diff.js';

export async function openMvpDatabase(wasmUrl, workerUrl) {
  const workerBlob = URL.createObjectURL(new Blob(
    [`importScripts(${JSON.stringify(workerUrl)});`],
    { type: 'text/javascript' },
  ));
  const worker = new Worker(workerBlob);
  const db = new duckdb.AsyncDuckDB(new duckdb.VoidLogger(), worker);
  try {
    await db.instantiate(wasmUrl, null);
  } finally {
    URL.revokeObjectURL(workerBlob);
  }
  return db;
}

export class DuckSession {
  constructor(db) {
    this.db = db;
    this.conn = null;
    this.loadedNames = [];
  }

  async connection() {
    if (!this.conn) {
      this.conn = await this.db.connect();
    }
    return this.conn;
  }

  async load(tables) {
    const conn = await this.connection();
    const wanted = new Set(tables.map((table) => table.name));
    for (const name of this.loadedNames) {
      if (!wanted.has(name)) {
        await conn.query(`DROP TABLE IF EXISTS ${quoteIdent(name)}`);
      }
    }
    for (const table of tables) {
      await createAndFill(conn, table);
    }
    this.loadedNames = tables.map((table) => table.name);
  }

  async run(sql) {
    const conn = await this.connection();
    const result = await conn.query(sql);
    try {
      return rowsFromTable(result);
    } finally {
      closeResult(result);
    }
  }

  async readTable(name) {
    const conn = await this.connection();
    const result = await conn.query(`SELECT * FROM ${quoteIdent(name)}`);
    try {
      return rowsFromTable(result);
    } finally {
      closeResult(result);
    }
  }
}

async function createAndFill(conn, table) {
  const columns = table.columns;
  for (const column of columns) {
    quoteIdent(column.name);
    assertDuckDbType(column.duckdb);
  }
  const definitions = columns.map((column) => `${quoteIdent(column.name)} ${column.duckdb}`).join(', ');
  await conn.query(`CREATE OR REPLACE TABLE ${quoteIdent(table.name)} (${definitions})`);
  if (!table.rows.length) {
    return;
  }

  const placeholders = columns.map((column) => {
    if (column.duckdb === 'BIGINT') {
      return 'CAST(? AS BIGINT)';
    }
    if (column.duckdb === 'DOUBLE') {
      return 'CAST(? AS DOUBLE)';
    }
    return '?';
  }).join(', ');
  const sql = `INSERT INTO ${quoteIdent(table.name)} (${columns.map((column) => quoteIdent(column.name)).join(', ')}) VALUES (${placeholders})`;
  const stmt = await conn.prepare(sql);
  try {
    for (const row of table.rows) {
      const params = columns.map((column) => bindParam(column, row[column.name]));
      const result = await stmt.query(...params);
      closeResult(result);
    }
  } finally {
    await stmt.close();
  }
}

const MAX_SAFE = 9007199254740991n;

// duckdb-wasm JSON.stringifies prepared parameters before they reach the
// worker. BigInt cannot cross that boundary. BIGINT values travel as decimal
// strings and the INSERT casts them.
function bindParam(column, value) {
  if (typeof value === 'bigint') {
    value = value.toString();
  }
  if (value === null || value === undefined) {
    return null;
  }
  if (column.duckdb === 'BIGINT') {
    if (typeof value === 'number' && Number.isInteger(value)) {
      return String(value);
    }
    if (typeof value === 'boolean') {
      return value ? '1' : '0';
    }
    if (typeof value === 'string' && /^-?\d+$/.test(value)) {
      return value;
    }
    return null;
  }
  if (column.duckdb === 'DOUBLE') {
    const number = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(number) ? number : null;
  }
  if (typeof value === 'boolean') {
    return value ? '1' : '0';
  }
  return value;
}

function rowsFromTable(table) {
  if (!table || typeof table.toArray !== 'function') {
    return [];
  }
  const fields = table.schema?.fields?.map((field) => field.name) ?? [];
  return table.toArray().map((record) => {
    const names = fields.length ? fields : Object.keys(record);
    const row = {};
    for (const name of names) {
      row[name] = jsonSafe(record[name]);
    }
    return row;
  });
}

function jsonSafe(value) {
  if (value === undefined) {
    return null;
  }
  if (typeof value === 'bigint') {
    if (value <= MAX_SAFE && value >= -MAX_SAFE) {
      return Number(value);
    }
    return value.toString();
  }
  return value;
}

function closeResult(result) {
  if (result && typeof result.close === 'function') {
    result.close();
  }
}
