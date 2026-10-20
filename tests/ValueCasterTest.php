<?php
/**
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Tests;

use PHPUnit\Framework\TestCase;
use Rawdreeg\WpDuckdb\Database\ApplyException;
use Rawdreeg\WpDuckdb\Database\ColumnSchema;
use Rawdreeg\WpDuckdb\Database\ValueCaster;

final class ValueCasterTest extends TestCase {
	private ValueCaster $caster;

	protected function setUp(): void {
		$this->caster = new ValueCaster();
	}

	public function testExportPreservesDatesDecimalsAndLargeIntegers(): void {
		self::assertSame( 42, $this->caster->exportValue( $this->column( 'ID', 'bigint' ), '42' ) );
		self::assertSame( '9007199254740993', $this->caster->exportValue( $this->column( 'ID', 'bigint' ), '9007199254740993' ) );
		self::assertSame( '0000-00-00 00:00:00', $this->caster->exportValue( $this->column( 'post_date', 'datetime' ), '0000-00-00 00:00:00' ) );
		self::assertSame( '10.50', $this->caster->exportValue( $this->column( 'price', 'decimal' ), '10.50' ) );
		self::assertSame( 1.5, $this->caster->exportValue( $this->column( 'rating', 'float' ), '1.5' ) );
		self::assertNull( $this->caster->exportValue( $this->column( 'post_title', 'text' ), null ) );
		self::assertSame( base64_encode( 'hi' ), $this->caster->exportValue( $this->column( 'blob', 'blob', true ), 'hi' ) );
	}

	public function testImportIntegerFormats(): void {
		$column = $this->column( 'ID', 'bigint' );
		self::assertSame( 5, $this->caster->importValue( $column, 5 ) );
		self::assertSame( '%d', $this->caster->formatFor( $column, 5 ) );
		self::assertSame( 5, $this->caster->importValue( $column, '5' ) );
		self::assertSame( 1, $this->caster->importValue( $column, true ) );
		self::assertSame( 1, $this->caster->importValue( $column, 1.0 ) );

		$big = '9223372036854775808';
		self::assertSame( $big, $this->caster->importValue( $column, $big ) );
		self::assertSame( '%s', $this->caster->formatFor( $column, $big ) );
	}

	public function testImportRejectsNonIntegersArraysAndHugeStrings(): void {
		$column = $this->column( 'ID', 'bigint' );
		$this->expectException( ApplyException::class );
		$this->caster->importValue( $column, 1.5 );
	}

	public function testImportRejectsArrays(): void {
		$this->expectException( ApplyException::class );
		$this->caster->importValue( $this->column( 'post_title', 'text' ), array( 'nope' ) );
	}

	public function testImportRejectsOversizedStrings(): void {
		$this->expectException( ApplyException::class );
		$this->caster->importValue( $this->column( 'post_content', 'longtext' ), str_repeat( 'a', 1000001 ) );
	}

	public function testStringColumnCastsNumbers(): void {
		$column = $this->column( 'post_title', 'text' );
		self::assertSame( '15', $this->caster->importValue( $column, 15 ) );
		self::assertSame( '%s', $this->caster->formatFor( $column, '15' ) );
	}

	public function testFloatFormat(): void {
		$column = $this->column( 'rating', 'double' );
		self::assertSame( 1.25, $this->caster->importValue( $column, '1.25' ) );
		self::assertSame( '%f', $this->caster->formatFor( $column, 1.25 ) );
	}

	public function testBinaryImportIsRejected(): void {
		$this->expectException( ApplyException::class );
		$this->caster->importValue( $this->column( 'blob', 'blob', true ), 'aGk=' );
	}

	private function column( string $name, string $type, bool $binary = false ): ColumnSchema {
		return new ColumnSchema( $name, $type, true, false, 0, $binary );
	}
}
