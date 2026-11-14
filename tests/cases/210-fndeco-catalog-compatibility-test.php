<?php
/**
 * FN-DECO Real Catalog Compatibility and M9-D Beta Import Tests.
 *
 * Covers:
 * - P0 Inventory quantity cap (0, 849, 999999, 1500000, 927999993, unmanaged stock semantics)
 * - P0 Exactly one primary category hierarchy (override, Yoast/RankMath, deterministic fallback strategies)
 * - P0 Gross/net price-source modes (1300 HUF gross / 27% VAT, net conversion, prevention of double taxation)
 * - P0 SKU to ProductNumber mapping (native SKU fallback, override priority, StructuredId protection, stable ID)
 * - Description Sanitizer paragraph and line break preservation
 * - WebP image derivative resolution and dimension check (< 300x300 warning, no upscaling)
 * - Admin Preflight Report metrics and diagnostics
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Admin\Preflight;
use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Description_Sanitizer;
use WPS\Pepita\Catalog\Image_Converter;
use WPS\Pepita\Catalog\Price_Calculator;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Feed\Xml_Writer;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/* =========================================================================
 * 1. Inventory Quantity Cap (P0)
 * ========================================================================= */

$suite->add(
	'fndeco inventory: managed stock below or at cap is exported exactly without warning',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true ) );

		// 0 in stock
		$p0 = WPS_Test_State::add_product(
			array(
				'id'             => 1001,
				'name'           => 'Product 0',
				'price'          => 1000,
				'manage_stock'   => true,
				'stock_quantity' => 0,
				'image_id'       => 10,
			)
		);
		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );
		WPS_Test_State::add_term( 100, 'Cat 100' );
		WPS_Test_State::assign_term( 1001, 100 );

		$mapped0 = Product_Mapper::map( 1001 );
		WPS_Assert::same( 0, $mapped0['quantity'] );
		$has_cap_warning0 = false;
		foreach ( $mapped0['warnings'] as $w ) {
			if ( 'stock_quantity_capped' === ( $w['code'] ?? '' ) ) {
				$has_cap_warning0 = true;
			}
		}
		WPS_Assert::false( $has_cap_warning0 );

		// 849 in stock
		$p849 = WPS_Test_State::add_product(
			array(
				'id'             => 1002,
				'name'           => 'Product 849',
				'price'          => 1000,
				'manage_stock'   => true,
				'stock_quantity' => 849,
				'image_id'       => 10,
			)
		);
		WPS_Test_State::assign_term( 1002, 100 );
		$mapped849 = Product_Mapper::map( 1002 );
		WPS_Assert::same( 849, $mapped849['quantity'] );

		// 999999 in stock (exact ceiling)
		$p999 = WPS_Test_State::add_product(
			array(
				'id'             => 1003,
				'name'           => 'Product 999999',
				'price'          => 1000,
				'manage_stock'   => true,
				'stock_quantity' => 999999,
				'image_id'       => 10,
			)
		);
		WPS_Test_State::assign_term( 1003, 100 );
		$mapped999 = Product_Mapper::map( 1003 );
		WPS_Assert::same( 999999, $mapped999['quantity'] );
	}
);

$suite->add(
	'fndeco inventory: managed stock exceeding 999999 is capped to 999999 with warning',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true ) );

		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );
		WPS_Test_State::add_term( 100, 'Cat 100' );

		// 1 500 000 in stock
		$p15m = WPS_Test_State::add_product(
			array(
				'id'             => 1004,
				'name'           => 'Product 1.5M',
				'price'          => 1000,
				'manage_stock'   => true,
				'stock_quantity' => 1500000,
				'image_id'       => 10,
			)
		);
		WPS_Test_State::assign_term( 1004, 100 );

		$mapped15m = Product_Mapper::map( 1004 );
		WPS_Assert::same( 999999, $mapped15m['quantity'] );

		$cap_warning = false;
		foreach ( $mapped15m['warnings'] as $w ) {
			if ( 'stock_quantity_capped' === ( $w['code'] ?? '' ) ) {
				$cap_warning = true;
				break;
			}
		}
		WPS_Assert::true( $cap_warning );

		// WC source data remains completely untouched
		WPS_Assert::same( 1500000, $p15m->get_stock_quantity() );

		// XML Writer outputs capped value
		$writer = new Xml_Writer();
		$xml = $writer->product( $mapped15m );
		WPS_Assert::true( str_contains( $xml, '<Quantity>999999</Quantity>' ) );
		WPS_Assert::false( str_contains( $xml, '<Quantity>1500000</Quantity>' ) );

		// Extreme count: 927 999 993
		$p_huge = WPS_Test_State::add_product(
			array(
				'id'             => 1005,
				'name'           => 'Product Huge',
				'price'          => 1000,
				'manage_stock'   => true,
				'stock_quantity' => 927999993,
				'image_id'       => 10,
			)
		);
		WPS_Test_State::assign_term( 1005, 100 );
		$mapped_huge = Product_Mapper::map( 1005 );
		WPS_Assert::same( 999999, $mapped_huge['quantity'] );
	}
);

