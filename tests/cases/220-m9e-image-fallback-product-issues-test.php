<?php
/**
 * Milestone M9-E: Image Fallback, Product Issues & Primary Category Mapping Tests.
 *
 * Covers:
 * - 1. Automatic WebP -> JPEG fallback, atomic .tmp write, corrupt/0-byte derivative regeneration
 * - 2. Gallery image fallback when primary/featured image is undersized (< 1200x630)
 * - 3. Primary category strategy 'approved_parent' and metabox ancestor category selection
 * - 4. Automatic quality issues exclusion (exclude_quality_issues) in full and stock feeds
 * - 5. Hibás termékek (Product_Issues) classification (blocking vs non-blocking, no stock_quantity_capped)
 * - 6. FN-DECO 269 baseline acceptance: 28 excluded, 241 exported, #9897 Stencil - Hópehely exact mapping
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Admin\Product_Issues;
use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Image_Converter;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

// Minimal valid 1x1 JPEG binary string for testing valid image derivatives.
$valid_jpeg_binary = base64_decode( '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=' );

/* =========================================================================
 * 1. Image_Converter: Integrity, Corruption Regeneration & Atomic Renaming
 * ========================================================================= */

$suite->add(
	'm9e images: is_valid_image_file detects non-existent, 0-byte, corrupt, and valid images',
	static function () use ( $valid_jpeg_binary ): void {
		$tmp_dir = sys_get_temp_dir() . '/wps_img_test_' . uniqid();
		@mkdir( $tmp_dir, 0755, true );

		// 1. Non-existent file
		WPS_Assert::false( Image_Converter::is_valid_image_file( $tmp_dir . '/non-existent.jpg' ) );

		// 2. 0-byte file
		$zero_file = $tmp_dir . '/zero.jpg';
		file_put_contents( $zero_file, '' );
		WPS_Assert::false( Image_Converter::is_valid_image_file( $zero_file ) );

		// 3. Corrupt/plain text file
		$corrupt_file = $tmp_dir . '/corrupt.jpg';
		file_put_contents( $corrupt_file, 'not an image data string' );
		WPS_Assert::false( Image_Converter::is_valid_image_file( $corrupt_file ) );

		// 4. Valid JPEG file
		$valid_file = $tmp_dir . '/valid.jpg';
		file_put_contents( $valid_file, $valid_jpeg_binary );
		WPS_Assert::true( Image_Converter::is_valid_image_file( $valid_file ) );

		@unlink( $zero_file );
		@unlink( $corrupt_file );
		@unlink( $valid_file );
		@rmdir( $tmp_dir );
	}
);

