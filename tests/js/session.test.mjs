import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { Worker } from 'node:worker_threads';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import * as duckdb from '@duckdb/duckdb-wasm';
import { patchMvpWorker } from '../../scripts/patch-mvp-worker.mjs';
import { DuckSession } from '../../js/session.js';

const dist = path.dirname(fileURLToPath(import.meta.resolve('@duckdb/duckdb-wasm/dist/duckdb-node-mvp.worker.cjs')));
const wasmPath = path.join(dist, 'duckdb-mvp.wasm');
const workerPath = path.join(dist, 'duckdb-node-mvp.worker.patched.cjs');
writeFileSync(workerPath, patchMvpWorker(readFileSync(path.join(dist, 'duckdb-node-mvp.worker.cjs'), 'utf8')));

function startWorker() {
  const source = `
    const { parentPort } = require('worker_threads');
    globalThis.postMessage = (data, transfer) => parentPort.postMessage(data, transfer);
    parentPort.on('message', (data) => {
      if (typeof globalThis.onmessage === 'function') globalThis.onmessage({ data });
    });
    require(${JSON.stringify(workerPath)});
  `;
  const worker = new Worker(source, { eval: true });
  const listeners = new Map();
  worker.on('message', (data) => {
    const event = { data };
    shim.onmessage?.(event);
    for (const fn of listeners.get('message') ?? []) fn(event);
  });
  const shim = {
    worker,
    postMessage(data, transfer) {
      worker.postMessage(data, transfer);
    },
    terminate() {
      return worker.terminate();
    },
    addEventListener(type, fn) {
      const list = listeners.get(type) ?? [];
      list.push(fn);
      listeners.set(type, list);
    },
    removeEventListener(type, fn) {
      listeners.set(type, (listeners.get(type) ?? []).filter((item) => item !== fn));
    },
  };
  return shim;
}

test('bigint and large integer strings load into DuckDB and read back', async () => {
  const handle = startWorker();
  const db = new duckdb.AsyncDuckDB(new duckdb.VoidLogger(), handle);
  try {
    await db.instantiate(wasmPath);
    const session = new DuckSession(db);
    await session.load([{
      name: 'posts',
      columns: [
        { name: 'ID', duckdb: 'BIGINT' },
        { name: 'post_title', duckdb: 'VARCHAR' },
      ],
      rows: [
        { ID: 42n, post_title: 'safe' },
        { ID: '9223372036854775807', post_title: 'huge' },
      ],
    }]);

    const rows = await session.readTable('posts');
    JSON.stringify(rows);
    const byTitle = new Map(rows.map((row) => [row.post_title, row.ID]));
    assert.equal(byTitle.get('safe'), 42);
    assert.equal(byTitle.get('huge'), '9223372036854775807');
    assert.equal(rows.some((row) => Object.values(row).some((value) => typeof value === 'bigint')), false);

    await assert.rejects(
      () => session.run('SELECT nope'),
      (error) => {
        assert.equal(error instanceof Error, true);
        assert.equal(String(error.message).includes('_setThrew'), false);
        assert.match(error.message, /Binder Error/);
        return true;
      },
    );
  } finally {
    await db.terminate();
    await handle.terminate();
  }
});
