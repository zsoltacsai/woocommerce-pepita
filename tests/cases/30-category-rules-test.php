<?php
/**
 * Category rule resolution, inheritance and priority.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/**
 * Build a small category tree: 10 (root) -> 11 (child) -> 12 (grandchild).
 */
$build_tree = static function (): void {
	WPS_Test_State::add_term( 10, 'Root' );
	WPS_Test_State::add_term( 11, 'Child', 10 );
	WPS_Test_State::add_term( 12, 'Grandchild', 11 );
};

$suite->add(
	'category: include is inherited by children when the flag allows it',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_include', 'include' );

		WPS_Assert::same( 'include', Category_Rules::decision( 10 ) );
		WPS_Assert::same( 'include', Category_Rules::decision( 11 ) );
		WPS_Assert::same( 'include', Category_Rules::decision( 12 ) );
	}
);

$suite->add(
	'category: include is not inherited when the parent disables child inheritance',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_include', 'include' );
		update_term_meta( 10, 'wps_include_children', '0' );

		WPS_Assert::same( 'include', Category_Rules::decision( 10 ) );
		WPS_Assert::same( 'none', Category_Rules::decision( 11 ) );
		WPS_Assert::same( 'none', Category_Rules::decision( 12 ) );
	}
);

$suite->add(
	'category: exclude is always inherited',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_include', 'exclude' );
		update_term_meta( 10, 'wps_include_children', '1' );

		WPS_Assert::same( 'exclude', Category_Rules::decision( 11 ) );
		WPS_Assert::same( 'exclude', Category_Rules::decision( 12 ) );
	}
);

$suite->add(
	'category: the deepest explicit rule wins',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_include', 'include' );
		update_term_meta( 12, 'wps_include', 'exclude' );

		WPS_Assert::same( 'include', Category_Rules::decision( 11 ) );
		WPS_Assert::same( 'exclude', Category_Rules::decision( 12 ) );
	}
);

$suite->add(
	'category: exclusion wins when a product is in included and excluded categories',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_include', 'include' );
		update_term_meta( 12, 'wps_include', 'exclude' );

		$decision = Category_Rules::decision_for_terms( array( 11, 12 ) );

		WPS_Assert::same( 'exclude', $decision['decision'] );
		WPS_Assert::same( 12, $decision['category_id'] );
	}
);

$suite->add(
	'category: category level price adjustment uses the deepest category',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_price_adjustment_type', 'percent' );
		update_term_meta( 10, 'wps_price_adjustment_value', 10 );
		update_term_meta( 12, 'wps_price_adjustment_type', 'fixed' );
		update_term_meta( 12, 'wps_price_adjustment_value', 5 );

		$adjustment = Category_Rules::price_adjustment( array( 12, 10 ) );

		WPS_Assert::same( 'fixed', $adjustment['type'] );
		WPS_Assert::equals( 5.0, $adjustment['value'] );
	}
);

$suite->add(
	'category: shipping delay and GTIN requirement resolve through ancestors',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_shipping_delay', 3 );
		update_term_meta( 12, 'wps_shipping_delay', 5 );
		update_term_meta( 11, 'wps_gtin_required', '1' );

		WPS_Assert::same( 5, Category_Rules::shipping_delay( array( 12, 10 ) ) );
		WPS_Assert::same( 3, Category_Rules::shipping_delay( array( 10 ) ) );
		WPS_Assert::true( Category_Rules::gtin_required( array( 12 ) ) );
		WPS_Assert::true( Category_Rules::gtin_required( array( 11, 12 ) ) );
	}
);

$suite->add(
	'category: GTIN requirement from settings applies to descendants',
	static function () use ( $build_tree ): void {
		$build_tree();
		Settings::update( array( 'gtin_required_categories' => array( 10 ) ) );

		WPS_Assert::true( Category_Rules::gtin_required( array( 12 ) ) );
		WPS_Assert::true( Category_Rules::gtin_required( array( 11 ) ) );
	}
);

