<?php
/**
 * MySQL access through $wpdb. User SQL is never passed in.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class WpConnection implements Connection {
	public function __construct( private readonly object $wpdb ) {}

	public function begin(): void {
		$this->command( 'START TRANSACTION' );
	}

	public function commit(): void {
		$this->command( 'COMMIT' );
	}

	public function rollback(): void {
		$this->command( 'ROLLBACK' );
	}

	public function insert( string $table, array $data, array $formats ): void {
		$result = $this->wpdb->insert( $table, $data, $formats );
		if ( false === $result ) {
			throw new ApplyException( 'write_failed', $this->lastError( 'Insert failed.' ) );
		}
	}

	public function update( string $table, array $data, array $where, array $formats, array $whereFormats ): int {
		$result = $this->wpdb->update( $table, $data, $where, $formats, $whereFormats );
		if ( false === $result ) {
			throw new ApplyException( 'write_failed', $this->lastError( 'Update failed.' ) );
		}
		if ( $result > 0 ) {
			return (int) $result;
		}
		if ( $this->rowExists( $table, $where, $whereFormats ) ) {
			return 1;
		}

		return 0;
	}

	public function delete( string $table, array $where, array $whereFormats ): int {
		$result = $this->wpdb->delete( $table, $where, $whereFormats );
		if ( false === $result ) {
			throw new ApplyException( 'write_failed', $this->lastError( 'Delete failed.' ) );
		}

		return (int) $result;
	}

	public function columns( string $table ): array {
		Identifiers::quote( $table );
		$wpdb   = $this->wpdb;
		$schema = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
				ORDER BY ORDINAL_POSITION',
				$wpdb->dbname,
				$table
			),
			'ARRAY_A'
		);
		if ( ! is_array( $schema ) ) {
			throw new ApplyException( 'schema_failed', $this->lastError( 'Could not read table schema.' ) );
		}

		$primary = $this->primaryKeyPositions( $table );
		$columns = array();
		foreach ( $schema as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name      = (string) ( $row['COLUMN_NAME'] ?? '' );
			$dataType  = strtolower( (string) ( $row['DATA_TYPE'] ?? '' ) );
			$position  = $primary[ $name ] ?? 0;
			$columns[] = new ColumnSchema(
				$name,
				$dataType,
				'YES' === ( $row['IS_NULLABLE'] ?? 'YES' ),
				$position > 0,
				$position,
				Identifiers::isBinaryType( $dataType )
			);
		}

		return $columns;
	}

	public function rows( string $table, int $limit ): array {
		$limit = max( 0, $limit );
		$sql   = 'SELECT * FROM ' . Identifiers::quote( $table ) . ' LIMIT ' . $limit;
		// Identifiers are validated before quoting. LIMIT is an integer. There is no value to prepare.
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			throw new ApplyException( 'read_failed', $this->lastError( 'Could not read rows.' ) );
		}

		return $rows;
	}

	public function count( string $table ): int {
		$sql = 'SELECT COUNT(*) FROM ' . Identifiers::quote( $table );
		// The table name is an allowlisted identifier, not a value placeholder.
		$value = $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( null === $value && '' !== (string) $this->wpdb->last_error ) {
			throw new ApplyException( 'read_failed', $this->lastError( 'Could not count rows.' ) );
		}

		return (int) $value;
	}

	private function command( string $sql ): void {
		// START TRANSACTION, COMMIT, and ROLLBACK are fixed statements.
		$result = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result ) {
			throw new ApplyException( 'transaction_failed', $this->lastError( 'Transaction statement failed.' ) );
		}
	}

	/**
	 * @return array<string, int>
	 */
	private function primaryKeyPositions( string $table ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME, SEQ_IN_INDEX
				FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s
				ORDER BY SEQ_IN_INDEX',
				$wpdb->dbname,
				$table,
				'PRIMARY'
			),
			'ARRAY_A'
		);
		if ( ! is_array( $rows ) ) {
			throw new ApplyException( 'schema_failed', $this->lastError( 'Could not read the primary key.' ) );
		}

		$positions = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$positions[ (string) $row['COLUMN_NAME'] ] = (int) $row['SEQ_IN_INDEX'];
		}

		return $positions;
	}

	/**
	 * @param array<string, mixed> $where
	 * @param list<string>         $formats
	 */
	private function rowExists( string $table, array $where, array $formats ): bool {
		$clauses      = array();
		$values       = array();
		$format_index = 0;

		foreach ( $where as $column => $value ) {
			$quoted = Identifiers::quote( (string) $column );
			$format = $formats[ $format_index ] ?? '%s';
			++$format_index;
			if ( null === $value ) {
				$clauses[] = $quoted . ' IS NULL';
				continue;
			}
			if ( ! in_array( $format, array( '%s', '%d', '%f' ), true ) ) {
				throw new ApplyException( 'invalid_format', 'Unsupported value format.' );
			}
			$clauses[] = $quoted . ' = ' . $format;
			$values[]  = $value;
		}

		$wpdb = $this->wpdb;
		$sql  = 'SELECT 1 FROM ' . Identifiers::quote( $table ) . ' WHERE ' . implode( ' AND ', $clauses ) . ' LIMIT 1';
		if ( array() === $values ) {
			// No value placeholders are possible when every key column is NULL.
			$found = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			// Identifiers are quoted; values are bound by prepare().
			$found = $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return null !== $found;
	}

	private function lastError( string $fallback ): string {
		$error = trim( (string) $this->wpdb->last_error );
		if ( '' === $error ) {
			return $fallback;
		}

		return $error;
	}
}