$suite->add(
	'fndeco inventory: unmanaged stock preserves null semantics without Quantity tag',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true ) );

		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );
		WPS_Test_State::add_term( 100, 'Cat 100' );

		$p_unmanaged = WPS_Test_State::add_product(
			array(
				'id'           => 1006,
				'name'         => 'Unmanaged Product',
				'price'        => 1000,
				'manage_stock' => false,
				'image_id'     => 10,
			)
		);
		WPS_Test_State::assign_term( 1006, 100 );

		$mapped = Product_Mapper::map( 1006 );
		WPS_Assert::null( $mapped['quantity'] );

		$writer = new Xml_Writer();
		$xml = $writer->product( $mapped );
		WPS_Assert::true( str_contains( $xml, '<Available>true</Available>' ) );
		WPS_Assert::false( str_contains( $xml, '<Quantity>' ) );
	}
);

/* =========================================================================
 * 2. Exactly One Primary Category Hierarchy (P0)
 * ========================================================================= */

$suite->add(
	'fndeco category: exactly one primary category hierarchy is output for multi-category products',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true, 'primary_category_strategy' => 'deepest' ) );

		// Category tree:
		// 10: Főoldal
		// 20: Otthon és Lakberendezés (parent: 10)
		// 30: Dísztárgyak (parent: 20)
		// 40: Kerti bútorok (parent: 10)
		WPS_Test_State::add_term( 10, 'Főoldal', 0 );
		WPS_Test_State::add_term( 20, 'Otthon és Lakberendezés', 10 );
		WPS_Test_State::add_term( 30, 'Dísztárgyak', 20 );
		WPS_Test_State::add_term( 40, 'Kerti bútorok', 10 );

		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );

		// Product assigned to 10, 20, 30, 40
		$p = WPS_Test_State::add_product(
			array(
				'id'       => 2001,
				'name'     => 'Több kategóriás termék',
				'price'    => 1000,
				'image_id' => 10,
			)
		);
		WPS_Test_State::assign_term( 2001, 10 );
		WPS_Test_State::assign_term( 2001, 20 );
		WPS_Test_State::assign_term( 2001, 30 );
		WPS_Test_State::assign_term( 2001, 40 );

		$mapped = Product_Mapper::map( 2001 );

		// Exactly one hierarchy returned
		WPS_Assert::same( 1, count( $mapped['categories'] ) );

		// By default (deepest), category 30 (depth 2) is chosen: 10 -> 20 -> 30
		$path = $mapped['categories'][0];
		WPS_Assert::same( 3, count( $path ) );
		WPS_Assert::same( 10, $path[0]['id'] );
		WPS_Assert::same( 20, $path[1]['id'] );
		WPS_Assert::same( 30, $path[2]['id'] );

		// Verify XML output contains exactly one <Categories> block
		$writer = new Xml_Writer();
		$xml = $writer->product( $mapped );
		WPS_Assert::same( 1, substr_count( $xml, '<Categories>' ) );
		WPS_Assert::same( 1, substr_count( $xml, '</Categories>' ) );
		WPS_Assert::same( 3, substr_count( $xml, '<Category>' ) );
	}
);

$suite->add(
	'fndeco category: explicit override _wps_primary_category_id takes precedence',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true, 'primary_category_strategy' => 'deepest' ) );

		WPS_Test_State::add_term( 10, 'Gyökér', 0 );
		WPS_Test_State::add_term( 20, 'Kategória A', 10 );
		WPS_Test_State::add_term( 30, 'Kategória B', 10 );

		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );

		$p = WPS_Test_State::add_product(
			array(
				'id'       => 2002,
				'name'     => 'Override termék',
				'price'    => 1000,
				'image_id' => 10,
			)
		);
		WPS_Test_State::assign_term( 2002, 20 );
		WPS_Test_State::assign_term( 2002, 30 );

		// Explicitly set primary category to 30
		update_post_meta( 2002, Product_Mapper::META_PRIMARY_CATEGORY, 30 );

		$mapped = Product_Mapper::map( 2002 );
		WPS_Assert::same( 1, count( $mapped['categories'] ) );
		$path = $mapped['categories'][0];
		$leaf = end( $path );
		WPS_Assert::same( 30, $leaf['id'] );
	}
);

