<?php
/**
 * REST export and apply. Cookie auth uses the wp_rest nonce; callbacks also require manage_options.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Rest;

use Rawdreeg\WpDuckdb\Database\Allowlist;
use Rawdreeg\WpDuckdb\Database\ApplyException;
use Rawdreeg\WpDuckdb\Database\DiffApplier;
use Rawdreeg\WpDuckdb\Database\Limits;
use Rawdreeg\WpDuckdb\Database\TableExporter;
use Rawdreeg\WpDuckdb\Database\TableMap;
use Rawdreeg\WpDuckdb\Database\ValueCaster;
use Rawdreeg\WpDuckdb\Database\WpConnection;

final class RestController {
	public function __construct(
		private readonly TableExporter $exporter,
		private readonly DiffApplier $applier
	) {}

	public static function fromGlobals(): self {
		global $wpdb;

		$allowlist = new Allowlist();
		$tables    = TableMap::fromWpdb( $wpdb, $allowlist );
		$database  = new WpConnection( $wpdb );
		$values    = new ValueCaster();

		return new self(
			new TableExporter( $allowlist, $tables, $database, $values ),
			new DiffApplier( $allowlist, $tables, $database, $values )
		);
	}

	public function registerRoutes(): void {
		\register_rest_route(
			'wp-duckdb/v1',
			'/tables',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'tables' ),
				'permission_callback' => array( $this, 'permissions' ),
				'show_in_index'       => false,
			)
		);

		\register_rest_route(
			'wp-duckdb/v1',
			'/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'permissions' ),
				'show_in_index'       => false,
			)
		);

		\register_rest_route(
			'wp-duckdb/v1',
			'/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'permissions' ),
				'show_in_index'       => false,
			)
		);
	}

	public function permissions(): bool|\WP_Error {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'wp_duckdb_forbidden',
				\__( 'Sorry, you are not allowed to query this site database.', 'wp-duckdb' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function tables(): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( array( 'tables' => $this->exporter->catalog() ), 200 );
		} catch ( ApplyException $error ) {
			return $this->error( $error );
		}
	}

	public function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$params = $this->objectParams( $request );
			$names  = $params['tables'] ?? null;
			if ( ! is_array( $names ) || ! array_is_list( $names ) ) {
				throw new ApplyException( 'invalid_diff', 'tables must be a list.' );
			}

			return new \WP_REST_Response(
				$this->exporter->export( $names, $this->limit( $params ), $this->confirmUsers( $params ) ),
				200
			);
		} catch ( ApplyException $error ) {
			return $this->error( $error );
		}
	}

	public function apply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$params = $this->objectParams( $request );
			$tables = $params['tables'] ?? null;
			if ( ! is_array( $tables ) || ! array_is_list( $tables ) ) {
				throw new ApplyException( 'invalid_diff', 'tables must be a list.' );
			}

			return new \WP_REST_Response(
				$this->applier->apply( $tables, $this->confirmUsers( $params ) ),
				200
			);
		} catch ( ApplyException $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function objectParams( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			throw new ApplyException( 'invalid_json', 'Request body must be a JSON object.' );
		}

		return $params;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function confirmUsers( array $params ): bool {
		$confirm = $params['confirm_users'] ?? false;
		if ( ! is_bool( $confirm ) ) {
			throw new ApplyException( 'invalid_diff', 'confirm_users must be true or false.' );
		}

		return $confirm;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function limit( array $params ): int {
		if ( ! array_key_exists( 'limit', $params ) ) {
			return Limits::EXPORT_DEFAULT;
		}
		$limit = $params['limit'];
		if ( ! is_int( $limit ) ) {
			throw new ApplyException( 'invalid_limit', 'limit must be an integer.' );
		}

		return $limit;
	}

	private function error( ApplyException $error ): \WP_Error {
		return new \WP_Error(
			'wp_duckdb_' . $error->errorCode(),
			$error->getMessage(),
			array( 'status' => 400 )
		);
	}
}
