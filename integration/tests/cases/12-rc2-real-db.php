<?php
/**
 * RC2 verification against the real MySQL / WordPress / WooCommerce stack (HPOS and legacy).
 *
 * Covers: order API default OFF over real HTTP, concurrent and repeated deliveries of one order
 * (no duplicate order, no duplicate stock reduction), expired worker lease and fencing, interrupted
 * finalization, database-error recovery, and merchant profile import lock / rollback / journal recovery.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Merchant_Profiles;
use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Installer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

WPS_IT::line( 'RC2 real database verification' );

$run      = WPS_IT::run_id();
$endpoint = '/wp-json/wps/v1/pepita/order';

/**
 * Current managed stock of the seeded simple product (null when unmanaged).
 */
$wps_it_stock = static function (): ?int {
	$seed    = WPS_IT::seed();
	$product = wc_get_product( (int) $seed['p1'] );

	return $product instanceof \WC_Product && $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
};

WPS_IT::test(
	'Order API: a fresh install is OFF and answers 503 over real HTTP, authenticated or not',
	static function () use ( $endpoint, $run ): void {
		$saved = get_option( Settings::OPTION );

		try {
			delete_option( Settings::OPTION );
			delete_option( Installer::ORDER_API_MIGRATION_OPTION );
			Settings::reset_cache();
			Installer::activate();

			WPS_IT::same( false, (bool) Settings::get( 'order_api_enabled' ), 'A freshly activated plugin must have the order API OFF.' );

			$key     = Settings::secret( 'order_api_key' );
			$payload = WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-RC2-OFF-' . $run );

			$unauth = WPS_IT::post_json( $endpoint, $payload );
			WPS_IT::same( 503, $unauth['code'], 'An unauthenticated request must receive 503 while OFF.' );

			$auth = WPS_IT::post_json( add_query_arg( 'apikey', rawurlencode( $key ), $endpoint ), $payload );
			WPS_IT::same( 503, $auth['code'], 'An authenticated request must receive 503 while OFF.' );
			WPS_IT::same( 0, count( WPS_IT::orders_by_external_id( 'IT-RC2-OFF-' . $run ) ), 'No order may exist while OFF.' );
		} finally {
			update_option( Settings::OPTION, $saved, false );
			Settings::reset_cache();
		}
	}
);

WPS_IT::test(
	'Order API: a legacy auto-enabled install is switched off by the migration and keeps its credentials',
	static function () use ( $endpoint, $run ): void {
		$saved = get_option( Settings::OPTION );
		$key   = Settings::secret( 'order_api_key' );

		try {
			Settings::update( array( 'order_api_enabled' => true ) );
			delete_option( Installer::ORDER_API_MIGRATION_OPTION );
			delete_option( Installer::ORDER_API_OPTIN_OPTION );
			delete_option( Installer::ORDER_API_LEGACY_DISABLED_OPTION );

			Installer::maybe_upgrade();

			WPS_IT::same( false, (bool) Settings::get( 'order_api_enabled' ), 'Unverified legacy enablement must be switched off.' );
			WPS_IT::same( $key, Settings::secret( 'order_api_key' ), 'The existing key must be kept.' );

			$response = WPS_IT::post_json(
				add_query_arg( 'apikey', rawurlencode( $key ), $endpoint ),
				WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-RC2-LEGACY-' . $run )
			);
			WPS_IT::same( 503, $response['code'], 'The migrated store must refuse orders over HTTP.' );
		} finally {
			update_option( Settings::OPTION, $saved, false );
			update_option( Installer::ORDER_API_MIGRATION_OPTION, '1', false );
			delete_option( Installer::ORDER_API_LEGACY_DISABLED_OPTION );
			Settings::reset_cache();
		}
	}
);

