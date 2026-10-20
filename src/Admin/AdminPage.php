<?php
/**
 * Tools → DuckDB screen. The browser loads the MVP DuckDB bundle from this plugin.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Admin;

use Rawdreeg\WpDuckdb\Database\Limits;

final class AdminPage {
	private const SCRIPT_HANDLE = 'wp-duckdb-admin';

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'menu' ) );
		\add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu(): void {
		\add_management_page(
			\__( 'DuckDB', 'wp-duckdb' ),
			\__( 'DuckDB', 'wp-duckdb' ),
			'manage_options',
			'wp-duckdb',
			array( $this, 'render' )
		);
	}

	public function assets( string $hook ): void {
		if ( 'tools_page_wp-duckdb' !== $hook || ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$style  = \plugins_url( 'assets/admin.css', \WP_DUCKDB_FILE );
		$script = \plugins_url( 'assets/admin.js', \WP_DUCKDB_FILE );

		\wp_enqueue_style( self::SCRIPT_HANDLE, $style, array(), \WP_DUCKDB_VERSION );
		\wp_enqueue_script( self::SCRIPT_HANDLE, $script, array(), \WP_DUCKDB_VERSION, true );

		$config = array(
			'restUrl'   => \esc_url_raw( \rest_url( 'wp-duckdb/v1/' ) ),
			'nonce'     => \wp_create_nonce( 'wp_rest' ),
			'mvpWasm'   => \esc_url_raw( \plugins_url( 'assets/duckdb/duckdb-mvp.wasm', \WP_DUCKDB_FILE ) ),
			'mvpWorker' => \esc_url_raw( \plugins_url( 'assets/duckdb/duckdb-browser-mvp.worker.js', \WP_DUCKDB_FILE ) ),
			'limits'    => array(
				'exportDefault' => Limits::EXPORT_DEFAULT,
				'exportMax'     => Limits::EXPORT_MAX,
				'maxMutations'  => Limits::MAX_MUTATIONS,
			),
			'i18n'      => array(
				'loadingTables' => \__( 'Loading tables…', 'wp-duckdb' ),
				'starting'      => \__( 'Starting DuckDB (single-thread)…', 'wp-duckdb' ),
				'loadingRows'   => \__( 'Copying rows into DuckDB…', 'wp-duckdb' ),
				'loaded'        => \__( 'Tables are loaded. SQL runs in the browser until you apply a previewed diff.', 'wp-duckdb' ),
				'running'       => \__( 'Running SQL…', 'wp-duckdb' ),
				'previewing'    => \__( 'Comparing DuckDB with the loaded snapshot…', 'wp-duckdb' ),
				'applying'      => \__( 'Applying changes…', 'wp-duckdb' ),
				'noChanges'     => \__( 'No database changes to apply.', 'wp-duckdb' ),
				'confirmUsers'  => \__( 'Confirm that you intend to read or change WordPress accounts before continuing.', 'wp-duckdb' ),
				'stale'         => \__( 'Preview the changes again before applying.', 'wp-duckdb' ),
				'emptySql'      => \__( 'Enter a SQL statement.', 'wp-duckdb' ),
				'selectTable'   => \__( 'Select at least one table.', 'wp-duckdb' ),
				'truncated'     => \__( 'Some tables were truncated. Deletes and updates apply only to loaded rows.', 'wp-duckdb' ),
				'noPrimaryKey'  => \__( 'A changed table has no primary key, so it cannot be written back.', 'wp-duckdb' ),
				'appliedReload' => \__( 'Changes were applied, but the snapshot could not be refreshed. Reload the tables before editing again.', 'wp-duckdb' ),
			),
		);

		\wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.wpDuckdb = ' . \wp_json_encode( $config ) . ';',
			'before'
		);
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-duckdb' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__( 'DuckDB', 'wp-duckdb' ) . '</h1>';
		echo '<div id="wp-duckdb-app" class="wp-duckdb-app">';
		echo '<p>' . \esc_html__( 'Loading the DuckDB workspace…', 'wp-duckdb' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}
}
