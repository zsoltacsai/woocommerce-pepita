<?php
/**
 * Strict monetary, VAT and quantity parsing for Pepita order payloads.
 *
 * Pepita sends JSON numbers, but a malformed or locale-formatted value must
 * never be silently coerced. PHP's (float) cast stops at the first non-numeric
 * character, so "4,990" becomes 4.0 and "1 490" becomes 1.0. This parser
 * accepts only unambiguous values and reports everything else as invalid.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Orders;

defined( 'ABSPATH' ) || exit;

final class Money {

	/**
	 * Upper bound for a single amount (unit price, shipping, voucher, total).
	 * Anything above this is treated as malformed rather than as a valid order.
	 */
	public const MAX_AMOUNT = 1000000000.0;

	/**
	 * Upper bound for a line quantity.
	 */
	public const MAX_QUANTITY = 100000;

	/**
	 * Parse a non-negative money amount.
	 *
	 * Accepted: JSON integers/floats, and strings matching
	 * `^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$`.
	 *
	 * Rejected: thousands separators, spaces, comma decimals, exponent
	 * notation, signs, leading dots, trailing dots, empty strings, NaN/INF,
	 * negative values, more than two decimal places and values above
	 * self::MAX_AMOUNT.
	 *
	 * @param mixed $value Raw payload value.
	 * @param bool  $allow_zero Whether 0 is a valid amount.
	 * @return float|null Null when the value is not a valid amount.
	 */
	public static function parse( $value, bool $allow_zero = false ): ?float {
		if ( is_int( $value ) ) {
			$amount = (float) $value;
		} elseif ( is_float( $value ) ) {
			$amount = $value;
		} elseif ( is_string( $value ) ) {
			$raw = trim( $value );

			if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', $raw ) ) {
				return null;
			}

			$amount = (float) $raw;
		} else {
			return null;
		}

		if ( ! is_finite( $amount ) || $amount < 0.0 ) {
			return null;
		}

		if ( abs( $amount - round( $amount, 2 ) ) > 1e-9 ) {
			return null;
		}

		if ( $amount > self::MAX_AMOUNT ) {
			return null;
		}

		if ( ! $allow_zero && $amount <= 0.0 ) {
			return null;
		}

		return $amount;
	}

	/**
	 * Parse an optional amount: a missing or null field means "not supplied"
	 * and resolves to 0.0. An explicitly empty or malformed value is rejected.
	 *
	 * @param mixed $value Raw payload value.
	 * @return float|null
	 */
	public static function parse_optional( $value ): ?float {
		if ( null === $value ) {
			return 0.0;
		}

		return self::parse( $value, true );
	}

	/**
	 * Parse a VAT percentage (0–100, at most two decimals).
	 *
	 * @param mixed $value Raw payload value.
	 * @return float|null
	 */
	public static function parse_vat( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return 0.0;
		}

		$vat = self::parse( $value, true );

		if ( null === $vat || $vat > 100.0 ) {
			return null;
		}

		return $vat;
	}

	/**
	 * Parse a line quantity. Only positive whole numbers are accepted.
	 *
	 * @param mixed $value Raw payload value.
	 * @return int|null
	 */
	public static function parse_quantity( $value ): ?int {
		if ( is_int( $value ) ) {
			$quantity = $value;
		} elseif ( is_string( $value ) && preg_match( '/^[1-9][0-9]{0,5}$/', trim( $value ) ) ) {
			$quantity = (int) trim( $value );
		} else {
			return null;
		}

		if ( $quantity < 1 || $quantity > self::MAX_QUANTITY ) {
			return null;
		}

		return $quantity;
	}
}
