import { api } from './api.js';
import { diffSnapshot } from './diff.js';
import { DuckSession, openMvpDatabase } from './session.js';

const state = {
  catalog: [],
  loaded: [],
  session: null,
  preview: null,
  previewFresh: false,
  busy: false,
};

const ui = {};

document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('wp-duckdb-app');
  if (!root || !window.wpDuckdb) {
    return;
  }
  renderShell(root);
  loadCatalog().catch((error) => setNotice('error', errorText(error)));
});

function renderShell(root) {
  root.replaceChildren();

  const lede = el('p', { className: 'wp-duckdb-lede' }, [
    text('DuckDB runs in this browser in single-thread mode. WordPress only receives the row changes you preview.'),
  ]);

  ui.tableList = el('div', { className: 'wp-duckdb-tables' });
  ui.limit = el('input', {
    id: 'wp-duckdb-limit',
    type: 'number',
    min: '1',
    max: String(window.wpDuckdb.limits.exportMax),
    value: String(window.wpDuckdb.limits.exportDefault),
  });
  ui.confirmWrap = el('label', { className: 'wp-duckdb-confirm' }, [
    (ui.confirm = el('input', { type: 'checkbox' })),
    text(' I understand this reads and can change WordPress accounts, passwords, and roles.'),
  ]);
  ui.confirmWrap.hidden = true;
  ui.load = el('button', { type: 'button', className: 'button button-primary' }, [text('Load into DuckDB')]);
  ui.load.addEventListener('click', () => loadIntoDuck().catch((error) => setNotice('error', errorText(error))));

  const aside = el('aside', { className: 'wp-duckdb-side' }, [
    el('h2', {}, [text('Tables')]),
    ui.tableList,
    el('label', { className: 'wp-duckdb-limit', htmlFor: 'wp-duckdb-limit' }, [text('Row limit'), ui.limit]),
    ui.confirmWrap,
    ui.load,
  ]);

  ui.sql = el('textarea', {
    id: 'wp-duckdb-sql',
    className: 'wp-duckdb-sql',
    spellcheck: 'false',
    rows: '10',
  });
  ui.sql.value = "SELECT ID, post_title, post_status\nFROM posts\nLIMIT 20;";
  ui.sql.addEventListener('input', markPreviewStale);
  ui.sql.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
      event.preventDefault();
      runSql().catch((error) => setNotice('error', errorText(error)));
    }
  });

  ui.run = el('button', { type: 'button', className: 'button button-primary' }, [text('Run')]);
  ui.preview = el('button', { type: 'button', className: 'button' }, [text('Preview changes')]);
  ui.apply = el('button', { type: 'button', className: 'button' }, [text('Apply to WordPress')]);
  ui.run.addEventListener('click', () => runSql().catch((error) => setNotice('error', errorText(error))));
  ui.preview.addEventListener('click', () => previewChanges().catch((error) => setNotice('error', errorText(error))));
  ui.apply.addEventListener('click', () => applyChanges().catch((error) => setNotice('error', errorText(error))));

  ui.status = el('p', { className: 'wp-duckdb-status', role: 'status' });
  ui.notice = el('div', { className: 'wp-duckdb-notice', role: 'alert' });
  ui.notice.hidden = true;
  ui.results = el('div', { className: 'wp-duckdb-grid' });
  ui.diff = el('div', { className: 'wp-duckdb-diff' });

  const main = el('section', { className: 'wp-duckdb-main' }, [
    el('label', { htmlFor: 'wp-duckdb-sql' }, [text('SQL')]),
    ui.sql,
    el('div', { className: 'wp-duckdb-toolbar' }, [ui.run, ui.preview, ui.apply]),
    ui.status,
    ui.notice,
    el('h2', {}, [text('Results')]),
    ui.results,
    el('h2', {}, [text('Changes')]),
    ui.diff,
  ]);

  root.append(
    lede,
    el('div', { className: 'wp-duckdb-layout' }, [aside, main]),
  );
  syncButtons();
}

async function loadCatalog() {
  setStatus(window.wpDuckdb.i18n.loadingTables);
  const payload = await api('tables');
  state.catalog = payload.tables ?? [];
  renderCatalog();
  setStatus('');
}