$suite->add(
	'm9e images: original sources fail closed on unsupported MIME, mismatch, corrupt, missing, or unknown source',
	static function (): void {
		WPS_Test_State::reset();
		$warnings = array();

		WPS_Test_State::add_attachment( 840, 'https://example.test/unsupported.gif' );
		WPS_Assert::true( is_file( WPS_Test_State::$attached_files[ 840 ] ?? '' ), 'Fixture path must resolve before image inspection.' );
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 840, $warnings ) );
		WPS_Assert::same( 'unsupported_image_format', $warnings[0]['code'] );

		WPS_Test_State::add_attachment( 841, 'https://example.test/mime-mismatch.jpg' );
		WPS_Test_State::$mime_types[ 841 ] = 'image/png';
		$mismatch_warnings = array();
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 841, $mismatch_warnings ) );
		WPS_Assert::same( 'image_mime_mismatch', $mismatch_warnings[0]['code'] );

		WPS_Test_State::add_attachment( 842, 'https://example.test/missing.jpg' );
		WPS_Test_State::$attached_files[ 842 ] = sys_get_temp_dir() . '/wps-image-missing-' . uniqid() . '.jpg';
		$missing_warnings = array();
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 842, $missing_warnings ) );
		WPS_Assert::same( 'image_source_unavailable', $missing_warnings[0]['code'] );

		$corrupt_path = sys_get_temp_dir() . '/wps-image-corrupt-' . uniqid() . '.jpg';
		file_put_contents( $corrupt_path, 'not an image' );
		WPS_Test_State::add_attachment( 843, 'https://example.test/corrupt.jpg' );
		WPS_Test_State::$attached_files[ 843 ] = $corrupt_path;
		$corrupt_warnings = array();
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 843, $corrupt_warnings ) );
		WPS_Assert::same( 'invalid_image_file', $corrupt_warnings[0]['code'] );
		@unlink( $corrupt_path );

		WPS_Test_State::add_attachment( 844, 'https://cdn.example.test/no-local-source' );
		unset( WPS_Test_State::$attachment_metadata[ 844 ] );
		WPS_Test_State::$attached_files[ 844 ] = '';
		WPS_Assert::same( 'image_source_unavailable', Image_Converter::check_dimensions( 844 )['code'] );
		$offloaded_warnings = array();
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 844, $offloaded_warnings ) );
		WPS_Assert::same( 'image_source_unavailable', $offloaded_warnings[0]['code'] );

		WPS_Test_State::add_attachment( 847, '' );
		WPS_Test_State::$attached_files[ 847 ] = __DIR__ . '/../fixtures/images/pepita-compatible-1200x630.jpg';
		unset( WPS_Test_State::$attachment_metadata[ 847 ] );
		WPS_Assert::same( null, Image_Converter::check_dimensions( 847 ), 'The local file supplies verifiable dimensions when metadata is absent.' );
		$missing_url_warnings = array();
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 847, $missing_url_warnings ) );
		WPS_Assert::same( 'image_url_unavailable', $missing_url_warnings[0]['code'] );

		$truncated_path = sys_get_temp_dir() . '/wps-image-truncated-' . uniqid() . '.jpg';
		$jpeg_bytes     = file_get_contents( __DIR__ . '/../fixtures/images/pepita-compatible-1200x630.jpg' );
		file_put_contents( $truncated_path, substr( (string) $jpeg_bytes, 0, 80 ) );
		WPS_Test_State::add_attachment( 848, 'https://example.test/truncated.jpg' );
		WPS_Test_State::$attached_files[ 848 ] = $truncated_path;
		$truncated_warnings = array();
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 848, $truncated_warnings ) );
		WPS_Assert::same( 'invalid_image_file', $truncated_warnings[0]['code'] );
		@unlink( $truncated_path );

		$oversized_path = sys_get_temp_dir() . '/wps-image-oversized-' . uniqid() . '.jpg';
		copy( __DIR__ . '/../fixtures/images/pepita-compatible-1200x630.jpg', $oversized_path );
		$oversized_file = fopen( $oversized_path, 'c' );
		WPS_Assert::true( is_resource( $oversized_file ) );
		ftruncate( $oversized_file, 33554433 );
		fclose( $oversized_file );
		WPS_Test_State::add_attachment( 849, 'https://example.test/oversized.jpg' );
		WPS_Test_State::$attached_files[ 849 ] = $oversized_path;
		$oversized_warnings = array();
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 849, $oversized_warnings ) );
		WPS_Assert::same( 'invalid_image_file', $oversized_warnings[0]['code'] );
		@unlink( $oversized_path );
	}
);

$suite->add(
	'm9e images: WebP original fallback is used only when its local JPEG bytes validate',
	static function (): void {
		WPS_Test_State::reset();
		WPS_Test_State::add_attachment( 845, 'https://example.test/source.webp' );
		WPS_Test_State::$mime_types[ 845 ]          = 'image/webp';
		WPS_Test_State::$attached_files[ 845 ]      = sys_get_temp_dir() . '/missing-webp-source';
		WPS_Test_State::$original_image_urls[ 845 ] = 'https://example.test/original.jpg';
		WPS_Test_State::$original_image_files[ 845 ] = __DIR__ . '/../fixtures/images/pepita-compatible-1200x630.jpg';

		WPS_Assert::same( null, Image_Converter::check_dimensions( 845 ), 'A validated compatible original can satisfy WebP dimension checks.' );
		WPS_Assert::same( 'https://example.test/original.jpg', Image_Converter::resolve_attachment_url( 845 ) );

		WPS_Test_State::$original_image_urls[ 845 ] = 'https://example.test/original.gif';
		WPS_Test_State::$original_image_files[ 845 ] = __DIR__ . '/../fixtures/images/pepita-unsupported-1200x630.gif';
		$warnings = array();
		WPS_Assert::same( '', Image_Converter::resolve_attachment_url( 845, $warnings ) );
		WPS_Assert::true( in_array( 'unsupported_image_format', array_column( $warnings, 'code' ), true ) );
	}
);