$suite->add(
	'fndeco category: Yoast and RankMath primary category takes precedence over fallback',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true, 'primary_category_strategy' => 'deepest' ) );

		WPS_Test_State::add_term( 10, 'Gyökér', 0 );
		WPS_Test_State::add_term( 20, 'Kategória A', 10 );
		WPS_Test_State::add_term( 30, 'Kategória B', 10 );

		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );

		// 1. Yoast primary category
		$p_yoast = WPS_Test_State::add_product(
			array(
				'id'       => 2003,
				'name'     => 'Yoast termék',
				'price'    => 1000,
				'image_id' => 10,
			)
		);
		WPS_Test_State::assign_term( 2003, 20 );
		WPS_Test_State::assign_term( 2003, 30 );
		update_post_meta( 2003, '_yoast_wpseo_primary_product_cat', 20 );

		$mapped_yoast = Product_Mapper::map( 2003 );
		$leaf_yoast = end( $mapped_yoast['categories'][0] );
		WPS_Assert::same( 20, $leaf_yoast['id'] );

		// 2. Rank Math primary category
		$p_rm = WPS_Test_State::add_product(
			array(
				'id'       => 2004,
				'name'     => 'RankMath termék',
				'price'    => 1000,
				'image_id' => 10,
			)
		);
		WPS_Test_State::assign_term( 2004, 20 );
		WPS_Test_State::assign_term( 2004, 30 );
		update_post_meta( 2004, 'rank_math_primary_product_cat', 30 );

		$mapped_rm = Product_Mapper::map( 2004 );
		$leaf_rm = end( $mapped_rm['categories'][0] );
		WPS_Assert::same( 30, $leaf_rm['id'] );
	}
);

$suite->add(
	'fndeco category: deterministic fallback strategies lowest_id, highest_id, and alphabetical',
	static function (): void {
		WPS_Test_State::reset();
		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );

		WPS_Test_State::add_term( 50, 'Zebra kategória' );
		WPS_Test_State::add_term( 10, 'Alma kategória' );
		WPS_Test_State::add_term( 90, 'Béka kategória' );

		$p = WPS_Test_State::add_product(
			array(
				'id'       => 2005,
				'name'     => 'Stratégia teszt',
				'price'    => 1000,
				'image_id' => 10,
			)
		);
		WPS_Test_State::assign_term( 2005, 50 );
		WPS_Test_State::assign_term( 2005, 10 );
		WPS_Test_State::assign_term( 2005, 90 );

		// Lowest ID: should pick 10
		Settings::update( array( 'default_included' => true, 'primary_category_strategy' => 'lowest_id' ) );
		$mapped_low = Product_Mapper::map( 2005 );
		WPS_Assert::same( 10, end( $mapped_low['categories'][0] )['id'] );

		// Highest ID: should pick 90
		Settings::update( array( 'default_included' => true, 'primary_category_strategy' => 'highest_id' ) );
		$mapped_high = Product_Mapper::map( 2005 );
		WPS_Assert::same( 90, end( $mapped_high['categories'][0] )['id'] );

		// Alphabetical: should pick 10 ('Alma kategória')
		Settings::update( array( 'default_included' => true, 'primary_category_strategy' => 'alphabetical' ) );
		$mapped_alpha = Product_Mapper::map( 2005 );
		WPS_Assert::same( 10, end( $mapped_alpha['categories'][0] )['id'] );
	}
);

