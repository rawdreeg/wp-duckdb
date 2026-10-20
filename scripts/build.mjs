import { copyFileSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as esbuild from 'esbuild';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'node_modules/@duckdb/duckdb-wasm/dist');

mkdirSync(join(root, 'assets/duckdb'), { recursive: true });

const wasmFrom = join(dist, 'duckdb-mvp.wasm');
const workerFrom = join(dist, 'duckdb-browser-mvp.worker.js');
const wasmTo = join(root, 'assets/duckdb/duckdb-mvp.wasm');
const workerTo = join(root, 'assets/duckdb/duckdb-browser-mvp.worker.js');

copyFileSync(wasmFrom, wasmTo);
copyFileSync(workerFrom, workerTo);

const magic = readFileSync(wasmTo).subarray(0, 4).toString('utf8');
if (magic !== '\0asm') {
  throw new Error('duckdb-mvp.wasm is missing a WebAssembly header.');
}

await esbuild.build({
  absWorkingDir: root,
  entryPoints: ['js/main.js'],
  bundle: true,
  format: 'iife',
  platform: 'browser',
  target: ['es2020'],
  outfile: 'assets/admin.js',
  minify: true,
  legalComments: 'none',
  logLevel: 'info',
});

await esbuild.build({
  absWorkingDir: root,
  entryPoints: ['js/style.css'],
  bundle: true,
  outfile: 'assets/admin.css',
  minify: true,
  legalComments: 'none',
  logLevel: 'info',
});
