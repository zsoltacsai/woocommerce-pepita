<?php
/**
 * M9-B lifecycle coverage: deactivation, reactivation, uninstall with data
 * removal. DESTRUCTIVE: must run last (file name sorts after every other
 * case); it recreates the schema at the end so the store stays usable.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Installer;
use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

WPS_IT::line( 'M9-B coverage: deactivation, reactivation, uninstall' );

$run = WPS_IT::run_id();

$wps_it_table_exists = static function ( string $table ): bool {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
};

$wps_it_scheduled = static function (): bool {
	foreach ( array( Scheduler::HOOK_FULL, Scheduler::HOOK_STOCK, Scheduler::HOOK_PURGE ) as $hook ) {
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( $hook ) ) {
			return true;
		}
		if ( wp_next_scheduled( $hook ) ) {
			return true;
		}
	}
	return false;
};

WPS_IT::test(
	'Deactivation keeps tables, orders and settings; reactivation resumes and a replay stays idempotent',
	static function () use ( $run, $wps_it_table_exists, $wps_it_scheduled ): void {
		$external_id = 'IT-LIFECYCLE-' . $run;
		$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );
		$first       = WPS_IT::post_order( $payload );

		WPS_IT::same( 200, $first['code'], 'Import failed: ' . $first['body'] );

		$order_id = (int) $first['json']['orderId'];
		$key      = (string) Settings::get( 'order_api_key' );

		Installer::deactivate();

		WPS_IT::false( $wps_it_scheduled(), 'Deactivation left scheduled jobs behind.' );
		WPS_IT::true( $wps_it_table_exists( Order_Repository::table() ), 'Deactivation dropped the claims table.' );
		WPS_IT::instance( \WC_Order::class, wc_get_order( $order_id ), 'Deactivation removed an order.' );
		WPS_IT::same( 'completed', (string) Order_Repository::get( $external_id )['status'] );
		WPS_IT::same( $key, (string) Settings::get( 'order_api_key' ), 'Deactivation changed the API key.' );

		Installer::activate();

		WPS_IT::true( $wps_it_scheduled(), 'Reactivation did not reschedule jobs.' );
		WPS_IT::same( $key, (string) Settings::get( 'order_api_key' ), 'Reactivation regenerated or changed the API key.' );

		$replay = WPS_IT::post_order( $payload );

		WPS_IT::same( 200, $replay['code'], 'Replay after reactivation failed: ' . $replay['body'] );
		WPS_IT::same( $order_id, (int) $replay['json']['orderId'], 'Replay returned a different order.' );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'Replay created a second order.' );

		$new = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id . '-B' ) );

		WPS_IT::same( 200, $new['code'], 'A new order after reactivation failed: ' . $new['body'] );
	}
);

WPS_IT::test(
	'Uninstall without the delete flag keeps every table and option',
	static function () use ( $wps_it_table_exists ): void {
		$settings = Settings::update( array( 'delete_data_on_uninstall' => false ) );
		WPS_IT::same( false, (bool) $settings['delete_data_on_uninstall'] );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-pepita-sync/woocommerce-pepita.php' );
		}

		include WPS_PEPITA_DIR . 'uninstall.php';

		WPS_IT::true( $wps_it_table_exists( Order_Repository::table() ), 'Uninstall without the flag dropped the claims table.' );
		WPS_IT::true( $wps_it_table_exists( \WPS\Pepita\Logger::table() ), 'Uninstall without the flag dropped the log table.' );
		WPS_IT::not_same( false, get_option( Settings::OPTION, false ), 'Uninstall without the flag removed the settings.' );
	}
);

WPS_IT::test(
	'Uninstall with the delete flag removes plugin tables and options but never WooCommerce orders; reinstall recovers',
	static function () use ( $run, $wps_it_table_exists ): void {
		global $wpdb;

		$external_id = 'IT-UNINSTALL-' . $run;
		$response    = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id ) );

		WPS_IT::same( 200, $response['code'], 'Import failed: ' . $response['body'] );

		$order_id = (int) $response['json']['orderId'];
		$orders   = wc_get_orders( array( 'limit' => 500, 'return' => 'ids', 'status' => 'any' ) );

		Settings::update( array( 'delete_data_on_uninstall' => true ) );

		include WPS_PEPITA_DIR . 'uninstall.php';

		WPS_IT::false( $wps_it_table_exists( $wpdb->prefix . 'wps_pepita_orders' ), 'The claims table survived uninstall.' );
		WPS_IT::false( $wps_it_table_exists( $wpdb->prefix . 'wps_pepita_logs' ), 'The log table survived uninstall.' );
		WPS_IT::same( false, get_option( 'wps_pepita_db_version', false ), 'The db-version option survived uninstall.' );
		WPS_IT::same( false, get_option( Settings::OPTION, false ), 'The settings option survived uninstall.' );

		$remaining = wc_get_orders( array( 'limit' => 500, 'return' => 'ids', 'status' => 'any' ) );

		WPS_IT::same( count( $orders ), count( $remaining ), 'Uninstall changed the number of WooCommerce orders.' );
		WPS_IT::instance( \WC_Order::class, wc_get_order( $order_id ), 'Uninstall deleted a WooCommerce order.' );

		// The plugin's order meta is removed in both storage modes (postmeta
		// for legacy stores, wc_orders_meta for HPOS); the order itself stays.
		$order = wc_get_order( $order_id );
		WPS_IT::same( '', (string) $order->get_meta( Importer::META_ORDER_ID ), 'Plugin order meta survived uninstall with data removal.' );
		WPS_IT::same( '', (string) $order->get_meta( Importer::META_PAYLOAD_HASH ), 'Plugin payload hash meta survived uninstall.' );

		// Database rollback / reinstall: activation must rebuild a working plugin.
		Settings::reset_cache();
		Order_Repository::reset_table_cache();
		\WPS\Pepita\Logger::reset_table_cache();
		Installer::activate();

		WPS_IT::true( $wps_it_table_exists( Order_Repository::table() ), 'Reactivation did not recreate the claims table.' );
		Settings::update( array(
			'order_api_enabled' => true,
			'order_api_key'     => (string) getenv( 'WPS_ORDER_API_KEY' ),
			'order_api_keep_payloads' => true,
			'order_api_require_https' => false,
			'delete_data_on_uninstall' => false,
		) );

		$again = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id . '-AFTER' ) );

		WPS_IT::same( 200, $again['code'], 'The plugin did not work after reinstall: ' . $again['body'] );
	}
);
