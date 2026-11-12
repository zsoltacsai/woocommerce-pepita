<?php
/**
 * Plugin bootstrap.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Feed\Change_Detector;
use WPS\Pepita\Feed\Endpoint;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Orders\Api_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component together and guards the WooCommerce dependency.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	/**
	 * Declare HPOS (custom order tables) compatibility using the official API.
	 */
	public function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WPS_PEPITA_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				WPS_PEPITA_FILE,
				true
			);
		}
	}

	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			return;
		}

		load_plugin_textdomain(
			'wp-pepita-sync',
			false,
			dirname( plugin_basename( WPS_PEPITA_FILE ) ) . '/languages'
		);

		Installer::maybe_upgrade();

		( new Change_Detector() )->register();
		( new Scheduler() )->register();
		( new Endpoint() )->register();
		( new Api_Controller() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wps-pepita', new Cli() );
		}
	}

	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			'A WP Pepita Sync működéséhez a WooCommerce plugin aktív telepítése szükséges.'
		);
		echo '</p></div>';
	}
}
