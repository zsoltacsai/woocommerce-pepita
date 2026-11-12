<?php
/**
 * Durable order claim / deduplication store.
 *
 * The UNIQUE index on external_id is the concurrency guarantee: exactly one
 * request can insert the claim for a given Pepita order, every other request
 * (including parallel ones) observes the existing row.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Orders;

use WPS\Pepita\Logger;

defined( 'ABSPATH' ) || exit;

final class Order_Repository {

	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';

	/**
	 * A processing claim older than this is considered abandoned and retryable.
	 */
	private const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	private static bool $table_checked = false;
	private static bool $table_exists = false;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wps_pepita_orders';
	}

	/**
	 * Attempt to claim an external order id.
	 *
	 * @return array{state: string, row: array<string, mixed>|null}
	 *         state: claimed | completed | busy | failed
	 */
	public static function claim( string $external_id, string $origin, string $payload, string $payload_hash ): array {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		if ( ! self::table_exists() ) {
			return array(
				'state' => 'failed',
				'row'   => null,
			);
		}

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (external_id, origin, status, wc_order_id, payload, payload_hash, attempts, created_at, updated_at)
				 VALUES (%s, %s, %s, 0, %s, %s, 1, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$external_id,
				$origin,
				self::STATUS_PROCESSING,
				$payload,
				$payload_hash,
				$now,
				$now
			)
		);

		if ( 1 === (int) $inserted ) {
			return array(
				'state' => 'claimed',
				'row'   => self::get( $external_id ),
			);
		}

		$row = self::get( $external_id );

		if ( null === $row ) {
			return array(
				'state' => 'failed',
				'row'   => null,
			);
		}

		if ( self::STATUS_COMPLETED === $row['status'] ) {
			return array(
				'state' => 'completed',
				'row'   => $row,
			);
		}

		$stale_before = gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER );
		$retryable    = self::STATUS_FAILED === $row['status'] || $row['updated_at'] < $stale_before;

		if ( ! $retryable ) {
			return array(
				'state' => 'busy',
				'row'   => $row,
			);
		}

		// Conditional re-claim: only one concurrent retry can win.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, payload = %s, payload_hash = %s, attempts = attempts + 1, last_error = NULL, updated_at = %s
				 WHERE external_id = %s AND (status = %s OR updated_at < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PROCESSING,
				$payload,
				$payload_hash,
				$now,
				$external_id,
				self::STATUS_FAILED,
				$stale_before
			)
		);

		if ( 1 === (int) $updated ) {
			return array(
				'state' => 'claimed',
				'row'   => self::get( $external_id ),
			);
		}

		return array(
			'state' => 'busy',
			'row'   => self::get( $external_id ),
		);
	}

	public static function complete( string $external_id, int $order_id ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'      => self::STATUS_COMPLETED,
				'wc_order_id' => max( 0, $order_id ),
				'last_error'  => null,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'external_id' => $external_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%s' )
		);

		Logger::info(
			'order_import',
			'Pepita rendelés sikeresen importálva.',
			array( 'external_id' => $external_id, 'order_id' => $order_id ),
			'order',
			$order_id
		);
	}

	public static function fail( string $external_id, string $error ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'     => self::STATUS_FAILED,
				'last_error' => substr( $error, 0, 4000 ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'external_id' => $external_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $external_id ): ?array {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE external_id = %s LIMIT 1", $external_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $args = array() ): array {
		if ( ! self::table_exists() ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		global $wpdb;

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		if ( in_array( $status, array( self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_FAILED ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$where[]  = 'external_id LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) ( array() !== $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_params
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	public static function count_completed(): int {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_COMPLETED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove payloads older than the retention window (GDPR friendly).
	 */
	public static function purge_payloads( int $days ): int {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET payload = NULL WHERE created_at < %s AND payload IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_by_wc_order( int $order_id ): ?array {
		if ( $order_id <= 0 || ! self::table_exists() ) {
			return null;
		}

		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d LIMIT 1", $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private static function table_exists(): bool {
		if ( self::$table_checked ) {
			return self::$table_exists;
		}

		global $wpdb;

		self::$table_checked = true;
		$table               = self::table();
		$found               = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		self::$table_exists = ( $found === $table );

		return self::$table_exists;
	}

	public static function reset_table_cache(): void {
		self::$table_checked = false;
		self::$table_exists  = false;
	}
}
