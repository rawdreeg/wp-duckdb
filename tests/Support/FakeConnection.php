<?php
/**
 * In-memory connection for exporter and applier tests.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Tests\Support;

use Rawdreeg\WpDuckdb\Database\ColumnSchema;
use Rawdreeg\WpDuckdb\Database\Connection;

final class FakeConnection implements Connection {
	/** @var list<array{op: string, args: array<int, mixed>}> */
	public array $calls = array();

	public ?\Throwable $failOn = null;

	public string $failOp = '';

	public int $updateResult = 1;

	public int $deleteResult = 1;

	/** @var array<string, list<ColumnSchema>> */
	public array $schemas = array();

	/** @var array<string, list<array<string, mixed>>> */
	public array $rows = array();

	/** @var array<string, int> */
	public array $counts = array();

	public function begin(): void {
		$this->record( 'begin' );
	}

	public function commit(): void {
		$this->record( 'commit' );
	}

	public function rollback(): void {
		$this->record( 'rollback' );
	}

	public function insert( string $table, array $data, array $formats ): void {
		$this->record( 'insert', array( $table, $data, $formats ) );
	}

	public function update( string $table, array $data, array $where, array $formats, array $whereFormats ): int {
		$this->record( 'update', array( $table, $data, $where, $formats, $whereFormats ) );
		return $this->updateResult;
	}

	public function delete( string $table, array $where, array $whereFormats ): int {
		$this->record( 'delete', array( $table, $where, $whereFormats ) );
		return $this->deleteResult;
	}

	public function columns( string $table ): array {
		$this->record( 'columns', array( $table ) );
		return $this->schemas[ $table ] ?? array();
	}

	public function rows( string $table, int $limit ): array {
		$this->record( 'rows', array( $table, $limit ) );
		return array_slice( $this->rows[ $table ] ?? array(), 0, $limit );
	}

	public function count( string $table ): int {
		$this->record( 'count', array( $table ) );
		if ( isset( $this->counts[ $table ] ) ) {
			return $this->counts[ $table ];
		}

		return count( $this->rows[ $table ] ?? array() );
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function record( string $op, array $args = array() ): void {
		$this->calls[] = array(
			'op'   => $op,
			'args' => $args,
		);
		if ( $this->failOp === $op && $this->failOn instanceof \Throwable ) {
			$error        = $this->failOn;
			$this->failOn = null;
			throw $error;
		}
	}
}
