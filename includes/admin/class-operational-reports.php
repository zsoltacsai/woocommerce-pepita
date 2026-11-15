<?php
/**
 * Operational Reports & Multi-Store Reconciliation.
 *
 * Provides aggregated operational metrics, audit summaries, and memory-bounded,
 * PII-free operational exports for merchants and support.
 *
 * Strict security invariants:
 * - NEVER exports customer names, delivery addresses, billing addresses, phone numbers.
 * - Masks any email addresses and phone numbers in error messages.
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
use WPS\Pepita\Redactor;

defined( 'ABSPATH' ) || exit;

final class Operational_Reports {

	public const MAX_EXPORT_ROWS = 1000;

	/**
	 * Sanitize and mask sensitive information in error messages.
	 *
	 * Masks emails, redacts phone numbers, names, and addresses, and truncates long strings.
	 *
	 * @param string $raw_err
	 * @return string
	 */
	public static function sanitize_error_message( string $raw_err ): string {
		if ( '' === trim( $raw_err ) ) {
			return '';
		}

		// 1. If error message contains embedded or full JSON objects/arrays, find, decode and redact structured fields.
		$raw_err = (string) preg_replace_callback(
			'/\{(?:[^{}]|(?R))*\}/s',
			static function ( array $matches ): string {
				$candidate = $matches[0];
				$decoded   = json_decode( $candidate, true );
				if ( is_array( $decoded ) ) {
					$redacted = function_exists( 'wp_json_encode' )
						? wp_json_encode( Redactor::redact( $decoded ) )
						: json_encode( Redactor::redact( $decoded ) );
					return is_string( $redacted ) ? $redacted : $candidate;
				}
				return $candidate;
			},
			$raw_err
		);

		$raw_err = (string) preg_replace_callback(
			'/\[(?:[^\[\]]|(?R))*\]/s',
			static function ( array $matches ): string {
				$candidate = $matches[0];
				$decoded   = json_decode( $candidate, true );
				if ( is_array( $decoded ) ) {
					$redacted = function_exists( 'wp_json_encode' )
						? wp_json_encode( Redactor::redact( $decoded ) )
						: json_encode( Redactor::redact( $decoded ) );
					return is_string( $redacted ) ? $redacted : $candidate;
				}
				return $candidate;
			},
			$raw_err
		);

		// 2. Mask email addresses.
		$masked = preg_replace_callback(
			'/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
			static function ( array $m ): string {
				return Redactor::mask_email( $m[0] );
			},
			$raw_err
		);

		$masked = is_string( $masked ) ? $masked : $raw_err;

		// 3. Redact phone numbers (Hungarian, international, and compact digit sequences).
		$redacted = preg_replace(
			'/(?:\+?[0-9]{1,3}[-.\s]?)?\(?[0-9]{1,4}?\)?[-.\s]?[0-9]{2,4}[-.\s]?[0-9]{2,4}[-.\s]?[0-9]{2,4}|\b(?:06|\+36)[0-9]{8,9}\b|\b[0-9]{10,12}\b/',
			'[REDACTED_PHONE]',
			$masked
		);

		$result = is_string( $redacted ) ? $redacted : $masked;

		// 4. Redact postal codes and city names (e.g., "1117 Budapest", "H-1052 Budapest").
		$result = preg_replace(
			'/\b(?:H-)?\d{4}\s+[A-ZÁÉÍÓÖŐÚÜŰ][a-záéíóöőúüű]+(?:,\s*)?/u',
			'[REDACTED_LOCATION], ',
			$result
		);
		$result = is_string( $result ) ? $result : $masked;

		// 5. Redact street addresses (both Hungarian "Kossuth Lajos utca 12." and English "12 Example Street").
		$addr_pattern = '/\b(?:\d{1,5}\s+[A-Za-z0-9ÁÉÍÓÖŐÚÜŰáéíóöőúüű\.\-\s]{2,40}?\s*(?:street|st|road|rd|avenue|ave|boulevard|blvd|lane|ln|drive|dr|court|ct|utca|út|u\.|tér|sor|köz|fasor|körút|krt)\.?\b|[A-Za-z0-9ÁÉÍÓÖŐÚÜŰáéíóöőúüű\.\-\s]{2,40}?\s*(?:street|st|road|rd|avenue|ave|boulevard|blvd|lane|ln|drive|dr|court|ct|utca|út|u\.|tér|sor|köz|fasor|körút|krt)\.?\s+\d{1,5}[\/\-a-zA-Z0-9]*)/ui';
		$result = preg_replace( $addr_pattern, '[REDACTED_ADDRESS]', $result );
		$result = is_string( $result ) ? $result : $masked;

		// 6. Redact person names following common context markers (e.g., "for Jane Smith", "customer John Doe", "címzett: Kovács János").
		$name_pattern = '/\b(for|customer|recipient|buyer|user|címzett|vásárló|ügyfél|név|name|first\s+name|last\s+name|full\s+name|contact|client):?\s+([A-ZÁÉÍÓÖŐÚÜŰ][a-záéíóöőúüű]+(?:\s+[A-ZÁÉÍÓÖŐÚÜŰ][a-záéíóöőúüű]+){0,3})\b/ui';
		$result = preg_replace( $name_pattern, '$1 [REDACTED_NAME]', $result );
		$result = is_string( $result ) ? $result : $masked;

		// 7. Clean up multiple spaces.
		$result = preg_replace( '/\s{2,}/', ' ', $result );
		$result = is_string( $result ) ? $result : $masked;

		// 8. Truncate long error details to bound memory and display size.
		if ( mb_strlen( $result ) > 255 ) {
			$result = mb_substr( $result, 0, 252 ) . '...';
		}

		return trim( $result );
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
					$sanitized_err = self::sanitize_error_message( $last_err );
					$error_counts[ $sanitized_err ] = ( $error_counts[ $sanitized_err ] ?? 0 ) + 1;
				}
			}
		}

		if ( ! empty( $error_counts ) ) {
			arsort( $error_counts, SORT_NUMERIC );
			$top = array_slice( $error_counts, 0, 5, true );
			foreach ( $top as $msg => $cnt ) {
				$defaults['top_errors'][] = array(
					'error' => $msg,
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
	 * Export order operations as a memory-bounded, PII-free CSV document.
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
				'Error Details',
			)
		);

		if ( is_array( $orders ) ) {
			foreach ( $orders as $row ) {
				$raw_err = (string) ( $row['last_error'] ?? '' );
				$sanitized_err = self::sanitize_error_message( $raw_err );

				// All cells sanitized against formula injection via Logger::sanitize_csv_field().
				fputcsv(
					$fp,
					array(
						Logger::sanitize_csv_field( (string) ( $row['external_id'] ?? '' ) ),
						(int) ( $row['wc_order_id'] ?? 0 ),
						Logger::sanitize_csv_field( (string) ( $row['origin'] ?? '' ) ),
						Logger::sanitize_csv_field( (string) ( $row['status'] ?? '' ) ),
						(int) ( $row['attempts'] ?? 0 ),
						Logger::sanitize_csv_field( (string) ( $row['created_at'] ?? '' ) ),
						Logger::sanitize_csv_field( (string) ( $row['updated_at'] ?? '' ) ),
						Logger::sanitize_csv_field( $sanitized_err ),
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