$suite->add(
	'm9e images: generate_jpeg_derivative automatically purges corrupt cached derivative',
	static function () use ( $valid_jpeg_binary ): void {
		WPS_Test_State::reset();

		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => '/tmp', 'baseurl' => 'https://example.com/wp-content/uploads' );
		$target_dir = rtrim( (string) ( $upload_dir['basedir'] ?? '' ), '/\\' ) . '/' . Image_Converter::DERIVATIVE_DIR;
		if ( ! is_dir( $target_dir ) ) {
			@mkdir( $target_dir, 0755, true );
		}

		$source_file = $target_dir . '/source-test.webp';
		file_put_contents( $source_file, 'dummy-webp' );

		WPS_Test_State::add_attachment( 701, 'https://example.test/uploads/source-test.webp' );
		WPS_Test_State::$attached_files[ 701 ] = $source_file;
		WPS_Test_State::$mime_types[ 701 ]     = 'image/webp';

		$source_hash          = hash_file( 'sha256', $source_file );
		$hash                 = substr( hash( 'sha256', '701:' . $source_hash ), 0, 16 );
		$derivative_filename = sprintf( 'att-%d-%s.jpg', 701, $hash );
		$derivative_path     = $target_dir . '/' . $derivative_filename;

		// Create a corrupt 0-byte derivative.
		file_put_contents( $derivative_path, '' );
		WPS_Assert::true( file_exists( $derivative_path ) );

		// Requesting resolution should purge the corrupt 0-byte file.
		$warnings = array();
		Image_Converter::generate_jpeg_derivative( 701, $warnings );

		// Because source was dummy, conversion fails and corrupt file should have been deleted.
		WPS_Assert::false( file_exists( $derivative_path ) );

		// A symlink must never be treated as a trusted derivative cache entry.
		$symlink_path = $target_dir . '/att-701-' . $hash . '.jpg';
		WPS_Assert::true( symlink( __DIR__ . '/../fixtures/images/pepita-compatible-1200x630.jpg', $symlink_path ) );
		Image_Converter::generate_jpeg_derivative( 701 );
		WPS_Assert::false( is_link( $symlink_path ) );

		@unlink( $source_file );
	}
);

/* =========================================================================
 * 2. Gallery Image Fallback
 * ========================================================================= */

$suite->add(
	'm9e images: featured image < 1200x630 falls back to compliant gallery image',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true ) );

		// Attachment 801: Small featured image (800 x 600 < 1200x630).
		WPS_Test_State::add_attachment( 801, 'https://example.test/featured-small.jpg' );
		WPS_Test_State::$attachment_metadata[ 801 ] = array(
			'width'  => 800,
			'height' => 600,
		);

		// Attachment 802: Compliant gallery image (1600 x 1200 >= 1200x630).
		WPS_Test_State::add_attachment( 802, 'https://example.test/gallery-compliant.jpg' );
		WPS_Test_State::$attachment_metadata[ 802 ] = array(
			'width'  => 1600,
			'height' => 1200,
		);

		WPS_Test_State::add_term( 100, 'Kategória 100' );

		// Product with small featured image and compliant gallery image.
		$p = WPS_Test_State::add_product(
			array(
				'id'          => 7001,
				'name'        => 'Kép fallback termék',
				'description' => 'Érvényes termékleírás',
				'price'       => 1500,
				'image_id'    => 801,
			)
		);
		$p->set_gallery_image_ids( array( 802 ) );
		WPS_Test_State::assign_term( 7001, 100 );

		// 1. Verify find_compliant_gallery_image identifies attachment 802.
		$found_gallery = Product_Mapper::find_compliant_gallery_image( $p );
		WPS_Assert::same( 802, $found_gallery );

		// 2. Map product and verify primary photo is gallery image 802.
		$mapped = Product_Mapper::map( 7001 );
		WPS_Assert::true( count( $mapped['photos'] ) >= 1 );
		WPS_Assert::same( 'https://example.test/gallery-compliant.jpg', $mapped['photos'][0]['url'] );
		WPS_Assert::true( $mapped['photos'][0]['primary'] );

		// 3. Inspect quality issues: no blocking image dimension issue because fallback succeeded!
		$issues = Product_Issues::inspect( 7001 );
		$has_dim_issue = false;
		foreach ( $issues as $issue ) {
			if ( Product_Issues::ISSUE_IMAGE_DIM === $issue['code'] ) {
				$has_dim_issue = true;
			}
		}
		WPS_Assert::false( $has_dim_issue );
	}
);

