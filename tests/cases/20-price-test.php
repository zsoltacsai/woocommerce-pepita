<?php
/**
 * Price, VAT, adjustment and rounding rules.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Price_Calculator;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

$suite->add(
	'price: gross equals the raw price when taxes are disabled',
	static function (): void {
		WPS_Test_State::$tax_enabled = false;

		$product = WPS_Test_State::add_product(
			array(
				'id'    => 101,
				'price' => '1000',
			)
		);

		WPS_Assert::equals( 1000.0, Price_Calculator::gross( $product ) );
		WPS_Assert::equals( 0.0, Price_Calculator::vat_percent( $product ) );
	}
);

$suite->add(
	'price: gross adds the tax rate when prices are entered net',
	static function (): void {
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 );

		$product = WPS_Test_State::add_product(
			array(
				'id'    => 102,
				'price' => '1000',
			)
		);

		WPS_Assert::equals( 1270.0, Price_Calculator::gross( $product ) );
		WPS_Assert::equals( 27.0, Price_Calculator::vat_percent( $product ) );
	}
);

$suite->add(
	'price: gross returns the price unchanged when prices already include tax',
	static function (): void {
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 );
		update_option( 'woocommerce_prices_include_tax', 'yes' );

		$product = WPS_Test_State::add_product(
			array(
				'id'    => 103,
				'price' => '1270',
			)
		);

		WPS_Assert::equals( 1270.0, Price_Calculator::gross( $product ) );
		WPS_Assert::equals( 27.0, Price_Calculator::vat_percent( $product ) );
	}
);

$suite->add(
	'price: per class VAT rates are resolved and cached',
	static function (): void {
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 );
		WPS_Test_State::add_tax_rate( 'reduced', 5 );

		$standard = WPS_Test_State::add_product( array( 'id' => 104, 'price' => '100' ) );
		$reduced  = WPS_Test_State::add_product(
			array(
				'id'        => 105,
				'price'     => '100',
				'tax_class' => 'reduced',
			)
		);

		WPS_Assert::equals( 27.0, Price_Calculator::vat_percent( $standard ) );
		WPS_Assert::equals( 5.0, Price_Calculator::vat_percent( $reduced ) );
	}
);

$suite->add(
	'price: fixed VAT mode overrides the tax tables',
	static function (): void {
		WPS_Test_State::$tax_enabled = true;
		WPS_Test_State::add_tax_rate( '', 27 );

		Settings::update(
			array(
				'vat_mode'  => 'fixed',
				'vat_fixed' => 18,
			)
		);

		$product = WPS_Test_State::add_product( array( 'id' => 106, 'price' => '100' ) );

		WPS_Assert::equals( 18.0, Price_Calculator::vat_percent( $product ) );
	}
);

$suite->add(
	'price: percentage and fixed adjustments are applied to the gross price',
	static function (): void {
		WPS_Assert::equals( 1100.0, Price_Calculator::adjust( 1000.0, array( 'type' => 'percent', 'value' => 10 ) ) );
		WPS_Assert::equals( 900.0, Price_Calculator::adjust( 1000.0, array( 'type' => 'fixed', 'value' => -100 ) ) );
		WPS_Assert::equals( 0.0, Price_Calculator::adjust( 0.0, array( 'type' => 'percent', 'value' => 10 ) ) );
	}
);

$suite->add(
	'price: rounding modes follow the configured rule',
	static function (): void {
		Settings::update( array( 'rounding' => 'nearest_5' ) );
		WPS_Assert::equals( 1010.0, Price_Calculator::round( 1012.0 ) );

		Settings::update( array( 'rounding' => 'nearest_10' ) );
		WPS_Assert::equals( 1020.0, Price_Calculator::round( 1016.0 ) );

		Settings::update( array( 'rounding' => 'nearest_100' ) );
		WPS_Assert::equals( 1100.0, Price_Calculator::round( 1050.0 ) );

		Settings::update( array( 'rounding' => 'up_1' ) );
		WPS_Assert::equals( 1001.0, Price_Calculator::round( 1000.1 ) );

		Settings::update( array( 'rounding' => 'none' ) );
		WPS_Assert::equals( 1000.12, Price_Calculator::round( 1000.123 ) );
	}
);

$suite->add(
	'price: adjustment priority is product, then category, then global',
	static function (): void {
		WPS_Test_State::add_term( 10, 'Root' );
		WPS_Test_State::add_term( 11, 'Child', 10 );
		update_term_meta( 10, 'wps_price_adjustment_type', 'percent' );
		update_term_meta( 10, 'wps_price_adjustment_value', 10 );
		update_term_meta( 11, 'wps_price_adjustment_type', 'fixed' );
		update_term_meta( 11, 'wps_price_adjustment_value', 50 );

		$product = WPS_Test_State::add_product( array( 'id' => 107, 'price' => '1000' ) );
		WPS_Test_State::assign_term( 107, 11 );

		$adjustment = Price_Calculator::resolve_adjustment( 107, array( 11 ) );

		WPS_Assert::same( 'fixed', $adjustment['type'] );
		WPS_Assert::equals( 50.0, $adjustment['value'] );

		update_post_meta( 107, '_wps_price_adjustment_type', 'none' );
		update_post_meta( 107, '_wps_price_adjustment_value', 0 );

		$adjustment = Price_Calculator::resolve_adjustment( 107, array( 11 ) );

		WPS_Assert::same( 'none', $adjustment['type'] );

		Settings::update(
			array(
				'price_adjustment_type'  => 'percent',
				'price_adjustment_value' => 5,
			)
		);

		$adjustment = Price_Calculator::resolve_adjustment( 999, array() );

		WPS_Assert::same( 'percent', $adjustment['type'] );
		WPS_Assert::equals( 5.0, $adjustment['value'] );
	}
);

$suite->add(
	'price: net and format helpers produce stable strings',
	static function (): void {
		WPS_Assert::equals( 1000.0, Price_Calculator::net( 1270.0, 27.0 ) );
		WPS_Assert::equals( 100.0, Price_Calculator::net( 100.0, 0.0 ) );
		WPS_Assert::same( '12', Price_Calculator::format( 12.0 ) );
		WPS_Assert::same( '12.5', Price_Calculator::format( 12.5 ) );
		WPS_Assert::same( '12.35', Price_Calculator::format( 12.345 ) );
	}
);

$suite->add(
	'price: sale price is ignored when it is not lower than the price',
	static function (): void {
		WPS_Test_State::$tax_enabled = false;

		$product = WPS_Test_State::add_product(
			array(
				'id'         => 108,
				'price'      => '1000',
				'sale_price' => '1200',
			)
		);

		WPS_Assert::equals( 1200.0, Price_Calculator::gross_sale( $product ) );
	}
);
