<?php
/**
 * Atomic, private storage for generated feeds and the catalog index.
 *
 * Feed files are never served by the web server directly: they live in a
 * salted directory (defence in depth) and are streamed through WordPress
 * with token authentication. The .htaccess/index.php files add another
 * layer on Apache.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

defined( 'ABSPATH' ) || exit;

final class Storage {

	private const DIRECTORY_PREFIX = 'wps-pepita-';

	private static ?string $base_dir = null;

	/**
	 * Absolute private storage directory (no trailing slash).
	 */
	public static function dir(): string {
		if ( null !== self::$base_dir ) {
			return self::$base_dir;
		}

		$key = (string) Settings::get( 'storage_key', '' );

		if ( '' === $key ) {
			$key = substr( hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) ), 0, 16 );
			Settings::update( array( 'storage_key' => $key ) );
		}

		$uploads = wp_upload_dir( null, false );

		$base = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] )
			? $uploads['basedir']
			: WP_CONTENT_DIR . '/uploads';

		self::$base_dir = rtrim( $base, '/\\' ) . '/' . self::DIRECTORY_PREFIX . preg_replace( '/[^a-z0-9]/i', '', $key );

		return self::$base_dir;
	}

	public static function reset(): void {
		self::$base_dir = null;
	}

	public static function ensure_directory(): bool {
		$dir = self::dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! is_file( $dir . '/web.config' ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$dir . '/web.config',
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n"
			);
		}

		return is_dir( $dir ) && is_writable( $dir );
	}

	public static function path( string $filename ): string {
		return self::dir() . '/' . ltrim( $filename, '/\\' );
	}

	public static function feed_path( string $type ): string {
		return self::path( 'full' === $type ? 'pepita-full.xml' : 'pepita-stock.xml' );
	}

	public static function previous_feed_path( string $type ): string {
		return self::path( 'full' === $type ? 'pepita-full.previous.xml' : 'pepita-stock.previous.xml' );
	}

	public static function temp_path( string $type, string $token ): string {
		return self::path( sprintf( '%s.tmp-%s.xml', 'full' === $type ? 'pepita-full' : 'pepita-stock', preg_replace( '/[^a-z0-9]/i', '', $token ) ) );
	}

	public static function catalog_index_path(): string {
		return self::path( 'catalog.jsonl' );
	}

	public static function previous_catalog_index_path(): string {
		return self::path( 'catalog.previous.jsonl' );
	}

	public static function catalog_index_temp_path( string $token ): string {
		return self::path( 'catalog.tmp-' . preg_replace( '/[^a-z0-9]/i', '', $token ) . '.jsonl' );
	}

	public static function label_dir(): string {
		$dir = self::dir() . '/labels';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	public static function exists( string $type ): bool {
		$path = self::feed_path( $type );

		return is_file( $path ) && filesize( $path ) > 0;
	}

	public static function size( string $type ): int {
		$path = self::feed_path( $type );

		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}

	public static function modified( string $type ): int {
		$path = self::feed_path( $type );

		return is_file( $path ) ? (int) filemtime( $path ) : 0;
	}

	/**
	 * Atomically replace the published feed, keeping one previous snapshot.
	 */
	public static function publish( string $type, string $temp_path ): bool {
		self::ensure_directory();

		$target = self::feed_path( $type );
		$backup = self::previous_feed_path( $type );

		if ( ! is_file( $temp_path ) ) {
			return false;
		}

		if ( is_file( $target ) ) {
			if ( ! @copy( $target, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				Logger::warning( 'feed_publish', 'A korábbi feed biztonsági mentése nem sikerült.', array( 'type' => $type ) );
			}
		}

		if ( ! @rename( $temp_path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $temp_path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false;
			}

			@unlink( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		clearstatcache( true, $target );

		return is_file( $target ) && filesize( $target ) > 0;
	}

	public static function publish_catalog_index( string $temp_path ): bool {
		$target = self::catalog_index_path();
		$backup = self::previous_catalog_index_path();

		if ( ! is_file( $temp_path ) ) {
			return false;
		}

		if ( is_file( $target ) ) {
			if ( ! @copy( $target, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				Logger::warning( 'feed_publish', 'A korábbi katalógus index biztonsági mentése nem sikerült.' );
			}
		}

		return (bool) @rename( $temp_path, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Atomically publish both the full catalog XML feed and its catalog index together.
	 *
	 * If publishing either artifact fails, any already-moved file is immediately rolled back
	 * to its previous backup so the published XML feed and catalog.jsonl index never diverge.
	 */
	public static function publish_full( string $feed_temp, string $index_temp ): bool {
		self::ensure_directory();

		$target_feed  = self::feed_path( 'full' );
		$backup_feed  = self::previous_feed_path( 'full' );
		$target_index = self::catalog_index_path();
		$backup_index = self::previous_catalog_index_path();

		if ( ! is_file( $feed_temp ) || ! is_file( $index_temp ) ) {
			return false;
		}

		if ( filesize( $feed_temp ) <= 0 ) {
			return false;
		}

		$backed_up_feed  = false;
		$backed_up_index = false;

		if ( is_file( $target_feed ) ) {
			$backed_up_feed = (bool) @copy( $target_feed, $backup_feed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $backed_up_feed ) {
				Logger::warning( 'feed_publish', 'A korábbi full feed biztonsági mentése nem sikerült.' );
			}
		}

		if ( is_file( $target_index ) ) {
			$backed_up_index = (bool) @copy( $target_index, $backup_index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $backed_up_index ) {
				Logger::warning( 'feed_publish', 'A korábbi katalógus index biztonsági mentése nem sikerült.' );
			}
		}

		// Stage 1: Publish catalog index.
		$index_ok = false;
		if ( @rename( $index_temp, $target_index ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$index_ok = true;
		} elseif ( @copy( $index_temp, $target_index ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $index_temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$index_ok = true;
		}

		if ( ! $index_ok ) {
			Logger::warning( 'feed_publish', 'A katalógus index publikálása sikertelen; a feed publikálás megszakítva.' );
			return false;
		}

		// Stage 2: Publish full XML feed.
		$feed_ok = false;
		if ( @rename( $feed_temp, $target_feed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$feed_ok = true;
		} elseif ( @copy( $feed_temp, $target_feed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $feed_temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$feed_ok = true;
		}

		if ( ! $feed_ok ) {
			Logger::warning( 'feed_publish', 'A feed XML publikálása meghiúsult; a katalógus index visszaállítása folyamatban.' );

			// Rollback index to preserve consistency.
			if ( $backed_up_index && is_file( $backup_index ) ) {
				@copy( $backup_index, $target_index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( ! $backed_up_index ) {
				@unlink( $target_index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			return false;
		}

		clearstatcache( true, $target_feed );
		clearstatcache( true, $target_index );

		return is_file( $target_feed ) && filesize( $target_feed ) > 0 && is_file( $target_index );
	}

	/**
	 * Delete generated artefacts (used by uninstall and the "reset" tool).
	 */
	public static function purge(): void {
		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '/*' );

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		$labels = glob( $dir . '/labels/*' );

		if ( is_array( $labels ) ) {
			foreach ( $labels as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		@rmdir( $dir . '/labels' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