$suite->add(
	'm9e images: when both featured and gallery images are undersized, issue is flagged',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true ) );

		// Attachment 803: Small featured image (600 x 400).
		WPS_Test_State::add_attachment( 803, 'https://example.test/small-featured.jpg' );
		WPS_Test_State::$attachment_metadata[ 803 ] = array( 'width' => 600, 'height' => 400 );

		// Attachment 804: Also small gallery image (500 x 500).
		WPS_Test_State::add_attachment( 804, 'https://example.test/small-gallery.jpg' );
		WPS_Test_State::$attachment_metadata[ 804 ] = array( 'width' => 500, 'height' => 500 );

		WPS_Test_State::add_term( 100, 'Kategória 100' );

		$p = WPS_Test_State::add_product(
			array(
				'id'          => 7002,
				'name'        => 'Kicsi képek termék',
				'description' => 'Érvényes termékleírás',
				'price'       => 2000,
				'image_id'    => 803,
			)
		);
		$p->set_gallery_image_ids( array( 804 ) );
		WPS_Test_State::assign_term( 7002, 100 );

		// No compliant gallery fallback exists.
		WPS_Assert::same( 0, Product_Mapper::find_compliant_gallery_image( $p ) );

		// Quality issue must be flagged.
		$issues = Product_Issues::inspect( 7002 );
		$has_dim_issue = false;
		foreach ( $issues as $issue ) {
			if ( Product_Issues::ISSUE_IMAGE_DIM === $issue['code'] && ! empty( $issue['blocking'] ) ) {
				$has_dim_issue = true;
			}
		}
		WPS_Assert::true( $has_dim_issue );
		WPS_Assert::true( Product_Issues::has_blocking_issues( 7002 ) );
	}
);

/* =========================================================================
 * 3. Primary Category Strategy: approved_parent & Ancestor Metabox Selection
 * ========================================================================= */

$suite->add(
	'm9e category: approved_parent resolves to most specific explicitly approved ancestor (87 -> 111 -> 28687)',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update(
			array(
				'default_included'          => true,
				'primary_category_strategy' => 'approved_parent',
			)
		);

		// Category tree: 87 (Webáruház) -> 111 (Stencilek) -> 28687 (HDS15).
		WPS_Test_State::add_term( 87, 'Webáruház', 0 );
		WPS_Test_State::add_term( 111, 'Stencilek', 87 );
		WPS_Test_State::add_term( 28687, 'HDS15', 111 );

		// Mark category 111 (Stencilek) explicitly approved with children allowed.
		update_term_meta( 111, Category_Rules::META_INCLUDE, Category_Rules::DECISION_INCLUDE );
		update_term_meta( 111, Category_Rules::META_INCLUDE_CHILDREN, '1' );

		WPS_Test_State::add_attachment( 810, 'https://example.test/img.jpg' );
		WPS_Test_State::$attachment_metadata[ 810 ] = array( 'width' => 1200, 'height' => 800 );

		// Product directly assigned only to child term 28687 (HDS15).
		$p = WPS_Test_State::add_product(
			array(
				'id'          => 7003,
				'name'        => 'Stencil Minta',
				'description' => 'Érvényes termékleírás',
				'price'       => 1300,
				'image_id'    => 810,
			)
		);
		WPS_Test_State::assign_term( 7003, 28687 );

		// Map product and verify primary category hierarchy.
		$mapped = Product_Mapper::map( 7003 );

		// Exactly one hierarchy path returned.
		WPS_Assert::same( 1, count( $mapped['categories'] ) );

		// Path must resolve to 87 -> 111 (Stencilek), omitting HDS15 (28687).
		$path = $mapped['categories'][0];
		WPS_Assert::same( 2, count( $path ) );
		WPS_Assert::same( 87, $path[0]['id'] );
		WPS_Assert::same( 'Webáruház', $path[0]['name'] );
		WPS_Assert::same( 111, $path[1]['id'] );
		WPS_Assert::same( 'Stencilek', $path[1]['name'] );
	}
);

$suite->add(
	'm9e category: explicit metabox override to ancestor or child is honored under approved_parent',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update(
			array(
				'default_included'          => true,
				'primary_category_strategy' => 'approved_parent',
			)
		);

		WPS_Test_State::add_term( 87, 'Webáruház', 0 );
		WPS_Test_State::add_term( 111, 'Stencilek', 87 );
		WPS_Test_State::add_term( 28687, 'HDS15', 111 );

		update_term_meta( 111, Category_Rules::META_INCLUDE, Category_Rules::DECISION_INCLUDE );
		update_term_meta( 111, Category_Rules::META_INCLUDE_CHILDREN, '1' );

		WPS_Test_State::add_attachment( 810, 'https://example.test/img.jpg' );
		WPS_Test_State::$attachment_metadata[ 810 ] = array( 'width' => 1200, 'height' => 800 );

		$p = WPS_Test_State::add_product(
			array(
				'id'          => 7004,
				'name'        => 'Explicit HDS15 Override',
				'description' => 'Érvényes termékleírás',
				'price'       => 1300,
				'image_id'    => 810,
			)
		);
		WPS_Test_State::assign_term( 7004, 28687 );

		// Explicitly override to 28687 (merchant explicitly chooses child category).
		update_post_meta( 7004, Product_Mapper::META_PRIMARY_CATEGORY, 28687 );

		$mapped = Product_Mapper::map( 7004 );
		$path   = $mapped['categories'][0];
		WPS_Assert::same( 3, count( $path ) );
		WPS_Assert::same( 28687, end( $path )['id'] );

		// Explicitly override to ancestor 111 (Stencilek).
		update_post_meta( 7004, Product_Mapper::META_PRIMARY_CATEGORY, 111 );
		$mapped_anc = Product_Mapper::map( 7004 );
		$path_anc   = $mapped_anc['categories'][0];
		WPS_Assert::same( 2, count( $path_anc ) );
		WPS_Assert::same( 111, end( $path_anc )['id'] );
	}
);

