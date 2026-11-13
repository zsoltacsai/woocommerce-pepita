<?php
/**
 * Image format conversion and derivative generation for marketplace compliance.
 *
 * Pepita requires standard JPEG/PNG image formats and minimum 300x300 resolution.
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
			return Product_Mapper::attachment_url( $attachment_id );
		}

		// Attachment is WebP. Check if an original non-WebP exists.
		if ( function_exists( 'wp_get_original_image_url' ) ) {
			$orig_url = (string) wp_get_original_image_url( $attachment_id );
			if ( '' !== $orig_url && ! self::is_webp_path( $orig_url ) ) {
				return str_replace( ' ', '%20', $orig_url );
			}
		}

		// Convert WebP to derivative JPEG.
		$derivative = self::generate_jpeg_derivative( $attachment_id, $warnings );

		if ( '' !== $derivative ) {
			return $derivative;
		}

		if ( is_array( $warnings ) ) {
			$warnings[] = array(
				'code'    => 'webp_conversion_failed',
				'message' => sprintf( 'A(z) #%d azonosítójú WebP kép nem alakítható át JPEG formátumra, és nem áll rendelkezésre kompatibilis eredeti kép.', $attachment_id ),
			);
		}

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

		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			return '';
		}

		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => '/tmp', 'baseurl' => 'https://example.com/wp-content/uploads' );
		$target_dir = rtrim( (string) ( $upload_dir['basedir'] ?? '' ), '/\\' ) . '/' . self::DERIVATIVE_DIR;

		if ( ! is_dir( $target_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $target_dir );
			} else {
				@mkdir( $target_dir, 0755, true );
			}
		}

		// Ensure directory protections exist regardless of directory creation time.
		if ( ! is_file( $target_dir . '/index.php' ) ) {
			@file_put_contents( $target_dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! is_file( $target_dir . '/.htaccess' ) ) {
			@file_put_contents( $target_dir . '/.htaccess', "<FilesMatch \"\\.(jpe?g|png)$\">\nAllow from all\n</FilesMatch>\nOptions -Indexes\n" );
		}

		$mtime               = (int) filemtime( $source_path );
		$hash                = substr( md5( $attachment_id . '-' . $mtime . '-' . filesize( $source_path ) ), 0, 12 );
		$derivative_filename = sprintf( 'att-%d-%s.jpg', $attachment_id, $hash );
		$derivative_path     = $target_dir . '/' . $derivative_filename;
		$derivative_url      = rtrim( (string) ( $upload_dir['baseurl'] ?? '' ), '/\\' ) . '/' . self::DERIVATIVE_DIR . '/' . $derivative_filename;

		if ( file_exists( $derivative_path ) && filesize( $derivative_path ) > 0 ) {
			return str_replace( ' ', '%20', $derivative_url );
		}

		// Perform conversion using GD or Imagick.
		$converted = false;

		// 1. Try GD.
		if ( function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagejpeg' ) && function_exists( 'imagecreatetruecolor' ) ) {
			$source_img = @imagecreatefromwebp( $source_path );

			if ( $source_img ) {
				$width  = (int) imagesx( $source_img );
				$height = (int) imagesy( $source_img );

				if ( $width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION ) {
					if ( is_array( $warnings ) ) {
						$warnings[] = array(
							'code'    => 'insufficient_image_dimensions',
							'message' => sprintf( 'A(z) #%d kép felbontása (%dx%d) kisebb a Pepita által elvárt %dx%d képpontnál.', $attachment_id, $width, $height, self::MIN_DIMENSION, self::MIN_DIMENSION ),
						);
					}
				}

				$target_img = imagecreatetruecolor( $width, $height );
				if ( $target_img ) {
					$white = imagecolorallocate( $target_img, 255, 255, 255 );
					imagefilledrectangle( $target_img, 0, 0, $width, $height, $white );
					imagecopy( $target_img, $source_img, 0, 0, 0, 0, $width, $height );
					$converted = @imagejpeg( $target_img, $derivative_path, 90 );
					imagedestroy( $target_img );
				}
				imagedestroy( $source_img );
			}
		}

		// 2. Try Imagick if GD was not available or failed.
		if ( ! $converted && class_exists( '\Imagick' ) ) {
			try {
				$imagick = new \Imagick( $source_path );
				$width   = (int) $imagick->getImageWidth();
				$height  = (int) $imagick->getImageHeight();

				if ( $width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION ) {
					if ( is_array( $warnings ) ) {
						$warnings[] = array(
							'code'    => 'insufficient_image_dimensions',
							'message' => sprintf( 'A(z) #%d kép felbontása (%dx%d) kisebb a Pepita által elvárt %dx%d képpontnál.', $attachment_id, $width, $height, self::MIN_DIMENSION, self::MIN_DIMENSION ),
						);
					}
				}

				$canvas = new \Imagick();
				$canvas->newImage( $width, $height, new \ImagickPixel( 'white' ) );
				$canvas->compositeImage( $imagick, \Imagick::COMPOSITE_OVER, 0, 0 );
				$canvas->setImageFormat( 'jpeg' );
				$canvas->setImageCompressionQuality( 90 );
				$canvas->writeImage( $derivative_path );
				$canvas->clear();
				$canvas->destroy();
				$imagick->clear();
				$imagick->destroy();
				$converted = file_exists( $derivative_path ) && filesize( $derivative_path ) > 0;
			} catch ( \Throwable ) {
				$converted = false;
			}
		}

		if ( $converted && file_exists( $derivative_path ) && filesize( $derivative_path ) > 0 ) {
			return str_replace( ' ', '%20', $derivative_url );
		}

		return '';
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
			return null;
		}

		if ( function_exists( 'wp_get_attachment_metadata' ) ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
				$w = (int) $meta['width'];
				$h = (int) $meta['height'];
				if ( ( $w > 0 && $w < self::MIN_WIDTH ) || ( $h > 0 && $h < self::MIN_HEIGHT ) ) {
					return array(
						'code'    => 'insufficient_image_dimensions',
						'message' => sprintf( 'A(z) #%d kép felbontása (%dx%d) nem éri el a Pepita Seller Center által elvárt minimális %dx%d képpontot.', $attachment_id, $w, $h, self::MIN_WIDTH, self::MIN_HEIGHT ),
					);
				}
			}
		}

		$file = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '';
		if ( '' !== $file && file_exists( $file ) ) {
			return self::validate_dimensions_on_file( $file );
		}

		return null;
	}

	/**
	 * Validate dimensions on a file path directly.
	 *
	 * @param array<int, array{code: string, message: string}>|null $warnings
	 * @return array{code: string, message: string}|null
	 */
	public static function validate_dimensions_on_file( string $path, ?array &$warnings = null ): ?array {
		if ( '' === $path || ! file_exists( $path ) ) {
			return null;
		}

		$size = @getimagesize( $path );
		if ( is_array( $size ) && isset( $size[0], $size[1] ) ) {
			$w = (int) $size[0];
			$h = (int) $size[1];
			if ( ( $w > 0 && $w < self::MIN_WIDTH ) || ( $h > 0 && $h < self::MIN_HEIGHT ) ) {
				$warning = array(
					'code'    => 'insufficient_image_dimensions',
					'message' => sprintf( 'A kép felbontása (%dx%d) nem éri el a Pepita Seller Center által elvárt minimális %dx%d képpontot.', $w, $h, self::MIN_WIDTH, self::MIN_HEIGHT ),
				);
				if ( is_array( $warnings ) ) {
					$warnings[] = $warning;
				}
				return $warning;
			}
		}

		return null;
	}
}
