<?php
/**
 * Real environment assertions: the exact stack the M8 suite claims to test.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Settings;

WPS_IT::line( 'Environment' );

WPS_IT::test(
	'PHP 8.5 is the runtime',
	static function (): void {
		WPS_IT::true( str_starts_with( PHP_VERSION, '8.5.' ), 'Expected PHP 8.5.x, got ' . PHP_VERSION . '.' );
	}
);

WPS_IT::test(
	'MySQL 8.4 is the database server',
	static function (): void {
		global $wpdb;

		$version = (string) $wpdb->db_version();

		WPS_IT::true( str_starts_with( $version, '8.4.' ), 'Expected MySQL 8.4.x, got ' . $version . '.' );
	}
);

WPS_IT::test(
	'WordPress 7.1.4 is installed',
	static function (): void {
		$version = (string) get_bloginfo( 'version' );

		WPS_IT::same( '7.1.4', $version, 'Unexpected WordPress version.' );
	}
);

WPS_IT::test(
	'WooCommerce 11.3.0 is active',
	static function (): void {
		WPS_IT::true( class_exists( 'WooCommerce' ), 'WooCommerce is not active.' );
		WPS_IT::true( defined( 'WC_VERSION' ), 'WC_VERSION is not defined.' );
		WPS_IT::same( '11.3.0', (string) WC_VERSION, 'Unexpected WooCommerce version.' );
	}
);

WPS_IT::test(
	'WP-CLI bootstraps the store',
	static function (): void {
		WPS_IT::true( defined( 'WP_CLI' ) && WP_CLI, 'The suite must run through wp eval-file.' );
	}
);

WPS_IT::test(
	'WP Pepita Sync is active',
	static function (): void {
		WPS_IT::true( class_exists( '\WPS\Pepita\Plugin' ), 'The plugin class is not loaded.' );
		WPS_IT::same( '1.0.0', (string) WPS_PEPITA_VERSION, 'Unexpected plugin version.' );
		WPS_IT::not_same( '', (string) Settings::secret( 'feed_token' ), 'The feed token is missing.' );
		WPS_IT::not_same( '', (string) Settings::secret( 'order_api_key' ), 'The order API key is missing.' );
	}
);

WPS_IT::test(
	'Required PHP extensions are loaded',
	static function (): void {
		foreach ( array( 'mysqli', 'xmlwriter', 'dom', 'mbstring', 'json' ) as $extension ) {
			WPS_IT::true( extension_loaded( $extension ), 'Missing PHP extension: ' . $extension . '.' );
		}
	}
);

WPS_IT::test(
	'Harness configuration is explicit',
	static function (): void {
		$expected = (string) getenv( 'WPS_IT_EXPECT_HPOS' );
		WPS_IT::true( in_array( $expected, array( 'yes', 'no' ), true ), 'WPS_IT_EXPECT_HPOS must be yes or no.' );
		WPS_IT::not_same( '', (string) getenv( 'WPS_IT_BASE_URL' ), 'WPS_IT_BASE_URL is missing.' );
		WPS_IT::same( 'local', wp_get_environment_type(), 'Expected the local environment type.' );
	}
);

WPS_IT::test(
	'Store currency and locale are deterministic',
	static function (): void {
		WPS_IT::same( 'HUF', get_woocommerce_currency(), 'Expected HUF.' );
		WPS_IT::same( 'no', get_option( 'woocommerce_calc_taxes' ), 'Expected taxes disabled for deterministic totals.' );
	}
);
