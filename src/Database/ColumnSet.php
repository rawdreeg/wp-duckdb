<?php
/**
 * Columns for one table, indexed by name, with the primary key in index order.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class ColumnSet {
	/**
	 * @var array<string, ColumnSchema>
	 */
	private array $byName;

	/**
	 * @var list<string>
	 */
	private array $primaryKey;

	/**
	 * @param list<ColumnSchema> $columns
	 */
	public function __construct( private readonly array $columns ) {
		$this->byName = array();
		foreach ( $columns as $column ) {
			$this->byName[ $column->name ] = $column;
		}

		$primary = array_values(
			array_filter(
				$columns,
				static fn ( ColumnSchema $column ): bool => $column->primary
			)
		);
		usort(
			$primary,
			static fn ( ColumnSchema $left, ColumnSchema $right ): int => $left->primaryPosition <=> $right->primaryPosition
		);
		$this->primaryKey = array_map(
			static fn ( ColumnSchema $column ): string => $column->name,
			$primary
		);
	}

	public function has( string $name ): bool {
		return isset( $this->byName[ $name ] );
	}

	public function get( string $name ): ColumnSchema {
		if ( ! isset( $this->byName[ $name ] ) ) {
			throw new ApplyException( 'unknown_column', 'Unknown column in the change set.' );
		}

		return $this->byName[ $name ];
	}

	/**
	 * @return list<string>
	 */
	public function primaryKey(): array {
		return $this->primaryKey;
	}

	/**
	 * @return list<ColumnSchema>
	 */
	public function all(): array {
		return $this->columns;
	}
}
