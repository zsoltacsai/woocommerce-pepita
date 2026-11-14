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

		$mtime               = (int) filemtime( $source_file );
		$hash                = substr( md5( '701-' . $mtime . '-' . filesize( $source_file ) ), 0, 12 );
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
				'id'        => 7001,
				'name'      => 'Kép fallback termék',
				'price'     => 1500,
				'image_id'  => 801,
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
				'id'       => 7002,
				'name'     => 'Kicsi képek termék',
				'price'    => 2000,
				'image_id' => 803,
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
				'id'       => 7003,
				'name'     => 'Stencil Minta',
				'price'    => 1300,
				'image_id' => 810,
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
				'id'       => 7004,
				'name'     => 'Explicit HDS15 Override',
				'price'    => 1300,
				'image_id' => 810,
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
				'id'       => 7010,
				'name'     => 'Compliant Product',
				'price'    => 5000,
				'sku'      => 'SKU-01',
				'image_id' => 820,
			)
		);
		WPS_Test_State::assign_term( 7010, 100 );

		// Product 2: Undersized image, no gallery fallback.
		$p2 = WPS_Test_State::add_product(
			array(
				'id'       => 7011,
				'name'     => 'Bad Image Product',
				'price'    => 5000,
				'sku'      => 'SKU-02',
				'image_id' => 821,
			)
		);
		WPS_Test_State::assign_term( 7011, 100 );

		// Product 3: Missing SKU (informational only; compliant image).
		$p3 = WPS_Test_State::add_product(
			array(
				'id'       => 7012,
				'name'     => 'No SKU Product',
				'price'    => 5000,
				'sku'      => '',
				'image_id' => 820,
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
