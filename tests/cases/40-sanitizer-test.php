<?php
/**
 * Description sanitising and GTIN normalisation.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Description_Sanitizer;

$suite = $GLOBALS['wps_suite'];

$suite->add(
	'sanitizer: HTML is converted to plain text',
	static function (): void {
		$clean = Description_Sanitizer::clean( '<p>Hello &amp; <strong>world</strong></p><script>alert(1)</script>' );

		WPS_Assert::same( "Hello & world", $clean );
		WPS_Assert::not_contains( 'alert', $clean );
		WPS_Assert::not_contains( '<', $clean );
	}
);

$suite->add(
	'sanitizer: shortcodes and embedded media are removed',
	static function (): void {
		$clean = Description_Sanitizer::clean( 'Első [gallery ids="1,2"] <iframe src="x"></iframe>második' );

		WPS_Assert::same( 'Első második', $clean );
	}
);

$suite->add(
	'sanitizer: line breaks are preserved but runs collapse',
	static function (): void {
		$clean = Description_Sanitizer::clean( "<p>One</p><p>Two</p><br><br><br>Three" );

		WPS_Assert::same( "One\nTwo\n\nThree", $clean );
	}
);

$suite->add(
	'sanitizer: single line output collapses whitespace',
	static function (): void {
		WPS_Assert::same( 'Samsung Galaxy S24', Description_Sanitizer::line( "  Samsung\n\tGalaxy   S24  " ) );
	}
);

$suite->add(
	'sanitizer: illegal XML control characters are removed',
	static function (): void {
		$clean = Description_Sanitizer::clean( "A\x01B\x0BC" );

		WPS_Assert::same( 'ABC', $clean );
	}
);

$suite->add(
	'sanitizer: GTIN accepts 8, 12, 13 and 14 digit codes',
	static function (): void {
		WPS_Assert::same( '5901234123457', Description_Sanitizer::gtin( '5901234123457' ) );
		WPS_Assert::same( '5901234123457', Description_Sanitizer::gtin( '590-1234-123457' ) );
		WPS_Assert::same( '0123456789123', Description_Sanitizer::gtin( '0123456789123' ) );
		WPS_Assert::same( '12345678', Description_Sanitizer::gtin( ' 123 456 78 ' ) );
	}
);

$suite->add(
	'sanitizer: invalid GTIN values are rejected instead of fabricated',
	static function (): void {
		WPS_Assert::same( '', Description_Sanitizer::gtin( '123' ) );
		WPS_Assert::same( '', Description_Sanitizer::gtin( 'abc' ) );
		WPS_Assert::same( '', Description_Sanitizer::gtin( '123456789012345' ) );
	}
);
