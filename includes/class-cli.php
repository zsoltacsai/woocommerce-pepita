<?php
/**
 * WP-CLI commands.
 *
 * wp wps-pepita status
 * wp wps-pepita generate full|stock [--runtime=20]
 * wp wps-pepita orders [--status=...] [--limit=20]
 * wp wps-pepita purge-logs
 * wp wps-pepita repair-claim <external_id>
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Orders\Order_Repository;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Show the current feed and order status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wps-pepita status
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$generator = new Generator();

		$rows = array();

		foreach ( array( Generator::TYPE_FULL, Generator::TYPE_STOCK ) as $type ) {
			$state = $generator->get_feed_state( $type );

			$rows[] = array(
				'feed'      => $type,
				'running'   => $generator->is_running( $type ) ? 'yes' : 'no',
				'exported'  => (int) ( $state['counts']['exported'] ?? 0 ),
				'invalid'   => (int) ( $state['counts']['invalid'] ?? 0 ),
				'last_ok'   => empty( $state['last_success'] ) ? '-' : gmdate( 'Y-m-d H:i:s', (int) $state['last_success'] ),
				'size'      => (int) ( $state['file_size'] ?? 0 ),
				'error'     => (string) ( $state['last_error'] ?? '' ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'feed', 'running', 'exported', 'invalid', 'last_ok', 'size', 'error' ) );

		\WP_CLI::log( sprintf( 'Importált Pepita rendelések: %d', Order_Repository::count_completed() ) );
	}

	/**
	 * Generate a feed until it completes.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : full or stock
	 *
	 * [--runtime=<seconds>]
	 * : Maximum runtime per slice.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wps-pepita generate full
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function generate( array $args, array $assoc_args ): void {
		$type = isset( $args[0] ) ? (string) $args[0] : '';

		if ( ! in_array( $type, array( Generator::TYPE_FULL, Generator::TYPE_STOCK ), true ) ) {
			\WP_CLI::error( 'A feed típusa "full" vagy "stock" lehet.' );
		}

		$runtime = isset( $assoc_args['runtime'] ) ? max( 5, (int) $assoc_args['runtime'] ) : 30;
		$limit   = 1000;

		for ( $i = 0; $i < $limit; $i++ ) {
			$result = ( new Generator() )->run( $type, array( 'max_runtime' => $runtime ) );
			$status = (string) ( $result['status'] ?? 'error' );

			if ( 'running' === $status ) {
				$counts = is_array( $result['counts'] ?? null ) ? $result['counts'] : array();

				\WP_CLI::log(
					sprintf(
						'Folyamatban: %d%% (%d termék feldolgozva)',
						(int) ( $result['progress'] ?? 0 ),
						(int) ( $counts['processed'] ?? 0 )
					)
				);

				continue;
			}

			if ( 'completed' === $status ) {
				$counts = is_array( $result['counts'] ?? null ) ? $result['counts'] : array();

				\WP_CLI::success(
					sprintf(
						'A(z) %s feed elkészült: %d exportálva, %d kihagyva, %d bájt.',
						$type,
						(int) ( $counts['exported'] ?? 0 ),
						(int) ( $counts['invalid'] ?? 0 ),
						(int) ( $result['file_size'] ?? 0 )
					)
				);

				return;
			}

			\WP_CLI::error( (string) ( $result['message'] ?? 'A generálás sikertelen.' ) );
		}

		\WP_CLI::error( 'A generálás nem fejeződött be a megadott időn belül.' );
	}

	/**
	 * List imported Pepita orders.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : completed, processing or failed
	 *
	 * [--limit=<number>]
	 * : Number of rows.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function orders( array $args, array $assoc_args ): void {
		$result = Order_Repository::query(
			array(
				'status'   => isset( $assoc_args['status'] ) ? (string) $assoc_args['status'] : '',
				'per_page' => isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20,
				'page'     => 1,
			)
		);

		if ( array() === $result['items'] ) {
			\WP_CLI::log( 'Nincs megfelelő Pepita rendelés.' );

			return;
		}

		$rows = array();

		foreach ( $result['items'] as $row ) {
			$rows[] = array(
				'external_id' => (string) ( $row['external_id'] ?? '' ),
				'status'      => (string) ( $row['status'] ?? '' ),
				'wc_order'    => (int) ( $row['wc_order_id'] ?? 0 ),
				'attempts'    => (int) ( $row['attempts'] ?? 0 ),
				'created_at'  => (string) ( $row['created_at'] ?? '' ),
				'error'       => (string) ( $row['last_error'] ?? '' ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'external_id', 'status', 'wc_order', 'attempts', 'created_at', 'error' ) );
	}

	/**
	 * Reopen a completed claim whose WooCommerce order is missing or unfinalized.
	 *
	 * The next webhook replay (or Pepita retry) for this id rebuilds the order. Use only after checking
	 * that the order really is missing/unfinalized (see the order_import error log).
	 *
	 * ## OPTIONS
	 *
	 * <external_id>
	 * : Pepita order id.
	 *
	 * [--force]
	 * : Reopen even if a finalized WooCommerce order (also in the trash) still exists. Rebuilding then
	 *   creates a SECOND order and reduces stock again.
	 *
	 * @subcommand repair-claim
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function repair_claim( array $args, array $assoc_args ): void {
		$external_id = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $external_id ) {
			\WP_CLI::error( 'Adja meg a Pepita rendelésazonosítót.' );

			return;
		}

		$row = Order_Repository::get( $external_id );

		if ( null === $row || Order_Repository::STATUS_COMPLETED !== (string) ( $row['status'] ?? '' ) ) {
			\WP_CLI::error( 'Nincs ilyen lezárt igény.' );

			return;
		}

		if ( empty( $assoc_args['force'] ) ) {
			$candidates = array();

			if ( (int) ( $row['wc_order_id'] ?? 0 ) > 0 ) {
				$candidates[] = wc_get_order( (int) $row['wc_order_id'] );
			}

			$found = wc_get_orders(
				array(
					'limit'      => 20,
					'return'     => 'objects',
					'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
					'meta_key'   => \WPS\Pepita\Orders\Importer::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			foreach ( array_merge( $candidates, is_array( $found ) ? $found : array() ) as $order ) {
				if ( $order instanceof \WC_Order && '1' !== (string) $order->get_meta( '_wps_pepita_in_progress' ) ) {
					\WP_CLI::error( sprintf( 'A(z) #%d WooCommerce rendelés létezik és véglegesített (státusz: %s). Az újranyitás másodpéldányt hozna létre; ha mégis szükséges, használja a --force kapcsolót.', $order->get_id(), $order->get_status() ) );

					return;
				}
			}
		}

		if ( Order_Repository::reopen_completed( $external_id ) ) {
			\WP_CLI::success( 'A lezárt igény újranyitva; a következő webhook-ismétlés újraépíti a rendelést.' );

			return;
		}

		\WP_CLI::error( 'Nincs ilyen lezárt igény.' );
	}

	/**
	 * Purge old log entries and stored payloads.
	 *
	 * @subcommand purge-logs
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function purge_logs( array $args, array $assoc_args ): void {
		$logs     = Logger::purge_old();
		$payloads = Order_Repository::purge_payloads( (int) Settings::get( 'log_retention_days', 30 ) );

		\WP_CLI::success( sprintf( '%d naplóbejegyzés és %d adatcsomag törölve.', $logs, $payloads ) );
	}

	/**
	 * Export the current store configuration as a secret-free profile JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Destination file path to save the profile.
	 *
	 * [--pretty]
	 * : Pretty-print the JSON output.
	 *
	 * @subcommand export-profile
	 */
	public function export_profile( array $args, array $assoc_args ): void {
		$profile = \WPS\Pepita\Admin\Merchant_Profiles::export_profile();
		$flags   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if ( isset( $assoc_args['pretty'] ) ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		$json = wp_json_encode( $profile, $flags );
		$file = $assoc_args['file'] ?? '';

		if ( '' !== $file ) {
			if ( false === file_put_contents( $file, $json ) ) {
				\WP_CLI::error( 'A profil mentése meghiúsult a megadott fájlba.' );
				return;
			}
			\WP_CLI::success( sprintf( 'Konfigurációs profil elmentve: %s', $file ) );
			return;
		}

		\WP_CLI::log( (string) $json );
	}

	/**
	 * Import a configuration profile JSON into the current store.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the profile JSON file.
	 *
	 * [--[no-]categories]
	 * : Import category rules (default). Pass --no-categories to skip them.
	 *
	 * @subcommand import-profile
	 */
	public function import_profile( array $args, array $assoc_args ): void {
		$file = $args[0] ?? '';
		if ( '' === $file || ! file_exists( $file ) ) {
			\WP_CLI::error( 'A megadott profilfájl nem található.' );
			return;
		}

		$content = (string) file_get_contents( $file );
		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( 'A profilfájl nem érvényes JSON.' );
			return;
		}

		// WP-CLI parses --no-categories into categories=false; the legacy 'no-categories' key is tolerated too.
		$categories_arg = array_key_exists( 'categories', $assoc_args ) ? $assoc_args['categories'] : true;
		$import_cats    = ! isset( $assoc_args['no-categories'] ) && ! in_array( $categories_arg, array( false, 'false', '0', 0 ), true );
		$res         = \WPS\Pepita\Admin\Merchant_Profiles::import_profile( $decoded, array( 'import_category_rules' => $import_cats ) );

		if ( ! $res['success'] ) {
			\WP_CLI::error( $res['message'] );
			return;
		}

		\WP_CLI::success( sprintf( 'Profil sikeresen importálva (%d beállítás, %d kategóriaszabály).', $res['imported_settings'], $res['imported_categories'] ) );
	}

	/**
	 * Show operational summary and order reconciliation statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to include in the report (default: 30).
	 *
	 * [--format=<format>]
	 * : Render output format (table, json, csv). Default: table.
	 *
	 * @subcommand report-orders
	 */
	public function report_orders( array $args, array $assoc_args ): void {
		$days    = isset( $assoc_args['days'] ) ? max( 1, (int) $assoc_args['days'] ) : 30;
		$format  = $assoc_args['format'] ?? 'table';
		$summary = \WPS\Pepita\Admin\Operational_Reports::get_summary( $days );

		if ( 'json' === $format ) {
			\WP_CLI::log( (string) wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		if ( 'csv' === $format ) {
			\WP_CLI::log( \WPS\Pepita\Admin\Operational_Reports::export_report_csv( $days ) );
			return;
		}

		$rows = array(
			array( 'Metrika' => 'Időszak (napok)', 'Érték' => $days ),
			array( 'Metrika' => 'Összes rendelésértesítés', 'Érték' => $summary['total_orders'] ),
			array( 'Metrika' => 'Sikeresen importálva', 'Érték' => $summary['completed_orders'] ),
			array( 'Metrika' => 'Sikertelen kísérlet', 'Érték' => $summary['failed_orders'] ),
			array( 'Metrika' => 'Árva rendelések', 'Érték' => $summary['orphan_orders'] ),
			array( 'Metrika' => 'Összesített GMV', 'Érték' => $summary['total_gmv'] ),
		);

		if ( ! empty( $summary['is_partial_data'] ) ) {
			$rows[] = array( 'Metrika' => 'RÉSZLEGES ADAT (legutóbbi ' . $summary['truncated_limit'] . ' rekord)', 'Érték' => 'igen' );
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Metrika', 'Érték' ) );
	}
}
