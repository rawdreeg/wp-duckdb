<?php
/**
 * Tables an administrator is allowed to copy into DuckDB.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class Allowlist {
	/**
	 * Core content tables. Logical names match $wpdb properties.
	 *
	 * @var list<string>
	 */
	private const CONTENT = array(
		'posts',
		'postmeta',
		'comments',
		'commentmeta',
		'terms',
		'termmeta',
		'term_taxonomy',
		'term_relationships',
		'links',
	);

	/**
	 * Account tables. Export and apply require an explicit confirmation.
	 *
	 * @var list<string>
	 */
	private const SENSITIVE = array(
		'users',
		'usermeta',
	);

	public function isContent( string $name ): bool {
		return in_array( $name, self::CONTENT, true );
	}

	public function isSensitive( string $name ): bool {
		return in_array( $name, self::SENSITIVE, true );
	}

	public function isAllowed( string $name ): bool {
		return Identifiers::isLogicalTable( $name ) && ( $this->isContent( $name ) || $this->isSensitive( $name ) );
	}

	/**
	 * @return list<string>
	 */
	public function all(): array {
		return array_merge( self::CONTENT, self::SENSITIVE );
	}
}
