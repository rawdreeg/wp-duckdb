<?php
/**
 * Caps for export size and a single apply request.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class Limits {
	public const EXPORT_DEFAULT = 1000;

	public const EXPORT_MAX = 10000;

	public const MAX_MUTATIONS = 1000;

	public const MAX_STRING_BYTES = 1000000;

	public const JS_MAX_SAFE_INTEGER = 9007199254740991;
}
