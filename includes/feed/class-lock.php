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

		// The lock is expired. Reclaiming is a read-check-delete-add sequence,
		// so it runs under a second, atomic option mutex: otherwise two
		// reclaimers could interleave and one could delete the fresh lock the
		// other just wrote, leaving two owners.
		$mutex = $key . '_reclaim';

		if ( ! self::acquire_mutex( $mutex ) ) {
			return null;
		}

		try {
			// Re-read under the mutex: another reclaimer may have won already.
			$existing = get_option( $key, array() );

			if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) > time() ) {
				return null;
			}

			delete_option( $key );

			if ( add_option( $key, $value, '', false ) ) {
				return $token;
			}

			return null;
		} finally {
			delete_option( $mutex );
		}
	}

	/**
	 * Acquire the short-lived reclaim mutex. A mutex abandoned by a crashed
	 * process expires after 30 seconds and can be reclaimed once.
	 */
	private static function acquire_mutex( string $key ): bool {
		$now = time();

		if ( add_option( $key, $now, '', false ) ) {
			return true;
		}

		$held = (int) get_option( $key, 0 );

		if ( $held > 0 && $held > $now - 30 ) {
			return false;
		}

		delete_option( $key );

		return (bool) add_option( $key, $now, '', false );
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

		// Read-only: an expired lock is reported as free but is never deleted
		// here. Deleting it would race with a process that has just reclaimed
		// it and could destroy a valid, fresh lock.
		return (int) ( $existing['expires'] ?? 0 ) > time();
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
