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
		$shell->update_meta_data( '_wps_pepita_in_progress', '1' );
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
			array( 'payload_hash' => '' ),
			array( 'external_id' => $external_id )
		);

		WPS_Assert::same( '', $order->get_meta( Importer::META_PAYLOAD_HASH ) );
		WPS_Assert::same( '', (string) Order_Repository::get( $external_id )['payload_hash'] );

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
		$notes = $order->get_all_notes();
		WPS_Assert::contains( 'megerősítve és hash rögzítve', end( $notes ) );
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
			array( 'payload_hash' => '' ),
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
		$notes = $order->get_all_notes();
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
			array( 'payload_hash' => '' ),
			array( 'external_id' => $external_id )
		);

		// Send altered currency: EUR
		$altered = $payload;
		$altered['products'][0]['currency'] = 'EUR';

		$second = $importer->import( $altered );
		WPS_Assert::same( 409, $second['http'] );
		WPS_Assert::same( 'conflict', $second['status'] );
		WPS_Assert::same( 'HUF', $order->get_currency() );

		$notes = $order->get_all_notes();
		WPS_Assert::contains( 'eltérő pénznem', end( $notes ) );
	}
);

$suite->add(
	'replay: historical order without hash fails closed when products are missing or invalid',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 706,
				'name'       => 'Historical Products Fail-Closed',
				'sku'        => 'SKU-HIST-FC',
				'price'      => '2500',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-HIST-FC-1';
		$payload     = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-706',
						'sku'      => 'SKU-HIST-FC',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 2500,
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
			array( 'payload_hash' => '' ),
			array( 'external_id' => $external_id )
		);

		// Replay with missing products array: must fail closed with 409
		$broken = $payload;
		unset( $broken['products'] );

		$second = $importer->import( $broken );
		WPS_Assert::same( 409, $second['http'] );
		WPS_Assert::same( 'conflict', $second['status'] );
	}
);

$suite->add(
	'replay: historical order without hash rejects same total with different SKU',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 707,
				'name'       => 'Original SKU Product',
				'sku'        => 'SKU-ORIG-707',
				'price'      => '3000',
				'tax_status' => 'none',
			)
		);

		WPS_Test_State::add_product(
			array(
				'id'         => 708,
				'name'       => 'Substituted SKU Product',
				'sku'        => 'SKU-SUBST-708',
				'price'      => '3000',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-HIST-SKU-SUBST';
		$payload     = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-707',
						'sku'      => 'SKU-ORIG-707',
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
		$order_id = $first['order_id'];

		$order = WPS_Test_State::$orders[ $order_id ];
		$order->delete_meta_data( Importer::META_PAYLOAD_HASH );
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'payload_hash' => '' ),
			array( 'external_id' => $external_id )
		);

		// Substituted payload: same total (4490), same quantity (1), but different SKU (SKU-SUBST-708)
		$substituted = $payload;
		$substituted['products'][0]['id']  = 'wps-708';
		$substituted['products'][0]['sku'] = 'SKU-SUBST-708';

		$second = $importer->import( $substituted );
		WPS_Assert::same( 409, $second['http'] );
		WPS_Assert::same( 'conflict', $second['status'] );

		$notes = $order->get_all_notes();
		WPS_Assert::contains( 'tétel nem egyezik', end( $notes ) );
	}
);

$suite->add(
	'recovery: claim hash is updated to match new order after crash recovery',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 709,
				'name'       => 'Recovery Hash Sync Product',
				'sku'        => 'SKU-REC-SYNC',
				'price'      => '1200',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-REC-HASH-SYNC';
		$payload_a   = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-709',
						'sku'      => 'SKU-REC-SYNC',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 1200,
						'vat'      => 0,
					),
				),
			)
		);
		$hash_a      = Importer::hash_payload( $payload_a );

		// Simulate claim left with hash A and broken shell with marker
		$shell = new WC_Order(
			array(
				'created_via' => 'wps-pepita',
				'currency'    => 'HUF',
			)
		);
		$shell->save();
		$shell_id = $shell->get_id();
		$shell->update_meta_data( Importer::META_ORDER_ID, $external_id );
		$shell->update_meta_data( Importer::META_PAYLOAD_HASH, $hash_a );
		$shell->update_meta_data( '_wps_pepita_in_progress', '1' );
		$shell->save_meta_data();

		Order_Repository::claim( $external_id, 'pepita.hu', '{}', $hash_a );
		Order_Repository::associate( $external_id, $shell_id );

		// Make claim stale
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1000 ) ),
			array( 'external_id' => $external_id )
		);

		// Now webhook delivers payload B (updated price or quantity)
		$payload_b = $payload_a;
		$payload_b['products'][0]['quantity'] = 2;
		$hash_b    = Importer::hash_payload( $payload_b );

		$importer = new Importer();
		$result   = $importer->import( $payload_b );

		WPS_Assert::same( 200, $result['http'] );
		WPS_Assert::same( 'created', $result['status'] );
		$new_order_id = $result['order_id'];

		// Claim table must now store hash B, NOT hash A
		$claim_row = Order_Repository::get( $external_id );
		WPS_Assert::same( 'completed', $claim_row['status'] );
		WPS_Assert::same( $new_order_id, (int) $claim_row['wc_order_id'] );
		WPS_Assert::same( $hash_b, $claim_row['payload_hash'] );

		// Subsequent delivery of payload B returns duplicate 200
		$dup = $importer->import( $payload_b );
		WPS_Assert::same( 200, $dup['http'] );
		WPS_Assert::same( 'duplicate', $dup['status'] );

		// Subsequent delivery of superseded payload A returns 409 Conflict
		$superseded = $importer->import( $payload_a );
		WPS_Assert::same( 409, $superseded['http'] );
		WPS_Assert::same( 'conflict', $superseded['status'] );
	}
);

