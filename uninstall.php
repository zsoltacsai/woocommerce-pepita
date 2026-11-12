<?php
/**
 * Uninstall routine.
 *
 * Data is only removed when the administrator explicitly enabled
 * "delete_data_on_uninstall". Orders, products and their meta always remain
 * untouched otherwise.
 *
 * @package WPS\Pepita
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'WPS_PEPITA_DIR' ) ) {
	define( 'WPS_PEPITA_DIR', plugin_dir_path( __FILE__ ) );
}

require_once WPS_PEPITA_DIR . 'includes/autoload.php';

use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$settings = get_option( Settings::OPTION, array() );
$settings = is_array( $settings ) ? $settings : array();

$delete = ! empty( $settings['delete_data_on_uninstall'] );

if ( ! $delete ) {
	return;
}

global $wpdb;

// Generated feed files.
Storage::purge();

// Options.
$option_names = array(
	Settings::OPTION,
	'wps_pepita_db_version',
	'wps_pepita_feed_state_full',
	'wps_pepita_feed_state_stock',
	'wps_pepita_generation_full',
	'wps_pepita_generation_stock',
	'wps_pepita_dirty_full',
	'wps_pepita_dirty_stock',
	'wps_pepita_schedule_signature',
	'wps_pepita_lock_full',
	'wps_pepita_lock_stock',
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
	delete_site_option( $option_name );
}

// Custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wps_pepita_logs" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wps_pepita_orders" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// Product and order meta created by the plugin.
$meta_keys = array(
	'_wps_export_id',
	'_wps_include',
	'_wps_title_override',
	'_wps_description_override',
	'_wps_gtin',
	'_wps_brand',
	'_wps_manufacturer',
	'_wps_mpn',
	'_wps_price_adjustment_type',
	'_wps_price_adjustment_value',
	'_wps_shipping_delay',
	'_wps_shipping_price',
	'_wps_warranty_type',
	'_wps_warranty_duration',
	'_wps_video_links',
	'_wps_restock_date',
	'_wps_last_export_status',
	'_wps_last_export_at',
	'_wps_last_export_message',
	'_wps_pepita_order_id',
	'_wps_pepita_origin',
	'_wps_pepita_payment_status',
	'_wps_pepita_delivery_mode',
	'_wps_pepita_imported_at',
	'_wps_pepita_payload_hash',
	'_wps_pepita_voucher',
	'_wps_pepita_raw_total',
	'_wps_pepita_package_label',
	'_wps_pepita_customer_message',
	'_wps_pepita_courier_message',
	'_wps_pepita_product_id',
	'_wps_pepita_sku',
	'_wps_pepita_vat',
);

foreach ( $meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
	$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $meta_key ) );
}

// Category rule term meta.
$term_meta_keys = array(
	'wps_include',
	'wps_include_children',
	'wps_gtin_required',
	'wps_price_adjustment_type',
	'wps_price_adjustment_value',
	'wps_shipping_delay',
);

foreach ( $term_meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $meta_key ) );
}

// Transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wps_pepita_%' OR option_name LIKE '_transient_timeout_wps_pepita_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// Scheduled events.
wp_clear_scheduled_hook( 'wps_pepita_generate_full' );
wp_clear_scheduled_hook( 'wps_pepita_generate_stock' );
wp_clear_scheduled_hook( 'wps_pepita_queue_full' );
wp_clear_scheduled_hook( 'wps_pepita_queue_stock' );
wp_clear_scheduled_hook( 'wps_pepita_continue_full' );
wp_clear_scheduled_hook( 'wps_pepita_continue_stock' );
wp_clear_scheduled_hook( 'wps_pepita_purge_logs' );
