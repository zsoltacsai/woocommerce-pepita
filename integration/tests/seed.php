<?php
/**
 * Seed the integration store with a deterministic catalog.
 *
 * Run with: wp eval-file /integration/tests/seed.php
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Feed\Lock;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

/**
 * Delete a previous seed, if any.
 */
function wps_it_seed_cleanup(): void {
	$seed = get_option( 'wps_it_seed' );

	if ( ! is_array( $seed ) ) {
		return;
	}

	foreach ( (array) ( $seed['variation_ids'] ?? array() ) as $variation_id ) {
		$variation = wc_get_product( (int) $variation_id );

		if ( $variation instanceof \WC_Product ) {
			$variation->delete( true );
		}
	}

	foreach ( (array) ( $seed['product_ids'] ?? array() ) as $product_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( $product instanceof \WC_Product ) {
			$product->delete( true );
		}
	}

	foreach ( (array) ( $seed['term_ids'] ?? array() ) as $term_id ) {
		$term = get_term( (int) $term_id, Category_Rules::TAXONOMY );

		if ( $term instanceof \WP_Term ) {
			wp_delete_term( (int) $term_id, Category_Rules::TAXONOMY );
		}
	}

	if ( ! empty( $seed['attachment_id'] ) ) {
		wp_delete_attachment( (int) $seed['attachment_id'], true );
	}

	delete_option( 'wps_it_seed' );
}

/**
 * Create the shared product image.
 */