$suite->add(
	'replay: legacy completed claim with wc_order_id = 0 recovers order via external_id meta',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 710,
				'name'       => 'Legacy Order Zero ID',
				'sku'        => 'SKU-LEGACY-ZERO',
				'price'      => '4000',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-LEGACY-ZERO-ID';
		$payload     = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-710',
						'sku'      => 'SKU-LEGACY-ZERO',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 4000,
						'vat'      => 0,
					),
				),
			)
		);
		$hash        = Importer::hash_payload( $payload );

		$importer = new Importer();
		$first    = $importer->import( $payload );
		WPS_Assert::same( 200, $first['http'] );
		$order_id = $first['order_id'];

		// Simulate legacy database where wc_order_id was 0 on a completed claim
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'wc_order_id' => 0 ),
			array( 'external_id' => $external_id )
		);

		// Replay delivery: recovers order ID from meta lookup and completes claim
		$second = $importer->import( $payload );
		WPS_Assert::same( 200, $second['http'] );
		WPS_Assert::same( 'duplicate', $second['status'] );
		WPS_Assert::same( $order_id, $second['order_id'] );
		WPS_Assert::same( $order_id, (int) Order_Repository::get( $external_id )['wc_order_id'] );
	}
);

$suite->add(
	'concurrency: claim state transitions and stale processing reclaim',
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

		// 3. Stale lease reclaim: set updated_at back 16 minutes
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1000 ) ),
			array( 'external_id' => $external_id )
		);

		// 4. Thread C retries after stale lease -> reclaims lock
		$third = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'claimed', $third['state'] );
		WPS_Assert::same( 2, (int) $third['row']['attempts'] );

		// 5. Thread C completes claim
		Order_Repository::complete( $external_id, 4242, $hash, (int) $third['row']['attempts'] );

		// 6. Subsequent claim is completed
		$fourth = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'completed', $fourth['state'] );
	}
);

$suite->add(
	'creation: order is born with marker and external id in initial save',
	static function () use ( $recovery_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'         => 711,
				'name'       => 'Born With Marker Product',
				'sku'        => 'SKU-BORN-MARKER',
				'price'      => '500',
				'tax_status' => 'none',
			)
		);

		$external_id = 'PEP-BORN-MARKER-1';
		$payload     = $recovery_payload(
			array(
				'id'       => $external_id,
				'products' => array(
					array(
						'id'       => 'wps-711',
						'sku'      => 'SKU-BORN-MARKER',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 500,
						'vat'      => 0,
					),
				),
			)
		);

		$importer = new Importer();
		$result   = $importer->import( $payload );
		WPS_Assert::same( 200, $result['http'] );
		$order = WPS_Test_State::$orders[ $result['order_id'] ];

		// Upon successful completion, marker is removed and imported_at is set
		WPS_Assert::same( '', $order->get_meta( '_wps_pepita_in_progress' ) );
		WPS_Assert::not_same( '', $order->get_meta( Importer::META_IMPORTED_AT ) );
		WPS_Assert::same( $external_id, $order->get_meta( Importer::META_ORDER_ID ) );
	}
);

$suite->add(
	'lease fencing: stale worker cannot overwrite newly reclaimed attempt',
	static function (): void {
		$external_id = 'PEP-FENCE-ATTEMPT-1';
		$payload     = '{"id":"PEP-FENCE-ATTEMPT-1"}';
		$hash        = hash( 'sha256', $payload );

		// 1. Worker A claims (attempt 1)
		$claim_a = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'claimed', $claim_a['state'] );
		WPS_Assert::same( 1, (int) $claim_a['row']['attempts'] );

		// 2. Simulate stale lease (set updated_at back 16 minutes)
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1000 ) ),
			array( 'external_id' => $external_id )
		);

		// 3. Worker B reclaims stale lease (attempt 2)
		$claim_b = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'claimed', $claim_b['state'] );
		WPS_Assert::same( 2, (int) $claim_b['row']['attempts'] );

		// 4. Stale Worker A wakes up and tries to associate with attempt 1 -> ignored (fenced)
		Order_Repository::associate( $external_id, 9991, 1 );
		$row = Order_Repository::get( $external_id );
		WPS_Assert::same( 0, (int) $row['wc_order_id'] );

		// 5. Stale Worker A tries to complete with attempt 1 -> ignored (fenced)
		Order_Repository::complete( $external_id, 9991, $hash, 1 );
		$row = Order_Repository::get( $external_id );
		WPS_Assert::same( 'processing', $row['status'] );
		WPS_Assert::same( 0, (int) $row['wc_order_id'] );

		// 6. Stale Worker A tries to fail with attempt 1 -> ignored (fenced)
		Order_Repository::fail( $external_id, 'Worker A failed', 1 );
		$row = Order_Repository::get( $external_id );
		WPS_Assert::same( 'processing', $row['status'] );

		// 7. Worker B completes with attempt 2 -> succeeds
		Order_Repository::complete( $external_id, 9992, $hash, 2 );
		$row = Order_Repository::get( $external_id );
		WPS_Assert::same( 'completed', $row['status'] );
		WPS_Assert::same( 9992, (int) $row['wc_order_id'] );
	}
);

