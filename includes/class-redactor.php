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

				if ( 'email' === $normalised && is_scalar( $item ) ) {
					$clean[ $key ] = self::mask_email( (string) $item );
					continue;
				}

				if ( in_array( $normalised, array( 'phone', 'telephone', 'billing_phone' ), true ) && is_scalar( $item ) ) {
					$clean[ $key ] = self::mask_phone( (string) $item );
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
