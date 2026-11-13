<?php
/**
 * Real MySQL schema creation, indexes and migration behaviour.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Installer;
use WPS\Pepita\Logger;
use WPS\Pepita\Orders\Order_Repository;

/**
 * Read SHOW COLUMNS into a field => type map.
 *
 * @return array<string, string>
 */
function wps_it_columns( string $table ): array {
	global $wpdb;

	$rows    = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$columns = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$columns[ (string) $row['Field'] ] = strtolower( (string) $row['Type'] );
	}

	return $columns;
}

/**
 * Read SHOW INDEX into a key name => rows map.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function wps_it_indexes( string $table ): array {
	global $wpdb;

	$rows    = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$indexes = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$indexes[ (string) $row['Key_name'] ][] = $row;
	}

	return $indexes;
}

WPS_IT::line( 'Database schema' );

global $wpdb;

$logs_table   = Logger::table();
$orders_table = Order_Repository::table();

WPS_IT::test(
	'Activation created both plugin tables',
	static function () use ( $wpdb, $logs_table, $orders_table ): void {
		foreach ( array( $logs_table, $orders_table ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			WPS_IT::same( $table, $found, 'Missing table: ' . $table . '.' );
		}
	}
);

WPS_IT::test(
	'create_tables() recreates a missing schema from scratch',
	static function () use ( $wpdb, $logs_table, $orders_table ): void {
		$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$orders_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		WPS_IT::same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ), 'The logs table was not dropped.' );
		WPS_IT::same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) ), 'The orders table was not dropped.' );

		Installer::create_tables();

		foreach ( array( $logs_table, $orders_table ) as $table ) {
			WPS_IT::same( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'create_tables() did not recreate ' . $table . '.' );
		}
	}
);

WPS_IT::test(
	'Log table columns are the documented schema',
	static function () use ( $logs_table ): void {
		$columns = wps_it_columns( $logs_table );

		foreach ( array( 'id', 'created_at', 'level', 'event', 'message', 'context', 'object_type', 'object_id' ) as $column ) {
			WPS_IT::true( isset( $columns[ $column ] ), 'Missing log column: ' . $column . '.' );
		}

		WPS_IT::contains( 'bigint', $columns['id'], 'The log primary key must be a bigint.' );
		WPS_IT::contains( 'longtext', $columns['context'], 'The log context must be longtext.' );
	}
);

WPS_IT::test(
	'Order claim table columns are the documented schema',
	static function () use ( $orders_table ): void {
		$columns = wps_it_columns( $orders_table );

		foreach ( array( 'id', 'external_id', 'origin', 'status', 'wc_order_id', 'payload', 'payload_hash', 'attempts', 'last_error', 'created_at', 'updated_at' ) as $column ) {
			WPS_IT::true( isset( $columns[ $column ] ), 'Missing order column: ' . $column . '.' );
		}

		WPS_IT::contains( 'varchar(191)', $columns['external_id'], 'external_id must be varchar(191).' );
		WPS_IT::contains( 'char(64)', $columns['payload_hash'], 'payload_hash must be char(64).' );
	}
);

WPS_IT::test(
	'External ids are compared case-sensitively',
	static function () use ( $orders_table ): void {
		global $wpdb;

		$row = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$orders_table} LIKE 'external_id'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		WPS_IT::true( is_array( $row ), 'The external_id column is missing.' );
		WPS_IT::same( 'utf8mb4_bin', strtolower( (string) ( $row['Collation'] ?? '' ) ), 'external_id must use a binary collation.' );
	}
);

WPS_IT::test(
	'Required indexes and the unique external id constraint exist',
	static function () use ( $orders_table, $logs_table ): void {
		$order_indexes = wps_it_indexes( $orders_table );

		WPS_IT::true( isset( $order_indexes['external_id'] ), 'Missing external_id index.' );
		WPS_IT::same( '0', (string) $order_indexes['external_id'][0]['Non_unique'], 'external_id must be UNIQUE.' );
		WPS_IT::same( 'external_id', (string) $order_indexes['external_id'][0]['Column_name'], 'Unexpected unique index column.' );

		foreach ( array( 'status', 'wc_order_id', 'created_at' ) as $index ) {
			WPS_IT::true( isset( $order_indexes[ $index ] ), 'Missing order index: ' . $index . '.' );
		}

		$log_indexes = wps_it_indexes( $logs_table );

		foreach ( array( 'created_at', 'level', 'event', 'object_lookup' ) as $index ) {
			WPS_IT::true( isset( $log_indexes[ $index ] ), 'Missing log index: ' . $index . '.' );
		}

		WPS_IT::count( 2, $log_indexes['object_lookup'], 'object_lookup must cover object_type and object_id.' );
	}
);

WPS_IT::test(
	'Tables use utf8mb4 and InnoDB',
	static function () use ( $wpdb, $logs_table, $orders_table ): void {
		foreach ( array( $logs_table, $orders_table ) as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );

			WPS_IT::true( is_array( $status ), 'Could not read table status for ' . $table . '.' );
			WPS_IT::same( 'InnoDB', (string) $status['Engine'], 'Expected InnoDB for ' . $table . '.' );
			WPS_IT::true( str_starts_with( strtolower( (string) $status['Collation'] ), 'utf8mb4_' ), 'Expected utf8mb4 collation for ' . $table . '.' );
		}
	}
);

WPS_IT::test(
	'The unique index rejects a duplicate external id',
	static function () use ( $wpdb, $orders_table ): void {
		$external_id = 'WPS-IT-SCHEMA-' . WPS_IT::run_id();
		$now         = current_time( 'mysql', true );

		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$orders_table} (external_id, origin, status, payload_hash, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$external_id,
			'pepita.hu',
			'processing',
			str_repeat( 'a', 64 ),
			$now,
			$now
		);

		WPS_IT::same( 1, (int) $wpdb->query( $sql ), 'The first insert should succeed.' );
		WPS_IT::same( 0, (int) $wpdb->query( $sql ), 'The duplicate insert must be ignored.' );
		WPS_IT::same(
			1,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_table} WHERE external_id = %s", $external_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'The unique index did not enforce one row per external id.'
		);

		$wpdb->delete( $orders_table, array( 'external_id' => $external_id ), array( '%s' ) );
	}
);

WPS_IT::test(
	'The logger writes to the real table',
	static function () use ( $wpdb ): void {
		Logger::reset_table_cache();
		Logger::info( 'integration', 'Schema integration check.', array( 'suite' => 'm8' ), 'test', 0 );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Logger::table() . ' WHERE event = %s', 'integration' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		WPS_IT::greater( 0, $count, 'The logger did not persist a row.' );

		$wpdb->delete( Logger::table(), array( 'event' => 'integration' ), array( '%s' ) );
	}
);

WPS_IT::test(
	'maybe_upgrade() migrates an old schema version idempotently',
	static function () use ( $wpdb, $logs_table, $orders_table ): void {
		update_option( 'wps_pepita_db_version', '0.9.0', false );

		Installer::maybe_upgrade();
		WPS_IT::same( WPS_PEPITA_DB_VERSION, (string) get_option( 'wps_pepita_db_version' ), 'The schema version was not upgraded.' );

		Installer::maybe_upgrade();
		WPS_IT::same( WPS_PEPITA_DB_VERSION, (string) get_option( 'wps_pepita_db_version' ), 'A second upgrade must be a no-op.' );

		delete_option( 'wps_pepita_db_version' );
		Installer::maybe_upgrade();
		WPS_IT::same( WPS_PEPITA_DB_VERSION, (string) get_option( 'wps_pepita_db_version' ), 'A missing schema version was not installed.' );

		foreach ( array( $logs_table, $orders_table ) as $table ) {
			WPS_IT::same( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Migration lost table ' . $table . '.' );
		}
	}
);

WPS_IT::test(
	'create_tables() is idempotent',
	static function () use ( $wpdb ): void {
		$wpdb->last_error = '';

		Installer::create_tables();
		Installer::create_tables();

		WPS_IT::same( '', (string) $wpdb->last_error, 'dbDelta reported an error: ' . $wpdb->last_error );
	}
);
