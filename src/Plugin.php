<?php
/**
 * Plugin bootstrap.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb;

use Rawdreeg\WpDuckdb\Admin\AdminPage;
use Rawdreeg\WpDuckdb\Rest\RestController;

final class Plugin {
	public function register(): void {
		\add_action( 'init', array( $this, 'loadTextdomain' ) );
		( new AdminPage() )->register();
		\add_action( 'rest_api_init', array( $this, 'registerRest' ) );
	}

	public function loadTextdomain(): void {
		\load_plugin_textdomain(
			'wp-duckdb',
			false,
			dirname( \plugin_basename( \WP_DUCKDB_FILE ) ) . '/languages'
		);
	}

	public function registerRest(): void {
		RestController::fromGlobals()->registerRoutes();
	}
}