WPS_IT::test(
	'Concurrent deliveries of one order create exactly one WooCommerce order and reduce stock once',
	static function () use ( $endpoint, $run, $wps_it_stock ): void {
		WPS_IT::clear_rate_limit( WPS_IT::remote_ip() );

		// Sequential baseline: how much stock one delivery of this fixture consumes.
		$stock_start = $wps_it_stock();
		$single      = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-RC2-BASE-' . $run ) );
		WPS_IT::same( 200, $single['code'], 'The baseline delivery failed: ' . $single['body'] );
		$stock_after_single = $wps_it_stock();
		$unit_delta         = null === $stock_start || null === $stock_after_single ? null : $stock_start - $stock_after_single;

		$external_id = 'IT-RC2-CONC-' . $run;
		$payload     = (string) wp_json_encode( WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id ) );
		$url         = WPS_IT::base_url() . add_query_arg( 'apikey', rawurlencode( Settings::secret( 'order_api_key' ) ), $endpoint );

		$requests = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$requests[ 'r' . $i ] = array(
				'url'     => $url,
				'type'    => 'POST',
				'headers' => array(
					'Content-Type'    => 'application/json; charset=utf-8',
					'Accept-Encoding' => 'identity',
				),
				'data'    => $payload,
			);
		}

		$stock_before = $wps_it_stock();
		$responses    = \WpOrg\Requests\Requests::request_multiple(
			$requests,
			array(
				'timeout' => 60,
				'verify'  => false,
			)
		);

		$ok   = 0;
		$busy = 0;
		foreach ( $responses as $response ) {
			WPS_IT::true( $response instanceof \WpOrg\Requests\Response, 'A concurrent request failed at transport level.' );
			$code = (int) $response->status_code;
			WPS_IT::true( 200 === $code || 409 === $code, 'Unexpected status ' . $code . ': ' . substr( (string) $response->body, 0, 200 ) );
			200 === $code ? ++$ok : ++$busy;
		}

		WPS_IT::greater( 0, $ok, 'At least one concurrent delivery must succeed.' );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'Concurrent deliveries created a duplicate WooCommerce order.' );

		// Duplicate webhook delivery after completion (the usual dispatcher retry).
		for ( $i = 0; $i < 3; $i++ ) {
			$again = WPS_IT::post_order( json_decode( $payload, true ) );
			WPS_IT::same( 200, $again['code'], 'A duplicate webhook delivery must return 200: ' . $again['body'] );
		}

		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'Repeated deliveries created a duplicate order.' );

		$row = Order_Repository::get( $external_id );
		WPS_IT::same( 'completed', (string) ( $row['status'] ?? '' ), 'The claim must end completed.' );

		if ( null === $unit_delta || null === $stock_before ) {
			WPS_IT::line( '        NOT VERIFIED: stock is not managed for the seeded product.' );
			return;
		}

		WPS_IT::same( $unit_delta, $stock_before - $wps_it_stock(), 'Stock was reduced more than once for one order.' );
	}
);

WPS_IT::test(
	'An expired worker lease is reclaimed and the stale worker is fenced out',
	static function () use ( $run ): void {
		global $wpdb;

		$external_id = 'IT-RC2-LEASE-' . $run;
		$payload     = '{"id":"' . $external_id . '"}';
		$hash        = hash( 'sha256', $payload );

		try {
			$first = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
			WPS_IT::same( 'claimed', (string) $first['state'], 'The first claim must win.' );
			$attempt_a = (int) $first['row']['attempts'];

			$busy = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
			WPS_IT::same( 'busy', (string) $busy['state'], 'A fresh lease must not be reclaimed.' );

			// The lease expires (worker stalled for longer than the stale window).
			$wpdb->update(
				Order_Repository::table(),
				array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
				array( 'external_id' => $external_id ),
				array( '%s' ),
				array( '%s' )
			);

			$second = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );
			WPS_IT::same( 'claimed', (string) $second['state'], 'An expired lease must be reclaimed.' );
			$attempt_b = (int) $second['row']['attempts'];
			WPS_IT::true( $attempt_b > $attempt_a, 'The reclaim must advance the attempt (fencing token).' );

			// The stalled first worker wakes up: every write with its old attempt is rejected.
			WPS_IT::false( Order_Repository::complete( $external_id, 111, $hash, $attempt_a ), 'A stale worker must not complete the claim.' );
			WPS_IT::false( Order_Repository::fail( $external_id, 'stale failure', $attempt_a ), 'A stale worker must not fail the claim.' );
			WPS_IT::same( 'processing', (string) Order_Repository::get( $external_id )['status'], 'Stale writes must not change the claim.' );

			WPS_IT::true( Order_Repository::complete( $external_id, 222, $hash, $attempt_b ), 'The current owner must be able to complete.' );
			$row = Order_Repository::get( $external_id );
			WPS_IT::same( 222, (int) $row['wc_order_id'], 'The current owner order id must win.' );
		} finally {
			$wpdb->delete( Order_Repository::table(), array( 'external_id' => $external_id ), array( '%s' ) );
		}
	}
);