$suite->add(
	'fail: unfenced fail() transitions processing claim to failed and records error',
	static function (): void {
		$external_id = 'PEP-FAIL-UNFENCED-1';
		$payload     = '{"id":"PEP-FAIL-UNFENCED-1"}';
		$hash        = hash( 'sha256', $payload );

		$claim = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
		WPS_Assert::same( 'claimed', $claim['state'] );
		WPS_Assert::same( 'processing', $claim['row']['status'] );

		// Call unfenced fail()
		$result = Order_Repository::fail( $external_id, 'Dedikált hiba leírás' );
		WPS_Assert::true( $result );

		$row = Order_Repository::get( $external_id );
		WPS_Assert::same( 'failed', $row['status'] );
		WPS_Assert::same( 'Dedikált hiba leírás', $row['last_error'] );
	}
);

$suite->add(
	'importer: fenced out on create_order initial associate returns 409 retry and discards incomplete shell',
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

		$importer    = new Importer();
		$external_id = 'PEP-FENCE-IMP-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		$filter_fired = false;
		$filter       = static function ( $enabled ) use ( &$filter_fired, $external_id ) {
			if ( ! $filter_fired ) {
				$filter_fired = true;
				global $wpdb;
				$wpdb->update(
					Order_Repository::table(),
					array( 'attempts' => 2 ),
					array( 'external_id' => $external_id )
				);
			}
			return $enabled;
		};

		add_filter( 'woocommerce_email_enabled_new_order', $filter );
		$result = $importer->import( $payload );
		remove_filter( 'woocommerce_email_enabled_new_order', $filter );

		WPS_Assert::true( $filter_fired );
		WPS_Assert::same( 409, $result['http'] );
		WPS_Assert::same( 'retry', $result['status'] );

		// The incomplete order shell must be discarded
		$found_orders = wc_get_orders(
			array(
				'meta_key'   => Importer::META_ORDER_ID,
				'meta_value' => $external_id,
				'return'     => 'ids',
			)
		);
		$found_id = ! empty( $found_orders ) ? (int) $found_orders[0] : 0;
		WPS_Assert::same( 0, $found_id );
	}
);

$suite->add(
	'importer: fenced out on complete() returns 409 retry and preserves completed order for adoption',
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

		$importer    = new Importer();
		$external_id = 'PEP-FENCE-IMP-2';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		$save_count = 0;
		$filter     = static function ( $enabled ) use ( &$save_count, $external_id ) {
			$save_count++;
			// Advance attempts on the second save (final save) after pre-save check passed
			if ( 2 === $save_count ) {
				global $wpdb;
				$wpdb->update(
					Order_Repository::table(),
					array( 'attempts' => 2 ),
					array( 'external_id' => $external_id )
				);
			}
			return $enabled;
		};

		add_filter( 'woocommerce_email_enabled_new_order', $filter );
		$result = $importer->import( $payload );
		remove_filter( 'woocommerce_email_enabled_new_order', $filter );

		WPS_Assert::same( 409, $result['http'] );
		WPS_Assert::same( 'retry', $result['status'] );

		// CRITICAL (Mad-Eye Finding 1): Completed order must NOT be deleted!
		$found_orders = wc_get_orders(
			array(
				'meta_key'   => Importer::META_ORDER_ID,
				'meta_value' => $external_id,
				'return'     => 'ids',
			)
		);
		$order_id = ! empty( $found_orders ) ? (int) $found_orders[0] : 0;
		WPS_Assert::true( $order_id > 0 );
		$order = wc_get_order( $order_id );
		WPS_Assert::true( $order instanceof \WC_Order );
		WPS_Assert::same( '', (string) $order->get_meta( '_wps_pepita_in_progress' ) );

		// Simulate Worker B or next retry adopting the completed order
		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ),
			array( 'external_id' => $external_id )
		);

		$retry_result = $importer->import( $payload );
		WPS_Assert::same( 200, $retry_result['http'] );
		WPS_Assert::same( 'duplicate', $retry_result['status'] );
		WPS_Assert::same( $order_id, $retry_result['order_id'] );
	}
);