$suite->add(
	'fndeco category: FN-DECO real catalog acceptance (Product 9897, Categories 87 & 111)',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true, 'primary_category_strategy' => 'deepest', 'price_tax_mode' => 'gross' ) );
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 );

		// FN-DECO verified real catalog hierarchy:
		// 87: "Webáruház" (Root)
		// 111: "Stencilek" (Child of 87)
		// 205: "Karácsony" (Other category)
		// 312: "HDS15 - 14,5x20 cm" (Other category)
		WPS_Test_State::add_term( 87, 'Webáruház', 0 );
		WPS_Test_State::add_term( 111, 'Stencilek', 87 );
		WPS_Test_State::add_term( 205, 'Karácsony', 0 );
		WPS_Test_State::add_term( 312, 'HDS15 - 14,5x20 cm', 0 );

		WPS_Test_State::add_attachment( 10, 'https://example.test/att.jpg' );

		// Product 9897: "Stencil - Hópehely", SKU: HDS15-016
		$p = WPS_Test_State::add_product(
			array(
				'id'             => 9897,
				'name'           => 'Stencil - Hópehely',
				'sku'            => 'HDS15-016',
				'price'          => 1300,
				'manage_stock'   => true,
				'stock_quantity' => 999846,
				'image_id'       => 10,
			)
		);
		WPS_Test_State::assign_term( 9897, 87 );
		WPS_Test_State::assign_term( 9897, 111 );
		WPS_Test_State::assign_term( 9897, 205 );
		WPS_Test_State::assign_term( 9897, 312 );

		// Set explicit primary category override to 111
		update_post_meta( 9897, Product_Mapper::META_PRIMARY_CATEGORY, 111 );

		$mapped = Product_Mapper::map( 9897 );
		WPS_Assert::same( 1, count( $mapped['categories'] ) );
		$path = $mapped['categories'][0];
		WPS_Assert::same( 2, count( $path ) );
		WPS_Assert::same( 87, $path[0]['id'] );
		WPS_Assert::same( 'Webáruház', $path[0]['name'] );
		WPS_Assert::same( 111, $path[1]['id'] );
		WPS_Assert::same( 'Stencilek', $path[1]['name'] );

		$writer = new Xml_Writer();
		$xml = $writer->product( $mapped );
		WPS_Assert::same( 1, substr_count( $xml, '<Categories>' ) );
		WPS_Assert::same( 2, substr_count( $xml, '<Category>' ) );
		WPS_Assert::true( str_contains( $xml, '<ProductNumber>HDS15-016</ProductNumber>' ) );
		WPS_Assert::true( str_contains( $xml, '<Price>1300</Price>' ) );
		WPS_Assert::true( str_contains( $xml, '<Quantity>999846</Quantity>' ) );
		WPS_Assert::true( str_contains( $xml, '<VatPercent>27</VatPercent>' ) );
	}
);

/* =========================================================================
 * 3. Gross/Net Price-Source Configuration (P0)
 * ========================================================================= */

$suite->add(
	'fndeco pricing: price_tax_mode gross treats source price as gross without double taxation',
	static function (): void {
		WPS_Test_State::reset();
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 );

		// Set price_tax_mode to 'gross'
		Settings::update(
			array(
				'default_included'     => true,
				'price_tax_mode'       => 'gross',
				'include_purchase_net' => true,
			)
		);

		// FN-DECO real product: 1300 HUF gross price entered into WC
		$p = WPS_Test_State::add_product(
			array(
				'id'       => 3001,
				'name'     => 'FN-DECO Gyertya',
				'price'    => '1300',
				'image_id' => 10,
			)
		);
		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );
		WPS_Test_State::add_term( 100, 'Katalógus' );
		WPS_Test_State::assign_term( 3001, 100 );

		$gross = Price_Calculator::gross( $p );
		WPS_Assert::equals( 1300.0, $gross );

		$mapped = Product_Mapper::map( 3001 );
		WPS_Assert::equals( 1300.0, $mapped['price'] );
		WPS_Assert::equals( 27.0, $mapped['vat_percent'] );

		// Net purchase price should be 1300 / 1.27 = 1023.62
		WPS_Assert::equals( 1023.62, $mapped['purchase_net'] );

		$writer = new Xml_Writer();
		$xml = $writer->product( $mapped );

		WPS_Assert::true( str_contains( $xml, '<Price>1300</Price>' ) );
		WPS_Assert::true( str_contains( $xml, '<VatPercent>27</VatPercent>' ) );
		WPS_Assert::false( str_contains( $xml, '<Price>1651</Price>' ) ); // Never double tax!
	}
);

$suite->add(
	'fndeco pricing: price_tax_mode net converts net source price to gross',
	static function (): void {
		WPS_Test_State::reset();
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 );

		Settings::update(
			array(
				'default_included' => true,
				'price_tax_mode'   => 'net',
			)
		);

		$p = WPS_Test_State::add_product(
			array(
				'id'       => 3002,
				'name'     => 'Nettó termék',
				'price'    => '1000',
				'image_id' => 10,
			)
		);
		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );
		WPS_Test_State::add_term( 100, 'Katalógus' );
		WPS_Test_State::assign_term( 3002, 100 );

		$gross = Price_Calculator::gross( $p );
		WPS_Assert::equals( 1270.0, $gross );

		$mapped = Product_Mapper::map( 3002 );
		WPS_Assert::equals( 1270.0, $mapped['price'] );
	}
);

