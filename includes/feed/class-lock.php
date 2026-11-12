<?php
/**
 * Option based generation lock with expiry and safe recovery.
 *
 * add_option() is atomic (INSERT with a unique key), so exactly one process
 * can create the lock. Expired locks are reclaimed once.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Feed;

defined( 'ABSPATH' ) || exit;

final class Lock {

	private const PREFIX = 'wps_pepita_lock_';

	public static function acquire( string $name, int $ttl = 1800 ): ?string {
		$key   = self::key( $name );
		$token = wp_generate_password( 32, false, false );

		$value = array(
			'token'   => $token,
			'expires' => time() + $ttl,
			'pid'     => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
		);

		if ( add_option( $key, $value, '', false ) ) {
			return $token;
		}

		$existing = get_option( $key, array() );

		if ( ! is_array( $existing ) || (int) ( $existing['expires'] ?? 0 ) > time() ) {
			return null;
		}

		// Expired: remove and retry once. The retry is still atomic.
		delete_option( $key );

		if ( add_option( $key, $value, '', false ) ) {
			return $token;
		}

		return null;
	}

	public static function refresh( string $name, string $token, int $ttl = 1800 ): bool {
		$key      = self::key( $name );
		$existing = get_option( $key, array() );

		if ( ! is_array( $existing ) || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
			return false;
		}

		$existing['expires'] = time() + $ttl;

		return update_option( $key, $existing, false );
	}

	public static function is_owner( string $name, string $token ): bool {
		$existing = get_option( self::key( $name ), array() );

		return is_array( $existing )
			&& hash_equals( (string) ( $existing['token'] ?? '' ), $token )
			&& (int) ( $existing['expires'] ?? 0 ) > time();
	}

	public static function release( string $name, string $token ): void {
		$key      = self::key( $name );
		$existing = get_option( $key, array() );

		if ( is_array( $existing ) && hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
			delete_option( $key );
		}
	}

	public static function is_locked( string $name ): bool {
		$existing = get_option( self::key( $name ), array() );

		if ( ! is_array( $existing ) || array() === $existing ) {
			return false;
		}

		if ( (int) ( $existing['expires'] ?? 0 ) <= time() ) {
			delete_option( self::key( $name ) );

			return false;
		}

		return true;
	}

	public static function release_all(): void {
		foreach ( array( 'full', 'stock' ) as $name ) {
			delete_option( self::key( $name ) );
		}
	}

	private static function key( string $name ): string {
		return self::PREFIX . sanitize_key( $name );
	}
}
