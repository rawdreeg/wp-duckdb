<?php
/**
 * Storage operations the exporter and applier need. WpConnection is the MySQL implementation.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

interface Connection {
	public function begin(): void;

	public function commit(): void;

	public function rollback(): void;

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>         $formats
	 */
	public function insert( string $table, array $data, array $formats ): void;

	/**
	 * Returns the number of matching rows. Zero means the primary key was not found.
	 * A row whose values did not change still counts as found.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @param list<string>         $formats
	 * @param list<string>         $whereFormats
	 */
	public function update( string $table, array $data, array $where, array $formats, array $whereFormats ): int;

	/**
	 * @param array<string, mixed> $where
	 * @param list<string>         $whereFormats
	 */
	public function delete( string $table, array $where, array $whereFormats ): int;

	/**
	 * @return list<ColumnSchema>
	 */
	public function columns( string $table ): array;

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows( string $table, int $limit ): array;

	public function count( string $table ): int;
}
