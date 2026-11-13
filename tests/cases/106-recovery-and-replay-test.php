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
		Order_Repository::complete( $external_id, 4242, $hash );

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
