<?php
/**
 * Recovery and replay tests: mid-creation crash recovery, historical orders
 * without payload hash, concurrent delivery, and deduplication safety.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
$recovery_payload = static function ( array $overrides = array() ): array {
	$base = array(
		'origin'                        => 'pepita.hu',
		'id'                            => 'PEP-REC-100',
		'date'                          => '2026-11-13 10:00:00',
		'payment_mode'                  => 'transfer',
		'payment_status'                => 'paid',
		'status'                        => 'NEW',
		'total_shipping_price'          => 1490,
		'total_shipping_price_currency' => 'HUF',
		'voucher'                       => 0,
		'delivery_mod'                  => 'gls',
		'customer'                      => array(
			'email'                 => 'buyer.rec@example.com',
			'first_name'            => 'Béla',
			'last_name'             => 'Kovács',
			'shipping_country'      => 'HU',
			'shipping_city'         => 'Debrecen',
			'shipping_postal_code'  => '4025',
			'shipping_street_address' => 'Piac utca 1.',
		),
		'products'                      => array(
			array(
				'id'       => 'wps-701',
				'sku'      => 'SKU-REC-1',
				'currency' => 'HUF',
				'quantity' => 2,
				'price'    => 2000,
				'vat'      => 27,
			),
		),
	);

	return array_replace_recursive( $base, $overrides );
};

$suite->add(
	'recovery: mid-creation crash leaving incomplete shell is discarded and cleanly rebuilt',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 701,
				'name'       => 'Recovery Product 1',
				'sku'        => 'SKU-REC-1',
				'price'      => '2000',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-CRASH-SHELL-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		// 1. Simulate a mid-creation crash:
		// wc_create_order created an empty order shell with ID 999, but process died before items were added.
		$shell = new WC_Order(
			array(
				'created_via' => 'wps-pepita',
				'currency'    => 'HUF',
			)
		);
		$shell->save(); // In stub, assigns ID
		$shell_id = $shell->get_id();
		$shell->update_meta_data( Importer::META_ORDER_ID, $external_id );
		$shell->update_meta_data( Importer::META_PAYLOAD_HASH, Importer::hash_payload( $payload ) );
		$shell->save_meta_data();

		// Claim was left in 'processing' with the associated shell ID
		$claim = Order_Repository::claim( $external_id, 'pepita.hu', '{}', Importer::hash_payload( $payload ) );
		WPS_Assert::same( 'claimed', $claim['state'] );
		Order_Repository::associate( $external_id, $shell_id );

		// Simulate passage of 15 minutes to make the claim stale and retryable
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1000 ) ),
			array( 'external_id' => $external_id )
		);

		// Shell exists in WooCommerce
		WPS_Assert::true( isset( WPS_Test_State::$orders[ $shell_id ] ) );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders[ $shell_id ]->get_items( 'line_item' ) ) );

		// 2. Retry arrives for the order:
		$importer = new Importer();
		$result   = $importer->import( $payload );

		WPS_Assert::same( 200, $result['http'] );
		WPS_Assert::same( 'created', $result['status'] );

		// The incomplete shell must have been purged
		WPS_Assert::false( isset( WPS_Test_State::$orders[ $shell_id ] ) );

		// Exactly 1 new order exists and has all lines and totals
		$new_order_id = $result['order_id'];
		WPS_Assert::not_same( $shell_id, $new_order_id );
		$order = WPS_Test_State::$orders[ $new_order_id ];
		WPS_Assert::same( 1, count( $order->get_items( 'line_item' ) ) );
		WPS_Assert::equals( 5490.0, $order->get_total() ); // 2 x 2000 + 1490 shipping
		WPS_Assert::same( 'completed', Order_Repository::get( $external_id )['status'] );
	}
);

$suite->add(
	'recovery: completed order post-save crash recovers order and verifies hash',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 702,
				'name'       => 'Recovery Product 2',
				'sku'        => 'SKU-REC-2',
				'price'      => '1500',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-CRASH-COMPLETE-1';
		$payload     = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-702',
						'sku'      => 'SKU-REC-2',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 1500,
						'vat'      => 0,
					),
				),
			)
		);

		// Import the order initially
		$importer = new Importer();
		$first    = $importer->import( $payload );
		WPS_Assert::same( 200, $first['http'] );
		$order_id = $first['order_id'];

		// Simulate crash before completion by setting claim back to stale processing
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array(
				'status'     => 'processing',
				'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1000 ),
			),
			array( 'external_id' => $external_id )
		);

		// Replay with identical payload: recovers cleanly and completes
		$second = $importer->import( $payload );
		WPS_Assert::same( 200, $second['http'] );
		WPS_Assert::same( 'duplicate', $second['status'] );
		WPS_Assert::same( $order_id, $second['order_id'] );
		WPS_Assert::same( 'completed', Order_Repository::get( $external_id )['status'] );

		// Replay with altered payload after crash: refused with 409 and existing order unmodified
		$altered = $payload;
		$altered['products'][0]['quantity'] = 10;
		$third   = $importer->import( $altered );
		WPS_Assert::same( 409, $third['http'] );
		WPS_Assert::same( 'conflict', $third['status'] );
		WPS_Assert::equals( 2990.0, WPS_Test_State::$orders[ $order_id ]->get_total() );
	}
);

$suite->add(
	'replay: historical order without hash backfills hash when data matches',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 703,
				'name'       => 'Historical Product',
				'sku'        => 'SKU-HIST-1',
				'price'      => '3000',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-HIST-MATCH-1';
		$payload     = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-703',
						'sku'      => 'SKU-HIST-1',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 3000,
						'vat'      => 0,
					),
				),
			)
		);

		$importer = new Importer();
		$first    = $importer->import( $payload );
		WPS_Assert::same( 200, $first['http'] );
		$order_id = $first['order_id'];

		// Strip payload hash from both order and repository to simulate legacy pre-1.1.0 order
		$order = WPS_Test_State::$orders[ $order_id ];
		$order->delete_meta_data( Importer::META_PAYLOAD_HASH );
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'payload_hash' => null ),
			array( 'external_id' => $external_id )
		);

		WPS_Assert::same( '', $order->get_meta( Importer::META_PAYLOAD_HASH ) );
		WPS_Assert::null( Order_Repository::get( $external_id )['payload_hash'] );

		// Replay identical data: must match invariants, return 200, and backfill hash
		$second = $importer->import( $payload );
		WPS_Assert::same( 200, $second['http'] );
		WPS_Assert::same( 'duplicate', $second['status'] );
		WPS_Assert::same( $order_id, $second['order_id'] );

		// Hash must now be backfilled
		$expected_hash = Importer::hash_payload( $payload );
		WPS_Assert::same( $expected_hash, $order->get_meta( Importer::META_PAYLOAD_HASH ) );
		WPS_Assert::same( $expected_hash, Order_Repository::get( $external_id )['payload_hash'] );

		// Note must be added
		$notes = $order->get_customer_notes();
		WPS_Assert::contains( 'hash rögzítve', end( $notes ) );
	}
);

$suite->add(
	'replay: historical order without hash rejects altered total with 409',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 704,
				'name'       => 'Historical Product Altered',
				'sku'        => 'SKU-HIST-2',
				'price'      => '1000',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-HIST-ALTER-1';
		$payload     = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-704',
						'sku'      => 'SKU-HIST-2',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 1000,
						'vat'      => 0,
					),
				),
			)
		);

		$importer = new Importer();
		$first    = $importer->import( $payload );
		WPS_Assert::same( 200, $first['http'] );
		$order_id = $first['order_id'];

		// Strip payload hash from both order and repository to simulate legacy pre-1.1.0 order
		$order = WPS_Test_State::$orders[ $order_id ];
		$order->delete_meta_data( Importer::META_PAYLOAD_HASH );
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'payload_hash' => null ),
			array( 'external_id' => $external_id )
		);

		// Send altered total: price changed from 1000 to 5000
		$altered = $payload;
		$altered['products'][0]['price'] = 5000;

		$second = $importer->import( $altered );
		WPS_Assert::same( 409, $second['http'] );
		WPS_Assert::same( 'conflict', $second['status'] );

		// Original order total preserved intact
		WPS_Assert::equals( 2490.0, $order->get_total() ); // 1000 + 1490 shipping

		// Note must document discrepancy
		$notes = $order->get_customer_notes();
		WPS_Assert::contains( 'eltérő végösszeg', end( $notes ) );
	}
);

$suite->add(
	'replay: historical order without hash rejects altered currency with 409',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 705,
				'name'       => 'Historical Currency Test',
				'sku'        => 'SKU-HIST-CURR',
				'price'      => '1000',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-HIST-CURR-1';
		$payload     = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-705',
						'sku'      => 'SKU-HIST-CURR',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 1000,
						'vat'      => 0,
					),
				),
			)
		);

		$importer = new Importer();
		$first    = $importer->import( $payload );
		$order_id = $first['order_id'];

		$order = WPS_Test_State::$orders[ $order_id ];
		$order->delete_meta_data( Importer::META_PAYLOAD_HASH );
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'payload_hash' => null ),
			array( 'external_id' => $external_id )
		);

		// Send altered currency: EUR
		$altered = $payload;
		$altered['products'][0]['currency'] = 'EUR';

		$second = $importer->import( $altered );
		WPS_Assert::same( 409, $second['http'] );
		WPS_Assert::same( 'conflict', $second['status'] );
		WPS_Assert::same( 'HUF', $order->get_currency() );

		$notes = $order->get_customer_notes();
		WPS_Assert::contains( 'eltérő pénznem', end( $notes ) );
	}
);

$suite->add(
	'concurrency: atomic claim transitions and conditional reclaim under load',
	static function (): void {
		$external_id = 'PEP-CONCUR-ATOMIC-1';
		$payload     = '{"id":"' . $external_id . '"}';
		$hash        = hash( 'sha256', $payload );

		// 1. Thread A wins initial claim
		$first = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'claimed', $first['state'] );

		// 2. Thread B attempts concurrent claim -> returns busy
		$second = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'busy', $second['state'] );

		// 3. Thread A fails claim
		Order_Repository::fail( $external_id, 'Simulated transient failure' );

		// 4. Thread C retries after failure -> wins claim
		$third = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'claimed', $third['state'] );
		WPS_Assert::same( 2, (int) $third['row']['attempts'] );

		// 5. Complete claim
		Order_Repository::complete( $external_id, 4242 );

		// 6. Any subsequent claim is completed
		$fourth = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'completed', $fourth['state'] );
	}
);
