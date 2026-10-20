<?php
/**
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Tests;

use PHPUnit\Framework\TestCase;
use Rawdreeg\WpDuckdb\Database\Allowlist;

final class AllowlistTest extends TestCase {
	private Allowlist $allowlist;

	protected function setUp(): void {
		$this->allowlist = new Allowlist();
	}

	public function testContentTablesAreAllowed(): void {
		foreach ( array( 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links' ) as $name ) {
			self::assertTrue( $this->allowlist->isAllowed( $name ) );
			self::assertTrue( $this->allowlist->isContent( $name ) );
			self::assertFalse( $this->allowlist->isSensitive( $name ) );
		}
	}

	public function testUsersAreSensitive(): void {
		self::assertTrue( $this->allowlist->isAllowed( 'users' ) );
		self::assertTrue( $this->allowlist->isSensitive( 'users' ) );
		self::assertTrue( $this->allowlist->isSensitive( 'usermeta' ) );
		self::assertFalse( $this->allowlist->isContent( 'users' ) );
	}

	public function testOptionsAndInjectedNamesAreRejected(): void {
		self::assertFalse( $this->allowlist->isAllowed( 'options' ) );
		self::assertFalse( $this->allowlist->isAllowed( 'posts; DROP TABLE wp_posts' ) );
		self::assertFalse( $this->allowlist->isAllowed( '../users' ) );
		self::assertFalse( $this->allowlist->isAllowed( 'Posts' ) );
		self::assertFalse( $this->allowlist->isAllowed( '' ) );
	}
}
