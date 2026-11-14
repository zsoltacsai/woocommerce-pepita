<?php
/**
 * M9-H Marketplace Inventory & Pricing Strategy test cases.
 *
 * Covers:
 * - Inventory buffer, max quantity, and rule precedence (product > parent > category > global).
 * - Unmanaged stock preservation (null), out-of-stock, and variable product aggregation.
 * - Pricing financial calculations: VAT, gross commission, operational fees, net margin.
 * - Missing cost price handling ('insufficient_data') and margin threshold warnings.
 * - Settings persistence reflection and sanitization.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Marketplace_Inventory;
use WPS\Pepita\Catalog\Marketplace_Pricing;
use WPS\Pepita\Catalog\Price_Calculator;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

$setup_taxonomy = static function (): void {
	WPS_Test_State::add_term( 201, 'Főkategória', 0, Category_Rules::TAXONOMY );
	WPS_Test_State::add_term( 202, 'Alkategória', 201, Category_Rules::TAXONOMY );
	WPS_Test_State::add_term( 203, 'Mély kategória', 202, Category_Rules::TAXONOMY );
};

/* -------------------------------------------------------------------------
 * 1. Inventory Strategy: Rule Precedence & Resolution
 * ---------------------------------------------------------------------- */

$suite->add(
	'strategy: inventory rule resolution precedence (product > category > global)',
	static function () use ( $setup_taxonomy ): void {
		$setup_taxonomy();

		// Configure global settings
		Settings::update(
			array(
				'inventory_strategy_enabled' => true,
				'inventory_buffer'           => 2,
				'inventory_max_qty'          => 50,
			)
		);

		// Global fallback
		$rule_global = Marketplace_Inventory::resolve_rules( 1001, array() );
		WPS_Assert::same( true, $rule_global['enabled'] );
		WPS_Assert::same( 2, $rule_global['buffer'] );
		WPS_Assert::same( 50, $rule_global['max_qty'] );
		WPS_Assert::same( 'global', $rule_global['source'] );

		// Category level override
		update_term_meta( 202, Marketplace_Inventory::TERM_META_BUFFER, 5 );
		update_term_meta( 202, Marketplace_Inventory::TERM_META_MAX_QTY, 20 );

		$rule_cat = Marketplace_Inventory::resolve_rules( 1002, array( 201, 202 ) );
		WPS_Assert::same( true, $rule_cat['enabled'] );
		WPS_Assert::same( 5, $rule_cat['buffer'] );
		WPS_Assert::same( 20, $rule_cat['max_qty'] );
		WPS_Assert::same( 'category', $rule_cat['source'] );

		// Deepest category wins
		update_term_meta( 203, Marketplace_Inventory::TERM_META_BUFFER, 8 );
		$rule_deep = Marketplace_Inventory::resolve_rules( 1003, array( 201, 202, 203 ) );
		WPS_Assert::same( 8, $rule_deep['buffer'] );
		WPS_Assert::same( 'category', $rule_deep['source'] );

		// Product level override takes highest precedence
		update_post_meta( 1004, Marketplace_Inventory::META_BUFFER, 12 );
		update_post_meta( 1004, Marketplace_Inventory::META_MAX_QTY, 100 );
		$rule_prod = Marketplace_Inventory::resolve_rules( 1004, array( 201, 202, 203 ) );
		WPS_Assert::same( 12, $rule_prod['buffer'] );
		WPS_Assert::same( 100, $rule_prod['max_qty'] );
		WPS_Assert::same( 'product', $rule_prod['source'] );

		// Explicit product disabling bypasses rules
		update_post_meta( 1005, Marketplace_Inventory::META_DISABLED, 'yes' );
		$rule_disabled = Marketplace_Inventory::resolve_rules( 1005, array( 201, 202 ) );
		WPS_Assert::same( false, $rule_disabled['enabled'] );
		WPS_Assert::same( 0, $rule_disabled['buffer'] );
		WPS_Assert::is_null( $rule_disabled['max_qty'] );
		WPS_Assert::same( 'product', $rule_disabled['source'] );
	}
);

