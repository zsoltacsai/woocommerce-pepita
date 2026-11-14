<?php
/**
 * Settings defaults, sanitisation and URL helpers.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

$suite->add(
	'settings: defaults contain every documented key',
	static function (): void {
		$defaults = Settings::defaults();

		foreach ( array( 'default_included', 'include_child_categories', 'show_internal_quality_score', 'vat_mode', 'price_adjustment_type', 'rounding', 'removal_policy', 'batch_size', 'full_feed_interval', 'stock_feed_interval', 'payment_map', 'shipping_map', 'order_api_status_paid' ) as $key ) {
			WPS_Assert::true( array_key_exists( $key, $defaults ), 'Missing default: ' . $key );
		}

		WPS_Assert::same( false, $defaults['default_included'] );
		WPS_Assert::same( false, $defaults['show_internal_quality_score'] );
		WPS_Assert::same( 'omit', $defaults['removal_policy'] );
		WPS_Assert::same( array( 'publish' ), $defaults['allowed_product_statuses'] );
		WPS_Assert::same( 20, $defaults['feed_safety_drop_threshold'] );
		WPS_Assert::same( false, $defaults['feed_safety_block_publication'] );
		WPS_Assert::same( true, $defaults['feed_safety_variation_change_warning'] );
	}
);

$suite->add(
	'settings: sanitize clamps integers, floats and enums',
	static function (): void {
		$clean = Settings::sanitize(
			array(
				'batch_size'            => 5,
				'batches_per_run'       => 1000,
				'full_feed_interval'    => 999,
				'stock_feed_interval'   => 1,
				'vat_fixed'             => 250,
				'vat_mode'              => 'bogus',
				'price_adjustment_type' => 'percent',
				'price_adjustment_value' => -5,
				'rounding'              => 'nearest_5',
				'default_included'      => '1',
				'include_child_categories' => '0',
				'feed_safety_drop_threshold' => 120,
				'feed_safety_block_publication' => '1',
				'feed_safety_variation_change_warning' => '0',
				'show_internal_quality_score' => '1',
				'log_level'             => 'verbose',
				'removal_policy'        => 'zero_stock',
			)
		);

		WPS_Assert::same( 10, $clean['batch_size'] );
		WPS_Assert::same( 100, $clean['batches_per_run'] );
		WPS_Assert::same( 168, $clean['full_feed_interval'] );
		WPS_Assert::same( 5, $clean['stock_feed_interval'] );
		WPS_Assert::same( 95, $clean['feed_safety_drop_threshold'] );
		WPS_Assert::same( true, $clean['feed_safety_block_publication'] );
		WPS_Assert::same( false, $clean['feed_safety_variation_change_warning'] );
		WPS_Assert::same( true, $clean['show_internal_quality_score'] );
		WPS_Assert::equals( 100.0, $clean['vat_fixed'] );
		WPS_Assert::same( 'auto', $clean['vat_mode'] );
		WPS_Assert::same( 'percent', $clean['price_adjustment_type'] );
		WPS_Assert::equals( -5.0, $clean['price_adjustment_value'] );
		WPS_Assert::same( 'nearest_5', $clean['rounding'] );
		WPS_Assert::same( true, $clean['default_included'] );
		WPS_Assert::same( false, $clean['include_child_categories'] );
		WPS_Assert::same( 'info', $clean['log_level'] );
		WPS_Assert::same( 'zero_stock', $clean['removal_policy'] );
	}
);

$suite->add(
	'settings: currency is normalised to a 3 letter ISO code',
	static function (): void {
		WPS_Assert::same( 'EUR', Settings::sanitize( array( 'currency' => 'eur' ) )['currency'] );
		WPS_Assert::same( '', Settings::sanitize( array( 'currency' => 'forint' ) )['currency'] );
		WPS_Assert::same( '', Settings::sanitize( array( 'currency' => 'EURO' ) )['currency'] );
	}
);

$suite->add(
	'settings: payment and shipping maps only keep documented keys',
	static function (): void {
		$clean = Settings::sanitize(
			array(
				'payment_map'  => array( 'cod' => 'cheque', 'unknown' => 'x' ),
				'shipping_map' => array( 'gls' => 'gls_method' ),
			)
		);

		WPS_Assert::same( array( 'cod', 'transfer', 'creditcard' ), array_keys( $clean['payment_map'] ) );
		WPS_Assert::same( 'cheque', $clean['payment_map']['cod'] );
		WPS_Assert::same( 'bacs', $clean['payment_map']['transfer'] );
		WPS_Assert::same( array( 'shipping', 'gls', 'gls_parcellocker', 'gls_xxl', 'mpl' ), array_keys( $clean['shipping_map'] ) );
		WPS_Assert::same( 'gls_method', $clean['shipping_map']['gls'] );
	}
);

$suite->add(
	'settings: tokenised feed URL contains the secret, public URL does not',
	static function (): void {
		Settings::update(
			array(
				'feed_token'  => 'token-123',
				'feed_public' => false,
			)
		);

		WPS_Assert::same( 'https://example.test/?wps_feed=full&token=token-123', Settings::feed_url( 'full' ) );

		Settings::update( array( 'feed_public' => true ) );

		WPS_Assert::same( 'https://example.test/?wps_feed=stock', Settings::feed_url( 'stock' ) );
	}
);

$suite->add(
	'settings: feed intervals are clamped by the accessors',
	static function (): void {
		Settings::update(
			array(
				'full_feed_interval'  => 2,
				'stock_feed_interval' => 5,
			)
		);

		WPS_Assert::same( 2 * HOUR_IN_SECONDS, Settings::full_feed_interval() );
		WPS_Assert::same( 5 * MINUTE_IN_SECONDS, Settings::stock_feed_interval() );
	}
);

$suite->add(
	'settings: GTIN meta key list and required categories are normalised',
	static function (): void {
		Settings::update(
			array(
				'gtin_meta_keys'           => ' _gtin, ,_ean , bad key ',
				'gtin_required_categories' => array( '12', 12, 0, 'x' ),
			)
		);

		WPS_Assert::same( array( '_gtin', '_ean', 'bad key' ), Settings::gtin_meta_keys() );
		WPS_Assert::same( array( 12 ), Settings::gtin_required_categories() );
	}
);

$suite->add(
	'settings: allowed product statuses always fall back to publish',
	static function (): void {
		Settings::update( array( 'allowed_product_statuses' => array( 'draft', 'bogus' ) ) );

		WPS_Assert::same( array( 'draft' ), Settings::allowed_product_statuses() );

		Settings::update( array( 'allowed_product_statuses' => array( 'bogus' ) ) );

		WPS_Assert::same( array( 'publish' ), Settings::allowed_product_statuses() );
	}
);

$suite->add(
	'settings: slug fields are sanitised and never empty',
	static function (): void {
		$clean = Settings::sanitize(
			array(
				'feed_full_slug'  => 'Teljes Feed!',
				'feed_stock_slug' => '',
			)
		);

		WPS_Assert::same( 'teljes-feed', $clean['feed_full_slug'] );
		WPS_Assert::same( 'stock', $clean['feed_stock_slug'] );
	}
);

$suite->add(
	'settings: attribute_name_map sanitisation removes invalid lines and preserves valid rules',
	static function (): void {
		$raw = "  pa_color = Szín  \n"
			. "invalid_rule_without_equals\n"
			. "=missing_source\n"
			. "missing_target=\n"
			. "\n"
			. "pa_size=Méret\n";

		$clean = Settings::sanitize( array( 'attribute_name_map' => $raw ) );
		WPS_Assert::same( "pa_color=Szín\npa_size=Méret", $clean['attribute_name_map'] );

		// Non-string input falls back to empty string.
		$clean_non_string = Settings::sanitize( array( 'attribute_name_map' => array( 'not', 'a', 'string' ) ) );
		WPS_Assert::same( '', $clean_non_string['attribute_name_map'] );
	}
);

$suite->add(
	'settings: Admin::settings_input preserves attribute_name_map from POST payload',
	static function (): void {
		$admin  = new \WPS\Pepita\Admin\Admin();
		$ref    = new \ReflectionClass( $admin );
		$method = $ref->getMethod( 'settings_input' );
		$method->setAccessible( true );

		$raw = array(
			'attribute_name_map' => "pa_color=Szín\npa_size=Méret",
		);

		$parsed = $method->invoke( $admin, $raw );

		WPS_Assert::same( "pa_color=Szín\npa_size=Méret", $parsed['attribute_name_map'] );
	}
);
