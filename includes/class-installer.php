<?php
/**
 * Activation, deactivation and database migrations.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

use WPS\Pepita\Feed\Lock;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Orders\Order_Repository;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/** Marker: the order API default-OFF migration already ran. */
	public const ORDER_API_MIGRATION_OPTION = 'wps_pepita_order_api_default_off';

	/** Audit record of a verified, explicit merchant opt-in (user id and time). */
	public const ORDER_API_OPTIN_OPTION = 'wps_pepita_order_api_optin';

	/** Timestamp set when an unverified legacy "enabled" state was switched off by the migration. */
	public const ORDER_API_LEGACY_DISABLED_OPTION = 'wps_pepita_order_api_legacy_disabled';

	public static function activate(): void {
		self::create_tables();
		self::record_collation_migration();
		Settings::install_defaults();
		Settings::ensure_tokens();
		self::migrate_order_api_default_off();
		self::enforce_order_api_beta_off();
		Storage::ensure_directory();
		update_option( 'wps_pepita_db_version', WPS_PEPITA_DB_VERSION, false );

		Scheduler::schedule_all();

		Logger::reset_table_cache();
		Logger::info( 'plugin', 'A WP Pepita Sync plugin aktiválva.' );
	}

	public static function deactivate(): void {
		Scheduler::unschedule_all();
		Lock::release_all();

		Logger::info( 'plugin', 'A WP Pepita Sync plugin deaktiválva. A feedek és a beállítások megmaradtak.' );
	}

	/**
	 * One-time, fail-closed migration to the default-OFF order API.
	 *
	 * Earlier builds defaulted `order_api_enabled` to true and generated a key on activation, so a
	 * persisted `true` cannot be told apart from a deliberate merchant decision unless an explicit
	 * opt-in audit record exists. Without that record the API is switched off. Credentials are left
	 * untouched; the merchant re-enables intake explicitly in the admin UI.
	 */
	/** Set when the migration could not complete: order intake is then refused for this request (fail closed). */
	public static bool $order_api_migration_failed = false;

	/**
	 * A valid opt-in audit record: user id and a positive timestamp written by Settings::enable_order_api().
	 *
	 * @param mixed $record
	 */
	private static function is_valid_order_api_optin( $record ): bool {
		return is_array( $record ) && isset( $record['user'], $record['time'] ) && (int) $record['time'] > 0 && (int) $record['user'] >= 0;
	}

	public static function migrate_order_api_default_off(): void {
		if ( '1' === (string) get_option( self::ORDER_API_MIGRATION_OPTION, '' ) ) {
			return;
		}

		try {
			$stored = get_option( Settings::OPTION, null );
			if ( is_array( $stored ) && ! empty( $stored['order_api_enabled'] ) && ! self::is_valid_order_api_optin( get_option( self::ORDER_API_OPTIN_OPTION, false ) ) ) {
				Settings::update( array( 'order_api_enabled' => false ) );
				update_option( self::ORDER_API_LEGACY_DISABLED_OPTION, time(), false );
				Logger::warning(
					'plugin',
					'A Pepita rendelés API kikapcsolva: korábbi, nem igazolt (automatikusan engedélyezett) állapot. Kapcsolja be kifejezetten a Pepita rendelés API oldalán.'
				);
			}

			update_option( self::ORDER_API_MIGRATION_OPTION, '1', true );
		} catch ( \Throwable $e ) {
			// Fail closed without taking the site down: refuse intake for this request and retry on the next boot.
			self::$order_api_migration_failed = true;
		}
	}

	/**
	 * Feed-only beta (setting or wp-config constant) must leave the PERSISTED flag off too, so removing
	 * the constant later never resumes intake without a new explicit opt-in.
	 */
	public static function enforce_order_api_beta_off(): void {
		try {
			// Read the PERSISTED value: Settings::all() already shows the beta-forced (OFF) view.
			$stored = get_option( Settings::OPTION, array() );
			if ( Settings::is_beta_feed_only() && is_array( $stored ) && ! empty( $stored['order_api_enabled'] ) ) {
				Settings::update( array( 'order_api_enabled' => false ) );
			}
		} catch ( \Throwable $e ) {
			self::$order_api_migration_failed = true;
		}
	}

	public static function maybe_upgrade(): void {
		self::migrate_order_api_default_off();
		self::enforce_order_api_beta_off();

		$installed    = (string) get_option( 'wps_pepita_db_version', '' );
		$collation_ok = '1' === (string) get_option( 'wps_pepita_collation_ok', '' );

		if ( WPS_PEPITA_DB_VERSION === $installed && $collation_ok ) {
			return;
		}

		// The schema version is already current but a previous collation
		// migration failed: retry it, throttled so a persistent DB error does
		// not run an ALTER on every request.
		if ( WPS_PEPITA_DB_VERSION === $installed ) {
			if ( false !== get_transient( 'wps_pepita_collation_retry' ) ) {
				return;
			}

			self::record_collation_migration();

			return;
		}

		self::create_tables();
		self::record_collation_migration();
		Settings::install_defaults();
		Settings::ensure_tokens();
		Storage::ensure_directory();
		update_option( 'wps_pepita_db_version', WPS_PEPITA_DB_VERSION, false );

		Logger::reset_table_cache();
		Logger::info(
			'plugin',
			'Adatbázis séma frissítve.',
			array( 'from' => $installed, 'to' => WPS_PEPITA_DB_VERSION )
		);
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$logs = Logger::table();
		$orders = Order_Repository::table();

		$sql_logs = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			event varchar(64) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level (level),
			KEY event (event),
			KEY object_lookup (object_type,object_id)
		) {$charset_collate};";

		$sql_orders = "CREATE TABLE {$orders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			external_id varchar(191) COLLATE utf8mb4_bin NOT NULL,
			origin varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'processing',
			wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payload longtext NULL,
			payload_hash char(64) NOT NULL DEFAULT '',
			attempts int(10) unsigned NOT NULL DEFAULT 1,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY external_id (external_id),
			KEY status (status),
			KEY wc_order_id (wc_order_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_logs );
		dbDelta( $sql_orders );

		Logger::reset_table_cache();
		Order_Repository::reset_table_cache();
	}

	/**
	 * Run the collation migration and remember the outcome. A failed ALTER is
	 * retried on a later request instead of being silently forgotten.
	 */
	private static function record_collation_migration(): void {
		if ( self::migrate_external_id_collation() ) {
			update_option( 'wps_pepita_collation_ok', '1', false );
			delete_transient( 'wps_pepita_collation_retry' );

			return;
		}

		set_transient( 'wps_pepita_collation_retry', 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Make external_id comparisons case- and accent-sensitive.
	 *
	 * The default utf8mb4_unicode_ci collation makes `PEP-1` and `pep-1` the
	 * same key, so two genuinely different Pepita orders would collide on the
	 * UNIQUE index and the second would be reported as a duplicate. dbDelta
	 * does not reliably change an existing column collation, so the upgrade
	 * path issues an explicit ALTER once.
	 *
	 * @return bool True when the column is binary (already or after the ALTER).
	 */
	private static function migrate_external_id_collation(): bool {
		global $wpdb;

		$table = Order_Repository::table();

		$column = $wpdb->get_row(
			$wpdb->prepare( "SHOW FULL COLUMNS FROM {$table} LIKE %s", 'external_id' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $column ) || ! isset( $column['Collation'] ) ) {
			return true;
		}

		if ( 'utf8mb4_bin' === (string) $column['Collation'] ) {
			return true;
		}

		$result = $wpdb->query(
			"ALTER TABLE {$table} MODIFY external_id varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( false === $result ) {
			Logger::warning(
				'plugin',
				'Az external_id oszlop bináris collationra állítása sikertelen; a rendelésazonosítók továbbra is kis-nagybetűtől függetlenül egyezhetnek.',
				array( 'error' => (string) $wpdb->last_error )
			);

			return false;
		}

		return true;
	}
}
