<?php
/**
 * Domain error for export and apply. The code is stable; the message is for an administrator.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class ApplyException extends \RuntimeException {
	public function __construct(
		private readonly string $errorCode,
		string $message
	) {
		parent::__construct( $message );
	}

	public function errorCode(): string {
		return $this->errorCode;
	}
}