function renderCatalog() {
  ui.tableList.replaceChildren();
  for (const table of state.catalog) {
    const input = el('input', { type: 'checkbox', value: table.name });
    input.addEventListener('change', () => {
      updateConfirmVisibility();
      markPreviewStale();
    });
    const label = el('label', {}, [
      input,
      text(` ${table.name}`),
      el('span', { className: 'wp-duckdb-muted' }, [
        text(table.sensitive ? ` · ${table.rows} rows · accounts` : ` · ${table.rows} rows`),
      ]),
    ]);
    ui.tableList.append(label);
  }
  updateConfirmVisibility();
}

function updateConfirmVisibility() {
  const needed = selectedNames().some((name) => state.catalog.find((table) => table.name === name)?.sensitive);
  ui.confirmWrap.hidden = !needed;
  if (!needed) {
    ui.confirm.checked = false;
  }
}

function selectedNames() {
  return [...ui.tableList.querySelectorAll('input[type="checkbox"]:checked')].map((input) => input.value);
}

async function loadIntoDuck() {
  const names = selectedNames();
  if (!names.length) {
    setNotice('error', window.wpDuckdb.i18n.selectTable);
    return;
  }
  if (needsUserConfirm(names) && !ui.confirm.checked) {
    setNotice('error', window.wpDuckdb.i18n.confirmUsers);
    return;
  }

  await withBusy(async () => {
    if (!state.session) {
      setStatus(window.wpDuckdb.i18n.starting);
      const db = await openMvpDatabase(window.wpDuckdb.mvpWasm, window.wpDuckdb.mvpWorker);
      state.session = new DuckSession(db);
    }
    setStatus(window.wpDuckdb.i18n.loadingRows);
    const limit = clampLimit(Number(ui.limit.value));
    ui.limit.value = String(limit);
    const payload = await api('export', {
      method: 'POST',
      body: {
        tables: names,
        limit,
        confirm_users: ui.confirm.checked,
      },
    });
    state.loaded = payload.tables ?? [];
    await state.session.load(state.loaded);
    state.preview = null;
    state.previewFresh = false;
    renderDiff(null);
    const truncated = state.loaded.some((table) => table.truncated);
    setNotice(truncated ? 'warn' : 'ok', truncated ? window.wpDuckdb.i18n.truncated : window.wpDuckdb.i18n.loaded);
    setStatus(loadedSummary());
  });
}

async function runSql() {
  if (!state.session) {
    setNotice('error', window.wpDuckdb.i18n.loaded);
    return;
  }
  const sql = ui.sql.value.trim();
  if (!sql) {
    setNotice('error', window.wpDuckdb.i18n.emptySql);
    return;
  }
  await withBusy(async () => {
    setStatus(window.wpDuckdb.i18n.running);
    const rows = await state.session.run(sql);
    renderResults(rows);
    markPreviewStale();
    setStatus(`${rows.length} result row${rows.length === 1 ? '' : 's'}. Preview changes before applying.`);
  });
}

async function previewChanges() {
  if (!state.session || !state.loaded.length) {
    setNotice('error', 'Load tables before previewing changes.');
    return;
  }
  await withBusy(async () => {
    setStatus(window.wpDuckdb.i18n.previewing);
    const currentByName = {};
    for (const table of state.loaded) {
      currentByName[table.name] = await state.session.readTable(table.name);
    }
    state.preview = diffSnapshot(state.loaded, currentByName);
    state.previewFresh = true;
    renderDiff(state.preview);
    const counts = mutationCounts(state.preview);
    const blocked = state.preview.some((table) => table.warning === 'no_primary_key');
    if (blocked) {
      setNotice('error', window.wpDuckdb.i18n.noPrimaryKey);
    } else if (counts.total === 0) {
      setNotice('ok', window.wpDuckdb.i18n.noChanges);
    } else {
      setNotice('ok', `${counts.inserted} inserts, ${counts.updated} updates, ${counts.deleted} deletes.`);
    }
    setStatus('Preview is current.');
  });
}

