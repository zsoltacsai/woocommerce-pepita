<?php
/**
 * M9-B additional coverage: unpaid cash-on-delivery intake, scheduled
 * maintenance and retention against real MySQL / WooCommerce.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Logger;
use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

WPS_IT::line( 'M9-B coverage: unpaid COD and maintenance' );

$run = WPS_IT::run_id();

WPS_IT::test(
	'An unpaid cash-on-delivery order is imported on-hold, unpaid, with customer and courier notes',
	static function () use ( $run ): void {
		$external_id = 'IT-COD-UNPAID-' . $run;
		$payload     = WPS_IT::order_payload( 'order-cod-paid.json', $external_id );

		WPS_IT::same( 'cod', (string) $payload['payment_mode'], 'Fixture is not a COD order.' );

		$payload['payment_status']  = 'unpaid';
		$payload['customer_message'] = 'Kérem csengessenek kétszer.';
		$payload['courier_message']  = 'Utánvét: fizetendő átvételkor.';

		$response = WPS_IT::post_order( $payload );

		WPS_IT::same( 200, $response['code'], 'The unpaid COD order was rejected: ' . $response['body'] );
		WPS_IT::same( false, $response['json']['isError'] );

		$order = wc_get_order( (int) $response['json']['orderId'] );

		WPS_IT::instance( \WC_Order::class, $order, 'The order was not created.' );
		WPS_IT::same( 'on-hold', $order->get_status(), 'An unpaid COD order must be on-hold.' );
		WPS_IT::same( null, $order->get_date_paid(), 'An unpaid order must not carry a paid date.' );
		WPS_IT::same( 'cod', (string) $order->get_payment_method(), 'The payment method was not mapped to cod.' );
		WPS_IT::same( 'Kérem csengessenek kétszer.', (string) $order->get_meta( Importer::META_CUSTOMER_MSG ) );
		WPS_IT::same( 'Utánvét: fizetendő átvételkor.', (string) $order->get_meta( Importer::META_COURIER_MSG ) );

		$notes = implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );

		WPS_IT::contains( 'Kérem csengessenek kétszer.', $notes, 'The customer message note is missing.' );
		WPS_IT::contains( 'Utánvét: fizetendő átvételkor.', $notes, 'The courier message note is missing.' );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ) );
	}
);

WPS_IT::test(
	'Scheduled log retention deletes expired log rows and keeps recent ones',
	static function () use ( $run ): void {
		global $wpdb;

		$table = Logger::table();

		Logger::info( 'it_retention', 'old-' . $run );
		Logger::info( 'it_retention', 'new-' . $run );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET created_at = %s WHERE message = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ),
				'old-' . $run
			)
		);

		$count = static function ( string $message ) use ( $wpdb, $table ): int {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE message = %s", $message ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		};

		WPS_IT::same( 1, $count( 'old-' . $run ), 'Precondition: the aged log row is missing.' );

		( new Scheduler() )->run_purge();

		WPS_IT::same( 0, $count( 'old-' . $run ), 'The expired log row survived the maintenance job.' );
		WPS_IT::same( 1, $count( 'new-' . $run ), 'The recent log row was deleted.' );
	}
);

WPS_IT::test(
	'Scheduled payload retention clears payloads of old claims only (keep-payloads on)',
	static function () use ( $run ): void {
		global $wpdb;

		WPS_IT::same( true, (bool) Settings::get( 'order_api_keep_payloads' ), 'Harness expects keep-payloads enabled.' );

		$old = 'IT-RET-OLD-' . $run;
		$new = 'IT-RET-NEW-' . $run;

		foreach ( array( $old, $new ) as $id ) {
			$payload = (string) wp_json_encode( array( 'id' => $id ) );
			$claim   = Order_Repository::claim( $id, 'pepita.hu', $payload, hash( 'sha256', $payload ) );
			WPS_IT::same( 'claimed', (string) $claim['state'] );
			WPS_IT::not_same( null, Order_Repository::get( $id )['payload'], 'Precondition: payload was not stored.' );
		}

		$wpdb->update(
			Order_Repository::table(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ),
			array( 'external_id' => $old )
		);

		$cleared = Order_Repository::purge_payloads( 30 );

		WPS_IT::greater( 0, $cleared, 'No payload was cleared.' );
		WPS_IT::same( null, Order_Repository::get( $old )['payload'], 'The old payload was not purged.' );
		WPS_IT::not_same( null, Order_Repository::get( $new )['payload'], 'The recent payload was purged.' );
		WPS_IT::same( 'processing', (string) Order_Repository::get( $old )['status'], 'The claim row itself must be retained.' );
	}
);

WPS_IT::test(
	'Label retention deletes labels of closed orders and keeps labels of open orders',
	static function () use ( $run ): void {
		$labels = array();

		foreach ( array( 'closed' => 'completed', 'open' => 'processing' ) as $kind => $status ) {
			$external_id = 'IT-LABEL-' . strtoupper( $kind ) . '-' . $run;
			$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );
			$response    = WPS_IT::post_order( $payload );

			WPS_IT::same( 200, $response['code'], 'Import failed: ' . $response['body'] );

			$order = wc_get_order( (int) $response['json']['orderId'] );
			$file  = (string) $order->get_meta( Importer::META_PACKAGE_LABEL );

			WPS_IT::true( '' !== $file && is_file( $file ), 'Precondition: the label file was not saved.' );

			$order->set_status( $status );
			$order->save();

			touch( $file, time() - 90 * DAY_IN_SECONDS );

			$labels[ $kind ] = array( (int) $order->get_id(), $file );
		}

		( new Scheduler() )->run_purge();

		WPS_IT::false( is_file( $labels['closed'][1] ), 'The label of a completed order was not purged.' );
		WPS_IT::true( is_file( $labels['open'][1] ), 'The label of an open order must be retained.' );

		$closed = wc_get_order( $labels['closed'][0] );
		WPS_IT::same( '', (string) $closed->get_meta( Importer::META_PACKAGE_LABEL ), 'The label meta of the purged label was not cleared.' );
	}
);

WPS_IT::test(
	'RR20 regression: an import executed in-process keeps the marker on every save but the last',
	static function () use ( $run ): void {
		$external_id = 'IT-RR20-INPROC-' . $run;
		$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );
		$saves       = array();

		$probe = static function ( $order ) use ( &$saves, $external_id ): void {
			if ( ! $order instanceof \WC_Order || $external_id !== (string) $order->get_meta( Importer::META_ORDER_ID ) ) {
				return;
			}

			$saves[] = array(
				'in_progress' => (string) $order->get_meta( '_wps_pepita_in_progress' ),
				'imported_at' => (string) $order->get_meta( Importer::META_IMPORTED_AT ),
				'status'      => (string) $order->get_status(),
			);
		};

		add_action( 'woocommerce_before_order_object_save', $probe, 1 );

		try {
			$result = ( new Importer() )->import( $payload );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $probe, 1 );
		}

		WPS_IT::same( 200, $result['http'], 'In-process import failed: ' . (string) $result['message'] );
		WPS_IT::greater( 1, count( $saves ), 'Expected several saves of the order during the import.' );

		$cleared_seen = false;

		foreach ( $saves as $index => $save ) {
			$label = 'Save #' . ( $index + 1 );

			if ( '1' === $save['in_progress'] ) {
				WPS_IT::false( $cleared_seen, $label . ': the marker reappeared after it was cleared.' );
				WPS_IT::same( '', $save['imported_at'], $label . ' set imported_at while the import was unfinished.' );
				continue;
			}

			// Marker cleared: this must be the finalizing save (final status + imported_at). An early
			// clear (status still pending) is the RR20 defect: a competing worker would adopt it.
			$cleared_seen = true;
			WPS_IT::not_same( '', $save['imported_at'], $label . ' cleared the marker without imported_at.' );
			WPS_IT::not_same( 'pending', $save['status'], $label . ' cleared the marker while the order was still pending (RR20).' );
		}

		WPS_IT::true( $cleared_seen, 'No save cleared the marker.' );
	}
);