/* -------------------------------------------------------------------------
 * 2. Inventory Strategy: Calculation Formulas & Boundaries
 * ---------------------------------------------------------------------- */

$suite->add(
	'strategy: inventory calculation formulas, buffer subtraction, max capping, and zero bounds',
	static function (): void {
		Settings::update(
			array(
				'inventory_strategy_enabled' => true,
				'inventory_buffer'           => 3,
				'inventory_max_qty'          => 15,
			)
		);

		// Normal case: 20 source - 3 buffer = 17 -> capped at max 15
		$p1 = WPS_Test_State::add_product(
			array(
				'id'             => 1010,
				'manage_stock'   => true,
				'stock_quantity' => 20,
				'stock_status'   => 'instock',
			)
		);
		WPS_Assert::same( 15, Marketplace_Inventory::calculate_quantity( $p1 ) );

		// Buffer applied without hitting max: 10 source - 3 buffer = 7
		$p2 = WPS_Test_State::add_product(
			array(
				'id'             => 1011,
				'manage_stock'   => true,
				'stock_quantity' => 10,
				'stock_status'   => 'instock',
			)
		);
		WPS_Assert::same( 7, Marketplace_Inventory::calculate_quantity( $p2 ) );

		// Stock lower than buffer: 2 source - 3 buffer = 0 (never negative)
		$p3 = WPS_Test_State::add_product(
			array(
				'id'             => 1012,
				'manage_stock'   => true,
				'stock_quantity' => 2,
				'stock_status'   => 'instock',
			)
		);
		WPS_Assert::same( 0, Marketplace_Inventory::calculate_quantity( $p3 ) );

		// Zero stock
		$p4 = WPS_Test_State::add_product(
			array(
				'id'             => 1013,
				'manage_stock'   => true,
				'stock_quantity' => 0,
				'stock_status'   => 'outofstock',
			)
		);
		WPS_Assert::same( 0, Marketplace_Inventory::calculate_quantity( $p4 ) );

		// Negative source stock (WooCommerce backorders) clamped to 0 unless backorderable
		$p5 = WPS_Test_State::add_product(
			array(
				'id'             => 1014,
				'manage_stock'   => true,
				'stock_quantity' => -5,
				'stock_status'   => 'outofstock',
			)
		);
		WPS_Assert::same( 0, Marketplace_Inventory::calculate_quantity( $p5 ) );

		// Out of stock status returns 0
		$p6 = WPS_Test_State::add_product(
			array(
				'id'             => 1015,
				'manage_stock'   => true,
				'stock_quantity' => 10,
				'stock_status'   => 'outofstock',
			)
		);
		WPS_Assert::same( 0, Marketplace_Inventory::calculate_quantity( $p6 ) );

		// Product never increases above source stock even if max_qty is higher
		update_post_meta( 1016, Marketplace_Inventory::META_MAX_QTY, 100 );
		update_post_meta( 1016, Marketplace_Inventory::META_BUFFER, 0 );
		$p7 = WPS_Test_State::add_product(
			array(
				'id'             => 1016,
				'manage_stock'   => true,
				'stock_quantity' => 25,
				'stock_status'   => 'instock',
			)
		);
		WPS_Assert::same( 25, Marketplace_Inventory::calculate_quantity( $p7 ) );
	}
);

$suite->add(
	'strategy: unmanaged stock returns null without numeric fabrication',
	static function (): void {
		Settings::update(
			array(
				'inventory_strategy_enabled' => true,
				'inventory_buffer'           => 5,
				'inventory_max_qty'          => 20,
			)
		);

		$product = WPS_Test_State::add_product(
			array(
				'id'           => 1020,
				'manage_stock' => false,
				'stock_status' => 'instock',
			)
		);

		WPS_Assert::is_null( Marketplace_Inventory::calculate_quantity( $product ) );
	}
);

/* -------------------------------------------------------------------------
 * 3. Variable Product Inventory Aggregation (No Double-Subtraction)
 * ---------------------------------------------------------------------- */

