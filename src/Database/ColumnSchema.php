<?php
/**
 * One live column, plus the DuckDB type used for the browser copy.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class ColumnSchema {
	public function __construct(
		public readonly string $name,
		public readonly string $dataType,
		public readonly bool $nullable,
		public readonly bool $primary,
		public readonly int $primaryPosition,
		public readonly bool $binary
	) {}

	public function isInteger(): bool {
		return in_array(
			$this->dataType,
			array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year', 'bit' ),
			true
		);
	}

	public function isFloat(): bool {
		return in_array( $this->dataType, array( 'float', 'double', 'real' ), true );
	}

	public function duckDbType(): string {
		if ( $this->binary ) {
			return 'VARCHAR';
		}
		if ( $this->isInteger() ) {
			return 'BIGINT';
		}
		if ( $this->isFloat() ) {
			return 'DOUBLE';
		}

		return 'VARCHAR';
	}
}
