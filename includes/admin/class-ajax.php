<?php
/**
 * Nonce-protected AJAX endpoints used by the admin UI.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Catalog\Validator;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Feed\Lock;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Logger;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	public const NONCE = 'wps_pepita_ajax';

	public function register(): void {
		$actions = array(
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
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_wps_pepita_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	private function verify(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultsága a művelethez.', 'wp-pepita-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Run one bounded generation slice and report progress. The browser polls
	 * this endpoint until the generation completes, so no long request is held.
	 */
	public function handle_generate(): void {
		$this->verify();

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( (string) $_POST['type'] ) ) : Generator::TYPE_FULL;
		$type = Generator::TYPE_STOCK === $type ? Generator::TYPE_STOCK : Generator::TYPE_FULL;

		$generator = new Generator();
		$result    = $generator->run( $type, array( 'max_runtime' => 15 ) );

		if ( 'completed' === ( $result['status'] ?? '' ) ) {
			Scheduler::clear_dirty( $type );
		}

		wp_send_json_success( $result );
	}

	public function handle_status(): void {
		$this->verify();

		$generator = new Generator();

		$full  = $generator->get_feed_state( Generator::TYPE_FULL );
		$stock = $generator->get_feed_state( Generator::TYPE_STOCK );

		wp_send_json_success(
			array(
				'full'  => array(
					'state'   => $full,
					'running' => $generator->is_running( Generator::TYPE_FULL ),
					'exists'  => Storage::exists( Generator::TYPE_FULL ),
					'size'    => Storage::size( Generator::TYPE_FULL ),
					'modified'=> Storage::modified( Generator::TYPE_FULL ),
				),
				'stock' => array(
					'state'   => $stock,
					'running' => $generator->is_running( Generator::TYPE_STOCK ),
					'exists'  => Storage::exists( Generator::TYPE_STOCK ),
					'size'    => Storage::size( Generator::TYPE_STOCK ),
					'modified'=> Storage::modified( Generator::TYPE_STOCK ),
				),
			)
		);
	}

	/**
	 * Bulk include / exclude / reset products.
	 */
	public function handle_bulk_inclusion(): void {
		$this->verify();

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) : Product_Selection::MODE_DEFAULT;
		$ids  = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			wp_send_json_error( array( 'message' => __( 'Nincs kijelölt termék.', 'wp-pepita-sync' ) ), 400 );
		}

		$changed = Product_Selection::bulk_set( $ids, $mode );

		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );

		wp_send_json_success(
			array(
				'changed' => $changed,
				'message' => sprintf(
					/* translators: %d: number of products */
					__( '%d termék módosítva. A feed a következő ütemezett futáskor frissül.', 'wp-pepita-sync' ),
					$changed
				),
			)
		);
	}

	/**
	 * Map a single product and return its validation result.
	 */
	public function handle_product_preview(): void {
		$this->verify();

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;

		if ( $product_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen termék.', 'wp-pepita-sync' ) ), 400 );
		}

		$data       = Product_Mapper::map( $product_id );
		$validation = Validator::validate( $data );

		wp_send_json_success(
			array(
				'export_id'  => $data['export_id'],
				'type'       => $data['type'],
				'name'       => $data['name'],
				'price'      => $data['price'],
				'sale_price' => $data['sale_price'],
				'vat'        => $data['vat_percent'],
				'currency'   => $data['currency'],
				'available'  => (bool) $data['available'],
				'quantity'   => $data['quantity'],
				'valid'      => (bool) $validation['valid'],
				'errors'     => $validation['errors'],
				'warnings'   => $validation['warnings'],
			)
		);
	}

	public function handle_validate_product(): void {
		$this->handle_product_preview();
	}

	/**
	 * Save the per-product Pepita overrides from the metabox.
	 */
	public function handle_save_product_meta(): void {
		$this->verify();

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;

		if ( $product_id <= 0 || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultsága a termék szerkesztéséhez.', 'wp-pepita-sync' ) ), 403 );
		}

		$fields = array(
			'include'           => Product_Selection::META_INCLUDE,
			'title_override'    => Product_Mapper::META_TITLE_OVERRIDE,
			'description_override' => Product_Mapper::META_DESCRIPTION_OVERRIDE,
			'gtin'              => Product_Mapper::META_GTIN,
			'brand'             => Product_Mapper::META_BRAND,
			'manufacturer'      => Product_Mapper::META_MANUFACTURER,
			'mpn'               => Product_Mapper::META_MPN,
			'shipping_delay'    => Product_Mapper::META_SHIPPING_DELAY,
			'shipping_price'    => Product_Mapper::META_SHIPPING_PRICE,
			'price_adjustment_type'  => Product_Mapper::META_PRICE_TYPE,
			'price_adjustment_value' => Product_Mapper::META_PRICE_VALUE,
		);

		foreach ( $fields as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $_POST ) ) {
				continue;
			}

			$value = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = is_array( $value ) ? '' : (string) $value;

			if ( 'include' === $field ) {
				if ( in_array( $value, array( Product_Selection::MODE_INCLUDE, Product_Selection::MODE_EXCLUDE ), true ) ) {
					update_post_meta( $product_id, $meta_key, $value );
				} else {
					delete_post_meta( $product_id, $meta_key );
				}

				continue;
			}

			if ( 'price_adjustment_type' === $field ) {
				if ( ! in_array( $value, array( 'none', 'fixed', 'percent' ), true ) ) {
					delete_post_meta( $product_id, $meta_key );

					continue;
				}
			}

			$value = trim( $value );

			if ( '' === $value ) {
				delete_post_meta( $product_id, $meta_key );

				continue;
			}

			if ( in_array( $field, array( 'shipping_delay', 'price_adjustment_value', 'shipping_price' ), true ) ) {
				$value = (string) max( 0, (float) str_replace( ',', '.', $value ) );
			}

			if ( 'gtin' === $field ) {
				$value = \WPS\Pepita\Catalog\Description_Sanitizer::gtin( $value );
			}

			if ( '' === $value ) {
				delete_post_meta( $product_id, $meta_key );

				continue;
			}

			update_post_meta( $product_id, $meta_key, $value );
		}

		Product_Selection::clear_cache();
		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );

		wp_send_json_success( array( 'message' => __( 'A termék Pepita beállításai mentve.', 'wp-pepita-sync' ) ) );
	}

	/**
	 * Save one category rule row.
	 */
	public function handle_category_rule(): void {
		$this->verify();

		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;

		if ( $term_id <= 0 || ! current_user_can( 'manage_product_terms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultsága a kategória módosításához.', 'wp-pepita-sync' ) ), 403 );
		}

		$data = array(
			'include'                => isset( $_POST['include'] ) ? sanitize_key( wp_unslash( (string) $_POST['include'] ) ) : 'inherit',
			'include_children'       => ! empty( $_POST['include_children'] ),
			'gtin_required'          => ! empty( $_POST['gtin_required'] ),
			'price_adjustment_type'  => isset( $_POST['price_adjustment_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['price_adjustment_type'] ) ) : '',
			'price_adjustment_value' => isset( $_POST['price_adjustment_value'] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST['price_adjustment_value'] ) ) : 0,
			'shipping_delay'         => isset( $_POST['shipping_delay'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_delay'] ) ) : '',
		);

		Category_Rules::save( $term_id, $data );

		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );

		wp_send_json_success( array( 'message' => __( 'A kategória szabály mentve.', 'wp-pepita-sync' ) ) );
	}

	public function handle_search_products(): void {
		$this->verify();

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['term'] ) ) : '';

		$result = Product_Selection::query(
			array(
				'search'   => $term,
				'per_page' => 20,
				'page'     => 1,
				'inclusion'=> 'all',
			)
		);

		$items = array();

		foreach ( $result['ids'] as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$items[] = array(
				'id'    => $product_id,
				'name'  => $product->get_name(),
				'sku'   => $product->get_sku(),
				'price' => $product->get_price(),
				'edit'  => get_edit_post_link( $product_id, 'raw' ),
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	public function handle_reset_feeds(): void {
		$this->verify();

		if ( Lock::is_locked( Generator::TYPE_FULL ) || Lock::is_locked( Generator::TYPE_STOCK ) ) {
			wp_send_json_error( array( 'message' => __( 'A feedek nem törölhetők, amíg generálás fut.', 'wp-pepita-sync' ) ), 409 );
		}

		Storage::purge();
		Storage::reset();

		delete_option( 'wps_pepita_feed_state_full' );
		delete_option( 'wps_pepita_feed_state_stock' );

		Logger::info( 'feed_reset', 'A generált feedek törlése AJAX kérésre megtörtént.' );

		wp_send_json_success( array( 'message' => __( 'A feedek törlésre kerültek.', 'wp-pepita-sync' ) ) );
	}

	/**
	 * Helper used by the API page to test whether the endpoint is reachable.
	 */
	public function handle_test_endpoint(): void {
		$this->verify();

		$url = rest_url( \WPS\Pepita\Orders\Api_Controller::REST_NAMESPACE . \WPS\Pepita\Orders\Api_Controller::REST_ROUTE );

		wp_send_json_success(
			array(
				'url'  => $url,
				'key'  => Settings::secret( 'order_api_key' ) !== '' ? __( 'beállítva', 'wp-pepita-sync' ) : __( 'hiányzik', 'wp-pepita-sync' ),
				'ssl'  => function_exists( 'is_ssl' ) && is_ssl(),
			)
		);
	}
}
