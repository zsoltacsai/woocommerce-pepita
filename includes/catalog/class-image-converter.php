<?php
/**
 * Image format conversion and derivative generation for marketplace compliance.
 *
 * Pepita requires standard JPEG/PNG image formats and minimum 1200x630 resolution.
 * WebP attachments are converted to derivative JPEG files stored in uploads/wps-derivatives/.
 * Original attachments are never modified.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

defined( 'ABSPATH' ) || exit;

final class Image_Converter {

	public const DERIVATIVE_DIR       = 'wps-derivatives';
	public const MIN_WIDTH            = 1200;
	public const MIN_HEIGHT           = 630;
	public const MIN_DIMENSION        = 300;
	public const LEGACY_MIN_DIMENSION = 300;

	private const COMPATIBLE_MIMES = array( 'image/jpeg', 'image/pjpeg', 'image/jpg', 'image/png' );
	private const SOURCE_MIMES     = array( 'image/jpeg', 'image/pjpeg', 'image/jpg', 'image/png', 'image/webp' );
	private const MAX_IMAGE_BYTES  = 33554432;

	/**
	 * Resolve a Pepita-compliant public image URL for an attachment.
	 *
	 * If the attachment is WebP, a JPEG derivative is generated.
	 * If conversion fails, '' is returned so the product is flagged as missing valid image.
	 * Original WooCommerce images and attachments are preserved unmodified.
	 *
	 * @param int         $attachment_id
	 * @param array<int, array{code: string, message: string}>|null $warnings
	 * @return string Compliant public URL or '' on failure.
	 */
	public static function resolve_attachment_url( int $attachment_id, ?array &$warnings = null ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$is_webp = self::is_webp( $attachment_id );

		if ( ! $is_webp ) {
			$url     = Product_Mapper::attachment_url( $attachment_id );
			if ( ! self::is_public_image_url( $url ) ) {
				self::add_warning( $warnings, self::image_warning( 'image_url_unavailable', sprintf( 'A(z) #%d kép nyilvános URL-je hiányzik vagy érvénytelen.', $attachment_id ) ) );
				return '';
			}
			$warning = self::validate_attachment_image( $attachment_id, $url, self::COMPATIBLE_MIMES );
			if ( null !== $warning ) {
				self::add_warning( $warnings, $warning );
				return '';
			}
			return str_replace( ' ', '%20', $url );
		}

		// Attachment is WebP. Check if an original non-WebP exists.
		if ( function_exists( 'wp_get_original_image_url' ) ) {
			$orig_url = (string) wp_get_original_image_url( $attachment_id );
			if ( '' !== $orig_url && ! self::is_webp_path( $orig_url ) ) {
				$orig_path = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( $attachment_id ) : '';
				$warning   = self::validate_image_path( $orig_path, $orig_url, '', $attachment_id, self::COMPATIBLE_MIMES, false );
				if ( null === $warning ) {
					return str_replace( ' ', '%20', $orig_url );
				}
				self::add_warning( $warnings, $warning );
			}
		}

		// Convert WebP to derivative JPEG.
		$derivative = self::generate_jpeg_derivative( $attachment_id, $warnings );

		if ( '' !== $derivative ) {
			return $derivative;
		}

		self::add_warning(
			$warnings,
			array(
				'code'    => 'webp_conversion_failed',
				'message' => sprintf( 'A(z) #%d azonosítójú WebP kép nem alakítható át ellenőrzött JPEG képpé.', $attachment_id ),
			)
		);

		return '';
	}

	/**
	 * Check if an attachment is WebP format.
	 */
	public static function is_webp( int $attachment_id ): bool {
		$mime = function_exists( 'get_post_mime_type' ) ? (string) get_post_mime_type( $attachment_id ) : '';

		if ( 'image/webp' === strtolower( $mime ) ) {
			return true;
		}

		$file = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '';

		if ( '' !== $file && self::is_webp_path( $file ) ) {
			return true;
		}

		$url = Product_Mapper::attachment_url( $attachment_id );

		return '' !== $url && self::is_webp_path( $url );
	}

	public static function is_webp_path( string $path ): bool {
		$clean = strtolower( (string) wp_parse_url( $path, PHP_URL_PATH ) );

		return str_ends_with( $clean, '.webp' );
	}

	/**
	 * Generate or reuse a JPEG derivative for a WebP attachment.
	 *
	 * @param array<int, array{code: string, message: string}>|null $warnings
	 */
	public static function generate_jpeg_derivative( int $attachment_id, ?array &$warnings = null ): string {
		$source_path = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '';
		if ( '' === $source_path || is_link( $source_path ) || ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
			self::add_warning( $warnings, self::image_warning( 'image_source_unavailable', sprintf( 'A(z) #%d WebP forrásfájl nem érhető el.', $attachment_id ) ) );
			return '';
		}
		$upload_dir  = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => '/tmp', 'baseurl' => 'https://example.com/wp-content/uploads' );
		$target_dir = rtrim( (string) ( $upload_dir['basedir'] ?? '' ), '/\\' ) . '/' . self::DERIVATIVE_DIR;

		if ( ! is_dir( $target_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $target_dir );
			} else {
				@mkdir( $target_dir, 0755, true );
			}
		}
		$base_real   = realpath( (string) ( $upload_dir['basedir'] ?? '' ) );
		$target_real = realpath( $target_dir );
		if ( false === $base_real || false === $target_real || $target_real !== $base_real . DIRECTORY_SEPARATOR . self::DERIVATIVE_DIR || ! is_writable( $target_real ) ) {
			self::add_warning( $warnings, self::image_warning( 'image_derivative_storage_unavailable', 'A JPEG képszármazék biztonságos tárolóhelye nem érhető el.' ) );
			return '';
		}

		// Ensure directory protections exist regardless of directory creation time.
		if ( ! is_file( $target_dir . '/index.php' ) ) {
			@file_put_contents( $target_dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! is_file( $target_dir . '/.htaccess' ) ) {
			@file_put_contents( $target_dir . '/.htaccess', "<FilesMatch \"\\.(jpe?g|png)$\">\nAllow from all\n</FilesMatch>\nOptions -Indexes\n" );
		}

		$source_hash = @hash_file( 'sha256', $source_path );
		if ( ! is_string( $source_hash ) || 64 !== strlen( $source_hash ) ) {
			self::add_warning( $warnings, self::image_warning( 'image_source_unavailable', sprintf( 'A(z) #%d WebP forrásfájl nem ellenőrizhető.', $attachment_id ) ) );
			return '';
		}
		$hash                = substr( hash( 'sha256', $attachment_id . ':' . $source_hash ), 0, 16 );
		$derivative_filename = sprintf( 'att-%d-%s.jpg', $attachment_id, $hash );
		$derivative_path     = $target_dir . '/' . $derivative_filename;
		$derivative_url      = rtrim( (string) ( $upload_dir['baseurl'] ?? '' ), '/\\' ) . '/' . self::DERIVATIVE_DIR . '/' . $derivative_filename;
		if ( ! self::is_public_image_url( $derivative_url ) ) {
			self::add_warning( $warnings, self::image_warning( 'image_url_unavailable', 'A JPEG képszármazék nyilvános URL-je érvénytelen.' ) );
			return '';
		}

		if ( file_exists( $derivative_path ) || is_link( $derivative_path ) ) {
			if ( ! is_link( $derivative_path ) && self::is_compliant_image_file( $derivative_path ) ) {
				return str_replace( ' ', '%20', $derivative_url );
			}
			// Corrupt or 0-byte cached derivative: delete and regenerate.
			@unlink( $derivative_path );
		}

		$source_url   = Product_Mapper::attachment_url( $attachment_id );
		$source_mime  = function_exists( 'get_post_mime_type' ) ? (string) get_post_mime_type( $attachment_id ) : '';
		$source_check = self::validate_image_path( $source_path, $source_url, $source_mime, $attachment_id, array( 'image/webp' ), true );
		if ( null !== $source_check ) {
			self::add_warning( $warnings, $source_check );
			return '';
		}

		$tmp_path = @tempnam( $target_dir, '.wps-pepita-' );
		if ( false === $tmp_path || realpath( dirname( $tmp_path ) ) !== realpath( $target_dir ) ) {
			if ( is_string( $tmp_path ) && is_file( $tmp_path ) ) {
				@unlink( $tmp_path );
			}
			return '';
		}

		// Perform conversion using GD or Imagick.
		$converted = false;

		// 1. Try GD.
		if ( function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagejpeg' ) && function_exists( 'imagecreatetruecolor' ) ) {
			$source_img = @imagecreatefromwebp( $source_path );

			if ( $source_img ) {
				$width  = (int) imagesx( $source_img );
				$height = (int) imagesy( $source_img );

				if ( $width < self::MIN_WIDTH || $height < self::MIN_HEIGHT ) {
					if ( is_array( $warnings ) ) {
						$warnings[] = array(
							'code'    => 'insufficient_image_dimensions',
							'message' => sprintf( 'A(z) #%d kép felbontása (%dx%d) kisebb a Pepita által elvárt %dx%d képpontnál.', $attachment_id, $width, $height, self::MIN_WIDTH, self::MIN_HEIGHT ),
						);
					}
				}

				$target_img = imagecreatetruecolor( $width, $height );
				if ( $target_img ) {
					$white = imagecolorallocate( $target_img, 255, 255, 255 );
					imagefilledrectangle( $target_img, 0, 0, $width, $height, $white );
					imagecopy( $target_img, $source_img, 0, 0, 0, 0, $width, $height );
					$converted = @imagejpeg( $target_img, $tmp_path, 90 );
					imagedestroy( $target_img );
				}
				imagedestroy( $source_img );
			}
		}

		// 2. Try Imagick if GD was not available or failed.
		if ( ! $converted && class_exists( '\Imagick' ) ) {
			$memory_limit    = self::memory_limit_bytes( ini_get( 'memory_limit' ) );
			$decode_budget   = min( 128 * 1024 * 1024, max( 1, $memory_limit - memory_get_usage( true ) ) );
			$previous_limits = self::set_imagick_limits( $decode_budget );
			$probe           = null;
			$imagick         = null;
			$canvas          = null;
			$limits_restored = false;
			if ( null !== $previous_limits ) {
				try {
					$probe = new \Imagick();
					$probe->pingImage( $source_path );
					if ( 1 === $probe->getNumberImages() ) {
						$imagick = new \Imagick();
						$imagick->readImage( $source_path );
						$width  = (int) $imagick->getImageWidth();
						$height = (int) $imagick->getImageHeight();

						if ( $width >= self::MIN_WIDTH && $height >= self::MIN_HEIGHT ) {
							$canvas = new \Imagick();
							$canvas->newImage( $width, $height, new \ImagickPixel( 'white' ) );
							$canvas->compositeImage( $imagick, \Imagick::COMPOSITE_OVER, 0, 0 );
							$canvas->setImageFormat( 'jpeg' );
							$canvas->setImageCompressionQuality( 90 );
							$canvas->writeImage( $tmp_path );
							$converted = file_exists( $tmp_path ) && filesize( $tmp_path ) > 0;
						}
					}
				} catch ( \Throwable ) {
					$converted = false;
				} finally {
					foreach ( array( $probe, $imagick, $canvas ) as $image ) {
						if ( $image instanceof \Imagick ) {
							try {
								$image->clear();
								$image->destroy();
							} catch ( \Throwable ) {
								$converted = false;
							}
						}
					}
					$limits_restored = self::restore_imagick_limits( $previous_limits );
				}
			}
			$converted = $converted && $limits_restored;
		}

		$source_unchanged = hash_equals( $source_hash, (string) @hash_file( 'sha256', $source_path ) );
		if ( $converted && $source_unchanged && self::is_compliant_image_file( $tmp_path ) && @chmod( $tmp_path, 0644 ) ) {
			if ( @rename( $tmp_path, $derivative_path ) ) {
				return str_replace( ' ', '%20', $derivative_url );
			}
		}

		if ( file_exists( $tmp_path ) ) {
			@unlink( $tmp_path );
		}

		return '';
	}

	/**
	 * Verify that an image derivative file exists, is non-empty, and is a valid JPEG/PNG image.
	 */
	public static function is_valid_image_file( string $path ): bool {
		if ( ! self::is_decodable_image_file( $path ) ) {
			return false;
		}

		$size = @getimagesize( $path );
		if ( ! is_array( $size ) ) {
			return false;
		}
		$mime = strtolower( (string) ( $size['mime'] ?? '' ) );
		return in_array( self::normalize_mime( $mime ), array_map( array( self::class, 'normalize_mime' ), self::COMPATIBLE_MIMES ), true );
	}

	/**
	 * Verify that an image is a valid Pepita-compatible JPEG/PNG at the required size.
	 */
	public static function is_compliant_image_file( string $path ): bool {
		if ( ! self::is_valid_image_file( $path ) ) {
			return false;
		}
		$size = @getimagesize( $path );

		return is_array( $size )
			&& (int) ( $size[0] ?? 0 ) >= self::MIN_WIDTH
			&& (int) ( $size[1] ?? 0 ) >= self::MIN_HEIGHT;
	}

	/**
	 * Check dimension requirements without modifying/upscaling the image.
	 * Pepita Seller Center specifies minimum dimensions of 1200 x 630 pixels.
	 * Returns diagnostic warning array or null if compliant.
	 *
	 * @param int $attachment_id
	 * @return array{code: string, message: string}|null
	 */
	public static function check_dimensions( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 ) {
			return self::image_warning( 'image_source_unavailable', 'A kép forrásazonosítója érvénytelen.' );
		}
		if ( self::is_webp( $attachment_id ) && function_exists( 'wp_get_original_image_url' ) && function_exists( 'wp_get_original_image_path' ) ) {
			$original_url = (string) wp_get_original_image_url( $attachment_id );
			if ( '' !== $original_url && ! self::is_webp_path( $original_url ) ) {
				$original_check = self::validate_image_path(
					(string) wp_get_original_image_path( $attachment_id ),
					$original_url,
					'',
					$attachment_id,
					self::COMPATIBLE_MIMES,
					false
				);
				if ( null === $original_check ) {
					return null;
				}
			}
		}
		$url  = Product_Mapper::attachment_url( $attachment_id );
		$mime = function_exists( 'get_post_mime_type' ) ? (string) get_post_mime_type( $attachment_id ) : '';
		return self::validate_image_path(
			function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '',
			$url,
			$mime,
			$attachment_id,
			self::SOURCE_MIMES,
			true
		);
	}

	/**
	 * Validate dimensions on a file path directly.
	 *
	 * @param array<int, array{code: string, message: string}>|null $warnings
	 * @return array{code: string, message: string}|null
	 */
	public static function validate_dimensions_on_file( string $path, ?array &$warnings = null ): ?array {
		$warning = self::validate_image_path( $path, '', '', 0, self::SOURCE_MIMES, false );
		self::add_warning( $warnings, $warning );
		return $warning;
	}

	/**
	 * Validate a local image file against its declared type and Pepita dimensions.
	 * Offloaded images without a verifiable local source fail closed.
	 *
	 * @param string   $path
	 * @param string   $url
	 * @param string   $declared_mime
	 * @param int      $attachment_id
	 * @param string[] $allowed_mimes
	 * @param bool     $check_metadata
	 * @return array{code: string, message: string}|null
	 */
	private static function validate_image_path( string $path, string $url, string $declared_mime, int $attachment_id, array $allowed_mimes, bool $check_metadata ): ?array {
		if ( '' === $path || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return self::image_warning( 'image_source_unavailable', sprintf( 'A(z) #%d kép helyi forrásfájlja nem érhető el biztonságos ellenőrzésre.', $attachment_id ) );
		}
		if ( '' !== $url ) {
			if ( ! self::is_public_image_url( $url ) ) {
				return self::image_warning( 'image_url_unavailable', sprintf( 'A(z) #%d kép nyilvános URL-je érvénytelen.', $attachment_id ) );
			}
		}
		if ( ! self::is_decodable_image_file( $path ) ) {
			return self::image_warning( 'invalid_image_file', sprintf( 'A(z) #%d kép fájlja hiányos vagy nem dekódolható.', $attachment_id ) );
		}

		$size = @getimagesize( $path );
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return self::image_warning( 'invalid_image_file', sprintf( 'A(z) #%d kép fájlja hiányos vagy sérült.', $attachment_id ) );
		}

		$actual_mime = self::normalize_mime( (string) ( $size['mime'] ?? '' ) );
		$declared    = self::normalize_mime( $declared_mime );
		if ( ! in_array( $actual_mime, array_map( array( self::class, 'normalize_mime' ), $allowed_mimes ), true ) ) {
			return self::image_warning( 'unsupported_image_format', sprintf( 'A(z) #%d kép formátuma nem Pepita-kompatibilis.', $attachment_id ) );
		}
		if ( '' !== $declared && $declared !== $actual_mime ) {
			return self::image_warning( 'image_mime_mismatch', sprintf( 'A(z) #%d kép deklarált MIME-típusa nem egyezik a fájl tényleges formátumával.', $attachment_id ) );
		}
		if ( '' === $declared && '' !== $declared_mime ) {
			return self::image_warning( 'image_mime_unavailable', sprintf( 'A(z) #%d kép MIME-típusa nem ellenőrizhető.', $attachment_id ) );
		}

		$url_mime = self::mime_from_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' !== $url_mime && $url_mime !== $actual_mime ) {
			return self::image_warning( 'image_mime_mismatch', sprintf( 'A(z) #%d kép URL-kiterjesztése nem egyezik a fájl tényleges formátumával.', $attachment_id ) );
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];
		if ( $check_metadata && function_exists( 'wp_get_attachment_metadata' ) ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) && ( isset( $meta['width'] ) || isset( $meta['height'] ) ) ) {
				if ( ! isset( $meta['width'], $meta['height'] ) || (int) $meta['width'] <= 0 || (int) $meta['height'] <= 0 ) {
					return self::image_warning( 'image_dimensions_unavailable', sprintf( 'A(z) #%d kép méretmetaadata hiányos.', $attachment_id ) );
				}
				if ( (int) $meta['width'] < self::MIN_WIDTH || (int) $meta['height'] < self::MIN_HEIGHT ) {
					return self::dimension_warning( $attachment_id, (int) $meta['width'], (int) $meta['height'] );
				}
			}
		}

		if ( $width < self::MIN_WIDTH || $height < self::MIN_HEIGHT ) {
			return self::dimension_warning( $attachment_id, $width, $height );
		}

		return null;
	}

	/** @return array{code: string, message: string} */
	private static function dimension_warning( int $attachment_id, int $width, int $height ): array {
		return self::image_warning(
			'insufficient_image_dimensions',
			sprintf( 'A(z) #%d kép felbontása (%dx%d) nem éri el a Pepita Seller Center által elvárt minimális %dx%d képpontot.', $attachment_id, $width, $height, self::MIN_WIDTH, self::MIN_HEIGHT )
		);
	}

	/** @return array{code: string, message: string} */
	private static function image_warning( string $code, string $message ): array {
		return array( 'code' => $code, 'message' => $message );
	}

	/** @param array<int, array{code: string, message: string}>|null $warnings */
	private static function add_warning( ?array &$warnings, ?array $warning ): void {
		if ( null !== $warning && is_array( $warnings ) ) {
			$warnings[] = $warning;
		}
	}

	private static function normalize_mime( string $mime ): string {
		$mime = strtolower( trim( $mime ) );
		return in_array( $mime, array( 'image/pjpeg', 'image/jpg' ), true ) ? 'image/jpeg' : $mime;
	}

	private static function mime_from_path( string $path ): string {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return match ( $extension ) {
			'jpg', 'jpeg' => 'image/jpeg',
			'png'         => 'image/png',
			'webp'        => 'image/webp',
			default       => '',
		};
	}

	private static function is_public_image_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] );
	}

	private static function validate_attachment_image( int $attachment_id, string $url, array $allowed_mimes ): ?array {
		return self::validate_image_path(
			function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '',
			$url,
			function_exists( 'get_post_mime_type' ) ? (string) get_post_mime_type( $attachment_id ) : '',
			$attachment_id,
			$allowed_mimes,
			true
		);
	}

	/**
	 * Check image header and decode the complete payload with an installed image library.
	 *
	 * @param string $path
	 */
	private static function is_decodable_image_file( string $path ): bool {
		$file_size = @filesize( $path );
		if ( ! is_file( $path ) || ! is_readable( $path ) || false === $file_size || $file_size <= 0 || $file_size > self::MAX_IMAGE_BYTES ) {
			return false;
		}
		$size = @getimagesize( $path );
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return false;
		}

		// Avoid allocating an unbounded image buffer during admin/feed inspection.
		$memory_limit  = self::memory_limit_bytes( ini_get( 'memory_limit' ) );
		$available     = $memory_limit > 0 ? max( 0, $memory_limit - memory_get_usage( true ) ) : 128 * 1024 * 1024;
		$decode_budget = min( $available, 128 * 1024 * 1024 );
		$width         = (int) $size[0];
		$height        = (int) $size[1];
		if ( $height <= 0 || $width > intdiv( $decode_budget, max( 1, $height * 8 ) ) ) {
			return false;
		}

		$mime = self::normalize_mime( (string) ( $size['mime'] ?? '' ) );
		if ( 'image/jpeg' === $mime && ! self::jpeg_has_end_marker( $path ) ) {
			return false;
		}
		$decoder = match ( $mime ) {
			'image/jpeg' => 'imagecreatefromjpeg',
			'image/png'  => 'imagecreatefrompng',
			'image/webp' => 'imagecreatefromwebp',
			default      => '',
		};
		if ( '' !== $decoder && function_exists( $decoder ) ) {
			$decode_warning = false;
			set_error_handler(
				static function () use ( &$decode_warning ): bool {
					$decode_warning = true;
					return true;
				}
			);
			try {
				$image = $decoder( $path );
			} catch ( \Throwable ) {
				$image = false;
			} finally {
				restore_error_handler();
			}
			if ( false === $image || imagesx( $image ) !== $width || imagesy( $image ) !== $height ) {
				if ( false !== $image ) {
					imagedestroy( $image );
				}
				return false;
			}
			imagedestroy( $image );
			return ! $decode_warning;
		}
		if ( class_exists( '\\Imagick' ) ) {
			$previous_limits = self::set_imagick_limits( $decode_budget );
			if ( null === $previous_limits ) {
				return false;
			}
			$valid = false;
			$probe = null;
			$image = null;
			try {
				$probe = new \Imagick();
				$probe->pingImage( $path );
				$single_frame = 1 === $probe->getNumberImages();
				if ( $single_frame ) {
					$image = new \Imagick();
					$image->readImage( $path );
					$valid = $image->getImageWidth() === $width && $image->getImageHeight() === $height;
				}
			} catch ( \Throwable ) {
				$valid = false;
			} finally {
				foreach ( array( $probe, $image ) as $instance ) {
					if ( $instance instanceof \Imagick ) {
						try {
							$instance->clear();
							$instance->destroy();
						} catch ( \Throwable ) {
							$valid = false;
						}
					}
				}
			}
			$limits_restored = self::restore_imagick_limits( $previous_limits );
			return $valid && $limits_restored;
		}
		return false;
	}

	private static function memory_limit_bytes( string|false $limit ): int {
		if ( false === $limit || '' === $limit || '-1' === $limit ) {
			return 128 * 1024 * 1024;
		}
		$unit  = strtolower( substr( $limit, -1 ) );
		$value = (int) $limit;
		return match ( $unit ) {
			'g' => $value * 1024 * 1024 * 1024,
			'm' => $value * 1024 * 1024,
			'k' => $value * 1024,
			default => $value,
		};
	}

	/** JPEG streams without the final EOI marker are incomplete, even if a decoder recovers them. */
	private static function jpeg_has_end_marker( string $path ): bool {
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		try {
			return 0 === fseek( $handle, -2, SEEK_END ) && "\xFF\xD9" === fread( $handle, 2 );
		} finally {
			fclose( $handle );
		}
	}

	/** @return array<int, int>|null Previous limits, or null if every cap could not be applied. */
	private static function set_imagick_limits( int $decode_budget ): ?array {
		$requested = array(
			'RESOURCETYPE_MEMORY' => min( $decode_budget, 128 * 1024 * 1024 ),
			'RESOURCETYPE_MAP'    => min( $decode_budget, 128 * 1024 * 1024 ),
			'RESOURCETYPE_DISK'   => 128 * 1024 * 1024,
			'RESOURCETYPE_AREA'   => intdiv( $decode_budget, 8 ),
			'RESOURCETYPE_THREAD' => 1,
		);
		$limits = array();
		foreach ( $requested as $name => $limit ) {
			$constant = \Imagick::class . '::' . $name;
			if ( ! defined( $constant ) ) {
				return null;
			}
			$limits[ constant( $constant ) ] = $limit;
		}

		return self::apply_imagick_limits(
			$limits,
			static fn ( int $resource ): int => (int) \Imagick::getResourceLimit( $resource ),
			static fn ( int $resource, int $limit ): bool => \Imagick::setResourceLimit( $resource, $limit )
		);
	}

	/** @param array<int, int> $requested Resource IDs mapped to requested maximums. */
	private static function apply_imagick_limits( array $requested, callable $get, callable $set ): ?array {
		$previous = array();
		try {
			foreach ( $requested as $resource => $limit ) {
				$previous[ $resource ] = (int) $get( (int) $resource );
				$current              = (int) $previous[ $resource ];
				// Zero means unlimited in ImageMagick; apply this decoder's finite cap.
				$target = $current > 0 ? min( $current, max( 1, (int) $limit ) ) : max( 1, (int) $limit );
				if ( true !== $set( (int) $resource, $target ) || (int) $get( (int) $resource ) !== $target ) {
					self::restore_imagick_limits( $previous, $set, $get );
					return null;
				}
			}
		} catch ( \Throwable ) {
			self::restore_imagick_limits( $previous, $set, $get );
			return null;
		}
		return $previous;
	}

	/** @param array<int, int> $limits */
	private static function restore_imagick_limits( array $limits, ?callable $set = null, ?callable $get = null ): bool {
		$set      ??= static fn ( int $resource, int $limit ): bool => \Imagick::setResourceLimit( $resource, $limit );
		$get      ??= static fn ( int $resource ): int => (int) \Imagick::getResourceLimit( $resource );
		$restored = true;
		foreach ( $limits as $resource => $limit ) {
			try {
				if ( true !== $set( (int) $resource, (int) $limit ) || (int) $get( (int) $resource ) !== (int) $limit ) {
					$restored = false;
				}
			} catch ( \Throwable ) {
				$restored = false;
			}
		}
		return $restored;
	}
}
