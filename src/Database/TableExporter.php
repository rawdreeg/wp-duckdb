<?php
/**
 * Reads allowlisted tables into a JSON payload the browser can load into DuckDB.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class TableExporter {
	public function __construct(
		private readonly Allowlist $allowlist,
		private readonly TableMap $tables,
		private readonly Connection $connection,
		private readonly ValueCaster $values
	) {}

	/**
	 * @return list<array{name: string, sensitive: bool, rows: int}>
	 */
	public function catalog(): array {
		$catalog = array();
		foreach ( $this->allowlist->all() as $name ) {
			$catalog[] = array(
				'name'      => $name,
				'sensitive' => $this->allowlist->isSensitive( $name ),
				'rows'      => $this->connection->count( $this->tables->physical( $name ) ),
			);
		}

		return $catalog;
	}

	/**
	 * @param list<mixed> $names
	 * @return array{limit: int, tables: list<array<string, mixed>>}
	 */
	public function export( array $names, int $limit, bool $confirmUsers ): array {
		if ( array() === $names ) {
			throw new ApplyException( 'empty_selection', 'Select at least one table.' );
		}

		$limit  = $this->clampLimit( $limit );
		$seen   = array();
		$tables = array();

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || ! $this->allowlist->isAllowed( $name ) ) {
				throw new ApplyException( 'unknown_table', 'That table is not on the allowlist.' );
			}
			if ( isset( $seen[ $name ] ) ) {
				throw new ApplyException( 'duplicate_table', 'The same table was requested more than once.' );
			}
			$seen[ $name ] = true;

			if ( $this->allowlist->isSensitive( $name ) && ! $confirmUsers ) {
				throw new ApplyException(
					'users_confirmation_required',
					'Confirm that you intend to read or change user tables.'
				);
			}

			$tables[] = $this->exportOne( $name, $limit );
		}

		return array(
			'limit'  => $limit,
			'tables' => $tables,
		);
	}

	public function clampLimit( int $limit ): int {
		if ( $limit < 1 ) {
			return 1;
		}
		if ( $limit > Limits::EXPORT_MAX ) {
			return Limits::EXPORT_MAX;
		}

		return $limit;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function exportOne( string $name, int $limit ): array {
		$physical = $this->tables->physical( $name );
		$columns  = new ColumnSet( $this->connection->columns( $physical ) );
		if ( array() === $columns->all() ) {
			throw new ApplyException( 'schema_failed', 'Could not read the schema for ' . $name . '.' );
		}

		$total = $this->connection->count( $physical );
		$rows  = array();
		foreach ( $this->connection->rows( $physical, $limit ) as $row ) {
			if ( ! is_array( $row ) ) {
				throw new ApplyException( 'read_failed', 'Could not read rows from ' . $name . '.' );
			}
			$cast = array();
			foreach ( $columns->all() as $column ) {
				$cast[ $column->name ] = $this->values->exportValue( $column, $row[ $column->name ] ?? null );
			}
			$rows[] = $cast;
		}

		$payload = array();
		foreach ( $columns->all() as $column ) {
			$payload[] = array(
				'name'     => $column->name,
				'type'     => $column->dataType,
				'duckdb'   => $column->duckDbType(),
				'nullable' => $column->nullable,
				'primary'  => $column->primary,
				'binary'   => $column->binary,
			);
		}

		return array(
			'name'        => $name,
			'physical'    => $physical,
			'primary_key' => $columns->primaryKey(),
			'total'       => $total,
			'truncated'   => $total > $limit,
			'columns'     => $payload,
			'rows'        => $rows,
		);
	}
}