$suite->add(
	'importer: reclaimed replay fenced out on complete() returns 409 retry',
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

		$importer    = new Importer();
		$external_id = 'PEP-FENCE-REPLAY-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		// 1. Initial order import with historical missing hash
		$first = $importer->import( $payload );
		WPS_Assert::same( 200, $first['http'] );
		$order_id = (int) $first['order_id'];

		// Clear hashes and set claim to failed/stale so that replay reclaims (attempt 2)
		$order = wc_get_order( $order_id );
		$order->delete_meta_data( Importer::META_PAYLOAD_HASH );
		$order->save();

		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array(
				'status'       => Order_Repository::STATUS_FAILED,
				'payload_hash' => null,
				'updated_at'   => gmdate( 'Y-m-d H:i:s', time() - 2000 ),
				'last_error'   => 'Stale/failed for replay',
			),
			array( 'external_id' => $external_id )
		);

		// During replay in handle_existing_order, wc_get_order() is called before complete().
		// Intercept to bump attempts to 99, simulating another worker taking over the lease.
		$filter_fired = false;
		$filter       = static function ( $order ) use ( &$filter_fired, $external_id ) {
			if ( ! $filter_fired && $order instanceof \WC_Order ) {
				$filter_fired = true;
				global $wpdb;
				$wpdb->update(
					Order_Repository::table(),
					array( 'attempts' => 99 ),
					array( 'external_id' => $external_id )
				);
			}
			return $order;
		};

		add_filter( 'woocommerce_get_order', $filter );
		$replay_result = $importer->import( $payload );
		remove_filter( 'woocommerce_get_order', $filter );

		WPS_Assert::true( $filter_fired );
		WPS_Assert::same( 409, $replay_result['http'] );
		WPS_Assert::same( 'retry', $replay_result['status'] );
	}
);

$suite->add(
	'importer: recovery shell purge aborts with 409 retry if fenced out',
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

		$importer    = new Importer();
		$external_id = 'PEP-FENCE-PURGE-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		// Create an incomplete shell order
		$shell = new \WC_Order();
		$shell->set_created_via( 'wps-pepita' );
		$shell->update_meta_data( Importer::META_ORDER_ID, $external_id );
		$shell->update_meta_data( '_wps_pepita_in_progress', '1' );
		$shell_id = $shell->save();
		WPS_Assert::true( $shell_id > 0 );

		// Create claim for this order and make it stale (> 15 min) so import() can reclaim
		$claim = Order_Repository::claim( $external_id, 'pepita.hu', wp_json_encode( $payload ), 'hash-1' );
		WPS_Assert::same( 'claimed', $claim['state'] );

		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ),
			array( 'external_id' => $external_id )
		);

		// When the incomplete shell is deleted during recovery, bump attempts to fence Worker A
		$action_fired = false;
		$action       = static function ( $id ) use ( &$action_fired, $shell_id, $external_id ) {
			if ( (int) $id === $shell_id ) {
				$action_fired = true;
				global $wpdb;
				$wpdb->update(
					Order_Repository::table(),
					array( 'attempts' => 5 ),
					array( 'external_id' => $external_id )
				);
			}
		};

		add_action( 'woocommerce_delete_order', $action );
		$result = $importer->import( $payload );
		remove_action( 'woocommerce_delete_order', $action );

		WPS_Assert::true( $action_fired );
		WPS_Assert::same( 409, $result['http'] );
		WPS_Assert::same( 'retry', $result['status'] );
	}
);

$suite->add(
	'importer: claim returning non-claimed state fails closed with 500 error',
	static function () use ( $recovery_payload ): void {
		$importer    = new Importer();
		$external_id = 'PEP-CLAIM-FAIL-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

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

		// Drop table so claim() read returns null and returns state = 'failed'
		global $wpdb;
		$wpdb->drop_table( 'wp_wps_pepita_orders' );
		Order_Repository::reset_table_cache();

		$result = $importer->import( $payload );

		// Restore table
		$GLOBALS['wpdb'] = wps_test_new_wpdb();
		Order_Repository::reset_table_cache();

		WPS_Assert::same( 500, $result['http'] );
		WPS_Assert::same( 'error', $result['status'] );
		WPS_Assert::same( 'A rendelés rögzítése átmenetileg nem lehetséges.', $result['message'] );
	}
);