WPS_IT::test(
	'An interrupted finalization (order created, claim never completed) is recovered without a duplicate',
	static function () use ( $run ): void {
		global $wpdb;

		$external_id = 'IT-RC2-FINAL-' . $run;
		$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );

		$first = WPS_IT::post_order( $payload );
		WPS_IT::same( 200, $first['code'], 'The first delivery failed: ' . $first['body'] );
		$order_id = (int) $first['json']['orderId'];

		// Simulate a crash after the WooCommerce order exists but before the claim was completed.
		$wpdb->update(
			Order_Repository::table(),
			array(
				'status'      => 'processing',
				'wc_order_id' => 0,
				'updated_at'  => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
			),
			array( 'external_id' => $external_id ),
			array( '%s', '%d', '%s' ),
			array( '%s' )
		);

		$retry = WPS_IT::post_order( $payload );
		WPS_IT::same( 200, $retry['code'], 'Recovery of an interrupted finalization must return 200: ' . $retry['body'] );
		WPS_IT::same( $order_id, (int) $retry['json']['orderId'], 'Recovery must return the existing order.' );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'Recovery created a duplicate order.' );
		WPS_IT::same( 'completed', (string) Order_Repository::get( $external_id )['status'], 'The claim must be completed after recovery.' );
	}
);

WPS_IT::test(
	'A database error fails closed (no order), and redelivery succeeds once the database is back',
	static function () use ( $run ): void {
		global $wpdb;

		$external_id = 'IT-RC2-DBERR-' . $run;
		$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );
		$table       = Order_Repository::table();
		$hidden      = $table . '_off';

		$wpdb->query( "RENAME TABLE `{$table}` TO `{$hidden}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		try {
			$failed = WPS_IT::post_order( $payload );
			WPS_IT::true( $failed['code'] >= 500, 'A missing claims table must fail closed with a 5xx, got ' . $failed['code'] . '.' );
			WPS_IT::same( true, (bool) ( $failed['json']['isError'] ?? false ), 'The failure must be a JSON error envelope.' );
			WPS_IT::same( 0, count( WPS_IT::orders_by_external_id( $external_id ) ), 'No order may be created without a durable claim.' );
		} finally {
			$wpdb->query( "RENAME TABLE `{$hidden}` TO `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Order_Repository::reset_table_cache();
		}

		$retry = WPS_IT::post_order( $payload );
		WPS_IT::same( 200, $retry['code'], 'Redelivery after the database recovered must succeed: ' . $retry['body'] );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'Exactly one order must exist after recovery.' );
	}
);

WPS_IT::test(
	'Profile import lock: atomic acquisition, lease renewal and exact-state reclaim on real MySQL',
	static function (): void {
		delete_option( Merchant_Profiles::LOCK_OPTION );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );

		$a = Merchant_Profiles::acquire_import_lock();
		WPS_IT::true( ! empty( $a ), 'The first acquisition must win.' );
		WPS_IT::same( null, Merchant_Profiles::acquire_import_lock(), 'A second acquisition must fail while the lease is live.' );

		WPS_IT::true( Merchant_Profiles::renew_import_lock( $a ), 'The owner must renew its lease.' );
		WPS_IT::false( Merchant_Profiles::renew_import_lock( 'someone-else' ), 'A non-owner must not renew.' );

		// Force the lease to expire, then prove a reclaimer that observed an older state cannot delete a renewed lease.
		$expired = time() - 30;
		update_option( Merchant_Profiles::LOCK_OPTION, array( 'token' => $a, 'expires' => $expired, 'pid' => 0 ), false );
		WPS_IT::false( Merchant_Profiles::delete_lock_if_stale( Merchant_Profiles::LOCK_OPTION, $a, $expired - 5 ), 'A mismatched observed expiry must not delete the lock.' );
		WPS_IT::true( Merchant_Profiles::renew_import_lock( $a ), 'The owner renews just before the reclaim.' );
		WPS_IT::false( Merchant_Profiles::delete_lock_if_stale( Merchant_Profiles::LOCK_OPTION, $a, $expired ), 'A renewed lease must survive a reclaim based on the stale observation.' );
		WPS_IT::true( Merchant_Profiles::is_import_locked(), 'The renewed lock must still be held.' );

		// A genuinely expired, unrenewed lease is reclaimable by exactly one process.
		update_option( Merchant_Profiles::LOCK_OPTION, array( 'token' => $a, 'expires' => $expired, 'pid' => 0 ), false );
		$b = Merchant_Profiles::acquire_import_lock();
		WPS_IT::true( ! empty( $b ) && $b !== $a, 'An expired lease must be reclaimed by a new owner.' );
		WPS_IT::false( Merchant_Profiles::renew_import_lock( $a ), 'The former owner is fenced out.' );

		Merchant_Profiles::release_import_lock( $a );
		WPS_IT::true( Merchant_Profiles::is_import_locked(), 'A former owner must not release the new owner lock.' );
		Merchant_Profiles::release_import_lock( $b );
		WPS_IT::false( Merchant_Profiles::is_import_locked(), 'The owner releases its lock.' );
	}
);

