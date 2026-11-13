<?php
/**
 * Atomic, private storage for generated feeds and the catalog index.
 *
 * Implements crash-consistent publication using immutable versioned generation
 * artifacts and an atomic active-generation pointer (active-generation.json).
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
	private const POINTER_FILENAME = 'active-generation.json';

	private static ?string $base_dir = null;

	/**
	 * @var array{version: int, active: array<string, string>, previous: array<string, string>, updated_at: int}|null
	 */
	private static ?array $pointer_cache = null;

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
		self::$base_dir      = null;
		self::$pointer_cache = null;
	}

	public static function pointer_path(): string {
		return self::path( self::POINTER_FILENAME );
	}

	/**
	 * @return array{version: int, active: array<string, string>, previous: array<string, string>, updated_at: int}|null
	 */
	public static function read_pointer(): ?array {
		if ( null !== self::$pointer_cache ) {
			return self::$pointer_cache;
		}

		$path = self::pointer_path();
		if ( ! is_file( $path ) ) {
			return null;
		}

		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['active'] ) ) {
			return null;
		}

		self::$pointer_cache = $data;
		return self::$pointer_cache;
	}

	public static function clear_pointer_cache(): void {
		self::$pointer_cache = null;
		clearstatcache( true, self::pointer_path() );
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
		$pointer = self::read_pointer();
		if ( null !== $pointer ) {
			if ( 'full' === $type && ! empty( $pointer['active']['full_feed'] ) ) {
				$candidate = self::path( $pointer['active']['full_feed'] );
				if ( is_file( $candidate ) ) {
					return $candidate;
				}
			}
			if ( 'stock' === $type && ! empty( $pointer['active']['stock_feed'] ) ) {
				$candidate = self::path( $pointer['active']['stock_feed'] );
				if ( is_file( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return self::path( 'full' === $type ? 'pepita-full.xml' : 'pepita-stock.xml' );
	}

	public static function previous_feed_path( string $type ): string {
		$pointer = self::read_pointer();
		if ( null !== $pointer && ! empty( $pointer['previous'] ) ) {
			if ( 'full' === $type && ! empty( $pointer['previous']['full_feed'] ) ) {
				$candidate = self::path( $pointer['previous']['full_feed'] );
				if ( is_file( $candidate ) ) {
					return $candidate;
				}
			}
			if ( 'stock' === $type && ! empty( $pointer['previous']['stock_feed'] ) ) {
				$candidate = self::path( $pointer['previous']['stock_feed'] );
				if ( is_file( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return self::path( 'full' === $type ? 'pepita-full.previous.xml' : 'pepita-stock.previous.xml' );
	}

	public static function temp_path( string $type, string $token ): string {
		return self::path( sprintf( '%s.tmp-%s.xml', 'full' === $type ? 'pepita-full' : 'pepita-stock', preg_replace( '/[^a-z0-9]/i', '', $token ) ) );
	}

	public static function catalog_index_path(): string {
		$pointer = self::read_pointer();
		if ( null !== $pointer && ! empty( $pointer['active']['catalog_index'] ) ) {
			$candidate = self::path( $pointer['active']['catalog_index'] );
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return self::path( 'catalog.jsonl' );
	}

	public static function previous_catalog_index_path(): string {
		$pointer = self::read_pointer();
		if ( null !== $pointer && ! empty( $pointer['previous']['catalog_index'] ) ) {
			$candidate = self::path( $pointer['previous']['catalog_index'] );
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

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
	 * Atomically replace the published stock feed.
	 */
	public static function publish( string $type, string $temp_path ): bool {
		self::ensure_directory();

		if ( ! is_file( $temp_path ) || filesize( $temp_path ) <= 0 ) {
			return false;
		}

		if ( 'stock' === $type ) {
			$pointer = self::read_pointer();
			$gen     = $pointer['active']['generation_id'] ?? ( gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) );
			$stock_filename = sprintf( 'pepita-stock.%s.xml', $gen );
			$target_stock   = self::path( $stock_filename );

			if ( ! @rename( $temp_path, $target_stock ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! @copy( $temp_path, $target_stock ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return false;
				}
				@unlink( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			// Update pointer atomically if pointer exists.
			if ( null !== $pointer ) {
				$new_pointer = $pointer;
				if ( ! isset( $new_pointer['previous'] ) || ! is_array( $new_pointer['previous'] ) ) {
					$new_pointer['previous'] = array();
				}
				$new_pointer['previous']['stock_feed'] = $pointer['active']['stock_feed'] ?? '';
				$new_pointer['active']['stock_feed']   = $stock_filename;
				$new_pointer['updated_at']             = time();

				$temp_ptr = self::path( sprintf( 'active-generation.tmp-stock-%s.json', $gen ) );
				if ( false !== file_put_contents( $temp_ptr, json_encode( $new_pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					@rename( $temp_ptr, self::pointer_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					self::clear_pointer_cache();
				}
			}

			// Maintain legacy file for compatibility.
			@copy( $target_stock, self::path( 'pepita-stock.xml' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			clearstatcache( true, $target_stock );

			return is_file( $target_stock ) && filesize( $target_stock ) > 0;
		}

		// Fallback for non-stock single file publish.
		$target = self::feed_path( $type );
		$backup = self::previous_feed_path( $type );

		if ( is_file( $target ) ) {
			@copy( $target, $backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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

	/**
	 * Crash-consistent publication of full catalog XML feed and catalog index.
	 *
	 * Uses immutable versioned artifacts and an atomic active-generation pointer swap.
	 * Both artifacts become active in a single filesystem operation (rename of active-generation.json).
	 * If the process terminates at any point prior to the pointer rename, the previous generation
	 * remains 100% active and uncorrupted.
	 */
	public static function publish_full( string $feed_temp, string $index_temp, ?string $generation_id = null ): bool {
		self::ensure_directory();

		if ( ! is_file( $feed_temp ) || ! is_file( $index_temp ) ) {
			return false;
		}

		if ( filesize( $feed_temp ) <= 0 ) {
			return false;
		}

		$current_pointer = self::read_pointer();
		$gen = $generation_id ?? ( gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) );

		$versioned_feed  = sprintf( 'pepita-full.%s.xml', $gen );
		$versioned_index = sprintf( 'catalog.%s.jsonl', $gen );
		$target_feed     = self::path( $versioned_feed );
		$target_index    = self::path( $versioned_index );

		// Stage versioned artifacts on disk. Pointer has NOT changed yet.
		if ( ! @rename( $feed_temp, $target_feed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $feed_temp, $target_feed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false;
			}
			@unlink( $feed_temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! @rename( $index_temp, $target_index ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $index_temp, $target_index ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $target_feed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false;
			}
			@unlink( $index_temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Prepare active generation pointer payload.
		$active_data = array(
			'generation_id' => $gen,
			'full_feed'     => $versioned_feed,
			'catalog_index' => $versioned_index,
			'stock_feed'    => '',
		);

		$previous_data = null !== $current_pointer ? ( $current_pointer['active'] ?? null ) : null;
		if ( null === $previous_data ) {
			$previous_data = array(
				'generation_id' => 'legacy',
				'full_feed'     => is_file( self::path( 'pepita-full.xml' ) ) ? 'pepita-full.xml' : '',
				'catalog_index' => is_file( self::path( 'catalog.jsonl' ) ) ? 'catalog.jsonl' : '',
				'stock_feed'    => is_file( self::path( 'pepita-stock.xml' ) ) ? 'pepita-stock.xml' : '',
			);
		}

		$new_pointer = array(
			'version'    => 1,
			'active'     => $active_data,
			'previous'   => $previous_data,
			'updated_at' => time(),
		);

		// Atomic pointer swap: write temp file then rename over active-generation.json.
		$temp_ptr_path = self::path( sprintf( 'active-generation.tmp-%s.json', $gen ) );
		$written       = @file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$temp_ptr_path,
			json_encode( $new_pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		if ( false === $written ) {
			@unlink( $target_feed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $target_index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		if ( ! @rename( $temp_ptr_path, self::pointer_path() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $temp_ptr_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $target_feed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $target_index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		self::clear_pointer_cache();

		// Maintain legacy compatibility copies so direct file readers/tools find valid files.
		@copy( $target_feed, self::path( 'pepita-full.xml' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@copy( $target_index, self::path( 'catalog.jsonl' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! empty( $previous_data['full_feed'] ) ) {
			$prev_f = self::path( $previous_data['full_feed'] );
			if ( is_file( $prev_f ) && $prev_f !== self::path( 'pepita-full.previous.xml' ) ) {
				@copy( $prev_f, self::path( 'pepita-full.previous.xml' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		if ( ! empty( $previous_data['catalog_index'] ) ) {
			$prev_i = self::path( $previous_data['catalog_index'] );
			if ( is_file( $prev_i ) && $prev_i !== self::path( 'catalog.previous.jsonl' ) ) {
				@copy( $prev_i, self::path( 'catalog.previous.jsonl' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		self::prune_old_generations( $new_pointer );

		clearstatcache( true, $target_feed );
		clearstatcache( true, $target_index );
		clearstatcache( true, self::pointer_path() );

		return is_file( $target_feed ) && is_file( $target_index );
	}

	/**
	 * Remove old versioned generation files that are no longer active or previous.
	 *
	 * @param array<string, mixed> $pointer
	 */
	public static function prune_old_generations( array $pointer ): void {
		$keep = array(
			self::POINTER_FILENAME,
			'pepita-full.xml',
			'pepita-full.previous.xml',
			'pepita-stock.xml',
			'pepita-stock.previous.xml',
			'catalog.jsonl',
			'catalog.previous.jsonl',
			'index.php',
			'.htaccess',
			'web.config',
		);

		if ( ! empty( $pointer['active']['full_feed'] ) ) {
			$keep[] = $pointer['active']['full_feed'];
		}
		if ( ! empty( $pointer['active']['catalog_index'] ) ) {
			$keep[] = $pointer['active']['catalog_index'];
		}
		if ( ! empty( $pointer['active']['stock_feed'] ) ) {
			$keep[] = $pointer['active']['stock_feed'];
		}

		if ( ! empty( $pointer['previous']['full_feed'] ) ) {
			$keep[] = $pointer['previous']['full_feed'];
		}
		if ( ! empty( $pointer['previous']['catalog_index'] ) ) {
			$keep[] = $pointer['previous']['catalog_index'];
		}
		if ( ! empty( $pointer['previous']['stock_feed'] ) ) {
			$keep[] = $pointer['previous']['stock_feed'];
		}

		$files = glob( self::dir() . '/*' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			$basename = basename( $file );
			if ( in_array( $basename, $keep, true ) ) {
				continue;
			}
			// Keep lock and temp files that are less than 1 hour old.
			if ( str_contains( $basename, '.tmp-' ) || str_contains( $basename, '.ids-' ) || str_contains( $basename, '.lock' ) ) {
				if ( time() - filemtime( $file ) < 3600 ) {
					continue;
				}
			}
			// Unlink unreferenced generation file.
			if ( preg_match( '/^(pepita-full\.|pepita-stock\.|catalog\.)[a-zA-Z0-9_-]+\.(xml|jsonl)$/', $basename ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Delete generated artefacts (used by uninstall and the "reset" tool).
	 */
	public static function purge(): void {
		self::$pointer_cache = null;
		$dir                 = self::dir();

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