$suite->add(
	'repository: fence_incomplete_order_cleanup validates attempt and status atomically',
	static function (): void {
		$external_id = 'PEP-FENCE-UNIT-1';
		global $wpdb;

		// 1. Setup claim with status='processing', attempts=2, wc_order_id=505
		$claim = Order_Repository::claim( $external_id, 'pepita.hu', '{}', 'hash-1' );
		WPS_Assert::same( 'claimed', $claim['state'] );
		$attempt = (int) $claim['row']['attempts'];
		Order_Repository::associate( $external_id, 505, $attempt );

		// 2. Fencing with wrong attempt fails
		$failed_attempt = Order_Repository::fence_incomplete_order_cleanup( $external_id, 505, $attempt + 1 );
		WPS_Assert::false( $failed_attempt );
		$row = Order_Repository::get( $external_id );
		WPS_Assert::same( 505, (int) $row['wc_order_id'] );

		// 3. Fencing with mismatched expected order ID fails
		$failed_id = Order_Repository::fence_incomplete_order_cleanup( $external_id, 999, $attempt );
		WPS_Assert::false( $failed_id );
		$row = Order_Repository::get( $external_id );
		WPS_Assert::same( 505, (int) $row['wc_order_id'] );

		// 4. Fencing with matching attempt and order ID succeeds and zeroes wc_order_id
		$success = Order_Repository::fence_incomplete_order_cleanup( $external_id, 505, $attempt );
		WPS_Assert::true( $success );
		$row = Order_Repository::get( $external_id );
		WPS_Assert::same( 0, (int) $row['wc_order_id'] );
		WPS_Assert::same( 'processing', $row['status'] );
		WPS_Assert::same( $attempt, (int) $row['attempts'] );

		// 5. Subsequent fencing with wc_order_id = 0 still succeeds for current holder
		$success_repeat = Order_Repository::fence_incomplete_order_cleanup( $external_id, 0, $attempt );
		WPS_Assert::true( $success_repeat );

		// 6. If claim transitions to completed, cleanup fence fails
		Order_Repository::complete( $external_id, 505, 'hash-1', $attempt );
		$failed_completed = Order_Repository::fence_incomplete_order_cleanup( $external_id, 0, $attempt );
		WPS_Assert::false( $failed_completed );
	}
);

$suite->add(
	'importer: shell cleanup is aborted without deleting shell if worker loses lease before fence',
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

		$importer    = new Importer();
		$external_id = 'PEP-FENCE-PRE-CLEAN-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		// Create an incomplete shell order
		$shell = new \WC_Order();
		$shell->set_created_via( 'wps-pepita' );
		$shell->update_meta_data( Importer::META_ORDER_ID, $external_id );
		$shell->update_meta_data( '_wps_pepita_in_progress', '1' );
		$shell_id = $shell->save();
		WPS_Assert::true( $shell_id > 0 );

		// Create claim and make it stale (> 15 min)
		$claim = Order_Repository::claim( $external_id, 'pepita.hu', wp_json_encode( $payload ), 'hash-1' );
		WPS_Assert::same( 'claimed', $claim['state'] );
		Order_Repository::associate( $external_id, $shell_id, (int) $claim['row']['attempts'] );

		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ),
			array( 'external_id' => $external_id )
		);

		// Hook woocommerce_get_order: on the first call finding $shell_id, steal lease by bumping attempts to 99
		$stolen = false;
		$hook = static function ( $obj, $order_arg ) use ( &$stolen, $shell_id, $external_id ) {
			$id = $obj instanceof \WC_Order ? $obj->get_id() : (int) $order_arg;
			if ( $id === $shell_id && ! $stolen ) {
				$stolen = true;
				global $wpdb;
				$wpdb->update(
					Order_Repository::table(),
					array( 'attempts' => 99 ),
					array( 'external_id' => $external_id )
				);
			}
			return $obj;
		};

		add_filter( 'woocommerce_get_order', $hook, 10, 2 );
		$result = $importer->import( $payload );
		remove_filter( 'woocommerce_get_order', $hook, 10 );

		WPS_Assert::true( $stolen );
		WPS_Assert::same( 409, $result['http'] );
		WPS_Assert::same( 'retry', $result['status'] );

		// The incomplete shell must NOT be deleted because fencing failed
		WPS_Assert::true( isset( WPS_Test_State::$orders[ $shell_id ] ) );
	}
);

