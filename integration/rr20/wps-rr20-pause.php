<?php
/**
 * Plugin Name: WPS RR20 Pause Harness (TEST ONLY)
 * Description: Pauses a Pepita import worker at the final WooCommerce save
 *              (after the lease re-verification, before the DB write) and
 *              records every side-effect hook. Never install outside a
 *              disposable integration store.
 *
 * Never installed by default: integration/rr20/run-rr20.sh copies it into a disposable
 * integration store and removes it afterwards.
 *
 * Control files live in wp-content/rr20/ so no candidate code is modified:
 *   mode            exists  -> harness armed
 *   entered         created -> worker A reached the pause point (pid inside)
 *   resume          created by the runner -> worker A continues
 *   trace.jsonl     append-only side-effect log
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPS_RR20_DIR' ) ) {
	define( 'WPS_RR20_DIR', WP_CONTENT_DIR . '/rr20' );
}

function wps_rr20_armed(): bool {
	return is_file( WPS_RR20_DIR . '/mode' );
}

function wps_rr20_trace( string $event, array $ctx = array() ): void {
	if ( ! wps_rr20_armed() ) {
		return;
	}
	$line = wp_json_encode(
		array_merge(
			array(
				't'     => microtime( true ),
				'pid'   => getmypid(),
				'event' => $event,
			),
			$ctx
		)
	);
	file_put_contents( WPS_RR20_DIR . '/trace.jsonl', $line . "\n", FILE_APPEND | LOCK_EX );
}

/*
 * Pause points (mode file: 'final' default, or 'early'). The importer re-verifies the lease, deletes the in-progress
 * marker in memory and calls $order->save(). woocommerce_before_order_object_save
 * fires inside save(), before the data-store write. Final save signature:
 * created via wps-pepita, in-progress marker already removed, imported_at set.
 */
add_action(
	'woocommerce_before_order_object_save',
	static function ( $order ) {
		if ( ! wps_rr20_armed() || ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'wps-pepita' !== $order->get_created_via() ) {
			return;
		}

		$mode        = trim( (string) @file_get_contents( WPS_RR20_DIR . '/mode' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( 'control' === $mode ) {
			return;
		}

		$in_progress = '1' === (string) $order->get_meta( '_wps_pepita_in_progress' );
		$has_items   = count( $order->get_items() ) > 0;

		if ( 'early' === $mode ) {
			// The totals save: items exist, raw total recorded, still unfinalized
			// (before the lease re-verification). Regression point for the RR20 defect.
			if ( ! $in_progress || ! $has_items || '' === (string) $order->get_meta( '_wps_pepita_raw_total' ) ) {
				return;
			}
		} else {
			// 'final': in-progress marker removed in memory and imported_at set,
			// i.e. the save right after the lease re-verification.
			if ( $in_progress || '' === (string) $order->get_meta( '_wps_pepita_imported_at' ) ) {
				return;
			}
		}

		wps_rr20_trace( 'pause_point_reached', array( 'order_id' => $order->get_id() ) );

		// Only the first worker to arrive pauses (atomic mkdir).
		if ( ! @mkdir( WPS_RR20_DIR . '/paused.lock' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return;
		}
		file_put_contents( WPS_RR20_DIR . '/entered', (string) getmypid() );
		wps_rr20_trace( 'paused', array( 'order_id' => $order->get_id() ) );

		$deadline = time() + 180;
		while ( ! is_file( WPS_RR20_DIR . '/resume' ) && time() < $deadline ) {
			usleep( 100000 );
		}
		wps_rr20_trace( 'resumed', array( 'order_id' => $order->get_id() ) );
	},
	1
);

/* Side-effect probes. Each records the order id so ghost-order activity is visible. */
foreach ( array(
	'woocommerce_new_order',
	'woocommerce_update_order',
	'woocommerce_before_delete_order',
	'woocommerce_delete_order',
	'woocommerce_trash_order',
	'woocommerce_payment_complete',
	'woocommerce_reduce_order_stock',
	'woocommerce_order_status_changed',
	'woocommerce_order_status_processing',
	'woocommerce_order_status_on-hold',
	'woocommerce_order_status_completed',
	'woocommerce_checkout_order_processed',
) as $hook ) {
	add_action(
		$hook,
		static function () use ( $hook ) {
			$args = func_get_args();
			$id   = isset( $args[0] ) && is_scalar( $args[0] ) ? (int) $args[0] : 0;
			wps_rr20_trace(
				'hook',
				array(
					'hook'     => $hook,
					'order_id' => $id,
					'exists'   => $id > 0 ? ( wc_get_order( $id ) instanceof WC_Order ) : null,
				)
			);
		},
		1,
		5
	);
}

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		wps_rr20_trace(
			'wp_mail',
			array(
				'to'      => is_array( $atts['to'] ?? null ) ? implode( ',', $atts['to'] ) : (string) ( $atts['to'] ?? '' ),
				'subject' => (string) ( $atts['subject'] ?? '' ),
			)
		);
		return $short_circuit; // Observe only; the isolated store has no SMTP.
	},
	1,
	2
);
