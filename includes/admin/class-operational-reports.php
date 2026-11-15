<?php
/**
 * Operational Reports & Multi-Store Reconciliation.
 *
 * Provides aggregated operational metrics, audit summaries, and memory-bounded
 * operational exports for merchants and support.
 *
 * Strict security invariants (fail-closed, no regex redaction of free text):
 * - Raw free-text errors are NEVER exported; they are only classified into fixed codes
 *   with predefined PII-free explanations.
 * - Untrusted identifiers (external_id, origin) are exported only if they match a strict
 *   safe pattern, otherwise as a salted hash / "other".
 * - Sanitizes all CSV cells via Logger::sanitize_csv_field() to neutralize formula injection.
 * - Bounded queries prevent memory exhaustion on high-volume stores.
 * - Compatible with both MySQL and in-memory test stubs (no GROUP BY dependencies).
 *
 * @package WPS\Pepita\Admin
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Admin\Order_Admin;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Logger;
use WPS\Pepita\Orders\Order_Repository;

defined( 'ABSPATH' ) || exit;

final class Operational_Reports {

	public const MAX_EXPORT_ROWS = 1000;

	/**
	 * Fixed, PII-free error classification table.
	 *
	 * Free-text error messages can contain customer data the plugin cannot reliably
	 * recognise, so exports and reports NEVER include raw text. A raw message is only
	 * ever used as classifier input; the output is always one of these fixed codes.
	 *
	 * @var array<string, array{0: string, 1: string}> code => [ pattern, explanation ]
	 */
	private const ERROR_CLASSES = array(
		'replay_conflict'   => array( '/conflicting replay|hash mismatch/i', 'Az ismételt értesítés tartalma eltér az eredetitől.' ),
		'sku_not_found'     => array( '/\bsku\b|product not found|unknown product|termék/i', 'A rendelésben szereplő termék nem azonosítható az áruházban.' ),
		'payment_mode'      => array( '/payment|fizet/i', 'A fizetési mód nem támogatott vagy érvénytelen.' ),
		'stock_problem'     => array( '/stock|készlet/i', 'Készletprobléma a rendelés létrehozásakor.' ),
		'shipping_problem'  => array( '/shipping|delivery|szállít/i', 'A szállítási adatok hiányosak vagy érvénytelenek.' ),
		'database_error'    => array( '/database|\bsql\b|duplicate entry|wpdb|deadlock/i', 'Adatbázis hiba a feldolgozás közben.' ),
		'validation_error'  => array( '/invalid|required|missing|validation|empty|érvénytelen|hiányz/i', 'A beérkezett adatok nem felelnek meg az elvárt formátumnak.' ),
		'order_creation'    => array( '/order|rendelés|wc_|woocommerce/i', 'A WooCommerce rendelés létrehozása sikertelen volt.' ),
	);

	public const UNCLASSIFIED_CODE = 'unclassified_error';

	/**
	 * Classify a raw error into a fixed code. Never returns any part of the input.
	 *
	 * @param string $raw_err Raw, possibly personal, error text (classifier input only).
	 * @return string One of the ERROR_CLASSES keys or UNCLASSIFIED_CODE; '' for empty input.
	 */
	public static function classify_error( string $raw_err ): string {
		if ( '' === trim( $raw_err ) ) {
			return '';
		}
		foreach ( self::ERROR_CLASSES as $code => $def ) {
			if ( 1 === @preg_match( $def[0], $raw_err ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid UTF-8 must fail closed, not warn.
				return $code;
			}
		}
		return self::UNCLASSIFIED_CODE;
	}

	/**
	 * Predefined PII-free explanation for a classification code.
	 *
	 * @param string $code Result of classify_error().
	 * @return string
	 */
	public static function explain_error( string $code ): string {
		if ( isset( self::ERROR_CLASSES[ $code ] ) ) {
			return self::ERROR_CLASSES[ $code ][1];
		}
		return '' === $code ? '' : 'Nem besorolható hiba; részletek a rendelés adminisztrációs nézetében.';
	}

	/**
	 * Export-safe identifier: strict safe charset passes through, anything else becomes a salted hash.
	 *
	 * @param string $id Untrusted external identifier.
	 * @return string
	 */
	public static function safe_identifier( string $id ): string {
		if ( 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
			return $id;
		}
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : 'wps-pepita';
		return 'hash:' . substr( hash_hmac( 'sha256', $id, $key ), 0, 16 );
	}

	/**
	 * Export-safe origin: only short marketplace-style identifiers are exported.
	 *
	 * @param string $origin Untrusted origin.
	 * @return string
	 */
	public static function safe_origin( string $origin ): string {
		return 1 === preg_match( '/^(?:[a-z]{2,3}|pepita\.[a-z]{2,3})$/', $origin ) ? $origin : 'other';
	}

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
			'is_partial_data'   => false,
			'gmv_truncated'     => false,
			'truncated_limit'   => self::MAX_EXPORT_ROWS,
			'top_errors'        => array(),
			'feed_telemetry'    => array(),
		);

		// Single memory-bounded query compatible with both MySQL and in-memory test harnesses.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, external_id, wc_order_id, last_error
				 FROM {$table}
				 WHERE created_at >= %s
				 ORDER BY id DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since,
				self::MAX_EXPORT_ROWS
			),
			ARRAY_A
		);

		$error_counts = array();
		if ( is_array( $rows ) ) {
			if ( count( $rows ) >= self::MAX_EXPORT_ROWS ) {
				$defaults['is_partial_data'] = true;
				$defaults['gmv_truncated']   = true;
			}

			foreach ( $rows as $row ) {
				++$defaults['total_orders'];

				$status   = (string) ( $row['status'] ?? '' );
				$wc_id    = (int) ( $row['wc_order_id'] ?? 0 );
				$ext_id   = (string) ( $row['external_id'] ?? '' );
				$last_err = (string) ( $row['last_error'] ?? '' );

				if ( Order_Repository::STATUS_COMPLETED === $status ) {
					++$defaults['completed_orders'];
					if ( $wc_id > 0 && function_exists( 'wc_get_order' ) ) {
						$order = wc_get_order( $wc_id );
						if ( $order instanceof \WC_Order ) {
							$defaults['total_gmv'] += (float) $order->get_total();
						}
					}
				} elseif ( Order_Repository::STATUS_FAILED === $status ) {
					++$defaults['failed_orders'];
				} elseif ( Order_Repository::STATUS_PROCESSING === $status ) {
					++$defaults['processing_orders'];
				}

				// Check for orphans across all unlinked claims regardless of status.
				if ( 0 === $wc_id && '' !== $ext_id ) {
					$candidate = Order_Admin::find_order_by_external_id( $ext_id );
					if ( $candidate > 0 ) {
						++$defaults['orphan_orders'];
					}
				}

				// Top error aggregation.
				if ( '' !== trim( $last_err ) ) {
					$code                  = self::classify_error( $last_err );
					$error_counts[ $code ] = ( $error_counts[ $code ] ?? 0 ) + 1;
				}
			}
		}

		if ( ! empty( $error_counts ) ) {
			arsort( $error_counts, SORT_NUMERIC );
			$top = array_slice( $error_counts, 0, 5, true );
			foreach ( $top as $code => $cnt ) {
				$defaults['top_errors'][] = array(
					'code'  => (string) $code,
					'error' => self::explain_error( (string) $code ),
					'count' => $cnt,
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
	 * Export order operations as a memory-bounded, CSV document that never contains raw free-text errors or untrusted identifiers.
	 *
	 * Sanitizes every cell against spreadsheet formula injection via Logger::sanitize_csv_field().
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

		if ( is_array( $orders ) && count( $orders ) >= self::MAX_EXPORT_ROWS ) {
			fwrite( $fp, "# MEGJEGYZÉS: RÉSZLEGES EXPORT (A legutóbbi 1 000 rekord alapján korlátozva).\n" );
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
				'Error Code',
				'Error Explanation',
			)
		);

		if ( is_array( $orders ) ) {
			foreach ( $orders as $row ) {
				$err_code = self::classify_error( (string) ( $row['last_error'] ?? '' ) );

				// All cells sanitized against formula injection via Logger::sanitize_csv_field().
				fputcsv(
					$fp,
					array(
						Logger::sanitize_csv_field( self::safe_identifier( (string) ( $row['external_id'] ?? '' ) ) ),
						(int) ( $row['wc_order_id'] ?? 0 ),
						Logger::sanitize_csv_field( self::safe_origin( (string) ( $row['origin'] ?? '' ) ) ),
						Logger::sanitize_csv_field( (string) ( $row['status'] ?? '' ) ),
						(int) ( $row['attempts'] ?? 0 ),
						Logger::sanitize_csv_field( (string) ( $row['created_at'] ?? '' ) ),
						Logger::sanitize_csv_field( (string) ( $row['updated_at'] ?? '' ) ),
						Logger::sanitize_csv_field( $err_code ),
						Logger::sanitize_csv_field( self::explain_error( $err_code ) ),
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