/* =========================================================================
 * 4. Automatic Quality Issues Exclusion (exclude_quality_issues)
 * ========================================================================= */

$suite->add(
	'm9e exclusion: exclude_quality_issues excludes products with blocking issues and keeps compliant ones',
	static function (): void {
		WPS_Test_State::reset();

		WPS_Test_State::add_term( 100, 'Kategória 100' );

		// Valid image 1200x800.
		WPS_Test_State::add_attachment( 820, 'https://example.test/good.jpg' );
		WPS_Test_State::$attachment_metadata[ 820 ] = array( 'width' => 1200, 'height' => 800 );

		// Undersized image 500x500.
		WPS_Test_State::add_attachment( 821, 'https://example.test/bad.jpg' );
		WPS_Test_State::$attachment_metadata[ 821 ] = array( 'width' => 500, 'height' => 500 );

		// Product 1: Compliant image.
		$p1 = WPS_Test_State::add_product(
			array(
				'id'          => 7010,
				'name'        => 'Compliant Product',
				'description' => 'Érvényes termékleírás',
				'price'       => 5000,
				'sku'         => 'SKU-01',
				'image_id'    => 820,
			)
		);
		WPS_Test_State::assign_term( 7010, 100 );

		// Product 2: Undersized image, no gallery fallback.
		$p2 = WPS_Test_State::add_product(
			array(
				'id'          => 7011,
				'name'        => 'Bad Image Product',
				'description' => 'Érvényes termékleírás',
				'price'       => 5000,
				'sku'         => 'SKU-02',
				'image_id'    => 821,
			)
		);
		WPS_Test_State::assign_term( 7011, 100 );

		// Product 3: Missing SKU (informational only; compliant image).
		$p3 = WPS_Test_State::add_product(
			array(
				'id'          => 7012,
				'name'        => 'No SKU Product',
				'description' => 'Érvényes termékleírás',
				'price'       => 5000,
				'sku'         => '',
				'image_id'    => 820,
			)
		);
		WPS_Test_State::assign_term( 7012, 100 );

		// Case A: exclude_quality_issues is OFF (default).
		Settings::update( array( 'default_included' => true, 'exclude_quality_issues' => false ) );
		Product_Selection::clear_cache();

		WPS_Assert::true( Product_Selection::resolve( 7010 )['included'] );
		WPS_Assert::true( Product_Selection::resolve( 7011 )['included'] );
		WPS_Assert::true( Product_Selection::resolve( 7012 )['included'] );

		// Case B: exclude_quality_issues is ON.
		Settings::update( array( 'default_included' => true, 'exclude_quality_issues' => true ) );
		Product_Selection::clear_cache();

		$res1 = Product_Selection::resolve( 7010 );
		$res2 = Product_Selection::resolve( 7011 );
		$res3 = Product_Selection::resolve( 7012 );

		// Compliant product is included.
		WPS_Assert::true( $res1['included'] );

		// Product with undersized image is excluded for quality.
		WPS_Assert::false( $res2['included'] );
		WPS_Assert::same( Product_Selection::SOURCE_QUALITY_EXCLUDED, $res2['source'] );

		// Product with missing SKU is NOT excluded (informational issue only!).
		WPS_Assert::true( $res3['included'] );

		// In Hibás termékek, feed_status reflects the exclusion.
		$status2 = Product_Issues::feed_status( 7011 );
		WPS_Assert::same( 'quality_excluded', $status2['key'] );
		WPS_Assert::same( 'Kizárva a minőség miatt', $status2['label'] );
	}
);

