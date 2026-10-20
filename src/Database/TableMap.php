<?php
/**
 * Logical allowlist names to the physical names WordPress already resolved.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class TableMap {
	/**
	 * @param array<string, string> $physicalByLogical
	 */
	public function __construct( private readonly array $physicalByLogical ) {}

	public static function fromWpdb( object $wpdb, Allowlist $allowlist ): self {
		$map = array();
		foreach ( $allowlist->all() as $name ) {
			$physical = $wpdb->{$name} ?? null;
			if ( ! is_string( $physical ) || ! Identifiers::isColumn( $physical ) ) {
				throw new ApplyException(
					'misconfigured_table',
					'WordPress did not provide a usable name for ' . $name . '.'
				);
			}
			$map[ $name ] = $physical;
		}

		return new self( $map );
	}

	public function physical( string $logical ): string {
		if ( ! isset( $this->physicalByLogical[ $logical ] ) ) {
			throw new ApplyException( 'unknown_table', 'That table is not on the allowlist.' );
		}

		return $this->physicalByLogical[ $logical ];
	}
}