async function applyChanges() {
  if (!state.previewFresh || !state.preview) {
    setNotice('error', window.wpDuckdb.i18n.stale);
    return;
  }
  const counts = mutationCounts(state.preview);
  if (counts.total === 0) {
    setNotice('ok', window.wpDuckdb.i18n.noChanges);
    return;
  }
  if (state.preview.some((table) => table.warning === 'no_primary_key')) {
    setNotice('error', window.wpDuckdb.i18n.noPrimaryKey);
    return;
  }
  const names = state.preview.filter(hasMutations).map((table) => table.name);
  if (needsUserConfirm(names) && !ui.confirm.checked) {
    setNotice('error', window.wpDuckdb.i18n.confirmUsers);
    return;
  }
  const confirmed = window.confirm(`Apply ${counts.inserted} inserts, ${counts.updated} updates, and ${counts.deleted} deletes to the WordPress database?`);
  if (!confirmed) {
    return;
  }

  await withBusy(async () => {
    setStatus(window.wpDuckdb.i18n.applying);
    const body = {
      confirm_users: ui.confirm.checked,
      tables: state.preview.filter(hasMutations).map((table) => ({
        name: table.name,
        inserts: table.inserts,
        updates: table.updates,
        deletes: table.deletes,
      })),
    };
    const applied = await api('apply', { method: 'POST', body });
    try {
      const limit = clampLimit(Number(ui.limit.value));
      const payload = await api('export', {
        method: 'POST',
        body: {
          tables: state.loaded.map((table) => table.name),
          limit,
          confirm_users: ui.confirm.checked || !needsUserConfirm(state.loaded.map((table) => table.name)),
        },
      });
      state.loaded = payload.tables ?? [];
      await state.session.load(state.loaded);
      setNotice('ok', appliedSummary(applied));
    } catch (error) {
      setNotice('warn', `${window.wpDuckdb.i18n.appliedReload} ${errorText(error)}`);
    }
    state.preview = null;
    state.previewFresh = false;
    renderDiff(null);
    setStatus(appliedSummary(applied));
  });
}

function renderResults(rows) {
  ui.results.replaceChildren();
  if (!rows.length) {
    ui.results.append(el('p', { className: 'wp-duckdb-muted' }, [text('Statement finished. No rows returned.')]));
    return;
  }
  const shown = rows.slice(0, 200);
  ui.results.append(grid(shown));
  if (rows.length > shown.length) {
    ui.results.append(el('p', { className: 'wp-duckdb-muted' }, [text(`Showing ${shown.length} of ${rows.length} rows.`)]));
  }
}

function renderDiff(diff) {
  ui.diff.replaceChildren();
  if (!diff) {
    ui.diff.append(el('p', { className: 'wp-duckdb-muted' }, [text('Run SQL, then preview the diff before applying it.')]));
    syncButtons();
    return;
  }
  const counts = mutationCounts(diff);
  ui.diff.append(el('p', {}, [text(`${counts.inserted} inserts, ${counts.updated} updates, ${counts.deleted} deletes.`)]));
  for (const table of diff) {
    if (table.warning === 'no_primary_key') {
      ui.diff.append(el('p', { className: 'wp-duckdb-notice is-warn' }, [text(`${table.name}: no primary key, so changes cannot be applied.`)]));
      continue;
    }
    if (!hasMutations(table)) {
      continue;
    }
    const block = el('section', {}, [el('h3', {}, [text(table.name)])]);
    block.append(changeList('Inserts', table.inserts.map((row) => JSON.stringify(row))));
    block.append(changeList('Updates', table.updates.map((row) => `${JSON.stringify(row.primary_key)} → ${JSON.stringify(row.changes)}`)));
    block.append(changeList('Deletes', table.deletes.map((row) => JSON.stringify(row))));
    if (touchesAccounts(table)) {
      block.append(el('p', { className: 'wp-duckdb-notice is-warn' }, [text('This change touches account fields such as user_pass, user_login, or capabilities.')]));
    }
    ui.diff.append(block);
  }
  syncButtons();
}

function changeList(title, lines) {
  const wrap = el('div', {}, [el('h4', {}, [text(title)])]);
  if (!lines.length) {
    wrap.append(el('p', { className: 'wp-duckdb-muted' }, [text('None')]));
    return wrap;
  }
  const list = el('ul', {});
  for (const line of lines.slice(0, 20)) {
    list.append(el('li', {}, [text(truncate(line, 180))]));
  }
  if (lines.length > 20) {
    list.append(el('li', {}, [text(`… ${lines.length - 20} more`)]));
  }
  wrap.append(list);
  return wrap;
}

