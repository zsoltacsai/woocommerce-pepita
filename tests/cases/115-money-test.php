<?php
/**
 * Strict monetary, VAT and quantity parsing.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Orders\Money;

$suite = $GLOBALS['wps_suite'];

$suite->add(
	'money: canonical values parse without losing precision',
	static function (): void {
		WPS_Assert::equals( 4990.0, Money::parse( 4990 ) );
		WPS_Assert::equals( 4990.0, Money::parse( 4990.00 ) );
		WPS_Assert::equals( 4990.0, Money::parse( '4990' ) );
		WPS_Assert::equals( 4990.0, Money::parse( '4990.00' ) );
		WPS_Assert::equals( 12.5, Money::parse( '12.5' ) );
		WPS_Assert::equals( 0.01, Money::parse( '0.01' ) );
	}
);

$suite->add(
	'money: ambiguous and malformed values are rejected, never zeroed',
	static function (): void {
		$rejected = array(
			'4,990',
			'1 490',
			'1,490',
			'12,5',
			'',
			' ',
			'abc',
			'4990 HUF',
			'.5',
			'5.',
			'-1',
			'+1',
			'1e3',
			'0x10',
			'1.234',
			4990.123,
			-1,
			0,
			INF,
			NAN,
			null,
			array(),
			true,
		);

		foreach ( $rejected as $value ) {
			WPS_Assert::is_null( Money::parse( $value ), 'Value should be rejected: ' . var_export( $value, true ) );
		}

		WPS_Assert::equals( 0.0, Money::parse( 0, true ) );
		WPS_Assert::is_null( Money::parse( 1000000001 ) );
	}
);

$suite->add(
	'money: optional fields distinguish missing from malformed',
	static function (): void {
		WPS_Assert::equals( 0.0, Money::parse_optional( null ) );
		WPS_Assert::equals( 0.0, Money::parse_optional( 0 ) );
		WPS_Assert::equals( 1490.0, Money::parse_optional( '1490' ) );
		WPS_Assert::is_null( Money::parse_optional( '' ) );
		WPS_Assert::is_null( Money::parse_optional( '1 490' ) );
		WPS_Assert::is_null( Money::parse_optional( -5 ) );
	}
);

$suite->add(
	'money: VAT and quantity parsing are bounded',
	static function (): void {
		WPS_Assert::equals( 27.0, Money::parse_vat( 27 ) );
		WPS_Assert::equals( 0.0, Money::parse_vat( null ) );
		WPS_Assert::equals( 0.0, Money::parse_vat( '' ) );
		WPS_Assert::is_null( Money::parse_vat( 120 ) );
		WPS_Assert::is_null( Money::parse_vat( '27%' ) );
		WPS_Assert::is_null( Money::parse_vat( -1 ) );

		WPS_Assert::same( 1, Money::parse_quantity( 1 ) );
		WPS_Assert::same( 12, Money::parse_quantity( '12' ) );
		WPS_Assert::is_null( Money::parse_quantity( 0 ) );
		WPS_Assert::is_null( Money::parse_quantity( -1 ) );
		WPS_Assert::is_null( Money::parse_quantity( 1.5 ) );
		WPS_Assert::is_null( Money::parse_quantity( '1.0' ) );
		WPS_Assert::is_null( Money::parse_quantity( '1 0' ) );
		WPS_Assert::is_null( Money::parse_quantity( Money::MAX_QUANTITY + 1 ) );
	}
);