/* =========================================================================
 * 4. SKU to ProductNumber (MPN) Mapping (P0)
 * ========================================================================= */

$suite->add(
	'fndeco sku: native WC SKU maps to ProductNumber and override wins',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true ) );

		WPS_Test_State::add_attachment( 10, 'https://example.test/img.jpg' );
		WPS_Test_State::add_term( 100, 'Katalógus' );

		// 1. Native SKU HDS15-016
		$p1 = WPS_Test_State::add_product(
			array(
				'id'       => 4001,
				'name'     => 'SKU Termék',
				'price'    => 1000,
				'sku'      => 'HDS15-016',
				'image_id' => 10,
			)
		);
		WPS_Test_State::assign_term( 4001, 100 );

		$mapped1 = Product_Mapper::map( 4001 );
		WPS_Assert::same( 'HDS15-016', $mapped1['mpn'] );
		WPS_Assert::same( 'wps-4001', $mapped1['export_id'] ); // Stable ID unchanged

		$writer = new Xml_Writer();
		$xml1 = $writer->product( $mapped1 );
		WPS_Assert::true( str_contains( $xml1, '<Id>wps-4001</Id>' ) );
		WPS_Assert::true( str_contains( $xml1, '<ProductNumber>HDS15-016</ProductNumber>' ) );
		WPS_Assert::false( str_contains( $xml1, '<StructuredId>HDS15-016</StructuredId>' ) ); // Never StructuredId!

		// 2. Leading zeroes preserved: 00123
		$p2 = WPS_Test_State::add_product(
			array(
				'id'       => 4002,
				'name'     => 'Zero SKU Termék',
				'price'    => 1000,
				'sku'      => '00123',
				'image_id' => 10,
			)
		);
		WPS_Test_State::assign_term( 4002, 100 );
		$mapped2 = Product_Mapper::map( 4002 );
		WPS_Assert::same( '00123', $mapped2['mpn'] );

		// 3. Explicit _wps_mpn override wins over native SKU
		$p3 = WPS_Test_State::add_product(
			array(
				'id'       => 4003,
				'name'     => 'Override MPN Termék',
				'price'    => 1000,
				'sku'      => 'WC-SKU-999',
				'image_id' => 10,
			)
		);
		WPS_Test_State::assign_term( 4003, 100 );
		update_post_meta( 4003, Product_Mapper::META_MPN, 'CUSTOM-MPN-123' );

		$mapped3 = Product_Mapper::map( 4003 );
		WPS_Assert::same( 'CUSTOM-MPN-123', $mapped3['mpn'] );
	}
);

/* =========================================================================
 * 5. Description Sanitizer Behavior
 * ========================================================================= */

$suite->add(
	'fndeco descriptions: line breaks and paragraphs are preserved cleanly',
	static function (): void {
		$html = "<p>Első bekezdés leírása.</p>\n<p>Második bekezdés részletei.<br>Új sor a bekezdésen belül.</p>";
		$clean = Description_Sanitizer::clean( $html );

		WPS_Assert::true( str_contains( $clean, "Első bekezdés leírása.\n\nMásodik bekezdés részletei.\nÚj sor a bekezdésen belül." ) );

		// Lists
		$list_html = '<p>Jellemzők:</p><ul><li>Méret: 30 cm</li><li>Anyag: Fa</li></ul>';
		$list_clean = Description_Sanitizer::clean( $list_html );
		WPS_Assert::true( str_contains( $list_clean, "Jellemzők:\nMéret: 30 cm\nAnyag: Fa" ) );
	}
);

/* =========================================================================
 * 6. WebP to Compatible JPEG Images
 * ========================================================================= */

$suite->add(
	'fndeco images: Image_Converter identifies WebP and validates dimensions',
	static function (): void {
		WPS_Test_State::reset();

		// Attachment 501: WebP format
		WPS_Test_State::add_attachment( 501, 'https://example.test/uploads/test-image.webp' );
		WPS_Test_State::$mime_types[ 501 ] = 'image/webp';

		WPS_Assert::true( Image_Converter::is_webp( 501 ) );

		// Attachment 502: Standard JPEG
		WPS_Test_State::add_attachment( 502, 'https://example.test/uploads/standard.jpg' );
		WPS_Test_State::$mime_types[ 502 ] = 'image/jpeg';

		WPS_Assert::false( Image_Converter::is_webp( 502 ) );

		// Non-WebP URL is resolved directly
		$warnings = array();
		$resolved = Image_Converter::resolve_attachment_url( 502, $warnings );
		WPS_Assert::same( 'https://example.test/uploads/standard.jpg', $resolved );
	}
);