$suite->add(
	'category: save persists and removes category meta',
	static function () use ( $build_tree ): void {
		$build_tree();

		Category_Rules::save(
			10,
			array(
				'include'                => 'include',
				'include_children'       => '1',
				'gtin_required'          => '1',
				'price_adjustment_type'  => 'percent',
				'price_adjustment_value' => '7.5',
				'shipping_delay'         => '4',
			)
		);

		WPS_Assert::same( 'include', get_term_meta( 10, 'wps_include', true ) );
		WPS_Assert::same( '1', get_term_meta( 10, 'wps_gtin_required', true ) );
		WPS_Assert::equals( 7.5, (float) get_term_meta( 10, 'wps_price_adjustment_value', true ) );
		WPS_Assert::same( 4, (int) get_term_meta( 10, 'wps_shipping_delay', true ) );

		Category_Rules::save( 10, array( 'include' => 'inherit' ) );

		WPS_Assert::same( '', get_term_meta( 10, 'wps_include', true ) );
	}
);

$suite->add(
	'selection: explicit product exclusion beats explicit inclusion and category rules',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_include', 'include' );

		WPS_Test_State::add_product( array( 'id' => 201, 'name' => 'Excluded' ) );
		WPS_Test_State::assign_term( 201, 11 );
		update_post_meta( 201, '_wps_include', 'exclude' );

		$resolution = Product_Selection::resolve( 201 );

		WPS_Assert::same( false, $resolution['included'] );
		WPS_Assert::same( 'product_excluded', $resolution['source'] );
	}
);

$suite->add(
	'selection: explicit product inclusion beats category exclusion',
	static function () use ( $build_tree ): void {
		$build_tree();
		update_term_meta( 10, 'wps_include', 'exclude' );

		WPS_Test_State::add_product( array( 'id' => 202, 'name' => 'Included' ) );
		WPS_Test_State::assign_term( 202, 11 );
		update_post_meta( 202, '_wps_include', 'include' );

		$resolution = Product_Selection::resolve( 202 );

		WPS_Assert::same( true, $resolution['included'] );
		WPS_Assert::same( 'product_included', $resolution['source'] );
	}
);

$suite->add(
	'selection: category rules beat the global default',
	static function () use ( $build_tree ): void {
		$build_tree();
		Settings::update( array( 'default_included' => true ) );

		WPS_Test_State::add_product( array( 'id' => 203, 'name' => 'Categorised' ) );
		WPS_Test_State::assign_term( 203, 11 );
		update_term_meta( 10, 'wps_include', 'exclude' );

		$resolution = Product_Selection::resolve( 203 );

		WPS_Assert::same( false, $resolution['included'] );
		WPS_Assert::same( 'category', $resolution['source'] );
	}
);

$suite->add(
	'selection: global default applies when no rule matches',
	static function (): void {
		Settings::update( array( 'default_included' => true ) );

		WPS_Test_State::add_product( array( 'id' => 204, 'name' => 'No rule' ) );

		$resolution = Product_Selection::resolve( 204 );

		WPS_Assert::same( true, $resolution['included'] );
		WPS_Assert::same( 'default', $resolution['source'] );
	}
);

$suite->add(
	'selection: bulk set updates inclusion and clears the cache',
	static function (): void {
		WPS_Test_State::add_product( array( 'id' => 205, 'name' => 'Bulk' ) );

		WPS_Assert::false( Product_Selection::is_included( 205 ) );

		$changed = Product_Selection::bulk_set( array( 205, 0, 205 ), 'include' );

		WPS_Assert::same( 1, $changed );
		WPS_Assert::true( Product_Selection::is_included( 205 ) );

		Product_Selection::bulk_set( array( 205 ), 'default' );

		WPS_Assert::false( Product_Selection::is_included( 205 ) );
	}
);
