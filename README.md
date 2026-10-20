# WP DuckDB

Query a copy of the WordPress database in DuckDB WASM, then apply the row changes you preview. SQL runs in the browser. PHP applies a structured diff with prepared statements and never forwards that SQL to MySQL.

## Requirements

- WordPress 6.4 or newer
- PHP 8.1 or newer
- An administrator account (`manage_options`)
- InnoDB tables, so the apply transaction can roll back

The admin screen loads `@duckdb/duckdb-wasm` **1.32.0** in MVP / single-thread mode (`duckdb-mvp.wasm` and `duckdb-browser-mvp.worker.js`). It does not need `Cross-Origin-Opener-Policy` or `Cross-Origin-Embedder-Policy` headers.

## Install

1. Copy this directory to `wp-content/plugins/wp-duckdb`. The built files in `assets/` are part of the plugin, so the site does not need Node.
2. Activate **WP DuckDB**.
3. Open **Tools → DuckDB**.

There are no runtime PHP dependencies. `wp-duckdb.php` loads `vendor/autoload.php` when Composer has been installed, and otherwise registers the same PSR-4 map for `Rawdreeg\WpDuckdb\`.

Apache sites can pick up `assets/duckdb/.htaccess`, which sets the WASM MIME type. If the engine fails to start, confirm the host serves `.wasm` as `application/wasm`.

## Usage

1. Choose content tables. `users` and `usermeta` stay locked until you check the account confirmation.
2. Set a row limit (default 1000, maximum 10000) and click **Load into DuckDB**. Table names in the editor are unprefixed (`posts`, not `wp_posts`).
3. Run one SQL statement. Ctrl/Cmd+Enter runs it. Results show up to 200 rows.
4. Click **Preview changes**. Inserts, updates, and deletes are computed against the loaded snapshot.
5. Click **Apply to WordPress** and confirm. PHP checks the diff, then writes it in one transaction.

A truncated load is marked in the screen. Deletes and updates then affect only the rows that were copied into DuckDB. Rows that were never loaded are left as they are.

`user_pass` is stored as-is. Replacing a password hash with plain text will not make that password log in.

## What can be changed

Content tables: `posts`, `postmeta`, `comments`, `commentmeta`, `terms`, `termmeta`, `term_taxonomy`, `term_relationships`, `links`.

Sensitive tables, after confirmation: `users`, `usermeta`.

`options` and every other table are refused. Tables without a primary key can be queried in DuckDB but cannot be written back. Binary columns are shown as base64 and cannot be written.

## Development

```bash
composer install
composer test
composer phpcs

npm ci
npm test
npm run build
```

`npm run build` writes `assets/admin.js`, `assets/admin.css`, `assets/duckdb/duckdb-mvp.wasm`, and `assets/duckdb/duckdb-browser-mvp.worker.js`.

PHPUnit covers the allowlist, value casting, export payload, and diff validation. Those tests use a fake database connection and do not boot WordPress. The browser diff uses the same rules and is tested with `node --test`.

## Uninstall

`uninstall.php` deletes the `wp_duckdb_settings` option if a future version stores one. Version 0.1 does not write options. Deactivating the plugin does not change table rows that were already applied.