function grid(rows) {
  const columns = Object.keys(rows[0] ?? {});
  const table = el('table', {});
  const head = el('tr', {});
  for (const column of columns) {
    head.append(el('th', {}, [text(column)]));
  }
  table.append(el('thead', {}, [head]));
  const body = el('tbody', {});
  for (const row of rows) {
    const tr = el('tr', {});
    for (const column of columns) {
      tr.append(el('td', { title: displayCell(row[column]) }, [text(truncate(displayCell(row[column]), 200))]));
    }
    body.append(tr);
  }
  table.append(body);
  return table;
}

function displayCell(value) {
  if (value === null || value === undefined) {
    return 'NULL';
  }
  if (typeof value === 'bigint') {
    return value.toString();
  }
  if (typeof value === 'object') {
    return JSON.stringify(value);
  }
  return String(value);
}

function truncate(value, length) {
  if (value.length <= length) {
    return value;
  }
  return `${value.slice(0, length)}…`;
}

function mutationCounts(diff) {
  const counts = { inserted: 0, updated: 0, deleted: 0, total: 0 };
  for (const table of diff) {
    counts.inserted += table.inserts.length;
    counts.updated += table.updates.length;
    counts.deleted += table.deletes.length;
  }
  counts.total = counts.inserted + counts.updated + counts.deleted;
  return counts;
}

function hasMutations(table) {
  return table.inserts.length + table.updates.length + table.deletes.length > 0;
}

function touchesAccounts(table) {
  const blobs = [
    ...table.inserts.map((row) => JSON.stringify(row)),
    ...table.updates.map((row) => JSON.stringify(row.changes)),
  ];
  return blobs.some((blob) => /"user_pass"|"user_login"|"wp_capabilities"|"capabilities"/.test(blob));
}

function needsUserConfirm(names) {
  return names.some((name) => name === 'users' || name === 'usermeta' || state.catalog.find((table) => table.name === name)?.sensitive);
}

function loadedSummary() {
  return state.loaded.map((table) => `${table.name} (${table.rows.length}${table.truncated ? ` of ${table.total}` : ''} rows)`).join(', ');
}

function appliedSummary(applied) {
  const tables = applied.tables ?? {};
  let inserted = 0;
  let updated = 0;
  let deleted = 0;
  for (const counts of Object.values(tables)) {
    inserted += counts.inserted ?? 0;
    updated += counts.updated ?? 0;
    deleted += counts.deleted ?? 0;
  }
  return `Applied ${inserted} inserts, ${updated} updates, ${deleted} deletes.`;
}

function clampLimit(value) {
  const max = window.wpDuckdb.limits.exportMax;
  const fallback = window.wpDuckdb.limits.exportDefault;
  if (!Number.isFinite(value)) {
    return fallback;
  }
  return Math.min(max, Math.max(1, Math.trunc(value)));
}

function markPreviewStale() {
  state.previewFresh = false;
  syncButtons();
}

function syncButtons() {
  const loaded = Boolean(state.session && state.loaded.length);
  if (ui.run) {
    ui.run.disabled = state.busy || !loaded;
  }
  if (ui.preview) {
    ui.preview.disabled = state.busy || !loaded;
  }
  if (ui.apply) {
    ui.apply.disabled = state.busy || !state.previewFresh || !state.preview || mutationCounts(state.preview).total === 0;
  }
  if (ui.load) {
    ui.load.disabled = state.busy;
  }
}

async function withBusy(work) {
  state.busy = true;
  syncButtons();
  try {
    await work();
  } finally {
    state.busy = false;
    syncButtons();
  }
}

function setStatus(message) {
  ui.status.textContent = message;
}

function setNotice(kind, message) {
  ui.notice.hidden = !message;
  ui.notice.className = `wp-duckdb-notice is-${kind}`;
  ui.notice.textContent = message;
}

function errorText(error) {
  if (error instanceof Error && error.message) {
    return error.message;
  }
  return String(error);
}

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'className') {
      node.className = value;
    } else if (key === 'htmlFor') {
      node.htmlFor = value;
    } else if (value === true) {
      node.setAttribute(key, '');
    } else if (value !== false && value !== undefined) {
      node.setAttribute(key, value);
    }
  }
  for (const child of children) {
    node.append(child);
  }
  return node;
}

function text(value) {
  return document.createTextNode(value);
}