$suite->add(
	'strategy: variable product variation quantities and parent aggregation without double-subtraction',
	static function (): void {
		Settings::update(
			array(
				'inventory_strategy_enabled' => true,
				'inventory_buffer'           => 2,
				'inventory_max_qty'          => 50,
			)
		);

		WPS_Test_State::add_term( 10, 'Kategória' );
		WPS_Test_State::add_term( 401, 'Kicsi', 0, 'pa_meret' );
		WPS_Test_State::add_term( 402, 'Nagy', 0, 'pa_meret' );

		$parent = WPS_Test_State::add_product(
			array(
				'id'          => 1030,
				'name'        => 'Variációs kabát',
				'description' => 'Leírás',
				'type'        => 'variable',
				'price'       => '15000',
				'image_id'    => 500,
				'attributes'  => array(
					new WC_Product_Attribute(
						array(
							'name'      => 'pa_meret',
							'options'   => array( 'kicsi', 'nagy' ),
							'position'  => 0,
							'variation' => true,
							'taxonomy'  => true,
						)
					),
				),
			)
		);
		WPS_Test_State::assign_term( 1030, 10 );

		// Variation 1: 10 stock -> 10 - 2 = 8
		WPS_Test_State::add_variation(
			1030,
			array(
				'id'             => 1031,
				'price'          => '15000',
				'stock_status'   => 'instock',
				'manage_stock'   => true,
				'stock_quantity' => 10,
				'variation'      => array( 'attribute_pa_meret' => 'kicsi' ),
			)
		);

		// Variation 2: 5 stock -> 5 - 2 = 3
		WPS_Test_State::add_variation(
			1030,
			array(
				'id'             => 1032,
				'price'          => '15000',
				'stock_status'   => 'instock',
				'manage_stock'   => true,
				'stock_quantity' => 5,
				'variation'      => array( 'attribute_pa_meret' => 'nagy' ),
			)
		);

		$data = Product_Mapper::map( 1030 );

		// Parent quantity should be 8 + 3 = 11 (NOT 15 - 2 - 2 - 2)
		WPS_Assert::same( 11, $data['quantity'] );
		WPS_Assert::count( 2, $data['variations']['items'] );
	}
);

/* -------------------------------------------------------------------------
 * 4. Pricing Strategy: Financial Modeling & Formula Verification
 * ---------------------------------------------------------------------- */

$suite->add(
	'strategy: pricing formulas for VAT, gross commission, fees, net revenue, and margin',
	static function (): void {
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 ); // 27% Hungarian VAT
		update_option( 'woocommerce_prices_include_tax', 'yes' );

		Settings::update(
			array(
				'pricing_strategy_enabled'       => true,
				'marketplace_commission_percent' => 10.0,
				'marketplace_commission_fixed'   => 100.0,
				'marketplace_packaging_cost'     => 150.0,
				'marketplace_shipping_cost'      => 800.0,
				'marketplace_min_margin_percent' => 15.0,
			)
		);

		$product = WPS_Test_State::add_product(
			array(
				'id'    => 1040,
				'price' => '12700', // Gross selling price
			)
		);

		$cost_inputs = array(
			'commission_percent' => 10.0,
			'commission_fixed'   => 100.0,
			'packaging_cost'     => 150.0,
			'shipping_cost'      => 800.0,
			'cost_price'         => 6000.0,
			'min_margin'         => 15.0,
			'source'             => 'global',
		);

		$calc = Marketplace_Pricing::calculate( $product, 12700.0, $cost_inputs );

		// Gross: 12700
		WPS_Assert::equals( 12700.0, $calc['gross_price'] );
		// Net price: 12700 / 1.27 = 10000
		WPS_Assert::equals( 10000.0, $calc['net_price'] );
		// VAT amount: 2700
		WPS_Assert::equals( 2700.0, $calc['vat_amount'] );

		// Marketplace commission is based on gross price: (12700 * 0.10) + 100 = 1270 + 100 = 1370
		WPS_Assert::equals( 1370.0, $calc['commission_fee'] );

		// Operational fees: commission (1370) + packaging (150) + shipping (800) = 2320
		WPS_Assert::equals( 2320.0, $calc['operational_fees'] );

		// Net revenue after fees: 10000 - 2320 = 7680
		WPS_Assert::equals( 7680.0, $calc['net_revenue_after_fees'] );

		// Estimated profit: 7680 - 6000 (cost) = 1680
		WPS_Assert::equals( 1680.0, $calc['estimated_profit'] );

		// Estimated margin: (1680 / 10000) * 100 = 16.8%
		WPS_Assert::equals( 16.8, $calc['estimated_margin'] );

		// Min margin was 15%, so 16.8% is OK
		WPS_Assert::same( 'ok', $calc['margin_status'] );
	}
);