$suite->add(
	'importer: shell concurrently completed before cleanup is preserved and handed off',
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

		$importer    = new Importer();
		$external_id = 'PEP-FENCE-CONCURRENT-COMPLETION-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );
		$hash        = Importer::hash_payload( $payload );

		// Create an incomplete shell order
		$shell = new \WC_Order();
		$shell->set_created_via( 'wps-pepita' );
		$shell->update_meta_data( Importer::META_ORDER_ID, $external_id );
		$shell->update_meta_data( '_wps_pepita_in_progress', '1' );
		$shell_id = $shell->save();
		WPS_Assert::true( $shell_id > 0 );

		// Create claim and make it stale (> 15 min)
		$claim = Order_Repository::claim( $external_id, 'pepita.hu', wp_json_encode( $payload ), $hash );
		WPS_Assert::same( 'claimed', $claim['state'] );
		Order_Repository::associate( $external_id, $shell_id, (int) $claim['row']['attempts'] );

		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ),
			array( 'external_id' => $external_id )
		);

		// Hook woocommerce_get_order: on call #3 (fresh reload after fencing), simulate concurrent completion
		$calls = 0;
		$hook = static function ( $obj, $order_arg ) use ( &$calls, $shell_id, $hash ) {
			$id = $obj instanceof \WC_Order ? $obj->get_id() : (int) $order_arg;
			if ( $id === $shell_id ) {
				++$calls;
				if ( 3 === $calls && $obj instanceof \WC_Order ) {
					// Concurrent process completes the order right during the post-fence reload
					$obj->delete_meta_data( '_wps_pepita_in_progress' );
					$obj->update_meta_data( Importer::META_PAYLOAD_HASH, $hash );
					$obj->set_status( 'processing' );
					$obj->save();
				}
			}
			return $obj;
		};

		add_filter( 'woocommerce_get_order', $hook, 10, 2 );
		$result = $importer->import( $payload );
		remove_filter( 'woocommerce_get_order', $hook, 10 );

		// Exactly 4 calls to wc_get_order:
		// 1: find_order_by_external_id() lookup
		// 2: import() pre-fence inspection
		// 3: import() post-fence reload (where concurrent completion happens)
		// 4: handle_existing_order() loading the adopted order to verify hash and status
		WPS_Assert::same( 4, $calls );
		WPS_Assert::same( 200, $result['http'] );
		WPS_Assert::same( 'duplicate', $result['status'] );
		WPS_Assert::same( $shell_id, $result['order_id'] );

		// The shell was preserved, NOT deleted
		WPS_Assert::true( isset( WPS_Test_State::$orders[ $shell_id ] ) );
		WPS_Assert::same( 'processing', WPS_Test_State::$orders[ $shell_id ]->get_status() );

		// The claim was completed with the preserved shell ID
		$claim_row = Order_Repository::get( $external_id );
		WPS_Assert::same( 'completed', $claim_row['status'] );
		WPS_Assert::same( $shell_id, (int) $claim_row['wc_order_id'] );
	}
);

$suite->add(
	'importer: shell vanishing between fence and reload falls through to clean creation',
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

		$importer    = new Importer();
		$external_id = 'PEP-VANISHED-SHELL-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		// Create an incomplete shell order
		$shell = new \WC_Order();
		$shell->set_created_via( 'wps-pepita' );
		$shell->update_meta_data( Importer::META_ORDER_ID, $external_id );
		$shell->update_meta_data( '_wps_pepita_in_progress', '1' );
		$shell_id = $shell->save();
		WPS_Assert::true( $shell_id > 0 );

		// Create claim and make it stale (> 15 min)
		$claim = Order_Repository::claim( $external_id, 'pepita.hu', wp_json_encode( $payload ), 'hash-1' );
		WPS_Assert::same( 'claimed', $claim['state'] );
		Order_Repository::associate( $external_id, $shell_id, (int) $claim['row']['attempts'] );

		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ),
			array( 'external_id' => $external_id )
		);

		// Hook wc_get_order: on call #3 (post-fence reload), remove the shell completely from WPS_Test_State
		$calls = 0;
		$hook  = static function ( $obj, $order_arg ) use ( &$calls, $shell_id ) {
			$id = $obj instanceof \WC_Order ? $obj->get_id() : (int) $order_arg;
			if ( $id === $shell_id ) {
				++$calls;
				if ( 3 === $calls ) {
					unset( WPS_Test_State::$orders[ $shell_id ] );
					return false;
				}
			}
			return $obj;
		};

		add_filter( 'woocommerce_get_order', $hook, 10, 2 );
		$result = $importer->import( $payload );
		remove_filter( 'woocommerce_get_order', $hook, 10 );

		// On call #3 (post-fence reload), the shell was deleted/vanished, so no further calls with $shell_id occurred
		WPS_Assert::same( 3, $calls );
		WPS_Assert::same( 200, $result['http'] );
		WPS_Assert::same( 'created', $result['status'] );
		$new_id = $result['order_id'];
		WPS_Assert::not_same( $shell_id, $new_id );

		// Claim was completed to the new order, NOT the vanished shell
		$claim_row = Order_Repository::get( $external_id );
		WPS_Assert::same( 'completed', $claim_row['status'] );
		WPS_Assert::same( $new_id, (int) $claim_row['wc_order_id'] );
	}
);

