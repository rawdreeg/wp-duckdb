<?php
/**
 * Checks for table and column identifiers that will be quoted into SQL.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class Identifiers {
	public static function isLogicalTable( string $name ): bool {
		return 1 === preg_match( '/^[a-z0-9_]+$/', $name );
	}

	public static function isColumn( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_]+$/', $name );
	}

	public static function quote( string $name ): string {
		if ( ! self::isColumn( $name ) ) {
			throw new ApplyException( 'invalid_identifier', 'Invalid identifier.' );
		}

		return '`' . $name . '`';
	}

	public static function isBinaryType( string $dataType ): bool {
		return in_array(
			$dataType,
			array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' ),
			true
		);
	}
}
