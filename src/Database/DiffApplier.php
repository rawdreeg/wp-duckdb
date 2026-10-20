<?php
/**
 * Applies a structured row diff with prepared statements inside one transaction.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class DiffApplier {
	public function __construct(
		private readonly Allowlist $allowlist,
		private readonly TableMap $tables,
		private readonly Connection $connection,
		private readonly ValueCaster $values
	) {}

	/**
	 * @param list<mixed> $tables
	 * @return array{tables: array<string, array{inserted: int, updated: int, deleted: int}>}
	 */
	public function apply( array $tables, bool $confirmUsers ): array {
		if ( array() === $tables ) {
			throw new ApplyException( 'empty_diff', 'No table changes were provided.' );
		}

		$normalized = array();
		$mutations  = 0;
		$seen       = array();

		foreach ( $tables as $table_diff ) {
			if ( ! is_array( $table_diff ) ) {
				throw new ApplyException( 'invalid_diff', 'Each table change must be an object.' );
			}

			$planned = $this->normalizeTable( $table_diff, $confirmUsers );
			if ( isset( $seen[ $planned['name'] ] ) ) {
				throw new ApplyException( 'duplicate_table', 'The same table was sent more than once.' );
			}
			$seen[ $planned['name'] ] = true;

			$count = count( $planned['inserts'] ) + count( $planned['updates'] ) + count( $planned['deletes'] );
			if ( 0 === $count ) {
				continue;
			}

			$mutations   += $count;
			$normalized[] = $planned;
		}

		if ( 0 === $mutations ) {
			throw new ApplyException( 'empty_diff', 'The diff has no inserts, updates, or deletes.' );
		}
		if ( $mutations > Limits::MAX_MUTATIONS ) {
			throw new ApplyException(
				'too_many_changes',
				'The diff has more than ' . Limits::MAX_MUTATIONS . ' changes.'
			);
		}

		$this->connection->begin();
		try {
			$applied = array();
			foreach ( $normalized as $planned ) {
				$applied[ $planned['name'] ] = $this->applyTable( $planned );
			}
			$this->connection->commit();
		} catch ( \Throwable $error ) {
			try {
				$this->connection->rollback();
			} catch ( \Throwable $rollback_error ) {
				throw new ApplyException(
					'rollback_failed',
					$error->getMessage() . ' Rollback failed: ' . $rollback_error->getMessage()
				);
			}
			throw $error;
		}

		return array( 'tables' => $applied );
	}

	/**
	 * @param array<string, mixed> $table_diff
	 * @return array<string, mixed>
	 */
	private function normalizeTable( array $table_diff, bool $confirm_users ): array {
		$name = $table_diff['name'] ?? null;
		if ( ! is_string( $name ) || ! $this->allowlist->isAllowed( $name ) ) {
			throw new ApplyException( 'unknown_table', 'That table is not on the allowlist.' );
		}
		if ( $this->allowlist->isSensitive( $name ) && ! $confirm_users ) {
			throw new ApplyException(
				'users_confirmation_required',
				'Confirm that you intend to read or change user tables.'
			);
		}

		$columns = new ColumnSet( $this->connection->columns( $this->tables->physical( $name ) ) );
		$primary = $columns->primaryKey();
		$inserts = $this->normalizeInserts( $this->listField( $table_diff, 'inserts' ), $columns, $primary );
		$updates = $this->normalizeUpdates( $this->listField( $table_diff, 'updates' ), $columns, $primary );
		$deletes = $this->normalizeDeletes( $this->listField( $table_diff, 'deletes' ), $columns, $primary );

		if ( array() === $primary && ( array() !== $inserts || array() !== $updates || array() !== $deletes ) ) {
			throw new ApplyException( 'no_primary_key', 'This table has no primary key and cannot be changed.' );
		}

		return array(
			'name'     => $name,
			'physical' => $this->tables->physical( $name ),
			'inserts'  => $inserts,
			'updates'  => $updates,
			'deletes'  => $deletes,
		);
	}

	/**
	 * @param array<string, mixed> $table_diff
	 * @return list<mixed>
	 */
	private function listField( array $table_diff, string $field ): array {
		if ( ! array_key_exists( $field, $table_diff ) ) {
			return array();
		}

		$value = $table_diff[ $field ];
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new ApplyException( 'invalid_diff', $field . ' must be a list.' );
		}

		return $value;
	}

	/**
	 * @param list<mixed>   $rows
	 * @param list<string>  $primary
	 * @return list<array{data: array<string, mixed>, formats: list<string>}>
	 */
	private function normalizeInserts( array $rows, ColumnSet $columns, array $primary ): array {
		$inserts = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new ApplyException( 'invalid_diff', 'Each insert must be an object.' );
			}
			$bound = $this->bindRow( $row, $columns );
			foreach ( $primary as $key ) {
				if ( ! array_key_exists( $key, $bound['data'] ) ) {
					throw new ApplyException( 'missing_primary_key', 'Inserts must include the primary key.' );
				}
			}
			$inserts[] = $bound;
		}

		return $inserts;
	}

	/**
	 * @param list<mixed>  $rows
	 * @param list<string> $primary
	 * @return list<array{changes: array<string, mixed>, changeFormats: list<string>, where: array<string, mixed>, whereFormats: list<string>}>
	 */
	private function normalizeUpdates( array $rows, ColumnSet $columns, array $primary ): array {
		$updates = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new ApplyException( 'invalid_diff', 'Each update must be an object.' );
			}

			$where_row  = $row['primary_key'] ?? null;
			$change_row = $row['changes'] ?? null;
			if ( ! is_array( $where_row ) || ! is_array( $change_row ) ) {
				throw new ApplyException( 'invalid_diff', 'Each update needs primary_key and changes objects.' );
			}
			if ( array() === $change_row ) {
				throw new ApplyException( 'empty_changes', 'An update has no column changes.' );
			}

			$this->assertExactPrimaryKey( $where_row, $primary );
			foreach ( array_keys( $change_row ) as $key ) {
				if ( in_array( $key, $primary, true ) ) {
					throw new ApplyException( 'primary_key_change', 'Updates cannot change primary key columns.' );
				}
			}

			$changes   = $this->bindRow( $change_row, $columns );
			$where     = $this->bindRow( $where_row, $columns );
			$updates[] = array(
				'changes'       => $changes['data'],
				'changeFormats' => $changes['formats'],
				'where'         => $where['data'],
				'whereFormats'  => $where['formats'],
			);
		}

		return $updates;
	}

	/**
	 * @param list<mixed>  $rows
	 * @param list<string> $primary
	 * @return list<array{data: array<string, mixed>, formats: list<string>}>
	 */
	private function normalizeDeletes( array $rows, ColumnSet $columns, array $primary ): array {
		$deletes = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new ApplyException( 'invalid_diff', 'Each delete must be a primary key object.' );
			}
			$this->assertExactPrimaryKey( $row, $primary );
			$deletes[] = $this->bindRow( $row, $columns );
		}

		return $deletes;
	}

	/**
	 * @param array<mixed, mixed> $row
	 * @param list<string>        $primary
	 */
	private function assertExactPrimaryKey( array $row, array $primary ): void {
		$keys = array();
		foreach ( array_keys( $row ) as $key ) {
			if ( ! is_string( $key ) ) {
				throw new ApplyException( 'invalid_primary_key', 'Primary key columns must be named.' );
			}
			$keys[] = $key;
		}

		$expected = $primary;
		$actual   = $keys;
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new ApplyException( 'invalid_primary_key', 'The primary key does not match this table.' );
		}
	}

	/**
	 * @param array<mixed, mixed> $row
	 * @return array{data: array<string, mixed>, formats: list<string>}
	 */
	private function bindRow( array $row, ColumnSet $columns ): array {
		$data    = array();
		$formats = array();
		foreach ( $row as $key => $value ) {
			if ( ! is_string( $key ) || ! $columns->has( $key ) ) {
				throw new ApplyException( 'unknown_column', 'Unknown column in the change set.' );
			}
			$column = $columns->get( $key );
			if ( $column->binary ) {
				throw new ApplyException( 'binary_column', 'Binary columns cannot be written.' );
			}
			$bound        = $this->values->importValue( $column, $value );
			$data[ $key ] = $bound;
			$formats[]    = $this->values->formatFor( $column, $bound );
		}

		return array(
			'data'    => $data,
			'formats' => $formats,
		);
	}

	/**
	 * @param array<string, mixed> $planned
	 * @return array{inserted: int, updated: int, deleted: int}
	 */
	private function applyTable( array $planned ): array {
		$deleted  = 0;
		$updated  = 0;
		$inserted = 0;

		foreach ( $planned['deletes'] as $delete ) {
			$affected = $this->connection->delete( $planned['physical'], $delete['data'], $delete['formats'] );
			if ( $affected < 1 ) {
				throw new ApplyException( 'row_not_found', 'No row matched the primary key for delete.' );
			}
			++$deleted;
		}

		foreach ( $planned['updates'] as $update ) {
			$affected = $this->connection->update(
				$planned['physical'],
				$update['changes'],
				$update['where'],
				$update['changeFormats'],
				$update['whereFormats']
			);
			if ( $affected < 1 ) {
				throw new ApplyException( 'row_not_found', 'No row matched the primary key for update.' );
			}
			++$updated;
		}

		foreach ( $planned['inserts'] as $insert ) {
			$this->connection->insert( $planned['physical'], $insert['data'], $insert['formats'] );
			++$inserted;
		}

		return array(
			'inserted' => $inserted,
			'updated'  => $updated,
			'deleted'  => $deleted,
		);
	}
}