$suite->add(
	'importer: shell cleanup is aborted if lease is lost at pre-delete check',
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

		$importer    = new Importer();
		$external_id = 'PEP-FENCE-PRE-DELETE-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		// Create an incomplete shell order
		$shell = new \WC_Order();
		$shell->set_created_via( 'wps-pepita' );
		$shell->update_meta_data( Importer::META_ORDER_ID, $external_id );
		$shell->update_meta_data( '_wps_pepita_in_progress', '1' );
		$shell_id = $shell->save();
		WPS_Assert::true( $shell_id > 0 );

		// Create claim and make it stale (> 15 min)
		$claim = Order_Repository::claim( $external_id, 'pepita.hu', wp_json_encode( $payload ), 'hash-1' );
		WPS_Assert::same( 'claimed', $claim['state'] );
		Order_Repository::associate( $external_id, $shell_id, (int) $claim['row']['attempts'] );

		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ),
			array( 'external_id' => $external_id )
		);

		// Hook woocommerce_get_order: on call #3 (fresh reload after fencing), steal the lease by bumping attempts to 999
		$calls = 0;
		$hook = static function ( $obj, $order_arg ) use ( &$calls, $shell_id, $external_id ) {
			$id = $obj instanceof \WC_Order ? $obj->get_id() : (int) $order_arg;
			if ( $id === $shell_id ) {
				++$calls;
				if ( 3 === $calls ) {
					global $wpdb;
					$wpdb->update(
						Order_Repository::table(),
						array( 'attempts' => 999 ),
						array( 'external_id' => $external_id )
					);
				}
			}
			return $obj;
		};

		add_filter( 'woocommerce_get_order', $hook, 10, 2 );
		$result = $importer->import( $payload );
		remove_filter( 'woocommerce_get_order', $hook, 10 );

		// On call #3, the lease was stolen; pre-delete check aborted immediately without further wc_get_order calls
		WPS_Assert::same( 3, $calls );
		WPS_Assert::same( 409, $result['http'] );
		WPS_Assert::same( 'retry', $result['status'] );

		// The shell was NOT deleted because pre-delete lease check failed
		WPS_Assert::true( isset( WPS_Test_State::$orders[ $shell_id ] ) );
	}
);

$suite->add(
	'importer: safe_cleanup_incomplete_order quarantines shell if delete throws exception',
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

		$importer    = new Importer();
		$external_id = 'PEP-QUARANTINE-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		// Create an incomplete shell order
		$shell = new \WC_Order();
		$shell->set_created_via( 'wps-pepita' );
		$shell->update_meta_data( Importer::META_ORDER_ID, $external_id );
		$shell->update_meta_data( '_wps_pepita_in_progress', '1' );
		$shell_id = $shell->save();
		WPS_Assert::true( $shell_id > 0 );

		// Create claim and make it stale (> 15 min)
		$claim = Order_Repository::claim( $external_id, 'pepita.hu', wp_json_encode( $payload ), 'hash-1' );
		WPS_Assert::same( 'claimed', $claim['state'] );
		Order_Repository::associate( $external_id, $shell_id, (int) $claim['row']['attempts'] );

		global $wpdb;
		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ),
			array( 'external_id' => $external_id )
		);

		// Make delete() throw an exception via hook
		$delete_action = static function ( $id ) use ( $shell_id ) {
			if ( (int) $id === $shell_id ) {
				throw new \RuntimeException( 'Simulated disk failure during order deletion' );
			}
		};

		add_action( 'woocommerce_delete_order', $delete_action );
		$result = $importer->import( $payload );
		remove_action( 'woocommerce_delete_order', $delete_action );

		WPS_Assert::same( 200, $result['http'] );
		WPS_Assert::same( 'created', $result['status'] );

		$new_order_id = $result['order_id'];
		WPS_Assert::not_same( $shell_id, $new_order_id );

		// The shell was quarantined, NOT deleted
		WPS_Assert::true( isset( WPS_Test_State::$orders[ $shell_id ] ) );
		$quarantined = WPS_Test_State::$orders[ $shell_id ];
		WPS_Assert::same( 'failed', $quarantined->get_status() );
		WPS_Assert::true( '' !== (string) $quarantined->get_meta( '_wps_pepita_quarantined_from' ) );
		// META_ORDER_ID must be cleared so it no longer collides
		WPS_Assert::same( '', (string) $quarantined->get_meta( Importer::META_ORDER_ID ) );
	}
);

$suite->add(
	'importer: duplicate webhook for completed order does not mutate claim table',
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

		$importer    = new Importer();
		$external_id = 'PEP-NO-MUTATE-DUP-1';
		$payload     = $recovery_payload( array( 'id' => $external_id ) );

		// Initial clean import
		$first_result = $importer->import( $payload );
		WPS_Assert::same( 200, $first_result['http'] );
		WPS_Assert::same( 'created', $first_result['status'] );
		$order_id = $first_result['order_id'];

		$claim_before = Order_Repository::get( $external_id );
		WPS_Assert::same( 'completed', $claim_before['status'] );
		WPS_Assert::same( $order_id, (int) $claim_before['wc_order_id'] );
		$updated_at_before = $claim_before['updated_at'];

		// Deliver identical duplicate replay
		$second_result = $importer->import( $payload );
		WPS_Assert::same( 200, $second_result['http'] );
		WPS_Assert::same( 'duplicate', $second_result['status'] );
		WPS_Assert::same( $order_id, $second_result['order_id'] );

		// Verify claim table was NOT mutated
		$claim_after = Order_Repository::get( $external_id );
		WPS_Assert::same( 'completed', $claim_after['status'] );
		WPS_Assert::same( $order_id, (int) $claim_after['wc_order_id'] );
		WPS_Assert::same( (int) $claim_before['attempts'], (int) $claim_after['attempts'] );
		WPS_Assert::same( $claim_before['payload_hash'], $claim_after['payload_hash'] );
		WPS_Assert::same( $updated_at_before, $claim_after['updated_at'] );
	}
);