$suite->add(
	'm9e exclusion: full and stock snapshots stay aligned when quality rules change',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update(
			array(
				'default_included'       => true,
				'exclude_quality_issues' => true,
				'batch_size'             => 10,
				'batches_per_run'        => 10,
			)
		);

		WPS_Test_State::add_term( 100, 'Kategória 100' );
		WPS_Test_State::add_attachment( 820, 'https://example.test/good.jpg' );
		WPS_Test_State::$attachment_metadata[ 820 ] = array( 'width' => 1200, 'height' => 800 );
		WPS_Test_State::add_attachment( 821, 'https://example.test/undersized.jpg' );
		WPS_Test_State::$attachment_metadata[ 821 ] = array( 'width' => 500, 'height' => 500 );

		foreach (
			array(
				7301 => array( 'name' => 'Megfelelő termék', 'image_id' => 820, 'price' => 1500, 'stock_quantity' => 35 ),
				7302 => array( 'name' => 'Kizárt termék', 'image_id' => 821, 'price' => 2300, 'stock_quantity' => 11 ),
			) as $id => $values
		) {
			WPS_Test_State::add_product(
				array_merge(
					array(
						'id'          => $id,
						'description' => 'Érvényes termékleírás',
						'sku'         => 'SKU-' . $id,
						'manage_stock' => true,
					),
					$values
				)
			);
			WPS_Test_State::assign_term( $id, 100 );
		}

		$run = static function ( string $type ): array {
			$generator = new Generator();
			$result    = $generator->run( $type, array( 'max_runtime' => 0 ) );
			$attempts  = 0;
			while ( 'running' === ( $result['status'] ?? '' ) && ++$attempts < 20 ) {
				$result = $generator->run( $type, array( 'max_runtime' => 0 ) );
			}
			return $result;
		};

		$full_result = $run( 'full' );
		WPS_Assert::same( 'completed', $full_result['status'] );
		$full_first   = file_get_contents( Storage::feed_path( 'full' ) );
		$stock_result = $run( 'stock' );
		WPS_Assert::same( 'completed', $stock_result['status'] );
		$stock_first = file_get_contents( Storage::feed_path( 'stock' ) );

		$rows = static function ( string $path ): array {
			$document = simplexml_load_file( $path );
			$namespace = 'https://pepita.hu/feed/1.0';
			$result = array();
			foreach ( $document->children( $namespace )->Product as $product ) {
				$id           = (string) $product->children( $namespace )->Id;
				$price        = (string) $product->children( $namespace )->Prices->children( $namespace )->Price;
				$quantity     = (string) $product->children( $namespace )->Availability->children( $namespace )->Quantity;
				$result[ $id ] = array(
					'price'    => $price,
					'quantity' => $quantity,
				);
			}
			return $result;
		};

		$full_rows_first  = $rows( Storage::feed_path( 'full' ) );
		$stock_rows_first = $rows( Storage::feed_path( 'stock' ) );
		WPS_Assert::same( array( 'wps-7301' ), array_keys( $full_rows_first ) );
		WPS_Assert::same( $full_rows_first, $stock_rows_first );

		// A stock-only publication must not silently adopt a new quality-filtered catalog.
		Settings::update( array( 'exclude_quality_issues' => false ) );
		Product_Selection::clear_cache();
		$blocked = $run( 'stock' );
		WPS_Assert::same( 'error', $blocked['status'] );
		WPS_Assert::same( $stock_first, file_get_contents( Storage::feed_path( 'stock' ) ) );

		// Refresh the full snapshot first, then its matching stock snapshot.
		WPS_Assert::same( 'completed', $run( 'full' )['status'] );
		WPS_Assert::same( 'completed', $run( 'stock' )['status'] );
		$full_rows  = $rows( Storage::feed_path( 'full' ) );
		$stock_rows = $rows( Storage::feed_path( 'stock' ) );
		WPS_Assert::same( array( 'wps-7301', 'wps-7302' ), array_keys( $full_rows ) );
		WPS_Assert::same( $full_rows, $stock_rows );
		WPS_Assert::true( $full_first !== file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::true( $stock_first !== file_get_contents( Storage::feed_path( 'stock' ) ) );
	}
);

