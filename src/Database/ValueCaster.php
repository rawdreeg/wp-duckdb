<?php
/**
 * Converts values between MySQL strings, JSON, and $wpdb formats.
 *
 * @package Rawdreeg\WpDuckdb
 */

declare(strict_types=1);

namespace Rawdreeg\WpDuckdb\Database;

final class ValueCaster {
	public function exportValue( ColumnSchema $column, mixed $value ): mixed {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			$value = (string) $value;
		}

		if ( $column->binary ) {
			// Encodes raw column bytes for JSON. This is not obfuscated PHP.
			return base64_encode( (string) $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		if ( $column->isInteger() ) {
			return $this->exportInteger( (string) $value );
		}

		if ( $column->isFloat() ) {
			return $this->exportFloat( (string) $value );
		}

		return (string) $value;
	}

	public function importValue( ColumnSchema $column, mixed $value ): mixed {
		if ( $column->binary ) {
			throw new ApplyException( 'binary_column', 'Binary columns cannot be written.' );
		}

		if ( null === $value ) {
			return null;
		}

		if ( is_bool( $value ) ) {
			$value = $value ? 1 : 0;
		}

		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			throw new ApplyException( 'invalid_value', 'Values must be null, boolean, number, or string.' );
		}

		if ( is_string( $value ) && strlen( $value ) > Limits::MAX_STRING_BYTES ) {
			throw new ApplyException( 'value_too_long', 'A value exceeds the size limit.' );
		}

		if ( $column->isInteger() ) {
			return $this->importInteger( $value );
		}

		if ( $column->isFloat() ) {
			return $this->importFloat( $value );
		}

		if ( is_float( $value ) && ! is_finite( $value ) ) {
			throw new ApplyException( 'invalid_value', 'Values must be finite numbers.' );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		return $value;
	}

	public function formatFor( ColumnSchema $column, mixed $value ): string {
		if ( is_int( $value ) && $column->isInteger() ) {
			return '%d';
		}

		if ( ( is_int( $value ) || is_float( $value ) ) && $column->isFloat() ) {
			return '%f';
		}

		return '%s';
	}

	private function exportInteger( string $raw ): int|string {
		if ( 1 !== preg_match( '/^-?\d+$/', $raw ) ) {
			return $raw;
		}

		$as_int = filter_var( $raw, FILTER_VALIDATE_INT );
		if ( false === $as_int || abs( $as_int ) > Limits::JS_MAX_SAFE_INTEGER ) {
			return $this->canonicalIntegerString( $raw );
		}

		return $as_int;
	}

	private function exportFloat( string $raw ): float|string {
		if ( ! is_numeric( $raw ) ) {
			return $raw;
		}

		$number = (float) $raw;
		if ( ! is_finite( $number ) ) {
			return $raw;
		}

		return $number;
	}

	private function importInteger( mixed $value ): int|string {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || floor( $value ) !== $value || abs( $value ) > Limits::JS_MAX_SAFE_INTEGER ) {
				throw new ApplyException( 'invalid_value', 'Integer columns require an integer.' );
			}

			return (int) $value;
		}

		if ( 1 !== preg_match( '/^-?\d+$/', $value ) ) {
			throw new ApplyException( 'invalid_value', 'Integer columns require an integer.' );
		}

		$as_int = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $as_int ) {
			return $this->canonicalIntegerString( $value );
		}

		return $as_int;
	}

	private function importFloat( mixed $value ): float {
		if ( is_int( $value ) ) {
			return (float) $value;
		}

		if ( is_string( $value ) ) {
			if ( ! is_numeric( $value ) ) {
				throw new ApplyException( 'invalid_value', 'Numeric columns require a number.' );
			}
			$value = (float) $value;
		}

		if ( ! is_float( $value ) || ! is_finite( $value ) ) {
			throw new ApplyException( 'invalid_value', 'Numeric columns require a finite number.' );
		}

		return $value;
	}

	private function canonicalIntegerString( string $raw ): string {
		$negative = str_starts_with( $raw, '-' );
		$digits   = ltrim( $raw, '-' );
		$digits   = ltrim( $digits, '0' );
		if ( '' === $digits ) {
			$digits = '0';
		}

		if ( $negative && '0' !== $digits ) {
			return '-' . $digits;
		}

		return $digits;
	}
}