$suite->add(
	'importer: is_order_incomplete is true for any unfinalized marker order (even with line items) and false for finished orders',
	static function (): void {
		$importer = new Importer();
		$ref      = new \ReflectionMethod( Importer::class, 'is_order_incomplete' );
		$ref->setAccessible( true );

		// 1. Order without in_progress flag is never incomplete
		$order1 = new \WC_Order();
		$order1->set_status( 'pending' );
		WPS_Assert::false( $ref->invoke( $importer, $order1 ) );

		// 2. Order with in_progress=1 and status completed is never incomplete
		$order2 = new \WC_Order();
		$order2->update_meta_data( '_wps_pepita_in_progress', '1' );
		$order2->set_status( 'completed' );
		WPS_Assert::false( $ref->invoke( $importer, $order2 ) );

		// 3. Order with in_progress=1 and status processing is never incomplete
		$order3 = new \WC_Order();
		$order3->update_meta_data( '_wps_pepita_in_progress', '1' );
		$order3->set_status( 'processing' );
		WPS_Assert::false( $ref->invoke( $importer, $order3 ) );

		// 4. RR20: the marker is cleared only by the final, lease-verified save, so an order that still
		// carries in_progress=1 is UNFINALIZED even when it already has line items. A competing worker
		// must never adopt and acknowledge it (it would be reported as imported with a partial total).
		$order4 = new \WC_Order();
		$order4->update_meta_data( '_wps_pepita_in_progress', '1' );
		$order4->set_status( 'pending' );
		$product = new \WC_Product( array( 'id' => 701, 'name' => 'Recovery Product 1' ) );
		$item    = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$order4->add_item( $item );
		WPS_Assert::true( $ref->invoke( $importer, $order4 ) );

		// 4b. ...but once the final save cleared the marker, the same populated order is complete.
		$order4->delete_meta_data( '_wps_pepita_in_progress' );
		WPS_Assert::false( $ref->invoke( $importer, $order4 ) );

		// 5. Order with in_progress=1 and status pending with empty items IS incomplete
		$order5 = new \WC_Order();
		$order5->update_meta_data( '_wps_pepita_in_progress', '1' );
		$order5->set_status( 'pending' );
		WPS_Assert::true( $ref->invoke( $importer, $order5 ) );
	}
);

$suite->add(
	'importer: replay of a completed claim whose order is missing is a 500 failure (never 200) and repair-claim lets it rebuild',
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

		$importer = new Importer();
		$payload  = $recovery_payload( array( 'id' => 'PEP-LOST-ORDER-1' ) );

		$first = $importer->import( $payload );
		WPS_Assert::same( 200, $first['http'] );
		$order_id = (int) $first['order_id'];
		WPS_Assert::true( $order_id > 0 );

		// RR20 victim: claim completed, WooCommerce order gone.
		unset( WPS_Test_State::$orders[ $order_id ] );

		$replay = $importer->import( $payload );
		WPS_Assert::same( 500, $replay['http'], 'A completed claim with a missing order must not be acknowledged.' );
		WPS_Assert::same( 'error', $replay['status'] );

		WPS_Assert::true( Order_Repository::reopen_completed( 'PEP-LOST-ORDER-1' ) );
		WPS_Assert::false( Order_Repository::reopen_completed( 'PEP-LOST-ORDER-1' ), 'Only a completed claim can be reopened.' );

		$again = $importer->import( $payload );
		WPS_Assert::same( 200, $again['http'] );
		WPS_Assert::same( 'created', $again['status'] );
		WPS_Assert::true( (int) $again['order_id'] > 0 );
	}
);

$suite->add(
	'importer: replay of a completed claim whose order still carries the in-progress marker is a 500 failure',
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

		$importer = new Importer();
		$payload  = $recovery_payload( array( 'id' => 'PEP-UNFINALIZED-1' ) );

		$first    = $importer->import( $payload );
		$order_id = (int) $first['order_id'];
		WPS_Assert::same( 200, $first['http'] );

		WPS_Test_State::$orders[ $order_id ]->update_meta_data( '_wps_pepita_in_progress', '1' );

		$replay = $importer->import( $payload );
		WPS_Assert::same( 500, $replay['http'], 'An unfinalized order must not be acknowledged on replay.' );
	}
);

$suite->add(
	'importer: an unfinalized marker order is incomplete whatever its non-final status (filtered default status)',
	static function (): void {
		$importer = new Importer();
		$ref      = new \ReflectionMethod( Importer::class, 'is_order_incomplete' );
		$ref->setAccessible( true );

		$order = new \WC_Order();
		$order->update_meta_data( '_wps_pepita_in_progress', '1' );
		$order->set_status( 'on-hold' );
		WPS_Assert::true( $ref->invoke( $importer, $order ) );

		$order->set_status( 'cancelled' );
		WPS_Assert::false( $ref->invoke( $importer, $order ), 'An operator-final status takes the order out of recovery.' );
	}
);