$suite->add(
	'm9e exclusion: invalid image formats are listed and omitted from the full feed',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true, 'exclude_quality_issues' => true ) );
		WPS_Test_State::add_term( 100, 'Kategória 100' );

		foreach (
			array(
				8461 => array( 'name' => 'Kompatibilis képű termék', 'image' => 'https://example.test/good.jpg' ),
				8462 => array( 'name' => 'Nem támogatott képű termék', 'image' => 'https://example.test/bad.gif' ),
			) as $id => $values
		) {
			WPS_Test_State::add_attachment( $id, $values['image'] );
			WPS_Test_State::add_product(
				array(
					'id'          => $id,
					'name'        => $values['name'],
					'description' => 'Érvényes termékleírás',
					'price'       => 1000,
					'sku'         => 'SKU-' . $id,
					'image_id'    => $id,
				)
			);
			WPS_Test_State::assign_term( $id, 100 );
		}

		WPS_Assert::true( Product_Selection::is_included( 8461 ) );
		WPS_Assert::false( Product_Selection::is_included( 8462 ) );
		WPS_Assert::true(
			in_array( Product_Issues::ISSUE_IMAGE_INVALID, array_column( Product_Issues::inspect( 8462 ), 'code' ), true )
		);

		$generator = new Generator();
		$result    = $generator->run( 'full', array( 'max_runtime' => 0 ) );
		$attempts  = 0;
		while ( 'running' === ( $result['status'] ?? '' ) && ++$attempts < 20 ) {
			$result = $generator->run( 'full', array( 'max_runtime' => 0 ) );
		}
		WPS_Assert::same( 'completed', $result['status'] );
		$document = simplexml_load_file( Storage::feed_path( 'full' ) );
		$document->registerXPathNamespace( 'p', 'https://pepita.hu/feed/1.0' );
		$ids = array_map( 'strval', $document->xpath( '//p:Product/p:Id' ) );
		WPS_Assert::same( array( 'wps-8461' ), $ids );
	}
);

/* =========================================================================
 * 5. Product_Issues Registry & Feed Quality Rules
 * ========================================================================= */

$suite->add(
	'm9e issues: stock_quantity_capped does not appear in Hibás termékek',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true ) );

		WPS_Test_State::add_term( 100, 'Kategória 100' );
		WPS_Test_State::add_attachment( 830, 'https://example.test/img.jpg' );
		WPS_Test_State::$attachment_metadata[ 830 ] = array( 'width' => 1200, 'height' => 800 );

		// Product with capped stock (1 500 000 -> 999 999).
		$p = WPS_Test_State::add_product(
			array(
				'id'             => 7020,
				'name'           => 'Capped Stock Product',
				'description'    => 'Érvényes termékleírás',
				'price'          => 1000,
				'sku'            => 'CAPPED-01',
				'image_id'       => 830,
				'manage_stock'   => true,
				'stock_quantity' => 1500000,
			)
		);
		WPS_Test_State::assign_term( 7020, 100 );

		// Mapping produces stock_quantity_capped warning.
		$mapped = Product_Mapper::map( 7020 );
		WPS_Assert::same( 999999, $mapped['quantity'] );
		$has_warning = false;
		foreach ( $mapped['warnings'] as $w ) {
			if ( 'stock_quantity_capped' === ( $w['code'] ?? '' ) ) {
				$has_warning = true;
			}
		}
		WPS_Assert::true( $has_warning );

		// Product_Issues::inspect must NOT report stock_quantity_capped!
		$issues = Product_Issues::inspect( 7020 );
		foreach ( $issues as $issue ) {
			WPS_Assert::false( 'stock_quantity_capped' === $issue['code'] );
		}
		WPS_Assert::false( Product_Issues::has_blocking_issues( 7020 ) );
	}
);

/* =========================================================================
 * 6. FN-DECO 269 Baseline Acceptance: 28 Excluded, 241 Exported, #9897 Verified
 * ========================================================================= */

