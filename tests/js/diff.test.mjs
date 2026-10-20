import assert from 'node:assert/strict';
import test from 'node:test';
import { diffTable, quoteIdent } from '../../js/diff.js';

const columns = [
  { name: 'ID', duckdb: 'BIGINT' },
  { name: 'post_title', duckdb: 'VARCHAR' },
];

test('quoteIdent rejects unsafe names', () => {
  assert.equal(quoteIdent('posts'), '"posts"');
  assert.throws(() => quoteIdent('posts"'), /Invalid identifier/);
});

test('diff reports updates inserts and deletes', () => {
  const diff = diffTable({
    name: 'posts',
    primaryKey: ['ID'],
    columns,
    originalRows: [
      { ID: 1, post_title: 'Hello' },
      { ID: 2, post_title: 'Gone' },
    ],
    currentRows: [
      { ID: 1n, post_title: 'Hello!' },
      { ID: 9, post_title: 'New' },
    ],
  });

  assert.deepEqual(diff.updates, [
    { primary_key: { ID: 1 }, changes: { post_title: 'Hello!' } },
  ]);
  assert.deepEqual(diff.deletes, [{ ID: 2 }]);
  assert.deepEqual(diff.inserts, [{ ID: 9, post_title: 'New' }]);
  assert.equal(JSON.stringify(diff).includes('bigint'), false);
});

test('unchanged bigint and number primary keys produce an empty diff', () => {
  const diff = diffTable({
    name: 'posts',
    primaryKey: ['ID'],
    columns,
    originalRows: [{ ID: 1, post_title: 'Hello' }],
    currentRows: [{ ID: 1n, post_title: 'Hello' }],
  });
  assert.deepEqual(diff.inserts, []);
  assert.deepEqual(diff.updates, []);
  assert.deepEqual(diff.deletes, []);
});

test('varchar keeps leading zeros distinct', () => {
  const diff = diffTable({
    name: 'posts',
    primaryKey: ['ID'],
    columns,
    originalRows: [{ ID: 1, post_title: '01' }],
    currentRows: [{ ID: 1, post_title: '1' }],
  });
  assert.equal(diff.updates[0].changes.post_title, '1');
});

test('null and empty string differ', () => {
  const diff = diffTable({
    name: 'posts',
    primaryKey: ['ID'],
    columns,
    originalRows: [{ ID: 1, post_title: null }],
    currentRows: [{ ID: 1, post_title: '' }],
  });
  assert.deepEqual(diff.updates[0].changes, { post_title: '' });
});

test('composite keys and large integers', () => {
  const wide = [
    { name: 'object_id', duckdb: 'BIGINT' },
    { name: 'term_taxonomy_id', duckdb: 'BIGINT' },
    { name: 'term_order', duckdb: 'BIGINT' },
  ];
  const big = '9223372036854775807';
  const diff = diffTable({
    name: 'term_relationships',
    primaryKey: ['object_id', 'term_taxonomy_id'],
    columns: wide,
    originalRows: [{ object_id: 1, term_taxonomy_id: 2, term_order: 0 }],
    currentRows: [{ object_id: 1, term_taxonomy_id: 2, term_order: BigInt(big) }],
  });
  assert.deepEqual(diff.updates, [
    {
      primary_key: { object_id: 1, term_taxonomy_id: 2 },
      changes: { term_order: big },
    },
  ]);
  JSON.stringify(diff);
});

test('tables without a primary key are not writable', () => {
  const diff = diffTable({
    name: 'posts',
    primaryKey: [],
    columns,
    originalRows: [{ ID: 1, post_title: 'A' }],
    currentRows: [{ ID: 1, post_title: 'B' }],
  });
  assert.equal(diff.warning, 'no_primary_key');
  assert.deepEqual(diff.updates, []);
});