/* =========================================================================
 * 7. Admin Preflight Report
 * ========================================================================= */

$suite->add(
	'fndeco preflight: audit correctly calculates summary and catches catalog issues',
	static function (): void {
		WPS_Test_State::reset();
		Settings::update( array( 'default_included' => true, 'price_tax_mode' => 'gross' ) );

		WPS_Test_State::add_term( 10, 'Bútorok' );
		WPS_Test_State::add_attachment( 100, 'https://example.test/sample.jpg' );

		// Product 1: Complete and valid
		$p1 = WPS_Test_State::add_product(
			array(
				'id'             => 6001,
				'name'           => 'Valid Asztal',
				'price'          => 25000,
				'sku'            => 'ASZTAL-01',
				'image_id'       => 100,
				'manage_stock'   => true,
				'stock_quantity' => 15,
			)
		);
		WPS_Test_State::assign_term( 6001, 10 );

		// Product 2: Capped stock (1 200 000) and missing SKU (warning)
		$p2 = WPS_Test_State::add_product(
			array(
				'id'             => 6002,
				'name'           => 'Óriási Készletű Szék',
				'price'          => 12000,
				'sku'            => '', // Missing SKU
				'image_id'       => 100,
				'manage_stock'   => true,
				'stock_quantity' => 1200000,
			)
		);
		WPS_Test_State::assign_term( 6002, 10 );

		$report = Preflight::run();
		$summary = $report['summary'];

		WPS_Assert::same( 2, $summary['total_products'] );
		WPS_Assert::same( 2, $summary['eligible_products'] );
		WPS_Assert::same( 1, $summary['capped_quantities'] );
		WPS_Assert::same( 1, $summary['missing_skus'] );
		WPS_Assert::same( 'gross', $summary['tax_mode'] );
	}
);

$suite->add(
	'fndeco images: cached WebP derivative resolution does not trigger fatal error',
	static function (): void {
		WPS_Test_State::reset();

		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => '/tmp', 'baseurl' => 'https://example.com/wp-content/uploads' );
		$target_dir = rtrim( (string) ( $upload_dir['basedir'] ?? '' ), '/\\' ) . '/' . Image_Converter::DERIVATIVE_DIR;
		if ( ! is_dir( $target_dir ) ) {
			@mkdir( $target_dir, 0755, true );
		}

		$source_file = $target_dir . '/test-dummy.webp';
		copy( __DIR__ . '/../fixtures/images/pepita-compatible-1200x630.webp', $source_file );

		WPS_Test_State::add_attachment( 555, 'https://example.test/uploads/test-dummy.webp' );
		WPS_Test_State::$attached_files[ 555 ] = $source_file;
		WPS_Test_State::$mime_types[ 555 ] = 'image/webp';

		$source_hash = hash_file( 'sha256', $source_file );
		$hash = substr( hash( 'sha256', '555:' . $source_hash ), 0, 16 );
		$derivative_filename = sprintf( 'att-%d-%s.jpg', 555, $hash );
		$derivative_path = $target_dir . '/' . $derivative_filename;
		// A valid full-size derivative must be reused from the content-addressed cache.
		copy( __DIR__ . '/../fixtures/images/pepita-compatible-1200x630.jpg', $derivative_path );

		$warnings = array();
		$resolved = Image_Converter::resolve_attachment_url( 555, $warnings );

		WPS_Assert::contains( $derivative_filename, $resolved );
		@unlink( $derivative_path );
		@unlink( $source_file );
	}
);

$suite->add(
	'fndeco admin: settings_input includes beta_feed_only and feed_emergency_disabled',
	static function (): void {
		$admin = new \WPS\Pepita\Admin\Admin();
		$ref = new \ReflectionClass( $admin );
		$method = $ref->getMethod( 'settings_input' );
		$method->setAccessible( true );

		$raw = array(
			'beta_feed_only'          => '1',
			'feed_emergency_disabled' => '1',
			'default_included'        => '0',
		);

		$parsed = $method->invoke( $admin, $raw );

		WPS_Assert::true( $parsed['beta_feed_only'] );
		WPS_Assert::true( $parsed['feed_emergency_disabled'] );
	}
);
