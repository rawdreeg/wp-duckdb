<?php
/**
 * Plugin Name: WP DuckDB
 * Description: Query and edit the site database in DuckDB WASM, then apply a structured diff with prepared statements.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Rodrigue
 * Author URI: https://github.com/rawdreeg
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-duckdb
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_DUCKDB_FILE', __FILE__ );
define( 'WP_DUCKDB_VERSION', '0.1.0' );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $fqcn ): void {
			$prefix = 'Rawdreeg\\WpDuckdb\\';
			if ( ! str_starts_with( $fqcn, $prefix ) ) {
				return;
			}

			$relative = substr( $fqcn, strlen( $prefix ) );
			$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}

add_action(
	'plugins_loaded',
	static function (): void {
		( new Rawdreeg\WpDuckdb\Plugin() )->register();
	}
);
