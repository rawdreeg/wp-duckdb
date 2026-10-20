<?php
/**
 * Remove plugin bookkeeping. Version 0.1 stores no settings; the deletes are safe if a later version adds them.
 *
 * @package Rawdreeg\WpDuckdb
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wp_duckdb_settings' );
delete_site_option( 'wp_duckdb_settings' );
