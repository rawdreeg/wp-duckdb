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
use Rawdreeg\WpDuckdb\Database\DiffApplier;
use Rawdreeg\WpDuckdb\Database\TableMap;
use Rawdreeg\WpDuckdb\Database\ValueCaster;
use Rawdreeg\WpDuckdb\Tests\Support\FakeConnection;

final class DiffApplierTest extends TestCase {
	private FakeConnection $db;

	private DiffApplier $applier;

	protected function setUp(): void {
		$this->db = new FakeConnection();
		$this->db->schemas['wp_posts'] = array(
			new ColumnSchema( 'ID', 'bigint', false, true, 1, false ),
			new ColumnSchema( 'post_title', 'text', false, false, 0, false ),
			new ColumnSchema( 'blob_col', 'blob', true, false, 0, true ),
		);
		$this->db->schemas['wp_term_relationships'] = array(
			new ColumnSchema( 'object_id', 'bigint', false, true, 1, false ),
			new ColumnSchema( 'term_taxonomy_id', 'bigint', false, true, 2, false ),
			new ColumnSchema( 'term_order', 'int', false, false, 0, false ),
		);
		$this->db->schemas['wp_notes'] = array(
			new ColumnSchema( 'note', 'text', true, false, 0, false ),
		);

		$allowlist = new Allowlist();
		$map       = array();
		foreach ( $allowlist->all() as $name ) {
			$map[ $name ] = 'wp_' . $name;
		}
		$tables        = new TableMap( $map );
		$this->applier = new DiffApplier( $allowlist, $tables, $this->db, new ValueCaster() );
	}

	public function testAppliesDeletesThenUpdatesThenInserts(): void {
		$result = $this->applier->apply(
			array(
				array(
					'name'    => 'posts',
					'deletes' => array( array( 'ID' => 2 ) ),
					'updates' => array(
						array(
							'primary_key' => array( 'ID' => 1 ),
							'changes'     => array( 'post_title' => "O'Reilly'); DROP TABLE wp_posts; --" ),
						),
					),
					'inserts' => array(
						array(
							'ID'         => '9',
							'post_title' => 'New',
						),
					),
				),
			),
			false
		);

		self::assertSame(
			array( 'posts' => array( 'inserted' => 1, 'updated' => 1, 'deleted' => 1 ) ),
			$result['tables']
		);
		self::assertSame(
			array( 'columns', 'begin', 'delete', 'update', 'insert', 'commit' ),
			array_column( $this->db->calls, 'op' )
		);

		$update = $this->call( 'update' );
		self::assertSame( 'wp_posts', $update[0] );
		self::assertSame( array( 'post_title' => "O'Reilly'); DROP TABLE wp_posts; --" ), $update[1] );
		self::assertSame( array( 'ID' => 1 ), $update[2] );
		self::assertSame( array( '%s' ), $update[3] );
		self::assertSame( array( '%d' ), $update[4] );

		$insert = $this->call( 'insert' );
		self::assertSame( array( 'ID' => 9, 'post_title' => 'New' ), $insert[1] );
		self::assertSame( array( '%d', '%s' ), $insert[2] );
	}

	public function testUsersRequireConfirmation(): void {
		$this->expectException( ApplyException::class );
		try {
			$this->applier->apply(
				array(
					array(
						'name'    => 'users',
						'updates' => array(
							array(
								'primary_key' => array( 'ID' => 1 ),
								'changes'     => array( 'user_login' => 'ada' ),
							),
						),
					),
				),
				false
			);
		} catch ( ApplyException $error ) {
			self::assertSame( 'users_confirmation_required', $error->errorCode() );
			self::assertSame( array(), $this->db->calls );
			throw $error;
		}
	}

	public function testRejectsUnknownTableColumnAndPrimaryKeyChanges(): void {
		$this->assertCode(
			'unknown_table',
			array(
				array(
					'name'    => 'options',
					'deletes' => array( array( 'option_id' => 1 ) ),
				),
			)
		);
		$this->assertCode(
			'unknown_column',
			array(
				array(
					'name'    => 'posts',
					'updates' => array(
						array(
							'primary_key' => array( 'ID' => 1 ),
							'changes'     => array( 'post_title;drop' => 'x' ),
						),
					),
				),
			)
		);
		$this->assertCode(
			'primary_key_change',
			array(
				array(
					'name'    => 'posts',
					'updates' => array(
						array(
							'primary_key' => array( 'ID' => 1 ),
							'changes'     => array( 'ID' => 5 ),
						),
					),
				),
			)
		);
		$this->assertCode(
			'missing_primary_key',
			array(
				array(
					'name'    => 'posts',
					'inserts' => array( array( 'post_title' => 'No key' ) ),
				),
			)
		);
		$this->assertCode(
			'invalid_primary_key',
			array(
				array(
					'name'    => 'posts',
					'deletes' => array( array( 'ID' => 1, 'post_title' => 'x' ) ),
				),
			)
		);
		$this->assertCode(
			'binary_column',
			array(
				array(
					'name'    => 'posts',
					'updates' => array(
						array(
							'primary_key' => array( 'ID' => 1 ),
							'changes'     => array( 'blob_col' => 'abc' ),
						),
					),
				),
			)
		);
		$this->assertCode(
			'invalid_value',
			array(
				array(
					'name'    => 'posts',
					'updates' => array(
						array(
							'primary_key' => array( 'ID' => 1 ),
							'changes'     => array( 'post_title' => array( 'nested' ) ),
						),
					),
				),
			)
		);
	}

