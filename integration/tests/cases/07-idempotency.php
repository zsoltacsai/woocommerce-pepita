<?php
/**
 * Durable idempotency and duplicate-delivery behaviour.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;

WPS_IT::line( 'Idempotency' );

$run = WPS_IT::run_id();

WPS_IT::test(
	'A repeated delivery returns the original order and changes nothing',
	static function () use ( $run ): void {
		$external_id = 'IT-DUPLICATE-' . $run;
		$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );

		$first = WPS_IT::post_order( $payload );

		WPS_IT::same( 200, $first['code'], 'The first delivery failed: ' . $first['body'] );

		$order_id = (int) $first['json']['orderId'];
		$order    = wc_get_order( $order_id );

		WPS_IT::instance( \WC_Order::class, $order, 'The first order is missing.' );

		$total_before = (float) $order->get_total();

		$second = WPS_IT::post_order( $payload );

		WPS_IT::same( 200, $second['code'], 'A duplicate delivery must return 200.' );
		WPS_IT::same( false, $second['json']['isError'], 'A duplicate delivery must not be an error.' );
		WPS_IT::same( $order_id, (int) $second['json']['orderId'], 'The duplicate returned a different order id.' );
		WPS_IT::contains( 'már korábban', (string) $second['json']['message'], 'The duplicate message is wrong.' );

		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'The duplicate created a second order.' );

		$reloaded = wc_get_order( $order_id );

		WPS_IT::close( $total_before, (float) $reloaded->get_total(), 0.01, 'The duplicate changed the order total.' );

		$row = Order_Repository::get( $external_id );

		WPS_IT::same( 'completed', (string) $row['status'], 'The claim was not completed.' );
		WPS_IT::same( 1, (int) $row['attempts'], 'A completed duplicate must not increment attempts.' );

		// A different payload with the same external id is a conflict: the
		// stored order must not be silently rewritten or reported as success.
		$changed = WPS_IT::order_payload( 'order-cod-paid.json', $external_id );
		$third   = WPS_IT::post_order( $changed );

		WPS_IT::same( 409, $third['code'], 'A changed duplicate must return 409.' );
		WPS_IT::same( true, (bool) $third['json']['isError'], 'A changed duplicate must be an error.' );

		$reloaded = wc_get_order( $order_id );

		WPS_IT::same( 'on-hold', $reloaded->get_status(), 'A changed duplicate modified the existing order.' );
		WPS_IT::close( $total_before, (float) $reloaded->get_total(), 0.01, 'A changed duplicate modified the total.' );

		$notes             = wc_get_order_notes( array( 'order_id' => $order_id ) );
		$has_conflict_note = false;
		foreach ( $notes as $n ) {
			if ( str_contains( (string) $n->content, 'NEW-2' ) || str_contains( (string) $n->content, 'eltérő tartalommal' ) ) {
				$has_conflict_note = true;
				break;
			}
		}
		WPS_IT::true( $has_conflict_note, 'Expected operator reconciliation note on existing order.' );
	}
);

WPS_IT::test(
	'External ids that differ only by case are distinct orders',
	static function () use ( $run ): void {
		$upper = 'IT-CASE-' . $run;
		$lower = strtolower( $upper );

		$first  = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $upper ) );
		$second = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $lower ) );

		WPS_IT::same( 200, $first['code'], 'The upper-case id failed: ' . $first['body'] );
		WPS_IT::same( 200, $second['code'], 'The lower-case id failed: ' . $second['body'] );
		WPS_IT::not_same( (int) $first['json']['orderId'], (int) $second['json']['orderId'], 'Case-different external ids collapsed into one order.' );
	}
);

WPS_IT::test(
	'The durable claim transitions claimed -> busy -> failed -> claimed -> completed',
	static function () use ( $run ): void {
		$external_id = 'IT-CLAIM-' . $run;
		$payload     = '{"id":"' . $external_id . '"}';
		$hash        = hash( 'sha256', $payload );

		$first = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );

		WPS_IT::same( 'claimed', (string) $first['state'], 'The first claim should win.' );

		$second = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );

		WPS_IT::same( 'busy', (string) $second['state'], 'A concurrent claim must be busy.' );

		Order_Repository::fail( $external_id, 'Simulated failure.' );

		$third = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );

		WPS_IT::same( 'claimed', (string) $third['state'], 'A failed claim must be retryable.' );
		WPS_IT::same( 2, (int) $third['row']['attempts'], 'The retry must increment attempts.' );

		Order_Repository::complete( $external_id, 424242 );

		$fourth = Order_Repository::claim( $external_id, 'pepita.hu', $payload, $hash );

		WPS_IT::same( 'completed', (string) $fourth['state'], 'A completed claim must stay completed.' );
		WPS_IT::same( 424242, (int) $fourth['row']['wc_order_id'], 'The completed claim lost the order id.' );

		global $wpdb;

		$wpdb->delete( Order_Repository::table(), array( 'external_id' => $external_id ), array( '%s' ) );
	}
);

WPS_IT::test(
	'A lost claim row recovers the existing order by its external id meta',
	static function () use ( $run ): void {
		$external_id = 'IT-CRASH-' . $run;
		$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );

		$first = WPS_IT::post_order( $payload );

		WPS_IT::same( 200, $first['code'], 'The first delivery failed: ' . $first['body'] );

		$order_id = (int) $first['json']['orderId'];

		global $wpdb;

		$wpdb->delete( Order_Repository::table(), array( 'external_id' => $external_id ), array( '%s' ) );
		WPS_IT::same( null, Order_Repository::get( $external_id ), 'The claim row was not removed for the crash simulation.' );

		$second = WPS_IT::post_order( $payload );

		WPS_IT::same( 200, $second['code'], 'Crash recovery must return 200.' );
		WPS_IT::same( $order_id, (int) $second['json']['orderId'], 'Crash recovery did not find the existing order.' );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'Crash recovery created a duplicate order.' );

		$row = Order_Repository::get( $external_id );

		WPS_IT::same( 'completed', (string) $row['status'], 'Crash recovery did not complete the new claim.' );
		WPS_IT::same( $order_id, (int) $row['wc_order_id'], 'Crash recovery stored the wrong order id.' );

		// A changed payload during crash recovery must be rejected with 409 and not modify the order
		$changed = WPS_IT::order_payload( 'order-cod-paid.json', $external_id );
		$wpdb->delete( Order_Repository::table(), array( 'external_id' => $external_id ), array( '%s' ) );

		$third = WPS_IT::post_order( $changed );

		WPS_IT::same( 409, $third['code'], 'A changed duplicate during crash recovery must return 409.' );
		WPS_IT::same( true, (bool) $third['json']['isError'], 'A changed duplicate during crash recovery must be an error.' );

		$reloaded = wc_get_order( $order_id );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'Crash recovery conflict created extra orders.' );

		$notes             = wc_get_order_notes( array( 'order_id' => $order_id ) );
		$has_conflict_note = false;
		foreach ( $notes as $n ) {
			if ( str_contains( (string) $n->content, 'NEW-2' ) || str_contains( (string) $n->content, 'eltérő tartalommal' ) ) {
				$has_conflict_note = true;
				break;
			}
		}
		WPS_IT::true( $has_conflict_note, 'Expected operator reconciliation note on existing order after crash recovery conflict.' );
	}
);

WPS_IT::test(
	'The order lookup used by crash recovery is exact and does not match prefixes',
	static function () use ( $run ): void {
		$external_id = 'IT-EXACT-' . $run;
		$prefix_id   = $external_id . '-PREFIX';

		$first = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id ) );

		WPS_IT::same( 200, $first['code'], 'The exact-id order failed: ' . $first['body'] );

		$other = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $prefix_id ) );

		WPS_IT::same( 200, $other['code'], 'The prefix-id order failed: ' . $other['body'] );
		WPS_IT::not_same( (int) $first['json']['orderId'], (int) $other['json']['orderId'], 'The two external ids collapsed into one order.' );
	}
);
