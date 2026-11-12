<?php
/**
 * Detects WooCommerce product/stock/category changes and queues debounced
 * regeneration requests. No feed is rebuilt during a product save.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Feed;

use WPS\Pepita\Catalog\Category_Rules;

defined( 'ABSPATH' ) || exit;

final class Change_Detector {

	public function register(): void {
		// Structural changes: catalog, descriptions, images, status, type.
		add_action( 'woocommerce_new_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_trash_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_untrash_product', array( $this, 'on_product_change' ), 10, 1 );

		add_action( 'woocommerce_new_product_variation', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_delete_product_variation', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'save_post_product_variation', array( $this, 'on_variation_save' ), 10, 1 );

		// Price and stock changes only require the stock feed.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_change' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_change' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_change' ), 10, 1 );
		add_action( 'woocommerce_product_object_updated_props', array( $this, 'on_props_updated' ), 10, 2 );

		// Category rules affect the whole catalog structure.
		add_action( 'created_product_cat', array( $this, 'on_category_change' ), 10, 1 );
		add_action( 'edited_product_cat', array( $this, 'on_category_change' ), 10, 1 );
		add_action( 'delete_product_cat', array( $this, 'on_category_change' ), 10, 1 );

		// CSV importer.
		add_action( 'woocommerce_product_import_inserted_product_object', array( $this, 'on_imported_product' ), 10, 1 );
		add_action( 'woocommerce_product_import_updated_product_object', array( $this, 'on_imported_product' ), 10, 1 );
	}

	/**
	 * @param mixed $product_id
	 */
	public function on_product_change( $product_id ): void {
		if ( ! is_numeric( $product_id ) || (int) $product_id <= 0 ) {
			return;
		}

		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );
	}

	/**
	 * @param mixed $post_id
	 */
	public function on_variation_save( $post_id ): void {
		if ( ! is_numeric( $post_id ) || (int) $post_id <= 0 ) {
			return;
		}

		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );
	}

	/**
	 * @param mixed $product
	 */
	public function on_stock_change( $product ): void {
		Scheduler::queue( Generator::TYPE_STOCK );
	}

	/**
	 * @param mixed $product
	 * @param mixed $updated_props
	 */
	public function on_props_updated( $product, $updated_props ): void {
		if ( ! is_array( $updated_props ) ) {
			return;
		}

		$stock_props = array( 'price', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'manage_stock', 'backorders' );
		$structural  = array( 'name', 'description', 'short_description', 'image_id', 'gallery_image_ids', 'status', 'category_ids', 'attributes', 'type' );

		if ( array() !== array_intersect( $updated_props, $structural ) ) {
			Scheduler::queue( Generator::TYPE_FULL );
		}

		if ( array() !== array_intersect( $updated_props, $stock_props ) ) {
			Scheduler::queue( Generator::TYPE_STOCK );
		}
	}

	/**
	 * @param mixed $term_id
	 */
	public function on_category_change( $term_id ): void {
		Category_Rules::clear_cache();

		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );
	}

	/**
	 * @param mixed $product
	 */
	public function on_imported_product( $product ): void {
		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );
	}
}