WPS_IT::test(
	'Profile import: rollback and journal recovery restore settings and category meta on real MySQL',
	static function () use ( $run ): void {
		delete_option( Merchant_Profiles::LOCK_OPTION );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );

		$slug_a = 'wps-it-a-' . strtolower( $run );
		$slug_b = 'wps-it-b-' . strtolower( $run );
		$term_a = wp_insert_term( 'WPS IT A ' . $run, Category_Rules::TAXONOMY, array( 'slug' => $slug_a ) );
		$term_b = wp_insert_term( 'WPS IT B ' . $run, Category_Rules::TAXONOMY, array( 'slug' => $slug_b ) );
		WPS_IT::true( ! is_wp_error( $term_a ) && ! is_wp_error( $term_b ), 'Could not create the test categories.' );
		$id_a = (int) $term_a['term_id'];
		$id_b = (int) $term_b['term_id'];

		$original_delay = Settings::get( 'shipping_delay' );
		$key_before     = Settings::secret( 'order_api_key' );
		$enabled_before = (bool) Settings::get( 'order_api_enabled' );
		$new_delay      = (int) $original_delay === 7 ? 8 : 7;

		$profile = array(
			'schema_version' => 1,
			'generator'      => 'wp-pepita-sync',
			'settings'       => array(
				'shipping_delay'    => $new_delay,
				'order_api_enabled' => ! $enabled_before,
				'order_api_key'     => 'attacker-controlled-key',
			),
			'category_rules' => array(
				$slug_a => array( 'name' => 'A', 'rules' => array( 'price_value' => '11' ) ),
				$slug_b => array( 'name' => 'B', 'rules' => array( 'price_value' => '22' ) ),
			),
		);

		try {
			// 1. Failure on the second category: everything is rolled back cleanly.
			$failed = Merchant_Profiles::import_profile( $profile, array( 'fail_on_slug' => $slug_b ) );
			WPS_IT::false( $failed['success'], 'The injected failure must fail the import.' );
			WPS_IT::true( $failed['rolled_back'] && ( $failed['rollback_clean'] ?? false ), 'The rollback must be clean.' );
			WPS_IT::same( $original_delay, Settings::get( 'shipping_delay' ), 'Settings must be restored by the rollback.' );
			WPS_IT::same( '', (string) get_term_meta( $id_a, Category_Rules::META_PRICE_VALUE, true ), 'Category meta must be restored (absent).' );
			WPS_IT::false( get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'The journal must be removed after a clean rollback.' );

			// 2. A successful import applies allowlisted values, never secrets or the order API switch.
			$ok = Merchant_Profiles::import_profile( $profile );
			WPS_IT::true( $ok['success'], 'The import must succeed: ' . $ok['message'] );
			WPS_IT::same( $new_delay, Settings::get( 'shipping_delay' ), 'The allowlisted setting must be imported.' );
			WPS_IT::same( '11', (string) get_term_meta( $id_a, Category_Rules::META_PRICE_VALUE, true ), 'Category rule A must be imported.' );
			WPS_IT::same( $key_before, Settings::secret( 'order_api_key' ), 'A profile must never replace the API key.' );
			WPS_IT::same( $enabled_before, (bool) Settings::get( 'order_api_enabled' ), 'A profile must never change the order API switch.' );
			WPS_IT::false( get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'The journal must be removed after success.' );
			WPS_IT::false( Merchant_Profiles::is_import_locked(), 'The lock must be released after the import.' );

			// 3. An interrupted import (dead owner left a journal): the next import recovers first.
			update_option(
				Merchant_Profiles::JOURNAL_OPTION,
				array(
					'token'              => 'dead-owner',
					'timestamp'          => time() - 600,
					'original_settings'  => array( 'shipping_delay' => $original_delay ),
					'original_term_meta' => array(
						$id_a => array( Category_Rules::META_PRICE_VALUE => array( 'exists' => false, 'value' => null ) ),
					),
				),
				false
			);
			$recovered = Merchant_Profiles::recover_interrupted_import();
			WPS_IT::true( $recovered['recovered'] && $recovered['rollback_clean'], 'Recovery of the dead owner journal must be clean.' );
			WPS_IT::same( $original_delay, Settings::get( 'shipping_delay' ), 'Recovery must restore the setting.' );
			WPS_IT::same( '', (string) get_term_meta( $id_a, Category_Rules::META_PRICE_VALUE, true ), 'Recovery must remove the imported meta.' );
			WPS_IT::false( get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'The recovered journal must be removed.' );
		} finally {
			Settings::update( array( 'shipping_delay' => $original_delay ) );
			wp_delete_term( $id_a, Category_Rules::TAXONOMY );
			wp_delete_term( $id_b, Category_Rules::TAXONOMY );
			delete_option( Merchant_Profiles::JOURNAL_OPTION );
			delete_option( Merchant_Profiles::LOCK_OPTION );
		}
	}
);
