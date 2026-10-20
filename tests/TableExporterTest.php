<?php
/**
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Tests;

use PHPUnit\Framework\TestCase;
use Rawdreeg\WpDuckdb\Database\Allowlist;
use Rawdreeg\WpDuckdb\Database\ApplyException;
use Rawdreeg\WpDuckdb\Database\ColumnSchema;
use Rawdreeg\WpDuckdb\Database\TableExporter;
use Rawdreeg\WpDuckdb\Database\TableMap;
use Rawdreeg\WpDuckdb\Database\ValueCaster;
use Rawdreeg\WpDuckdb\Tests\Support\FakeConnection;

final class TableExporterTest extends TestCase {
	private FakeConnection $db;

	private TableExporter $exporter;

	protected function setUp(): void {
		$this->db = new FakeConnection();
		$this->db->schemas['wp_posts'] = array(
			new ColumnSchema( 'ID', 'bigint', false, true, 1, false ),
			new ColumnSchema( 'post_date', 'datetime', false, false, 0, false ),
			new ColumnSchema( 'price', 'decimal', true, false, 0, false ),
			new ColumnSchema( 'rating', 'float', true, false, 0, false ),
			new ColumnSchema( 'payload', 'blob', true, false, 0, true ),
		);
		$this->db->rows['wp_posts'] = array(
			array(
				'ID'        => '42',
				'post_date' => '0000-00-00 00:00:00',
				'price'     => '10.50',
				'rating'    => '1.5',
				'payload'   => 'hi',
			),
			array(
				'ID'        => '9007199254740993',
				'post_date' => '2026-01-01 00:00:00',
				'price'     => null,
				'rating'    => null,
				'payload'   => null,
			),
			array(
				'ID'        => '7',
				'post_date' => '2026-01-02 00:00:00',
				'price'     => '1.00',
				'rating'    => '2',
				'payload'   => '',
			),
		);
		$this->db->counts['wp_posts'] = 3;

		$allowlist = new Allowlist();
		$map       = array();
		foreach ( $allowlist->all() as $name ) {
			$map[ $name ] = 'wp_' . $name;
		}
		$this->exporter = new TableExporter( $allowlist, new TableMap( $map ), $this->db, new ValueCaster() );
	}

	public function testExportCastsRowsAndMarksTruncation(): void {
		$result = $this->exporter->export( array( 'posts' ), 2, false );
		self::assertSame( 2, $result['limit'] );
		self::assertCount( 1, $result['tables'] );

		$table = $result['tables'][0];
		self::assertSame( 'posts', $table['name'] );
		self::assertSame( 'wp_posts', $table['physical'] );
		self::assertSame( array( 'ID' ), $table['primary_key'] );
		self::assertTrue( $table['truncated'] );
		self::assertSame( 3, $table['total'] );
		self::assertCount( 2, $table['rows'] );
		self::assertSame( 42, $table['rows'][0]['ID'] );
		self::assertSame( '0000-00-00 00:00:00', $table['rows'][0]['post_date'] );
		self::assertSame( '10.50', $table['rows'][0]['price'] );
		self::assertSame( 1.5, $table['rows'][0]['rating'] );
		self::assertSame( base64_encode( 'hi' ), $table['rows'][0]['payload'] );
		self::assertSame( '9007199254740993', $table['rows'][1]['ID'] );
		self::assertSame( 'BIGINT', $table['columns'][0]['duckdb'] );
		self::assertSame( 'VARCHAR', $table['columns'][1]['duckdb'] );
		self::assertTrue( $table['columns'][4]['binary'] );

		$rows_call = null;
		foreach ( $this->db->calls as $call ) {
			if ( 'rows' === $call['op'] ) {
				$rows_call = $call['args'];
			}
		}
		self::assertSame( array( 'wp_posts', 2 ), $rows_call );
	}

	public function testUsersRequireConfirmationAndLimitsClamp(): void {
		try {
			$this->exporter->export( array( 'users' ), 1000, false );
			self::fail( 'Expected confirmation.' );
		} catch ( ApplyException $error ) {
			self::assertSame( 'users_confirmation_required', $error->errorCode() );
		}

		$result = $this->exporter->export( array( 'posts' ), 99999, false );
		self::assertSame( 10000, $result['limit'] );

		$clamped = $this->exporter->export( array( 'posts' ), 0, false );
		self::assertSame( 1, $clamped['limit'] );
		self::assertCount( 1, $clamped['tables'][0]['rows'] );
	}

	public function testRejectsUnknownAndDuplicateTables(): void {
		try {
			$this->exporter->export( array( 'posts; DROP TABLE wp_posts' ), 10, false );
			self::fail( 'Expected unknown table.' );
		} catch ( ApplyException $error ) {
			self::assertSame( 'unknown_table', $error->errorCode() );
		}

		try {
			$this->exporter->export( array( 'posts', 'posts' ), 10, false );
			self::fail( 'Expected duplicate.' );
		} catch ( ApplyException $error ) {
			self::assertSame( 'duplicate_table', $error->errorCode() );
		}

		try {
			$this->exporter->export( array(), 10, false );
			self::fail( 'Expected empty selection.' );
		} catch ( ApplyException $error ) {
			self::assertSame( 'empty_selection', $error->errorCode() );
		}
	}

	public function testCatalogMarksSensitiveTables(): void {
		$this->db->counts['wp_users'] = 4;
		$catalog = $this->exporter->catalog();
		$by_name = array();
		foreach ( $catalog as $table ) {
			$by_name[ $table['name'] ] = $table;
		}
		self::assertFalse( $by_name['posts']['sensitive'] );
		self::assertTrue( $by_name['users']['sensitive'] );
		self::assertSame( 4, $by_name['users']['rows'] );
		self::assertArrayNotHasKey( 'options', $by_name );
	}
}