/* -------------------------------------------------------------------------
 * 5. Unknown Cost & Margin Below Threshold Safety Gate
 * ---------------------------------------------------------------------- */

$suite->add(
	'strategy: unknown cost reports insufficient_data without fabricating 0',
	static function (): void {
		$product = WPS_Test_State::add_product(
			array(
				'id'    => 1050,
				'price' => '5000',
			)
		);

		$cost_inputs = array(
			'commission_percent' => 12.0,
			'commission_fixed'   => 0.0,
			'packaging_cost'     => 100.0,
			'shipping_cost'      => 500.0,
			'cost_price'         => null, // Unknown cost
			'min_margin'         => 10.0,
			'source'             => 'global',
		);

		$calc = Marketplace_Pricing::calculate( $product, 5000.0, $cost_inputs );

		WPS_Assert::same( false, $calc['has_cost_data'] );
		WPS_Assert::is_null( $calc['cost_price'] );
		WPS_Assert::is_null( $calc['estimated_profit'] );
		WPS_Assert::is_null( $calc['estimated_margin'] );
		WPS_Assert::same( 'insufficient_data', $calc['margin_status'] );
	}
);

$suite->add(
	'strategy: margin below threshold flags below_threshold and adds mapper warning without price inflation',
	static function (): void {
		WPS_Test_State::$tax_enabled = false;

		Settings::update(
			array(
				'pricing_strategy_enabled'       => true,
				'marketplace_commission_percent' => 15.0,
				'marketplace_min_margin_percent' => 20.0,
			)
		);

		WPS_Test_State::add_term( 10, 'Kategória' );
		WPS_Test_State::add_attachment( 500, 'https://example.test/img.jpg' );

		$product = WPS_Test_State::add_product(
			array(
				'id'          => 1060,
				'name'        => 'Alacsony árrésű termék',
				'description' => 'Leírás',
				'price'       => '1000',
				'image_id'    => 500,
			)
		);
		WPS_Test_State::assign_term( 1060, 10 );

		// Set cost price to 900 -> Gross 1000, Comm 150 -> Net after fees 850, Cost 900 -> Profit -50 -> Margin -5%
		update_post_meta( 1060, Marketplace_Pricing::META_COST_PRICE, 900.0 );

		$data = Product_Mapper::map( 1060 );

		// WooCommerce source price must NEVER be inflated or mutated
		WPS_Assert::equals( 1000.0, $data['price'] );

		// Warning must be recorded for safety review
		$has_margin_warning = false;
		foreach ( $data['warnings'] as $warning ) {
			if ( 'margin_below_threshold' === $warning['code'] ) {
				$has_margin_warning = true;
				break;
			}
		}

		WPS_Assert::same( true, $has_margin_warning );
	}
);

/* -------------------------------------------------------------------------
 * 6. Settings Sanitization & Input Boundaries
 * ---------------------------------------------------------------------- */

