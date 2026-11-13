<?php
/**
 * Beta feed-only mode: category allowlist enforcement tests (Phase 1).
 *
 * Verifies that in beta feed-only mode:
 * - Category selection acts as a strict hard allowlist.
 * - Only merchant-approved categories are exported.
 * - Explicit product inclusion cannot bypass category exclusion or lack of category approval.
 * - Overlapping category membership (included + excluded) excludes the product.
 * - Child category inheritance behaves strictly according to wps_include_children.
 * - Zero approved categories halts generation safely without publishing empty/broken feeds.
 * - Full and stock feeds export the identical product universe.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

$build_category_tree = static function (): void {
	WPS_Test_State::reset();

	// Tree: 10 (Root Electronics) -> 11 (Smartphones) -> 12 (Accessories)
	// Tree: 20 (Home & Garden) -> 21 (Furniture)
	WPS_Test_State::add_term( 10, 'Root Electronics' );
	WPS_Test_State::add_term( 11, 'Smartphones', 10 );
	WPS_Test_State::add_term( 12, 'Accessories', 11 );
	WPS_Test_State::add_term( 20, 'Home & Garden' );
	WPS_Test_State::add_term( 21, 'Furniture', 20 );
};

$suite->add(
	'beta category: helper methods identify approved and excluded categories',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Settings::update( array( 'beta_feed_only' => true ) );

		WPS_Assert::false( Category_Rules::has_included_categories() );
		WPS_Assert::same( 0, Category_Rules::count_included_categories() );
		WPS_Assert::same( array(), Category_Rules::get_included_category_ids() );
		WPS_Assert::same( array(), Category_Rules::get_excluded_category_ids() );

		// Approve root electronics with children
		update_term_meta( 10, 'wps_include', 'include' );
		update_term_meta( 10, 'wps_include_children', '1' );

		// Explicitly exclude furniture
		update_term_meta( 21, 'wps_include', 'exclude' );
		Category_Rules::clear_cache();

		WPS_Assert::true( Category_Rules::has_included_categories() );
		// Root (10), Child (11), Grandchild (12) all have include decision
		WPS_Assert::same( 3, Category_Rules::count_included_categories() );
		WPS_Assert::same( array( 10, 11, 12 ), Category_Rules::get_included_category_ids() );
		WPS_Assert::same( array( 21 ), Category_Rules::get_excluded_category_ids() );
	}
);

$suite->add(
	'beta category: products in unapproved categories are excluded in beta mode',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Settings::update( array( 'beta_feed_only' => true ) );

		// Approve only electronics
		update_term_meta( 10, 'wps_include', 'include' );
		update_term_meta( 10, 'wps_include_children', '1' );
		Category_Rules::clear_cache();

		// Product 501 in Electronics (11) -> included
		WPS_Test_State::add_product( array( 'id' => 501, 'name' => 'Phone' ) );
		WPS_Test_State::assign_term( 501, 11 );

		// Product 502 in Furniture (21) -> not approved -> excluded
		WPS_Test_State::add_product( array( 'id' => 502, 'name' => 'Sofa' ) );
		WPS_Test_State::assign_term( 502, 21 );

		// Product 503 uncategorized -> excluded
		WPS_Test_State::add_product( array( 'id' => 503, 'name' => 'Uncategorized item' ) );

		$res501 = Product_Selection::resolve( 501 );
		WPS_Assert::true( $res501['included'] );
		WPS_Assert::same( 'category', $res501['source'] );

		$res502 = Product_Selection::resolve( 502 );
		WPS_Assert::false( $res502['included'] );
		WPS_Assert::same( 'beta_unapproved_category', $res502['source'] );

		$res503 = Product_Selection::resolve( 503 );
		WPS_Assert::false( $res503['included'] );
		WPS_Assert::same( 'beta_unapproved_category', $res503['source'] );
	}
);

$suite->add(
	'beta category: explicit product inclusion cannot bypass category exclusion or unapproved status',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Settings::update( array( 'beta_feed_only' => true ) );

		// Approve only electronics (10)
		update_term_meta( 10, 'wps_include', 'include' );
		update_term_meta( 10, 'wps_include_children', '1' );
		Category_Rules::clear_cache();

		// Product 504 in unapproved category Furniture (21), marked as explicitly included on product level
		WPS_Test_State::add_product( array( 'id' => 504, 'name' => 'VIP Table' ) );
		WPS_Test_State::assign_term( 504, 21 );
		update_post_meta( 504, '_wps_include', 'include' );

		// In beta mode, this MUST be excluded
		$res504 = Product_Selection::resolve( 504 );
		WPS_Assert::false( $res504['included'] );
		WPS_Assert::same( 'beta_unapproved_category', $res504['source'] );

		// Product 505 in explicit excluded category, marked with product include
		update_term_meta( 20, 'wps_include', 'exclude' );
		Category_Rules::clear_cache();
		WPS_Test_State::add_product( array( 'id' => 505, 'name' => 'VIP Garden' ) );
		WPS_Test_State::assign_term( 505, 20 );
		update_post_meta( 505, '_wps_include', 'include' );

		$res505 = Product_Selection::resolve( 505 );
		WPS_Assert::false( $res505['included'] );
		WPS_Assert::same( 'category', $res505['source'] );

		// Product 506 uncategorized, marked with product include
		WPS_Test_State::add_product( array( 'id' => 506, 'name' => 'VIP Orphan' ) );
		update_post_meta( 506, '_wps_include', 'include' );

		$res506 = Product_Selection::resolve( 506 );
		WPS_Assert::false( $res506['included'] );
		WPS_Assert::same( 'beta_unapproved_category', $res506['source'] );

		// However, product 507 in approved category with product include IS included
		WPS_Test_State::add_product( array( 'id' => 507, 'name' => 'VIP Smartphone' ) );
		WPS_Test_State::assign_term( 507, 11 );
		update_post_meta( 507, '_wps_include', 'include' );

		$res507 = Product_Selection::resolve( 507 );
		WPS_Assert::true( $res507['included'] );
		WPS_Assert::same( 'product_included', $res507['source'] );
	}
);

$suite->add(
	'beta category: overlapping category membership excludes product if any assigned category is excluded',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Settings::update( array( 'beta_feed_only' => true ) );

		// Electronics (10) included, Furniture (21) excluded
		update_term_meta( 10, 'wps_include', 'include' );
		update_term_meta( 10, 'wps_include_children', '1' );
		update_term_meta( 21, 'wps_include', 'exclude' );
		Category_Rules::clear_cache();

		// Product 508 assigned to BOTH Electronics (11) and Furniture (21)
		WPS_Test_State::add_product( array( 'id' => 508, 'name' => 'Smart Desk' ) );
		WPS_Test_State::assign_term( 508, 11 );
		WPS_Test_State::assign_term( 508, 21 );

		$res508 = Product_Selection::resolve( 508 );
		WPS_Assert::false( $res508['included'] );
		WPS_Assert::same( 'category', $res508['source'] );
	}
);

$suite->add(
	'beta category: child inheritance respect include_children flag in beta mode',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Settings::update( array( 'beta_feed_only' => true ) );

		// Root (10) included but include_children = 0
		update_term_meta( 10, 'wps_include', 'include' );
		update_term_meta( 10, 'wps_include_children', '0' );
		Category_Rules::clear_cache();

		// Product in root (10) is included
		WPS_Test_State::add_product( array( 'id' => 509, 'name' => 'Root item' ) );
		WPS_Test_State::assign_term( 509, 10 );
		WPS_Assert::true( Product_Selection::resolve( 509 )['included'] );

		// Product in child (11) is NOT included because inheritance was disabled -> DECISION_NONE -> beta_unapproved_category
		WPS_Test_State::add_product( array( 'id' => 510, 'name' => 'Child item' ) );
		WPS_Test_State::assign_term( 510, 11 );
		$res510 = Product_Selection::resolve( 510 );
		WPS_Assert::false( $res510['included'] );
		WPS_Assert::same( 'beta_unapproved_category', $res510['source'] );
	}
);

$suite->add(
	'beta category: zero approved categories blocks feed generation from starting',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Storage::purge();
		Settings::update( array( 'beta_feed_only' => true ) );

		// No categories approved at all
		WPS_Test_State::add_product( array( 'id' => 511, 'name' => 'Item', 'price' => 1000 ) );
		WPS_Test_State::assign_term( 511, 10 );

		$generator = new Generator();
		$state = $generator->run( Generator::TYPE_FULL );

		WPS_Assert::same( 'error', $state['status'] );
		$msg = (string) ( $state['message'] ?? $state['error'] ?? '' );
		WPS_Assert::contains( 'legalább egy', $msg );

		// Feed file should not exist
		WPS_Assert::false( is_file( Storage::feed_path( 'full' ) ) );
	}
);

$suite->add(
	'beta category: unexpected category deletion or missing term fails closed without crashing',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Settings::update( array( 'beta_feed_only' => true ) );

		// Approve root electronics
		update_term_meta( 10, 'wps_include', 'include' );
		Category_Rules::clear_cache();

		// Product assigned to nonexistent category 999
		WPS_Test_State::add_product( array( 'id' => 512, 'name' => 'Ghost category product' ) );
		WPS_Test_State::assign_term( 512, 999 );

		$res512 = Product_Selection::resolve( 512 );
		WPS_Assert::false( $res512['included'] );
		WPS_Assert::same( 'beta_unapproved_category', $res512['source'] );
	}
);

$suite->add(
	'beta category: full and stock feed share the exact same approved catalog selection',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Storage::purge();
		Settings::update( array( 'beta_feed_only' => true, 'feed_secret' => 'test-secret' ) );

		// Approve category 11 (smartphones)
		update_term_meta( 11, 'wps_include', 'include' );
		Category_Rules::clear_cache();
		WPS_Test_State::add_attachment( 500, 'https://example.test/phone.jpg' );

		// Add product in 11 (allowed)
		WPS_Test_State::add_product(
			array(
				'id'          => 520,
				'name'        => 'Allowed Smartphone',
				'description' => 'Great phone description',
				'sku'         => 'SKU-520',
				'price'       => '15000',
				'stock'       => 5,
				'image_id'    => 500,
			)
		);
		WPS_Test_State::assign_term( 520, 11 );

		// Add product in 21 (furniture - unapproved)
		WPS_Test_State::add_product(
			array(
				'id'          => 521,
				'name'        => 'Unapproved Couch',
				'description' => 'Comfortable couch',
				'sku'         => 'SKU-521',
				'price'       => '50000',
				'stock'       => 2,
				'image_id'    => 500,
			)
		);
		WPS_Test_State::assign_term( 521, 21 );

		$generator = new Generator();
		$full_state = $generator->run( Generator::TYPE_FULL, array( 'max_runtime' => 60 ) );
		WPS_Assert::same( 'completed', $full_state['status'] );

		// Check full feed XML
		$xml_content = (string) file_get_contents( Storage::feed_path( Generator::TYPE_FULL ) );
		WPS_Assert::contains( 'Allowed Smartphone', $xml_content );
		WPS_Assert::not_contains( 'Unapproved Couch', $xml_content );

		// Now run stock feed
		$stock_state = $generator->run( Generator::TYPE_STOCK, array( 'max_runtime' => 60 ) );
		WPS_Assert::same( 'completed', $stock_state['status'] );

		// Check stock feed XML
		$stock_content = (string) file_get_contents( Storage::feed_path( Generator::TYPE_STOCK ) );
		$eid520 = Product_Mapper::export_id( 520 );
		$eid521 = Product_Mapper::export_id( 521 );

		WPS_Assert::true( ! empty( $eid520 ) );
		WPS_Assert::contains( "<Id>{$eid520}</Id>", $stock_content );
		if ( ! empty( $eid521 ) ) {
			WPS_Assert::not_contains( "<Id>{$eid521}</Id>", $stock_content );
		}
	}
);

$suite->add(
	'beta category: category deletion and rule changes invalidate cache and re-resolve accurately',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Settings::update( array( 'beta_feed_only' => true ) );

		// Approve category 11
		update_term_meta( 11, 'wps_include', 'include' );
		Category_Rules::clear_cache();

		WPS_Test_State::add_product( array( 'id' => 601, 'name' => 'Dynamic Item' ) );
		WPS_Test_State::assign_term( 601, 11 );

		// Initially approved
		$res1 = Product_Selection::resolve( 601 );
		WPS_Assert::true( $res1['included'] );

		// Rule change: change 11 to exclude
		update_term_meta( 11, 'wps_include', 'exclude' );
		Product_Selection::clear_cache();

		$res2 = Product_Selection::resolve( 601 );
		WPS_Assert::false( $res2['included'] );
		WPS_Assert::same( 'category', $res2['source'] );

		// Rule change: remove meta (unapproved)
		delete_term_meta( 11, 'wps_include' );
		Product_Selection::clear_cache();

		$res3 = Product_Selection::resolve( 601 );
		WPS_Assert::false( $res3['included'] );
		WPS_Assert::same( 'beta_unapproved_category', $res3['source'] );
	}
);

$suite->add(
	'beta category: preview counts count_selected matches exportable products in beta mode',
	static function () use ( $build_category_tree ): void {
		$build_category_tree();
		Settings::update( array( 'beta_feed_only' => true ) );

		// Approve only category 11
		update_term_meta( 11, 'wps_include', 'include' );
		Category_Rules::clear_cache();

		// Add 3 products in approved category 11
		for ( $i = 701; $i <= 703; ++$i ) {
			WPS_Test_State::add_product( array( 'id' => $i, 'name' => "Approved Item {$i}" ) );
			WPS_Test_State::assign_term( $i, 11 );
		}

		// Add 2 products in unapproved category 21
		for ( $i = 704; $i <= 705; ++$i ) {
			WPS_Test_State::add_product( array( 'id' => $i, 'name' => "Unapproved Item {$i}" ) );
			WPS_Test_State::assign_term( $i, 21 );
		}

		$counts = Product_Selection::count_selected();
		// Scanned total: 5
		WPS_Assert::same( 5, $counts['scanned'] );
		// Selected (exportable): exactly 3
		WPS_Assert::same( 3, $counts['selected'] );
	}
);
