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

	public static function activate(): void {
		self::create_tables();
		Settings::install_defaults();
		Settings::ensure_tokens();
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

	public static function maybe_upgrade(): void {
		$installed = (string) get_option( 'wps_pepita_db_version', '' );

		if ( WPS_PEPITA_DB_VERSION === $installed ) {
			return;
		}

		self::create_tables();
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
			external_id varchar(191) NOT NULL,
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
}
