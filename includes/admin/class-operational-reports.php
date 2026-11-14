<?php
/**
 * Operational Reports & Multi-Store Reconciliation.
 *
 * Provides aggregated operational metrics, audit summaries, and memory-bounded,
 * PII-free operational exports for merchants and support.
 *
 * Strict security invariants:
 * - NEVER exports customer names, delivery addresses, billing addresses, phone numbers.
 * - Masks any email addresses present in error messages via Redactor::mask_email().
 * - Bounded queries prevent memory exhaustion on high-volume stores.
 *
 * @package WPS\Pepita\Admin
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Admin\Order_Admin;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Redactor;

defined( 'ABSPATH' ) || exit;

final class Operational_Reports {

	public const MAX_EXPORT_ROWS = 1000;

	/**
	 * Compute an aggregated operational summary for the specified time window.
	 *
	 * @param int $days
	 * @return array<string, mixed>
	 */
	public static function get_summary( int $days = 30 ): array {
		global $wpdb;

		$table = Order_Repository::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$defaults = array(
			'days'              => $days,
			'total_orders'      => 0,
			'completed_orders'  => 0,
			'failed_orders'     => 0,
			'processing_orders' => 0,
			'orphan_orders'     => 0,
			'total_gmv'         => 0.0,
			'top_errors'        => array(),
			'feed_telemetry'    => array(),
		);

		// Status breakdown.
		$status_counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as cnt
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		if ( is_array( $status_counts ) ) {
			foreach ( $status_counts as $row ) {
				$status = (string) ( $row['status'] ?? '' );
				$count  = (int) ( $row['cnt'] ?? 0 );
				$defaults['total_orders'] += $count;

				if ( Order_Repository::STATUS_COMPLETED === $status ) {
					$defaults['completed_orders'] = $count;
				} elseif ( Order_Repository::STATUS_FAILED === $status ) {
					$defaults['failed_orders'] = $count;
				} elseif ( Order_Repository::STATUS_PROCESSING === $status ) {
					$defaults['processing_orders'] = $count;
				}
			}
		}

		// Calculate total GMV and orphan count from completed orders in window.
		$completed_orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT external_id, wc_order_id
				 FROM {$table}
				 WHERE created_at >= %s AND status = %s
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since,
				Order_Repository::STATUS_COMPLETED,
				self::MAX_EXPORT_ROWS
			),
			ARRAY_A
		);

		if ( is_array( $completed_orders ) ) {
			foreach ( $completed_orders as $row ) {
				$wc_id = (int) ( $row['wc_order_id'] ?? 0 );
				$ext_id = (string) ( $row['external_id'] ?? '' );

				if ( $wc_id > 0 && function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( $wc_id );
					if ( $order instanceof \WC_Order ) {
						$defaults['total_gmv'] += (float) $order->get_total();
					}
				} elseif ( 0 === $wc_id ) {
					// Check if there is an orphan order in WooCommerce.
					$candidate = Order_Admin::find_order_by_external_id( $ext_id );
					if ( $candidate > 0 ) {
						++$defaults['orphan_orders'];
					}
				}
			}
		}

		// Top errors within window.
		$top_errors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT last_error, COUNT(*) as cnt
				 FROM {$table}
				 WHERE created_at >= %s AND last_error IS NOT NULL AND last_error != ''
				 GROUP BY last_error
				 ORDER BY cnt DESC
				 LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		if ( is_array( $top_errors ) ) {
			foreach ( $top_errors as $err_row ) {
				$raw_err = (string) ( $err_row['last_error'] ?? '' );
				// Strictly mask any emails or sensitive patterns in error display.
				$masked_err = preg_replace_callback(
					'/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
					static function ( array $m ): string {
						return Redactor::mask_email( $m[0] );
					},
					$raw_err
				);

				$defaults['top_errors'][] = array(
					'error' => is_string( $masked_err ) ? $masked_err : $raw_err,
					'count' => (int) ( $err_row['cnt'] ?? 0 ),
				);
			}
		}

		// Feed telemetry.
		$generator = new Generator();
		foreach ( array( Generator::TYPE_FULL, Generator::TYPE_STOCK ) as $feed_type ) {
			$state = $generator->get_feed_state( $feed_type );
			$defaults['feed_telemetry'][ $feed_type ] = array(
				'is_running'   => $generator->is_running( $feed_type ),
				'last_success' => $state['last_success'] ?? 0,
				'exported'     => $state['counts']['exported'] ?? 0,
				'invalid'      => $state['counts']['invalid'] ?? 0,
				'file_size'    => $state['file_size'] ?? 0,
			);
		}

		return $defaults;
	}

	/**
	 * Export order operations as a memory-bounded, PII-free CSV document.
	 *
	 * @param int $days
	 * @return string
	 */
	public static function export_report_csv( int $days = 30 ): string {
		global $wpdb;

		$table = Order_Repository::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT external_id, origin, status, wc_order_id, attempts, last_error, created_at, updated_at
				 FROM {$table}
				 WHERE created_at >= %s
				 ORDER BY id DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since,
				self::MAX_EXPORT_ROWS
			),
			ARRAY_A
		);

		$fp = fopen( 'php://temp', 'w+' );
		if ( false === $fp ) {
			return '';
		}

		// Write header.
		fputcsv(
			$fp,
			array(
				'Pepita ID',
				'WC Order ID',
				'Origin',
				'Status',
				'Attempts',
				'Created At (UTC)',
				'Updated At (UTC)',
				'Error Details',
			)
		);

		if ( is_array( $orders ) ) {
			foreach ( $orders as $row ) {
				$raw_err = (string) ( $row['last_error'] ?? '' );
				$masked_err = preg_replace_callback(
					'/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
					static function ( array $m ): string {
						return Redactor::mask_email( $m[0] );
					},
					$raw_err
				);

				fputcsv(
					$fp,
					array(
						(string) ( $row['external_id'] ?? '' ),
						(int) ( $row['wc_order_id'] ?? 0 ),
						(string) ( $row['origin'] ?? '' ),
						(string) ( $row['status'] ?? '' ),
						(int) ( $row['attempts'] ?? 0 ),
						(string) ( $row['created_at'] ?? '' ),
						(string) ( $row['updated_at'] ?? '' ),
						is_string( $masked_err ) ? $masked_err : $raw_err,
					)
				);
			}
		}

		rewind( $fp );
		$csv_out = stream_get_contents( $fp );
		fclose( $fp );

		return is_string( $csv_out ) ? $csv_out : '';
	}
}
