<?php
/**
 * Durable, filterable plugin log.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	private const LEVEL_WEIGHT = array(
		'debug'   => 10,
		'info'    => 20,
		'success' => 20,
		'warning' => 30,
		'error'   => 40,
	);

	private static bool $table_checked = false;
	private static bool $table_exists = false;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wps_pepita_logs';
	}

	public static function log(
		string $level,
		string $event,
		string $message,
		array $context = array(),
		string $object_type = '',
		int $object_id = 0
	): void {
		$level = strtolower( $level );

		if ( ! isset( self::LEVEL_WEIGHT[ $level ] ) ) {
			$level = 'info';
		}

		if ( ! self::should_log( $level ) || ! self::table_exists() ) {
			return;
		}

		global $wpdb;

		$context = Redactor::redact( $context );

		$encoded = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'level'       => 'success' === $level ? 'info' : $level,
				'event'       => substr( sanitize_key( $event ), 0, 64 ),
				'message'     => wp_strip_all_tags( $message ),
				'context'     => is_string( $encoded ) ? $encoded : null,
				'object_type' => substr( sanitize_key( $object_type ), 0, 32 ),
				'object_id'   => max( 0, $object_id ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( '' === (string) $wpdb->last_error && defined( 'WP_DEBUG' ) && WP_DEBUG && 'debug' === $level ) {
			// Debug entries are also mirrored to the PHP error log for developers.
			error_log( '[WP Pepita Sync] ' . $event . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function debug( string $event, string $message, array $context = array() ): void {
		self::log( 'debug', $event, $message, $context );
	}

	public static function info( string $event, string $message, array $context = array() ): void {
		self::log( 'info', $event, $message, $context );
	}

	public static function warning( string $event, string $message, array $context = array() ): void {
		self::log( 'warning', $event, $message, $context );
	}

	public static function error( string $event, string $message, array $context = array() ): void {
		self::log( 'error', $event, $message, $context );
	}

	private static function should_log( string $level ): bool {
		$configured = (string) Settings::get( 'log_level', 'info' );
		$minimum    = self::LEVEL_WEIGHT[ $configured ] ?? 20;
		$weight     = self::LEVEL_WEIGHT[ $level ] ?? 20;

		return $weight >= $minimum;
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

		$where  = array( '1=1' );
		$params = array();

		$level = isset( $args['level'] ) ? sanitize_key( (string) $args['level'] ) : '';
		if ( '' !== $level && in_array( $level, self::LEVELS, true ) ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}

		$event = isset( $args['event'] ) ? sanitize_key( (string) $args['event'] ) : '';
		if ( '' !== $event ) {
			$where[]  = 'event = %s';
			$params[] = $event;
		}

		$object_type = isset( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : '';
		if ( '' !== $object_type ) {
			$where[]  = 'object_type = %s';
			$params[] = $object_type;
		}

		$object_id = isset( $args['object_id'] ) ? (int) $args['object_id'] : 0;
		if ( $object_id > 0 ) {
			$where[]  = 'object_id = %d';
			$params[] = $object_id;
		}

		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$where[]  = '(message LIKE %s OR context LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$date_from = isset( $args['date_from'] ) ? (string) $args['date_from'] : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		$date_to = isset( $args['date_to'] ) ? (string) $args['date_to'] : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		$table     = self::table();

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		$total = (int) ( array() !== $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$orderby = isset( $args['orderby'] ) ? sanitize_key( (string) $args['orderby'] ) : 'created_at';
		$orderby = in_array( $orderby, array( 'created_at', 'level', 'event', 'id' ), true ) ? $orderby : 'created_at';
		$order   = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		$items = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent_errors( int $limit = 5 ): array {
		$result = self::query(
			array(
				'level'    => 'error',
				'per_page' => max( 1, $limit ),
				'page'     => 1,
			)
		);

		return $result['items'];
	}

	/**
	 * @return string[]
	 */
	public static function event_types(): array {
		if ( ! self::table_exists() ) {
			return array();
		}

		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_col( "SELECT DISTINCT event FROM {$table} ORDER BY event ASC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	public static function clear(): int {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$table = self::table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $count;
	}

	public static function purge_old( ?int $days = null ): int {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$days = null === $days ? (int) Settings::get( 'log_retention_days', 30 ) : $days;
		$days = max( 1, $days );

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Sanitize a field for CSV export, preventing formula injection (CSV injection).
	 *
	 * Prepend a single quote (') if the field starts with =, +, -, @, tab or return,
	 * or starts with whitespace followed by any of those characters.
	 *
	 * @param mixed $value Field value to sanitize.
	 * @return string
	 */
	public static function sanitize_csv_field( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$string = (string) $value;

		if ( '' === $string ) {
			return '';
		}

		$trimmed = ltrim( $string );
		if ( '' !== $trimmed ) {
			$first = $trimmed[0];
			if ( in_array( $first, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				return "'" . $string;
			}
		}

		$raw_first = $string[0];
		if ( in_array( $raw_first, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $string;
		}

		return $string;
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