$suite->add(
	'strategy: settings sanitization clamps percentages and ignores invalid inputs',
	static function (): void {
		Settings::update(
			array(
				'inventory_buffer'               => -5,   // Should be clamped to 0
				'inventory_max_qty'              => 'abc', // Should be null
				'marketplace_commission_percent' => 150.0, // Should be clamped to 100
				'marketplace_min_margin_percent' => -10.0, // Should be clamped to 0
			)
		);

		WPS_Assert::same( 0, (int) Settings::get( 'inventory_buffer' ) );
		WPS_Assert::is_null( Settings::get( 'inventory_max_qty' ) );
		WPS_Assert::equals( 100.0, (float) Settings::get( 'marketplace_commission_percent' ) );
		WPS_Assert::equals( 0.0, (float) Settings::get( 'marketplace_min_margin_percent' ) );
	}
);

$suite->add(
	'strategy: category pricing rule inheritance when product only defines cost of goods',
	static function () use ( $setup_taxonomy ): void {
		$setup_taxonomy();

		Settings::update(
			array(
				'marketplace_commission_percent' => 5.0,
				'marketplace_min_margin_percent' => 10.0,
			)
		);

		// Category 202 overrides commission to 15% and min margin to 25%
		Category_Rules::save(
			202,
			array(
				'commission_percent' => '15,5',
				'min_margin'         => '25,0',
			)
		);

		// Product has category 202 and only sets cost price
		$product = WPS_Test_State::add_product(
			array(
				'id'    => 1070,
				'price' => '10000',
			)
		);
		update_post_meta( 1070, Marketplace_Pricing::META_COST_PRICE, 4500.0 );

		$resolved = Marketplace_Pricing::resolve_cost_inputs( 1070, array( 201, 202 ) );

		// Product COGS must NOT wipe out category commission % or min margin
		WPS_Assert::equals( 15.5, $resolved['commission_percent'] );
		WPS_Assert::equals( 25.0, $resolved['min_margin'] );
		WPS_Assert::equals( 4500.0, $resolved['cost_price'] );
		WPS_Assert::same( 'category', $resolved['source'] );
	}
);

$suite->add(
	'strategy: admin strategy preview view renders cleanly without runtime warnings or errors',
	static function (): void {
		WPS_Test_State::$current_user_caps['manage_woocommerce'] = true;

		Settings::update(
			array(
				'inventory_strategy_enabled'     => true,
				'pricing_strategy_enabled'       => true,
				'marketplace_commission_percent' => 12.0,
				'marketplace_min_margin_percent' => 15.0,
			)
		);

		$admin = new Admin();

		ob_start();
		$admin->render_strategy();
		$html = ob_get_clean();

		WPS_Assert::true( is_string( $html ) && '' !== $html, 'Strategy view rendered output string' );
		WPS_Assert::true( false !== strpos( $html, 'Piactéri készlet- és árazási stratégia előnézet' ), 'Contains Hungarian view title' );
		WPS_Assert::true( false !== strpos( $html, 'wps-strategy-summary' ), 'Contains strategy summary container' );
		WPS_Assert::true( false !== strpos( $html, 'wps-table' ), 'Contains table container' );
		WPS_Assert::true( false !== strpos( $html, 'Készletstratégia állapota' ), 'Contains inventory summary card' );
		WPS_Assert::true( false !== strpos( $html, 'Árazási és árrés modell állapota' ), 'Contains pricing summary card' );
	}
);

$suite->add(
	'strategy: inventory buffer reducing stock to 0 sets available to false (no contradiction)',
	static function (): void {
		Settings::update(
			array(
				'inventory_strategy_enabled' => true,
				'inventory_buffer'           => 5,
			)
		);

		WPS_Test_State::add_term( 10, 'Kategória' );
		WPS_Test_State::add_attachment( 500, 'https://example.test/img.jpg' );

		// Product has 3 items in stock (instock), but buffer is 5 -> exported quantity is 0
		$product = WPS_Test_State::add_product(
			array(
				'id'             => 1080,
				'name'           => 'Buffer teszt termék',
				'description'    => 'Leírás',
				'price'          => '5000',
				'image_id'       => 500,
				'manage_stock'   => true,
				'stock_quantity' => 3,
				'stock_status'   => 'instock',
			)
		);
		WPS_Test_State::assign_term( 1080, 10 );

		$mapped = Product_Mapper::map( 1080 );

		WPS_Assert::same( 0, $mapped['quantity'] );
		// Available MUST be false when exported quantity is 0 and backorders are not allowed
		WPS_Assert::same( false, $mapped['available'] );
	}
);

