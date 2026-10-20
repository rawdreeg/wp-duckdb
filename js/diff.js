const MAX_SAFE = 9007199254740991n;

const DUCKDB_TYPES = new Set(['BIGINT', 'DOUBLE', 'VARCHAR']);

export function quoteIdent(name) {
  if (!/^[A-Za-z0-9_]+$/.test(name)) {
    throw new Error(`Invalid identifier: ${name}`);
  }
  return `"${name}"`;
}

export function assertDuckDbType(type) {
  if (!DUCKDB_TYPES.has(type)) {
    throw new Error(`Unsupported DuckDB type: ${type}`);
  }
}

export function diffSnapshot(loaded, currentByName) {
  return loaded.map((table) => diffTable({
    name: table.name,
    primaryKey: table.primary_key,
    columns: table.columns,
    originalRows: table.rows,
    currentRows: currentByName[table.name] ?? [],
  }));
}

export function diffTable(table) {
  const columns = table.columns;
  const primaryKey = table.primaryKey ?? [];
  const byName = new Map(columns.map((column) => [column.name, column]));

  if (primaryKey.length === 0) {
    return {
      name: table.name,
      inserts: [],
      updates: [],
      deletes: [],
      warning: 'no_primary_key',
    };
  }

  for (const key of primaryKey) {
    if (!byName.has(key)) {
      throw new Error(`Primary key column ${key} is not in the schema.`);
    }
  }

  const original = indexRows(table.originalRows ?? [], primaryKey, byName);
  const current = indexRows(table.currentRows ?? [], primaryKey, byName);
  const inserts = [];
  const updates = [];
  const deletes = [];

  for (const [key, row] of original) {
    if (!current.has(key)) {
      deletes.push(primaryObject(row, primaryKey, byName));
      continue;
    }
    const next = current.get(key);
    const changes = {};
    for (const column of columns) {
      if (primaryKey.includes(column.name)) {
        continue;
      }
      assertPresent(row, column.name);
      assertPresent(next, column.name);
      if (!cellEqual(column, row[column.name], next[column.name])) {
        changes[column.name] = emit(column, next[column.name]);
      }
    }
    if (Object.keys(changes).length > 0) {
      updates.push({
        primary_key: primaryObject(row, primaryKey, byName),
        changes,
      });
    }
  }

  for (const [key, row] of current) {
    if (!original.has(key)) {
      inserts.push(fullRow(row, columns));
    }
  }

  return { name: table.name, inserts, updates, deletes };
}

function indexRows(rows, primaryKey, byName) {
  const map = new Map();
  for (const row of rows) {
    const key = keyOf(row, primaryKey, byName);
    if (map.has(key)) {
      throw new Error('Duplicate primary key in table snapshot.');
    }
    map.set(key, row);
  }
  return map;
}

function keyOf(row, primaryKey, byName) {
  return primaryKey.map((name) => coerce(byName.get(name), row[name] ?? null)).join('\u0000');
}

function primaryObject(row, primaryKey, byName) {
  const key = {};
  for (const name of primaryKey) {
    assertPresent(row, name);
    key[name] = emit(byName.get(name), row[name]);
  }
  return key;
}

function fullRow(row, columns) {
  const out = {};
  for (const column of columns) {
    assertPresent(row, column.name);
    out[column.name] = emit(column, row[column.name]);
  }
  return out;
}

function assertPresent(row, name) {
  if (!Object.prototype.hasOwnProperty.call(row, name)) {
    throw new Error(`Column ${name} is missing after the query. Reload the tables before applying.`);
  }
}

function cellEqual(column, left, right) {
  return coerce(column, left) === coerce(column, right);
}

function coerce(column, value) {
  if (value === null || value === undefined) {
    return null;
  }
  if (column.duckdb === 'BIGINT') {
    if (typeof value === 'bigint') {
      return value.toString();
    }
    if (typeof value === 'number' && Number.isFinite(value)) {
      return String(Math.trunc(value));
    }
    if (typeof value === 'boolean') {
      return value ? '1' : '0';
    }
    return String(value);
  }
  if (column.duckdb === 'DOUBLE') {
    const number = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(number)) {
      return String(value);
    }
    return String(number);
  }
  if (typeof value === 'bigint') {
    return value.toString();
  }
  return String(value);
}

function emit(column, value) {
  if (value === null || value === undefined) {
    return null;
  }
  if (column.duckdb === 'BIGINT') {
    if (typeof value === 'bigint') {
      return bigintToJson(value);
    }
    if (typeof value === 'number' && Number.isInteger(value)) {
      return value;
    }
    if (typeof value === 'boolean') {
      return value ? 1 : 0;
    }
    if (typeof value === 'string' && /^-?\d+$/.test(value)) {
      try {
        return bigintToJson(BigInt(value));
      } catch {
        return value;
      }
    }
    return String(value);
  }
  if (column.duckdb === 'DOUBLE') {
    const number = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(number)) {
      return String(value);
    }
    return number;
  }
  if (typeof value === 'bigint') {
    return value.toString();
  }
  if (typeof value === 'boolean') {
    return value ? '1' : '0';
  }
  return String(value);
}

function bigintToJson(value) {
  if (value <= MAX_SAFE && value >= -MAX_SAFE) {
    return Number(value);
  }
  return value.toString();
}