function wps_it_seed_image(): int {
	$image = file_get_contents( __DIR__ . '/../fixtures/pepita-compatible-1200x630.jpg' );
	if ( false === $image ) {
		WP_CLI::error( 'Compliant integration image fixture could not be read.' );
	}

	$upload = wp_upload_bits( 'wps-it-product.jpg', null, $image );

	if ( ! empty( $upload['error'] ) ) {
		WP_CLI::error( 'Image upload failed: ' . $upload['error'] );
	}

	$attachment_id = wp_insert_attachment(
		array(
		'post_mime_type' => 'image/jpeg',
			'post_title'     => 'WPS Integration Product Image',
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		WP_CLI::error( 'Attachment creation failed.' );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( (int) $attachment_id, $metadata );

	return (int) $attachment_id;
}

/**
 * Create a simple product.
 *
 * @param array<string, mixed> $args Product arguments.
 */
function wps_it_seed_simple( array $args ): int {
	$product = new \WC_Product_Simple();

	$product->set_name( (string) $args['name'] );
	$product->set_sku( (string) $args['sku'] );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_description( (string) ( $args['description'] ?? '<p>Integration description.</p>' ) );
	$product->set_short_description( (string) ( $args['short_description'] ?? 'Rövid leírás.' ) );
	$product->set_regular_price( (string) $args['regular_price'] );

	if ( isset( $args['sale_price'] ) ) {
		$product->set_sale_price( (string) $args['sale_price'] );
	}

	$product->set_manage_stock( true );
	$product->set_stock_quantity( (int) ( $args['stock'] ?? 10 ) );
	$product->set_stock_status( (int) ( $args['stock'] ?? 10 ) > 0 ? 'instock' : 'outofstock' );
	$product->set_category_ids( array( (int) $args['category_id'] ) );

	if ( ! empty( $args['image_id'] ) ) {
		$product->set_image_id( (int) $args['image_id'] );
	}

	$product->update_meta_data( '_wps_brand', 'WPS Integration Brand' );
	$product->update_meta_data( '_wps_manufacturer', 'WPS Integration Manufacturer' );
	$product->update_meta_data( '_wps_mpn', 'MPN-' . $args['sku'] );

	if ( ! empty( $args['gtin'] ) ) {
		$product->update_meta_data( '_wps_gtin', (string) $args['gtin'] );
	}

	if ( ! empty( $args['include'] ) ) {
		$product->update_meta_data( '_wps_include', (string) $args['include'] );
	}

	$product_id = $product->save();

	if ( ! $product_id ) {
		WP_CLI::error( 'Simple product creation failed: ' . $args['sku'] );
	}

	Product_Mapper::ensure_export_id( (int) $product_id );

	return (int) $product_id;
}

/**
 * Create the variable product with two variations.
 *
 * @param int $category_id Category term ID.
 * @param int $image_id    Attachment ID.
 * @return array{product_id: int, variation_ids: int[]}
 */
function wps_it_seed_variable( int $category_id, int $image_id ): array {
	$product = new \WC_Product_Variable();

	$product->set_name( 'WPS Integration Variable Product' );
	$product->set_sku( 'WPS-IT-VAR' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_description( '<p>Változatos integrációs termék.</p>' );
	$product->set_short_description( 'Variációs rövid leírás.' );
	$product->set_category_ids( array( $category_id ) );
	$product->set_image_id( $image_id );

	$attribute = new \WC_Product_Attribute();
	$attribute->set_id( 0 );
	$attribute->set_name( 'Size' );
	$attribute->set_options( array( 'S', 'M' ) );
	$attribute->set_position( 0 );
	$attribute->set_visible( true );
	$attribute->set_variation( true );

	$product->set_attributes( array( $attribute ) );

	$product_id = $product->save();

	if ( ! $product_id ) {
		WP_CLI::error( 'Variable product creation failed.' );
	}

	$variations = array();

	foreach ( array( array( 'S', '12000', 5 ), array( 'M', '13000', 0 ) ) as $row ) {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( (int) $product_id );
		$variation->set_attributes( array( 'attribute_size' => $row[0] ) );
		$variation->set_regular_price( (string) $row[1] );
		$variation->set_sku( 'WPS-IT-VAR-' . $row[0] );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( (int) $row[2] );
		$variation->set_stock_status( (int) $row[2] > 0 ? 'instock' : 'outofstock' );
		$variation->set_image_id( $image_id );

		$variation_id = $variation->save();

		if ( ! $variation_id ) {
			WP_CLI::error( 'Variation creation failed: ' . $row[0] );
		}

		$variations[] = (int) $variation_id;
	}

	\WC_Product_Variable::sync( (int) $product_id );
	wc_delete_product_transients( (int) $product_id );

	Product_Mapper::ensure_export_id( (int) $product_id );

	return array(
		'product_id'    => (int) $product_id,
		'variation_ids' => $variations,
	);
}

wps_it_seed_cleanup();

// A rerun starts from clean scheduler and feed state in each isolated store.
Scheduler::unschedule_all();
Lock::release_all();
delete_option( 'wps_pepita_generation_full' );
delete_option( 'wps_pepita_generation_stock' );
delete_option( 'wps_pepita_feed_state_full' );
delete_option( 'wps_pepita_feed_state_stock' );
delete_option( 'wps_pepita_dirty_full' );
delete_option( 'wps_pepita_dirty_stock' );
Storage::reset();
Storage::purge();

WP_CLI::log( 'Seeding WooCommerce options...' );

update_option( 'woocommerce_currency', 'HUF' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_default_country', 'HU' );
update_option( 'woocommerce_store_address', 'Teszt utca 1.' );
update_option( 'woocommerce_store_city', 'Budapest' );
update_option( 'woocommerce_store_postcode', '1011' );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );

WP_CLI::log( 'Creating categories...' );

$parent = wp_insert_term(
	'WPS Integration',
	Category_Rules::TAXONOMY,
	array( 'slug' => 'wps-integration' )
);
$child  = wp_insert_term(
	'WPS Integration Child',
	Category_Rules::TAXONOMY,
	array(
		'slug'   => 'wps-integration-child',
		'parent' => (int) $parent['term_id'],
	)
);

if ( is_wp_error( $parent ) || is_wp_error( $child ) ) {
	WP_CLI::error( 'Category creation failed.' );
}

update_term_meta( (int) $parent['term_id'], Category_Rules::META_INCLUDE, Category_Rules::DECISION_INCLUDE );
update_term_meta( (int) $parent['term_id'], Category_Rules::META_INCLUDE_CHILDREN, 'yes' );

WP_CLI::log( 'Creating product image...' );

$image_id = wps_it_seed_image();

WP_CLI::log( 'Creating products...' );

$p1 = wps_it_seed_simple(
	array(
		'name'          => 'WPS Integration Simple Product',
		'sku'           => 'WPS-IT-001',
		'regular_price' => '10000',
		'sale_price'    => '8000',
		'stock'         => 25,
		'category_id'   => (int) $child['term_id'],
		'image_id'      => $image_id,
		'gtin'          => '1234567890123',
	)
);

$p2 = wps_it_seed_simple(
	array(
		'name'          => 'WPS Integration Out Of Stock Product',
		'sku'           => 'WPS-IT-002',
		'regular_price' => '5000',
		'stock'         => 0,
		'category_id'   => (int) $parent['term_id'],
		'image_id'      => $image_id,
	)
);

$p3 = wps_it_seed_simple(
	array(
		'name'          => 'WPS Integration Excluded Product',
		'sku'           => 'WPS-IT-003',
		'regular_price' => '3000',
		'stock'         => 7,
		'category_id'   => (int) $parent['term_id'],
		'image_id'      => $image_id,
		'include'       => 'exclude',
	)
);

$p4 = wps_it_seed_simple(
	array(
		'name'          => 'WPS Integration Invalid Product',
		'sku'           => 'WPS-IT-004',
		'regular_price' => '2000',
		'stock'         => 3,
		'category_id'   => (int) $parent['term_id'],
	)
);

$variable = wps_it_seed_variable( (int) $child['term_id'], $image_id );

WP_CLI::log( 'Configuring plugin settings...' );

Settings::update(
	array(
		'default_included'         => false,
		'include_child_categories' => true,
		'allowed_product_statuses' => array( 'publish' ),
		'currency'                 => 'HUF',
		'vat_mode'                 => 'fixed',
		'vat_fixed'                => 27.0,
		'price_adjustment_type'    => 'none',
		'price_adjustment_value'   => 0.0,
		'rounding'                 => 'nearest_1',
		'include_purchase_net'     => true,
		'shipping_delay'           => 2,
		'shipping_price'           => '990',
		'warranty_type'            => 'Year',
		'warranty_duration'        => '2',
		'description_source'       => 'long',
		'brand_source'             => 'meta',
		'brand_meta_key'           => '_wps_brand',
		'manufacturer_default'     => 'WPS Default Manufacturer',
		'gtin_meta_keys'           => '_wps_gtin,_gtin,_ean',
		'gtin_required_categories' => array(),
		'max_category_paths'       => 5,
		'max_gallery_images'       => 5,
		'batch_size'               => 2,
		'batches_per_run'          => 1,
		'max_runtime'              => 0,
		'full_feed_interval'       => 24,
		'stock_feed_interval'      => 30,
		'stock_requires_full'      => true,
		'removal_policy'           => 'omit',
		'removal_grace_days'       => 3,
		'allow_empty_feed'         => false,
		'feed_public'              => false,
		'feed_cache_max_age'       => 300,
		'feed_token'               => (string) getenv( 'WPS_FEED_TOKEN' ),
		'feed_full_slug'           => 'full',
		'feed_stock_slug'          => 'stock',
		'order_api_enabled'        => true,
		'order_api_key'            => (string) getenv( 'WPS_ORDER_API_KEY' ),
		'order_api_allowed_ips'    => '',
		'order_api_status_paid'    => 'processing',
		'order_api_status_unpaid'  => 'on-hold',
		'order_api_require_https'  => false,
		'order_api_max_body_kb'    => 512,
		'order_api_keep_payloads'  => true,
		'order_api_save_label'     => true,
		'payment_map'              => array(
			'cod'        => 'cod',
			'transfer'   => 'bacs',
			'creditcard' => '',
		),
		'shipping_map'             => array(
			'shipping'         => 'flat_rate',
			'gls'              => 'flat_rate',
			'gls_parcellocker' => 'flat_rate',
			'gls_xxl'          => 'flat_rate',
			'mpl'              => 'flat_rate',
		),
		'log_retention_days'       => 30,
		'log_level'                => 'debug',
		'record_export_meta'       => true,
		'delete_data_on_uninstall' => false,
	)
);

Product_Selection::clear_cache();

delete_option( 'wps_it_mail_log' );
delete_option( 'wps_it_email_gate_log' );
delete_option( 'wps_it_disable_as' );
Scheduler::schedule_all();

update_option(
	'wps_it_seed',
	array(
		'run_id'        => gmdate( 'YmdHis' ),
		'term_ids'      => array( (int) $parent['term_id'], (int) $child['term_id'] ),
		'parent_term'   => (int) $parent['term_id'],
		'child_term'    => (int) $child['term_id'],
		'attachment_id' => $image_id,
		'product_ids'   => array( $p1, $p2, $p3, $p4, $variable['product_id'] ),
		'variation_ids' => $variable['variation_ids'],
		'p1'            => $p1,
		'p2'            => $p2,
		'p3'            => $p3,
		'p4'            => $p4,
		'variable'      => $variable['product_id'],
		'sku_p1'        => 'WPS-IT-001',
		'sku_p2'        => 'WPS-IT-002',
		'sku_var'       => 'WPS-IT-VAR',
		'export_p1'     => Product_Mapper::export_id( $p1 ),
		'export_p2'     => Product_Mapper::export_id( $p2 ),
		'export_var'    => Product_Mapper::export_id( $variable['product_id'] ),
	),
	false
);

WP_CLI::log( 'Seed complete.' );
WP_CLI::log( '  p1=' . $p1 . ' p2=' . $p2 . ' p3=' . $p3 . ' p4=' . $p4 . ' var=' . $variable['product_id'] );