$suite->add(
	'strategy: master switch off prevents rules bypass and simulation calculates proposed stock',
	static function (): void {
		// Global switch is OFF
		Settings::update(
			array(
				'inventory_strategy_enabled' => false,
				'inventory_buffer'           => 3,
			)
		);

		// Product has a direct buffer override of 4
		update_post_meta( 1090, Marketplace_Inventory::META_BUFFER, 4 );
		$product = WPS_Test_State::add_product(
			array(
				'id'             => 1090,
				'manage_stock'   => true,
				'stock_quantity' => 10,
				'stock_status'   => 'instock',
			)
		);

		// 1. Direct calculate_quantity without force_enable must NOT bypass master switch
		$rules = Marketplace_Inventory::resolve_rules( 1090, array() );
		WPS_Assert::same( false, $rules['enabled'], 'Master switch OFF forces resolved rule enabled to false' );
		WPS_Assert::same( 10, Marketplace_Inventory::calculate_quantity( $product ), 'Returns unadjusted raw stock when disabled' );

		// 2. Simulation mode ($force_enable = true) must calculate proposed stock (10 - 4 = 6)
		$simulated = Marketplace_Inventory::calculate_quantity( $product, array(), null, true );
		WPS_Assert::same( 6, $simulated, 'Simulation mode forces rule calculation even when master switch is OFF' );
	}
);

$suite->add(
	'strategy: generator safety gate trips on low margin even after 20 earlier warnings (uncapped counter)',
	static function (): void {
		Settings::update(
			array(
				'pricing_strategy_enabled'       => true,
				'marketplace_commission_percent' => 15.0,
				'marketplace_min_margin_percent' => 20.0,
				'feed_safety_require_approval'   => true,
			)
		);

		WPS_Test_State::add_term( 10, 'Kategória' );
		WPS_Test_State::add_attachment( 500, 'https://example.test/img.jpg' );

		// 1. Create 25 products with non-margin warnings (long name > 500 chars)
		$long_name = str_repeat( 'Hosszú terméknév teszt ', 30 );
		for ( $i = 1; $i <= 25; $i++ ) {
			WPS_Test_State::add_product(
				array(
					'id'          => 2000 + $i,
					'name'        => $long_name . ' ' . $i,
					'description' => 'Leírás',
					'price'       => '5000',
					'image_id'    => 500,
				)
			);
			WPS_Test_State::assign_term( 2000 + $i, 10 );
		}

		// 2. Product #26 has a margin below threshold warning
		WPS_Test_State::add_product(
			array(
				'id'          => 2026,
				'name'        => 'Alacsony árrés termék 26',
				'description' => 'Leírás',
				'price'       => '1000',
				'image_id'    => 500,
			)
		);
		WPS_Test_State::assign_term( 2026, 10 );
		update_post_meta( 2026, Marketplace_Pricing::META_COST_PRICE, 900.0 );

		// Run generator
		$generator = new \WPS\Pepita\Feed\Generator();
		$generator->run( \WPS\Pepita\Feed\Generator::TYPE_FULL, array( 'max_runtime' => 100 ) );

		// The uncapped counter must record the margin warning and stage for approval
		WPS_Assert::true( \WPS\Pepita\Storage::has_staged_full(), 'Feed must be staged for approval when margin is below threshold' );
		$staged = \WPS\Pepita\Storage::get_staged_full();
		WPS_Assert::true( null !== $staged, 'Staged metadata exists' );
		WPS_Assert::same( 'approval_required', $staged['diff']['overall_impact'] );

		// Cleanup
		\WPS\Pepita\Storage::discard_staged_full();
	}
);
