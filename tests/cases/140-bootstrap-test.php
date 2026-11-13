<?php
/**
 * Bootstrap smoke tests: wiring, activation, admin screens and health checks.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Health;
use WPS\Pepita\Installer;
use WPS\Pepita\Plugin;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

$grant_admin = static function (): void {
	WPS_Test_State::$is_admin          = true;
	WPS_Test_State::$current_user_caps = array(
		'manage_woocommerce' => true,
		'activate_plugins'   => true,
		'edit_products'      => true,
		'manage_product_terms' => true,
	);
};

$suite->add(
	'bootstrap: a front-end request registers the public components only',
	static function (): void {
		WPS_Test_State::$is_admin = false;

		Plugin::instance()->boot();

		WPS_Assert::true( has_action( 'rest_api_init' ) );
		WPS_Assert::true( has_action( 'init' ) );
		WPS_Assert::true( has_action( 'woocommerce_update_product' ) );
		WPS_Assert::true( has_action( 'woocommerce_product_set_stock' ) );
		WPS_Assert::false( has_action( 'admin_menu' ) );
		WPS_Assert::false( has_action( 'admin_post_wps_pepita_save_settings' ) );
	}
);

$suite->add(
	'bootstrap: an admin request registers every screen and action',
	static function () use ( $grant_admin ): void {
		$grant_admin();

		Plugin::instance()->boot();

		WPS_Assert::true( has_action( 'admin_menu' ) );
		WPS_Assert::true( has_action( 'admin_enqueue_scripts' ) );
		WPS_Assert::true( has_action( 'admin_notices' ) );

		foreach (
			array(
				'admin_post_wps_pepita_save_settings',
				'admin_post_wps_pepita_generate',
				'admin_post_wps_pepita_download',
				'admin_post_wps_pepita_regenerate_secret',
				'admin_post_wps_pepita_clear_logs',
				'admin_post_wps_pepita_reset_feeds',
			) as $hook
		) {
			WPS_Assert::true( has_action( $hook ), 'Missing handler: ' . $hook );
		}

		foreach (
			array(
				'generate',
				'status',
				'bulk_inclusion',
				'product_preview',
				'save_product_meta',
				'category_rule',
				'search_products',
				'validate_product',
				'reset_feeds',
				'test_endpoint',
			) as $action
		) {
			WPS_Assert::true(
				has_action( 'wp_ajax_wps_pepita_' . $action ),
				'Missing AJAX handler: ' . $action
			);
		}

		WPS_Assert::true( has_action( 'add_meta_boxes' ) );
		WPS_Assert::true( has_action( 'save_post_product' ) );
		WPS_Assert::true( has_action( 'save_post_product_variation' ) );
	}
);

$suite->add(
	'bootstrap: every admin screen renders without a fatal error',
	static function () use ( $grant_admin ): void {
		$grant_admin();

		Plugin::instance()->boot();

		$admin = new Admin();

		foreach (
			array(
				'render_dashboard',
				'render_products',
				'render_categories',
				'render_feeds',
				'render_orders',
				'render_api',
				'render_settings',
				'render_logs',
			) as $method
		) {
			ob_start();
			$admin->{$method}();
			$html = (string) ob_get_clean();

			WPS_Assert::true( '' !== trim( $html ), 'Empty output from ' . $method );
			WPS_Assert::contains( 'wps-', $html, 'Missing plugin markup in ' . $method );
		}
	}
);

$suite->add(
	'bootstrap: activation creates settings, tokens, schedules and storage',
	static function (): void {
		Installer::activate();

		$settings = get_option( Settings::OPTION, array() );

		WPS_Assert::true( is_array( $settings ) );
		WPS_Assert::same( '1.1.0', (string) get_option( 'wps_pepita_db_version', '' ) );
		WPS_Assert::true( strlen( (string) ( $settings['feed_token'] ?? '' ) ) >= 32 );
		WPS_Assert::true( strlen( (string) ( $settings['order_api_key'] ?? '' ) ) >= 32 );
		WPS_Assert::true( is_dir( Storage::dir() ) );

		WPS_Assert::true( isset( WPS_Test_State::$cron[ Scheduler::HOOK_FULL ] ) );
		WPS_Assert::true( isset( WPS_Test_State::$cron[ Scheduler::HOOK_STOCK ] ) );
		WPS_Assert::true( isset( WPS_Test_State::$cron[ Scheduler::HOOK_PURGE ] ) );
	}
);

$suite->add(
	'bootstrap: deactivation releases schedules and locks but keeps data',
	static function (): void {
		Installer::activate();
		Installer::deactivate();

		WPS_Assert::false( isset( WPS_Test_State::$cron[ Scheduler::HOOK_FULL ] ) );
		WPS_Assert::false( isset( WPS_Test_State::$cron[ Scheduler::HOOK_STOCK ] ) );
		WPS_Assert::false( isset( WPS_Test_State::$cron[ Scheduler::HOOK_PURGE ] ) );

		$settings = get_option( Settings::OPTION, array() );

		WPS_Assert::true( is_array( $settings ) && array() !== $settings );
	}
);

$suite->add(
	'bootstrap: health checks cover the operational surface',
	static function (): void {
		Installer::activate();

		$checks = Health::checks();

		WPS_Assert::true( count( $checks ) >= 9 );

		$labels = array_column( $checks, 'label' );

		foreach ( array( 'WooCommerce', 'HPOS (egyedi rendeléstáblák)', 'PHP verzió', 'Feed könyvtár', 'Feed token', 'Rendelés API kulcs', 'HTTPS', 'Ütemező', 'Teljes feed kora', 'Zárolás' ) as $label ) {
			WPS_Assert::true( in_array( $label, $labels, true ), 'Missing health check: ' . $label );
		}

		foreach ( $checks as $check ) {
			WPS_Assert::true( isset( $check['status'], $check['value'], $check['hint'] ) );
			WPS_Assert::true( in_array( $check['status'], array( Health::STATUS_OK, Health::STATUS_WARN, Health::STATUS_FAIL ), true ) );
		}

		$summary = Health::summary();

		WPS_Assert::same( count( $checks ), $summary['ok'] + $summary['warn'] + $summary['fail'] );
	}
);

$suite->add(
	'bootstrap: the missing WooCommerce notice is shown to administrators',
	static function () use ( $grant_admin ): void {
		$grant_admin();

		$plugin = Plugin::instance();

		ob_start();
		$plugin->render_missing_woocommerce_notice();
		$html = (string) ob_get_clean();

		WPS_Assert::contains( 'WooCommerce', $html );
		WPS_Assert::contains( 'notice-error', $html );
	}
);
