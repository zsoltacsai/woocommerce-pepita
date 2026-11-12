<?php
/**
 * Minimal assertion helpers (no PHPUnit available in this environment).
 *
 * @package WPS\Pepita\Tests
 */

defined( 'ABSPATH' ) || exit;

final class WPS_Assertion_Failed extends \Exception {
}

final class WPS_Assert {

	/**
	 * @param mixed $value
	 */
	public static function true( $value, string $message = '' ): void {
		if ( true !== $value ) {
			self::fail( '' !== $message ? $message : 'Expected true, got ' . self::describe( $value ) );
		}
	}

	/**
	 * @param mixed $value
	 */
	public static function false( $value, string $message = '' ): void {
		if ( false !== $value ) {
			self::fail( '' !== $message ? $message : 'Expected false, got ' . self::describe( $value ) );
		}
	}

	/**
	 * @param mixed $value
	 */
	public static function truthy( $value, string $message = '' ): void {
		if ( ! $value ) {
			self::fail( '' !== $message ? $message : 'Expected a truthy value, got ' . self::describe( $value ) );
		}
	}

	/**
	 * @param mixed $value
	 */
	public static function falsy( $value, string $message = '' ): void {
		if ( $value ) {
			self::fail( '' !== $message ? $message : 'Expected a falsy value, got ' . self::describe( $value ) );
		}
	}

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	public static function same( $expected, $actual, string $message = '' ): void {
		if ( $expected !== $actual ) {
			self::fail(
				'' !== $message
					? $message . ' (expected ' . self::describe( $expected ) . ', got ' . self::describe( $actual ) . ')'
					: 'Expected ' . self::describe( $expected ) . ', got ' . self::describe( $actual )
			);
		}
	}

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	public static function equals( $expected, $actual, string $message = '', float $delta = 0.000001 ): void {
		if ( is_numeric( $expected ) && is_numeric( $actual ) ) {
			if ( abs( (float) $expected - (float) $actual ) > $delta ) {
				self::fail(
					'' !== $message
						? $message . ' (expected ' . self::describe( $expected ) . ', got ' . self::describe( $actual ) . ')'
						: 'Expected ' . self::describe( $expected ) . ', got ' . self::describe( $actual )
				);
			}

			return;
		}

		if ( $expected != $actual ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			self::fail( '' !== $message ? $message : 'Expected ' . self::describe( $expected ) . ', got ' . self::describe( $actual ) );
		}
	}

	public static function contains( string $needle, string $haystack, string $message = '' ): void {
		if ( false === strpos( $haystack, $needle ) ) {
			self::fail( '' !== $message ? $message : 'Expected to find "' . $needle . '" in "' . self::excerpt( $haystack ) . '"' );
		}
	}

	public static function not_contains( string $needle, string $haystack, string $message = '' ): void {
		if ( false !== strpos( $haystack, $needle ) ) {
			self::fail( '' !== $message ? $message : 'Did not expect to find "' . $needle . '" in "' . self::excerpt( $haystack ) . '"' );
		}
	}

	public static function matches( string $pattern, string $actual, string $message = '' ): void {
		if ( 1 !== preg_match( $pattern, $actual ) ) {
			self::fail( '' !== $message ? $message : 'Value "' . self::excerpt( $actual ) . '" does not match ' . $pattern );
		}
	}

	/**
	 * @param mixed $actual
	 */
	public static function count( int $expected, $actual, string $message = '' ): void {
		if ( ! is_countable( $actual ) ) {
			self::fail( '' !== $message ? $message : 'Value is not countable: ' . self::describe( $actual ) );
		}

		if ( count( $actual ) !== $expected ) {
			self::fail( '' !== $message ? $message : 'Expected count ' . $expected . ', got ' . count( $actual ) );
		}
	}

	/**
	 * @param mixed $actual
	 */
	public static function instance_of( string $class, $actual, string $message = '' ): void {
		if ( ! $actual instanceof $class ) {
			self::fail( '' !== $message ? $message : 'Expected instance of ' . $class . ', got ' . self::describe( $actual ) );
		}
	}

	/**
	 * @param mixed $value
	 */
	public static function not_empty( $value, string $message = '' ): void {
		if ( empty( $value ) ) {
			self::fail( '' !== $message ? $message : 'Expected a non-empty value' );
		}
	}

	/**
	 * @param mixed $value
	 */
	public static function is_null( $value, string $message = '' ): void {
		if ( null !== $value ) {
			self::fail( '' !== $message ? $message : 'Expected null, got ' . self::describe( $value ) );
		}
	}

	public static function fail( string $message ): void {
		throw new WPS_Assertion_Failed( $message );
	}

	/**
	 * @param mixed $value
	 */
	private static function describe( $value ): string {
		if ( is_string( $value ) ) {
			return '"' . self::excerpt( $value ) . '"';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		if ( is_array( $value ) ) {
			return 'array(' . count( $value ) . ') ' . self::excerpt( json_encode( $value ) ?: '' );
		}

		if ( is_object( $value ) ) {
			return get_class( $value );
		}

		return (string) $value;
	}

	private static function excerpt( string $value ): string {
		$value = preg_replace( '/\s+/', ' ', $value ) ?? $value;

		return strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '…' : $value;
	}
}