	public function testRollbackWhenALaterWriteFails(): void {
		$this->db->failOp = 'insert';
		$this->db->failOn = new ApplyException( 'write_failed', 'Duplicate entry' );

		try {
			$this->applier->apply(
				array(
					array(
						'name'    => 'posts',
						'deletes' => array( array( 'ID' => 2 ) ),
						'inserts' => array( array( 'ID' => 3, 'post_title' => 'x' ) ),
					),
				),
				false
			);
			self::fail( 'Expected the insert to fail.' );
		} catch ( ApplyException $error ) {
			self::assertSame( 'write_failed', $error->errorCode() );
		}

		self::assertSame(
			array( 'columns', 'begin', 'delete', 'insert', 'rollback' ),
			array_column( $this->db->calls, 'op' )
		);
		self::assertNotContains( 'commit', array_column( $this->db->calls, 'op' ) );
	}

	public function testMissingUpdateRowRollsBack(): void {
		$this->db->updateResult = 0;
		try {
			$this->applier->apply(
				array(
					array(
						'name'    => 'posts',
						'deletes' => array( array( 'ID' => 2 ) ),
						'updates' => array(
							array(
								'primary_key' => array( 'ID' => 99 ),
								'changes'     => array( 'post_title' => 'missing' ),
							),
						),
					),
				),
				false
			);
			self::fail( 'Expected a missing row.' );
		} catch ( ApplyException $error ) {
			self::assertSame( 'row_not_found', $error->errorCode() );
		}

		self::assertContains( 'rollback', array_column( $this->db->calls, 'op' ) );
		self::assertNotContains( 'commit', array_column( $this->db->calls, 'op' ) );
	}

	public function testCompositePrimaryKey(): void {
		$this->applier->apply(
			array(
				array(
					'name'    => 'term_relationships',
					'deletes' => array(
						array(
							'object_id'        => 4,
							'term_taxonomy_id' => 8,
						),
					),
				),
			),
			false
		);

		$delete = $this->call( 'delete' );
		self::assertSame( 'wp_term_relationships', $delete[0] );
		self::assertSame(
			array(
				'object_id'        => 4,
				'term_taxonomy_id' => 8,
			),
			$delete[1]
		);
	}

	public function testRejectsTooManyChangesAndEmptyDiffs(): void {
		$inserts = array();
		for ( $i = 1; $i <= 1001; $i++ ) {
			$inserts[] = array(
				'ID'         => $i,
				'post_title' => 'n',
			);
		}
		$this->assertCode(
			'too_many_changes',
			array(
				array(
					'name'    => 'posts',
					'inserts' => $inserts,
				),
			)
		);
		$this->assertCode( 'empty_diff', array() );
		$this->assertCode(
			'empty_diff',
			array(
				array(
					'name'    => 'posts',
					'inserts' => array(),
					'updates' => array(),
					'deletes' => array(),
				),
			)
		);
	}

	public function testTableWithoutPrimaryKeyCannotBeWritten(): void {
		$this->db->schemas['wp_posts'] = $this->db->schemas['wp_notes'];
		$this->assertCode(
			'no_primary_key',
			array(
				array(
					'name'    => 'posts',
					'inserts' => array( array( 'note' => 'x' ) ),
				),
			)
		);
	}

	public function testLargeIntegerStaysAStringParameter(): void {
		$big = '9223372036854775808';
		$this->applier->apply(
			array(
				array(
					'name'    => 'posts',
					'inserts' => array(
						array(
							'ID'         => $big,
							'post_title' => 'big',
						),
					),
				),
			),
			false
		);
		$insert = $this->call( 'insert' );
		self::assertSame( $big, $insert[1]['ID'] );
		self::assertSame( '%s', $insert[2][0] );
	}

	/**
	 * @param list<mixed> $tables
	 */
	private function assertCode( string $code, array $tables ): void {
		try {
			$this->applier->apply( $tables, false );
			self::fail( 'Expected ' . $code );
		} catch ( ApplyException $error ) {
			self::assertSame( $code, $error->errorCode() );
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	private function call( string $op ): array {
		foreach ( $this->db->calls as $call ) {
			if ( $call['op'] === $op ) {
				return $call['args'];
			}
		}
		self::fail( 'Missing call ' . $op );
	}
}
