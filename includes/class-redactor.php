<?php
/**
 * Removes secrets and unnecessary personal data from log payloads.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	private const SENSITIVE_KEYS = array(
		'apikey',
		'api_key',
		'api-key',
		'token',
		'feed_token',
		'secret',
		'password',
		'passwd',
		'authorization',
		'auth',
		'cookie',
		'set-cookie',
		'package_label',
		'private_key',
		'consumer_key',
		'consumer_secret',
	);

	/**
	 * Personal data that must not reach the log table in clear text. Keys are
	 * matched case-insensitively after normalisation; the nested `customer`
	 * object is handled by recursion.
	 */
	private const PII_KEYS = array(
		'first_name',
		'last_name',
		'full_name',
		'billing_name',
		'shipping_name',
		'billing_first_name',
		'billing_last_name',
		'shipping_first_name',
		'shipping_last_name',
		'company',
		'company_name',
		'tax_number',
		'vat_number',
		'address',
		'address_1',
		'address_2',
		'billing_address',
		'billing_address_1',
		'billing_address_2',
		'shipping_address',
		'shipping_address_1',
		'shipping_address_2',
		'street',
		'billing_street',
		'shipping_street',
		'street_address',
		'billing_street_address',
		'shipping_street_address',
		'house_number',
		'billing_house_number',
		'shipping_house_number',
		'city',
		'billing_city',
		'shipping_city',
		'postcode',
		'postal_code',
		'billing_postcode',
		'billing_postal_code',
		'shipping_postcode',
		'shipping_postal_code',
		'zip',
		'customer_message',
		'courier_message',
		'note',
		'comments',
		'remote_addr',
		'ip',
		'ip_address',
	);

	/**
	 * Recursively mask sensitive keys and truncate huge values.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function redact( $value, int $depth = 0 ) {
		if ( $depth > 8 ) {
			return '[…]';
		}

		if ( is_array( $value ) ) {
			$clean = array();

			foreach ( $value as $key => $item ) {
				$normalised = strtolower( (string) $key );

				if ( in_array( $normalised, self::SENSITIVE_KEYS, true ) ) {
					$clean[ $key ] = self::mask_secret( $item );
					continue;
				}

				if ( 'email' === $normalised || str_ends_with( $normalised, '_email' ) ) {
					$clean[ $key ] = is_scalar( $item ) ? self::mask_email( (string) $item ) : '[redacted]';
					continue;
				}

				if ( 'phone' === $normalised || str_ends_with( $normalised, '_phone' ) ) {
					$clean[ $key ] = is_scalar( $item ) ? self::mask_phone( (string) $item ) : '[redacted]';
					continue;
				}

				if ( in_array( $normalised, self::PII_KEYS, true ) ) {
					$clean[ $key ] = is_scalar( $item ) && '' !== (string) $item ? '[redacted]' : '';
					continue;
				}

				$clean[ $key ] = self::redact( $item, $depth + 1 );
			}

			return $clean;
		}

		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 2000 ) {
				return substr( $value, 0, 2000 ) . '…[truncated ' . strlen( $value ) . ' bytes]';
			}

			return $value;
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 */
	private static function mask_secret( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '[redacted]';
		}

		$string = (string) $value;

		if ( '' === $string ) {
			return '';
		}

		return '[redacted:' . strlen( $string ) . ' chars]';
	}

	public static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );

		if ( false === $at || $at < 1 ) {
			return '[redacted]';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );

		return substr( $local, 0, 1 ) . '***' . $domain;
	}

	public static function mask_phone( string $phone ): string {
		$digits = preg_replace( '/\D/', '', $phone ) ?? '';

		if ( strlen( $digits ) < 4 ) {
			return '[redacted]';
		}

		return str_repeat( '*', max( 0, strlen( $digits ) - 4 ) ) . substr( $digits, -4 );
	}
}
