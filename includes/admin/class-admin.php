<?php
/**
 * Hungarian admin UI: menu, pages, form handlers and assets.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Feed\Lock;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Logger;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const MENU_SLUG = 'wps-pepita';

	/**
	 * Capability required for every screen and action.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wps_pepita_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_wps_pepita_generate', array( $this, 'handle_generate' ) );
		add_action( 'admin_post_wps_pepita_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_wps_pepita_regenerate_secret', array( $this, 'handle_regenerate_secret' ) );
		add_action( 'admin_post_wps_pepita_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_wps_pepita_reset_feeds', array( $this, 'handle_reset_feeds' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );

		( new Ajax() )->register();
		( new Product_Metabox() )->register();
		( new Product_List() )->register();
		( new Category_List() )->register();
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Pepita', 'wp-pepita-sync' ),
			__( 'Pepita', 'wp-pepita-sync' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-store',
			56
		);

		$pages = array(
			array( self::MENU_SLUG, __( 'Áttekintés', 'wp-pepita-sync' ), 'render_dashboard' ),
			array( 'wps-pepita-products', __( 'Termékek', 'wp-pepita-sync' ), 'render_products' ),
			array( 'wps-pepita-categories', __( 'Kategóriák', 'wp-pepita-sync' ), 'render_categories' ),
			array( 'wps-pepita-feeds', __( 'Feedek', 'wp-pepita-sync' ), 'render_feeds' ),
			array( 'wps-pepita-orders', __( 'Rendelések', 'wp-pepita-sync' ), 'render_orders' ),
			array( 'wps-pepita-api', __( 'Rendelés API', 'wp-pepita-sync' ), 'render_api' ),
			array( 'wps-pepita-settings', __( 'Beállítások', 'wp-pepita-sync' ), 'render_settings' ),
			array( 'wps-pepita-logs', __( 'Napló', 'wp-pepita-sync' ), 'render_logs' ),
		);

		foreach ( $pages as $page ) {
			add_submenu_page(
				self::MENU_SLUG,
				$page[1],
				$page[1],
				self::CAPABILITY,
				$page[0],
				array( $this, $page[2] )
			);
		}
	}

	/**
	 * @param string $hook
	 */
	public function enqueue_assets( $hook ): void {
		$is_plugin_page = is_string( $hook ) && str_contains( $hook, 'wps-pepita' );
		$screen         = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_product     = $screen && in_array( (string) $screen->id, array( 'product', 'edit-product' ), true );

		if ( ! $is_plugin_page && ! $is_product ) {
			return;
		}

		wp_enqueue_style(
			'wps-pepita-admin',
			WPS_PEPITA_URL . 'assets/admin.css',
			array(),
			WPS_PEPITA_VERSION
		);

		wp_enqueue_script(
			'wps-pepita-admin',
			WPS_PEPITA_URL . 'assets/admin.js',
			array( 'jquery' ),
			WPS_PEPITA_VERSION,
			true
		);

		wp_localize_script(
			'wps-pepita-admin',
			'wpsPepita',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'strings' => array(
					'confirm'      => __( 'Biztosan folytatja?', 'wp-pepita-sync' ),
					'working'      => __( 'Feldolgozás…', 'wp-pepita-sync' ),
					'saved'        => __( 'Mentve.', 'wp-pepita-sync' ),
					'error'        => __( 'Hiba történt. Kérjük, próbálja újra.', 'wp-pepita-sync' ),
					'copied'       => __( 'Vágólapra másolva.', 'wp-pepita-sync' ),
					'generateFull' => __( 'Teljes feed generálása indul…', 'wp-pepita-sync' ),
					'generateStock'=> __( 'Készletfeed generálása indul…', 'wp-pepita-sync' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Page renderers
	 * ------------------------------------------------------------------ */

	public function render_dashboard(): void {
		$this->guard();

		$generator = new Generator();
		$full      = $generator->get_feed_state( Generator::TYPE_FULL );
		$stock     = $generator->get_feed_state( Generator::TYPE_STOCK );

		$this->view(
			'dashboard',
			array(
				'full'          => $full,
				'stock'         => $stock,
				'full_running'  => $generator->is_running( Generator::TYPE_FULL ),
				'stock_running' => $generator->is_running( Generator::TYPE_STOCK ),
				'errors'        => Logger::recent_errors( 5 ),
			)
		);
	}

	public function render_products(): void {
		$this->guard();
		$this->view( 'products', array() );
	}

	public function render_categories(): void {
		$this->guard();
		$this->view( 'categories', array() );
	}

	public function render_feeds(): void {
		$this->guard();

		$generator = new Generator();

		$this->view(
			'feeds',
			array(
				'full'          => $generator->get_feed_state( Generator::TYPE_FULL ),
				'stock'         => $generator->get_feed_state( Generator::TYPE_STOCK ),
				'full_running'  => $generator->is_running( Generator::TYPE_FULL ),
				'stock_running' => $generator->is_running( Generator::TYPE_STOCK ),
				'full_exists'   => Storage::exists( Generator::TYPE_FULL ),
				'stock_exists'  => Storage::exists( Generator::TYPE_STOCK ),
				'full_url'      => Settings::feed_url( Generator::TYPE_FULL ),
				'stock_url'     => Settings::feed_url( Generator::TYPE_STOCK ),
			)
		);
	}

	public function render_orders(): void {
		$this->guard();
		$this->view( 'orders', array() );
	}

	public function render_api(): void {
		$this->guard();
		$this->view( 'api', array() );
	}

	public function render_settings(): void {
		$this->guard();
		$this->view( 'settings', array() );
	}

	public function render_logs(): void {
		$this->guard();
		$this->view( 'logs', array() );
	}

	/* ---------------------------------------------------------------------
	 * admin-post handlers
	 * ------------------------------------------------------------------ */

	public function handle_save_settings(): void {
		$this->guard( true );

		check_admin_referer( 'wps_pepita_save_settings' );

		$raw = isset( $_POST['wps'] ) && is_array( $_POST['wps'] )
			? wp_unslash( $_POST['wps'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$input = $this->settings_input( $raw );

		Settings::update( $input );

		Scheduler::schedule_all();
		Settings::reset_cache();

		Logger::info( 'settings', 'Beállítások mentve.' );

		$this->redirect( 'wps-pepita-settings', array( 'wps_notice' => 'settings_saved' ) );
	}

	/**
	 * Translate the raw form into a complete settings array.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	private function settings_input( array $raw ): array {
		$bools = array(
			'default_included',
			'include_child_categories',
			'include_purchase_net',
			'stock_requires_full',
			'allow_empty_feed',
			'feed_public',
			'order_api_enabled',
			'order_api_require_https',
			'order_api_keep_payloads',
			'order_api_save_label',
			'record_export_meta',
			'delete_data_on_uninstall',
		);

		$input = array();

		foreach ( $bools as $key ) {
			$input[ $key ] = ! empty( $raw[ $key ] );
		}

		$scalars = array(
			'currency',
			'vat_mode',
			'vat_fixed',
			'price_adjustment_type',
			'price_adjustment_value',
			'rounding',
			'shipping_delay',
			'shipping_price',
			'warranty_type',
			'warranty_duration',
			'description_source',
			'brand_source',
			'brand_meta_key',
			'brand_taxonomy',
			'manufacturer_default',
			'gtin_meta_keys',
			'max_category_paths',
			'max_gallery_images',
			'batch_size',
			'batches_per_run',
			'max_runtime',
			'full_feed_interval',
			'stock_feed_interval',
			'removal_policy',
			'removal_grace_days',
			'feed_cache_max_age',
			'feed_full_slug',
			'feed_stock_slug',
			'order_api_allowed_ips',
			'order_api_status_paid',
			'order_api_status_unpaid',
			'order_api_max_body_kb',
			'log_retention_days',
			'log_level',
		);

		foreach ( $scalars as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$input[ $key ] = $raw[ $key ];
			}
		}

		$statuses = isset( $raw['allowed_product_statuses'] ) && is_array( $raw['allowed_product_statuses'] )
			? $raw['allowed_product_statuses']
			: array();

		$input['allowed_product_statuses'] = $statuses;

		$gtin = isset( $raw['gtin_required_categories'] ) ? (string) $raw['gtin_required_categories'] : '';
		$input['gtin_required_categories'] = array_filter( array_map( 'intval', preg_split( '/[\s,;]+/', $gtin ) ?: array() ) );

		$payment = isset( $raw['payment_map'] ) && is_array( $raw['payment_map'] ) ? $raw['payment_map'] : array();
		$input['payment_map'] = $payment;

		$shipping = isset( $raw['shipping_map'] ) && is_array( $raw['shipping_map'] ) ? $raw['shipping_map'] : array();
		$input['shipping_map'] = $shipping;

		return $input;
	}

	public function handle_generate(): void {
		$this->guard( true );

		check_admin_referer( 'wps_pepita_generate' );

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : Generator::TYPE_FULL;
		$type = Generator::TYPE_STOCK === $type ? Generator::TYPE_STOCK : Generator::TYPE_FULL;

		Scheduler::clear_dirty( $type );

		$result = ( new Generator() )->run( $type, array( 'max_runtime' => 20 ) );

		$notice = 'generation_' . (string) ( $result['status'] ?? 'error' );

		$this->redirect(
			'wps-pepita-feeds',
			array(
				'wps_notice' => $notice,
				'wps_type'   => $type,
			)
		);
	}

	public function handle_download(): void {
		$this->guard( true );

		check_admin_referer( 'wps_pepita_download' );

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : Generator::TYPE_FULL;
		$type = Generator::TYPE_STOCK === $type ? Generator::TYPE_STOCK : Generator::TYPE_FULL;

		if ( ! Storage::exists( $type ) ) {
			$this->redirect( 'wps-pepita-feeds', array( 'wps_notice' => 'feed_missing' ) );
		}

		$path = Storage::feed_path( $type );
		$name = Generator::TYPE_FULL === $type ? 'pepita-full.xml' : 'pepita-stock.xml';

		Logger::info( 'feed_download', 'Feed letöltve az admin felületről.', array( 'type' => $type ) );

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public function handle_regenerate_secret(): void {
		$this->guard( true );

		check_admin_referer( 'wps_pepita_regenerate_secret' );

		$key = isset( $_GET['secret'] ) ? sanitize_key( wp_unslash( (string) $_GET['secret'] ) ) : '';

		if ( ! in_array( $key, Settings::SECRET_KEYS, true ) ) {
			$this->redirect( 'wps-pepita-settings', array( 'wps_notice' => 'secret_invalid' ) );
		}

		if ( Settings::using_constant( $key ) ) {
			$this->redirect( 'wps-pepita-settings', array( 'wps_notice' => 'secret_constant' ) );
		}

		Settings::regenerate_secret( $key );

		Logger::info( 'settings', 'Titkos kulcs újragenerálva.', array( 'key' => $key ) );

		$page = 'feed_token' === $key ? 'wps-pepita-feeds' : 'wps-pepita-api';

		$this->redirect( $page, array( 'wps_notice' => 'secret_regenerated' ) );
	}

	public function handle_clear_logs(): void {
		$this->guard( true );

		check_admin_referer( 'wps_pepita_clear_logs' );

		$deleted = Logger::clear();

		Logger::info( 'log_maintenance', 'Napló törölve.', array( 'deleted' => $deleted ) );

		$this->redirect( 'wps-pepita-logs', array( 'wps_notice' => 'logs_cleared' ) );
	}

	public function handle_reset_feeds(): void {
		$this->guard( true );

		check_admin_referer( 'wps_pepita_reset_feeds' );

		if ( Lock::is_locked( Generator::TYPE_FULL ) || Lock::is_locked( Generator::TYPE_STOCK ) ) {
			$this->redirect( 'wps-pepita-feeds', array( 'wps_notice' => 'reset_locked' ) );
		}

		Storage::purge();
		Storage::reset();

		delete_option( 'wps_pepita_feed_state_full' );
		delete_option( 'wps_pepita_feed_state_stock' );

		Logger::info( 'feed_reset', 'A generált feedek és az állapot törölve.' );

		$this->redirect( 'wps-pepita-feeds', array( 'wps_notice' => 'feeds_reset' ) );
	}

	/* ---------------------------------------------------------------------
	 * Notices and helpers
	 * ------------------------------------------------------------------ */

	public function render_notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! str_contains( (string) $screen->id, 'wps-pepita' ) ) {
			return;
		}

		$notice = isset( $_GET['wps_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['wps_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'settings_saved'      => array( 'success', __( 'A beállítások mentésre kerültek.', 'wp-pepita-sync' ) ),
			'generation_completed'=> array( 'success', __( 'A feed generálása befejeződött és publikálásra került.', 'wp-pepita-sync' ) ),
			'generation_running'  => array( 'info', __( 'A feed generálása elindult, és háttérfolyamatban folytatódik. Az állapot néhány perc múlva frissül.', 'wp-pepita-sync' ) ),
			'generation_started'  => array( 'info', __( 'A feed generálása elindult.', 'wp-pepita-sync' ) ),
			'generation_skipped'  => array( 'warning', __( 'A generálás nem indult el, mert már fut egy folyamat ehhez a feedhez.', 'wp-pepita-sync' ) ),
			'generation_error'    => array( 'error', __( 'A feed generálása sikertelen volt. A korábbi feed megmaradt. A részletek a naplóban találhatók.', 'wp-pepita-sync' ) ),
			'feed_missing'        => array( 'error', __( 'A kért feed még nem készült el.', 'wp-pepita-sync' ) ),
			'secret_regenerated'  => array( 'success', __( 'Az új titkos kulcs elkészült. A régi kulcs azonnal érvénytelenné vált.', 'wp-pepita-sync' ) ),
			'secret_constant'     => array( 'warning', __( 'A kulcs a wp-config.php fájlban van meghatározva, ezért az admin felületről nem módosítható.', 'wp-pepita-sync' ) ),
			'secret_invalid'      => array( 'error', __( 'Érvénytelen kulcs azonosító.', 'wp-pepita-sync' ) ),
			'logs_cleared'        => array( 'success', __( 'A napló törlésre került.', 'wp-pepita-sync' ) ),
			'feeds_reset'         => array( 'success', __( 'A generált feedek törlésre kerültek.', 'wp-pepita-sync' ) ),
			'reset_locked'        => array( 'error', __( 'A feedek nem törölhetők, amíg generálás fut.', 'wp-pepita-sync' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type    = $messages[ $notice ][0];
		$message = $messages[ $notice ][1];

		$type = in_array( $type, array( 'success', 'info', 'warning', 'error' ), true ) ? $type : 'info';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * @param array<string, string> $args
	 */
	public function redirect( string $page, array $args = array() ): void {
		$args = array_merge( array( 'page' => $page ), $args );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function guard( bool $post = false ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Nincs jogosultsága az oldal megtekintéséhez.', 'wp-pepita-sync' ),
				esc_html__( 'Hozzáférés megtagadva', 'wp-pepita-sync' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function view( string $name, array $data = array() ): void {
		$file = WPS_PEPITA_DIR . 'includes/views/' . $name . '.php';

		if ( ! is_readable( $file ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'A nézet nem található.', 'wp-pepita-sync' ) . '</p></div>';

			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data, EXTR_SKIP );

		require $file;
	}

	/* ---------------------------------------------------------------------
	 * Small formatting helpers shared by the views
	 * ------------------------------------------------------------------ */

	public static function date( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		return (string) wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	public static function ago( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		return sprintf(
			/* translators: %s: human readable time difference */
			__( '%s ezelőtt', 'wp-pepita-sync' ),
			human_time_diff( $timestamp, time() )
		);
	}

	public static function bytes( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return '—';
		}

		return size_format( $bytes, 2 );
	}

	public static function status_badge( bool $ok, string $ok_label, string $bad_label ): string {
		return sprintf(
			'<span class="wps-badge %1$s">%2$s</span>',
			$ok ? 'wps-badge--ok' : 'wps-badge--bad',
			esc_html( $ok ? $ok_label : $bad_label )
		);
	}

	/**
	 * Nonce-protected admin-post URL.
	 *
	 * @param array<string, string> $args
	 */
	public static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ),
			$action
		);
	}

	/**
	 * Masked secret display.
	 */
	public static function mask( string $secret ): string {
		if ( '' === $secret ) {
			return '—';
		}

		$length = strlen( $secret );

		if ( $length <= 8 ) {
			return str_repeat( '•', $length );
		}

		return substr( $secret, 0, 4 ) . str_repeat( '•', max( 4, $length - 8 ) ) . substr( $secret, -4 );
	}
}
