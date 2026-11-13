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
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

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

		$inserted = self::insert_claim( $external_id, $origin, $payload, $payload_hash, $now );

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
		$updated = self::update_claim( $external_id, $payload, $payload_hash, $now, $stale_before );

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

	/**
	 * Insert a new claim, storing the raw payload only when the operator asked
	 * for it. `order_api_keep_payloads` defaults to off: the hash alone is
	 * enough for idempotency and reconciliation.
	 *
	 * @return int|false
	 */
	private static function insert_claim( string $external_id, string $origin, string $payload, string $payload_hash, string $now ) {
		global $wpdb;

		$table = self::table();
		$keep  = (bool) Settings::get( 'order_api_keep_payloads', false );

		if ( $keep ) {
			return $wpdb->query(
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
		}

		return $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (external_id, origin, status, wc_order_id, payload, payload_hash, attempts, created_at, updated_at)
				 VALUES (%s, %s, %s, 0, NULL, %s, 1, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$external_id,
				$origin,
				self::STATUS_PROCESSING,
				$payload_hash,
				$now,
				$now
			)
		);
	}

	/**
	 * @return int|false
	 */
	private static function update_claim( string $external_id, string $payload, string $payload_hash, string $now, string $stale_before ) {
		global $wpdb;

		$table          = self::table();
		$keep           = (bool) Settings::get( 'order_api_keep_payloads', false );
		$existing_claim = self::get( $external_id );
		$has_order      = null !== $existing_claim && (int) ( $existing_claim['wc_order_id'] ?? 0 ) > 0;
		$target_hash    = $has_order && '' !== (string) ( $existing_claim['payload_hash'] ?? '' )
			? (string) $existing_claim['payload_hash']
			: $payload_hash;

		if ( $keep && ( ! $has_order || null !== ( $existing_claim['payload'] ?? null ) ) ) {
			$target_payload = $has_order ? (string) $existing_claim['payload'] : $payload;

			return $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET status = %s, payload = %s, payload_hash = %s, attempts = attempts + 1, last_error = NULL, updated_at = %s
					 WHERE external_id = %s AND (status = %s OR updated_at < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_PROCESSING,
					$target_payload,
					$target_hash,
					$now,
					$external_id,
					self::STATUS_FAILED,
					$stale_before
				)
			);
		}

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, payload = NULL, payload_hash = %s, attempts = attempts + 1, last_error = NULL, updated_at = %s
				 WHERE external_id = %s AND (status = %s OR updated_at < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PROCESSING,
				$target_hash,
				$now,
				$external_id,
				self::STATUS_FAILED,
				$stale_before
			)
		);
	}

	public static function complete( string $external_id, int $order_id, string $payload_hash = '', int $expected_attempt = 0 ): bool {
		global $wpdb;

		if ( $expected_attempt <= 0 ) {
			return false;
		}

		$table = self::table();

		if ( '' !== $payload_hash ) {
			$res = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET status = %s, wc_order_id = %d, payload_hash = %s, last_error = NULL, updated_at = %s
					 WHERE external_id = %s AND (status = %s OR status = %s) AND attempts = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_COMPLETED,
					max( 0, $order_id ),
					$payload_hash,
					current_time( 'mysql', true ),
					$external_id,
					self::STATUS_PROCESSING,
					self::STATUS_COMPLETED,
					$expected_attempt
				)
			);
		} else {
			$res = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET status = %s, wc_order_id = %d, last_error = NULL, updated_at = %s
					 WHERE external_id = %s AND (status = %s OR status = %s) AND attempts = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_COMPLETED,
					max( 0, $order_id ),
					current_time( 'mysql', true ),
					$external_id,
					self::STATUS_PROCESSING,
					self::STATUS_COMPLETED,
					$expected_attempt
				)
			);
		}

		if ( false === $res ) {
			return false;
		}

		if ( 0 === (int) $res ) {
			$row = self::get( $external_id );
			return null !== $row
				&& (int) ( $row['attempts'] ?? 0 ) === $expected_attempt
				&& self::STATUS_COMPLETED === ( $row['status'] ?? '' );
		}

		Logger::info(
			'order_import',
			'Pepita rendelés sikeresen importálva.',
			array( 'external_id' => $external_id, 'order_id' => $order_id )
		);

		return true;
	}

	/**
	 * Associate an in-progress WooCommerce order ID with an active claim.
	 *
	 * Ensures crash recovery can locate the order even if the process is killed
	 * before final metadata persistence or completion.
	 */
	public static function associate( string $external_id, int $order_id, int $expected_attempt = 0 ): bool {
		global $wpdb;

		$table = self::table();

		if ( $expected_attempt > 0 ) {
			$res = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET wc_order_id = %d, updated_at = %s
					 WHERE external_id = %s AND (status = %s OR status = %s) AND attempts = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					max( 0, $order_id ),
					current_time( 'mysql', true ),
					$external_id,
					self::STATUS_PROCESSING,
					self::STATUS_COMPLETED,
					$expected_attempt
				)
			);

			if ( false === $res ) {
				return false;
			}

			if ( 0 === (int) $res ) {
				return self::is_attempt_current( $external_id, $expected_attempt );
			}

			return true;
		}

		$res = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET wc_order_id = %d, updated_at = %s
				 WHERE external_id = %s AND (status = %s OR status = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 0, $order_id ),
				current_time( 'mysql', true ),
				$external_id,
				self::STATUS_PROCESSING,
				self::STATUS_COMPLETED
			)
		);

		return false !== $res;
	}

	/**
	 * Associate a recovered order ID with a legacy completed claim that lacked wc_order_id.
	 *
	 * Strictly fenced to completed claims where wc_order_id is currently 0 or NULL.
	 *
	 * @param string $external_id
	 * @param int    $order_id
	 * @return bool
	 */
	public static function associate_legacy_completed_order( string $external_id, int $order_id ): bool {
		global $wpdb;

		if ( $order_id <= 0 ) {
			return false;
		}

		$table = self::table();
		$res   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET wc_order_id = %d, updated_at = %s
				 WHERE external_id = %s AND status = %s AND (wc_order_id = 0 OR wc_order_id IS NULL)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id,
				current_time( 'mysql', true ),
				$external_id,
				self::STATUS_COMPLETED
			)
		);

		return false !== $res;
	}

	/**
	 * Atomically fence cleanup of an incomplete shell order.
	 *
	 * Verifies that the current worker still owns the lease (status = 'processing'
	 * and attempts = $attempt) and that wc_order_id matches $expected_order_id (or 0).
	 * Sets wc_order_id = 0 atomically to prevent concurrent adoption while deletion proceeds.
	 *
	 * @param string $external_id
	 * @param int    $expected_order_id
	 * @param int    $attempt
	 * @return bool True if fencing succeeded and this worker owns the lease, false otherwise.
	 */
	public static function fence_incomplete_order_cleanup( string $external_id, int $expected_order_id, int $attempt ): bool {
		global $wpdb;

		if ( $attempt <= 0 ) {
			return false;
		}

		$table = self::table();
		$res   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET wc_order_id = 0, updated_at = %s
				 WHERE external_id = %s
				   AND status = %s
				   AND attempts = %d
				   AND (wc_order_id = %d OR wc_order_id = 0)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$external_id,
				self::STATUS_PROCESSING,
				$attempt,
				max( 0, $expected_order_id )
			)
		);

		if ( false === $res ) {
			return false;
		}

		if ( 0 === (int) $res ) {
			$row = self::get( $external_id );
			return null !== $row
				&& (int) ( $row['attempts'] ?? 0 ) === $attempt
				&& self::STATUS_PROCESSING === ( $row['status'] ?? '' )
				&& 0 === (int) ( $row['wc_order_id'] ?? 0 );
		}

		return true;
	}

	/**
	 * Verify whether an attempt counter is still the active holder of the claim lease.
	 */
	public static function is_attempt_current( string $external_id, int $expected_attempt ): bool {
		if ( $expected_attempt <= 0 ) {
			return true;
		}

		$row = self::get( $external_id );
		if ( null === $row ) {
			return false;
		}

		return (int) ( $row['attempts'] ?? 0 ) === $expected_attempt
			&& ( self::STATUS_PROCESSING === ( $row['status'] ?? '' ) || self::STATUS_COMPLETED === ( $row['status'] ?? '' ) );
	}

	/**
	 * Backfill payload hash for historical claims where it was missing.
	 */
	public static function backfill_hash( string $external_id, string $payload_hash ): void {
		global $wpdb;

		if ( '' === $payload_hash ) {
			return;
		}

		$table = self::table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET payload_hash = %s, updated_at = %s
				 WHERE external_id = %s AND (payload_hash IS NULL OR payload_hash = '')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$payload_hash,
				current_time( 'mysql', true ),
				$external_id
			)
		);
	}

	public static function fail( string $external_id, string $error, int $expected_attempt = 0 ): bool {
		global $wpdb;

		$table = self::table();

		if ( $expected_attempt > 0 ) {
			$res = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET status = %s, last_error = %s, updated_at = %s
					 WHERE external_id = %s AND status = %s AND attempts = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_FAILED,
					substr( $error, 0, 4000 ),
					current_time( 'mysql', true ),
					$external_id,
					self::STATUS_PROCESSING,
					$expected_attempt
				)
			);

			return false !== $res && (int) $res > 0;
		}

		$res = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, last_error = %s, updated_at = %s
				 WHERE external_id = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_FAILED,
				substr( $error, 0, 4000 ),
				current_time( 'mysql', true ),
				$external_id,
				self::STATUS_PROCESSING
			)
		);

		return false !== $res;
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
	 *
	 * When $force is true (the operator disabled payload retention), every
	 * stored payload is cleared immediately. The dedupe row and its hash are
	 * always kept, so idempotency is unaffected.
	 */
	public static function purge_payloads( int $days, bool $force = false ): int {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$table = self::table();

		if ( $force ) {
			$updated = $wpdb->query(
				"UPDATE {$table} SET payload = NULL WHERE payload IS NOT NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			return is_int( $updated ) ? $updated : 0;
		}

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
	 * Delete package-label PDFs past their retention window.
	 *
	 * A label attached to an order that is still operationally open
	 * (pending / processing / on-hold) is kept, so a late shipment never loses
	 * its document. Labels of completed, cancelled, refunded or failed orders,
	 * and labels with no linked order, are removed after the configured window.
	 *
	 * @return int Number of deleted files.
	 */
	public static function purge_labels( int $days ): int {
		$dir = Storage::label_dir();

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$files = glob( $dir . '/pepita-*.pdf' );

		if ( ! is_array( $files ) ) {
			return 0;
		}

		$cutoff  = time() - ( max( 1, $days ) * DAY_IN_SECONDS );
		$deleted = 0;

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) || filemtime( $file ) >= $cutoff ) {
				continue;
			}

			if ( ! self::label_is_releasable( $file ) ) {
				continue;
			}

			if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				self::clear_label_meta( $file );
				$deleted++;
			}
		}

		return $deleted;
	}

	private static function label_is_releasable( string $file ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return true;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => Importer::META_PACKAGE_LABEL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $file, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'status'     => 'any',
			)
		);

		if ( ! is_array( $orders ) || ! isset( $orders[0] ) ) {
			return true;
		}

		$order = wc_get_order( (int) $orders[0] );

		if ( ! $order instanceof \WC_Order ) {
			return true;
		}

		// Only delete a label when the order is in a known-closed state. Any
		// custom status (for example "shipped" or "packed") is treated as open
		// so a fulfilment workflow never loses its document.
		return in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' ), true );
	}

	private static function clear_label_meta( string $file ): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'meta_key'   => Importer::META_PACKAGE_LABEL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $file, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'status'     => 'any',
			)
		);

		if ( ! is_array( $orders ) ) {
			return;
		}

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( (int) $order_id );

			if ( $order instanceof \WC_Order ) {
				$order->delete_meta_data( Importer::META_PACKAGE_LABEL );
				$order->save();
			}
		}
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