$suite->add(
	'm9e acceptance: FN-DECO 269 catalog baseline with automatic quality exclusion ON exports 241 products',
	static function (): void {
		WPS_Test_State::reset();
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 );
		Settings::update(
			array(
				'default_included'          => true,
				'exclude_quality_issues'    => true,
				'primary_category_strategy' => 'approved_parent',
				'price_tax_mode'            => 'gross',
			)
		);
		Product_Selection::clear_cache();

		// Category tree: 87 (Webáruház) -> 111 (Stencilek) -> 28687 (HDS15).
		WPS_Test_State::add_term( 87, 'Webáruház', 0 );
		WPS_Test_State::add_term( 111, 'Stencilek', 87 );
		WPS_Test_State::add_term( 28687, 'HDS15', 111 );

		update_term_meta( 111, Category_Rules::META_INCLUDE, Category_Rules::DECISION_INCLUDE );
		update_term_meta( 111, Category_Rules::META_INCLUDE_CHILDREN, '1' );

		// Attachments:
		// 901: Compliant image (1200 x 800)
		WPS_Test_State::add_attachment( 901, 'https://example.test/uploads/stencil-9897.jpg' );
		WPS_Test_State::$attachment_metadata[ 901 ] = array( 'width' => 1200, 'height' => 800 );

		// 902: Undersized image (600 x 400 < 1200x630)
		WPS_Test_State::add_attachment( 902, 'https://example.test/uploads/small.jpg' );
		WPS_Test_State::$attachment_metadata[ 902 ] = array( 'width' => 600, 'height' => 400 );

		// Seed #9897: "Stencil - Hópehely"
		$p9897 = WPS_Test_State::add_product(
			array(
				'id'             => 9897,
				'name'           => 'Stencil - Hópehely',
				'description'    => 'Részletgazdag műanyag stencil hópehely mintával.',
				'price'          => 1300,
				'sku'            => 'HDS15-016',
				'image_id'       => 901,
				'manage_stock'   => true,
				'stock_quantity' => 999846,
			)
		);
		WPS_Test_State::assign_term( 9897, 28687 );

		// Seed remaining 240 compliant products (total 241 compliant).
		for ( $i = 1; $i <= 240; $i++ ) {
			$pid = 10000 + $i;
			$p   = WPS_Test_State::add_product(
				array(
					'id'             => $pid,
					'name'           => sprintf( 'Compliant Stencil #%d', $i ),
					'description'    => sprintf( 'Leírás a stencilekhez #%d', $i ),
					'price'          => 1300,
					'sku'            => sprintf( 'SKU-%d', $i ),
					'image_id'       => 901,
					'manage_stock'   => true,
					'stock_quantity' => 50,
				)
			);
			WPS_Test_State::assign_term( $pid, 28687 );
		}

		// Seed 28 products with undersized images and no compliant gallery image (total 269 products).
		for ( $j = 1; $j <= 28; $j++ ) {
			$pid = 20000 + $j;
			$p   = WPS_Test_State::add_product(
				array(
					'id'             => $pid,
					'name'           => sprintf( 'Undersized Product #%d', $j ),
					'description'    => sprintf( 'Leírás kisméretű képű termékhez #%d', $j ),
					'price'          => 1200,
					'sku'            => sprintf( 'BAD-SKU-%d', $j ),
					'image_id'       => 902,
					'manage_stock'   => true,
					'stock_quantity' => 10,
				)
			);
			WPS_Test_State::assign_term( $pid, 28687 );
		}

		// 1. Verify exact counts across catalog.
		$total_products = 1 + 240 + 28; // 269 total.
		WPS_Assert::same( 269, $total_products );

		$eligible_count = 0;
		$excluded_count = 0;

		$all_products = array_merge( array( 9897 ), range( 10001, 10240 ), range( 20001, 20028 ) );

		foreach ( $all_products as $pid ) {
			if ( Product_Selection::is_included( $pid ) ) {
				$eligible_count++;
			} else {
				$excluded_count++;
			}
		}

		// Exactly 241 exported, 28 excluded!
		WPS_Assert::same( 241, $eligible_count );
		WPS_Assert::same( 28, $excluded_count );

		// 2. Verify exact mapping for #9897 "Stencil - Hópehely".
		$mapped9897 = Product_Mapper::map( 9897 );
		WPS_Assert::same( 'wps-9897', $mapped9897['export_id'] );
		WPS_Assert::same( 'HDS15-016', $mapped9897['mpn'] );
		WPS_Assert::equals( 1300.0, $mapped9897['price'] );
		WPS_Assert::equals( 27.0, $mapped9897['vat_percent'] );
		WPS_Assert::same( 999846, $mapped9897['quantity'] );

		// Category hierarchy: 87 -> 111 (Stencilek), omitting HDS15 (28687).
		WPS_Assert::same( 1, count( $mapped9897['categories'] ) );
		$cat_path = $mapped9897['categories'][0];
		WPS_Assert::same( 2, count( $cat_path ) );
		WPS_Assert::same( 87, $cat_path[0]['id'] );
		WPS_Assert::same( 'Webáruház', $cat_path[0]['name'] );
		WPS_Assert::same( 111, $cat_path[1]['id'] );
		WPS_Assert::same( 'Stencilek', $cat_path[1]['name'] );

		// 3. Verify Generator build_plan counts.
		$generator = new Generator();
		$ref       = new \ReflectionClass( $generator );
		$method    = $ref->getMethod( 'build_plan' );
		$method->setAccessible( true );

		$tmp_ids_file = sys_get_temp_dir() . '/wps_plan_test_' . uniqid() . '.txt';
		$plan         = $method->invoke( $generator, $tmp_ids_file );

		WPS_Assert::same( 241, $plan['eligible'] );
		WPS_Assert::same( 28, $plan['excluded'] );

		@unlink( $tmp_ids_file );
	}
);
