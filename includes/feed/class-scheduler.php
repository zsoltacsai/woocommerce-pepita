<?php
/**
 * Scheduling: Action Scheduler when available, WP-Cron fallback.
 *
 * Recurring, debounced queue and continuation jobs use separate hooks so a
 * schedule change can never remove a queued generation by accident.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Feed;

use WPS\Pepita\Logger;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const GROUP = 'wps-pepita';

	public const HOOK_FULL  = 'wps_pepita_generate_full';
	public const HOOK_STOCK = 'wps_pepita_generate_stock';
	public const HOOK_PURGE = 'wps_pepita_purge_logs';

	public const HOOK_QUEUE_FULL     = 'wps_pepita_queue_full';
	public const HOOK_QUEUE_STOCK    = 'wps_pepita_queue_stock';
	public const HOOK_CONTINUE_FULL  = 'wps_pepita_continue_full';
	public const HOOK_CONTINUE_STOCK = 'wps_pepita_continue_stock';

	private const DIRTY_FULL  = 'wps_pepita_dirty_full';
	private const DIRTY_STOCK = 'wps_pepita_dirty_stock';
	private const SIGNATURE   = 'wps_pepita_schedule_signature';

	public function register(): void {
		add_action( self::HOOK_FULL, array( $this, 'run_full' ) );
		add_action( self::HOOK_STOCK, array( $this, 'run_stock' ) );
		add_action( self::HOOK_QUEUE_FULL, array( $this, 'run_full' ) );
		add_action( self::HOOK_QUEUE_STOCK, array( $this, 'run_stock' ) );
		add_action( self::HOOK_CONTINUE_FULL, array( $this, 'run_full' ) );
		add_action( self::HOOK_CONTINUE_STOCK, array( $this, 'run_stock' ) );
		add_action( self::HOOK_PURGE, array( $this, 'run_purge' ) );

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'ensure_schedules' ), 30 );
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function cron_schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules['wps_pepita_full_interval'] = array(
			'interval' => Settings::full_feed_interval(),
			'display'  => 'WP Pepita Sync – teljes feed gyakoriság',
		);

		$schedules['wps_pepita_stock_interval'] = array(
			'interval' => Settings::stock_feed_interval(),
			'display'  => 'WP Pepita Sync – készletfeed gyakoriság',
		);

		return $schedules;
	}

	public static function schedule_all(): void {
		self::schedule_recurring( self::HOOK_FULL, Settings::full_feed_interval(), 'wps_pepita_full_interval' );
		self::schedule_recurring( self::HOOK_STOCK, Settings::stock_feed_interval(), 'wps_pepita_stock_interval' );
		self::schedule_recurring( self::HOOK_PURGE, DAY_IN_SECONDS, 'daily' );

		if ( self::schedule_signature() !== (string) get_option( self::SIGNATURE, '' ) ) {
			update_option( self::SIGNATURE, self::schedule_signature(), false );
		}
	}

	public static function unschedule_all(): void {
		foreach ( self::all_hooks() as $hook ) {
			if ( self::has_action_scheduler() ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}

			wp_clear_scheduled_hook( $hook );
		}
	}

	public function ensure_schedules(): void {
		if ( get_transient( 'wps_pepita_schedule_checked' ) ) {
			return;
		}

		set_transient( 'wps_pepita_schedule_checked', 1, HOUR_IN_SECONDS );

		if ( self::schedule_signature() !== (string) get_option( self::SIGNATURE, '' ) ) {
			self::reschedule_recurring();
		}

		self::schedule_all();
	}

	private static function reschedule_recurring(): void {
		foreach ( array( self::HOOK_FULL, self::HOOK_STOCK, self::HOOK_PURGE ) as $hook ) {
			if ( self::has_action_scheduler() ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}

			wp_clear_scheduled_hook( $hook );
		}
	}

	private static function schedule_recurring( string $hook, int $interval, string $schedule_name ): void {
		if ( self::has_action_scheduler() ) {
			if ( false === as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 120, max( 60, $interval ), $hook, array(), self::GROUP );
			}

			return;
		}

		if ( false === wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 120, $schedule_name, $hook );
		}
	}

	/**
	 * Debounced regeneration request after a product/category change.
	 */
	public static function queue( string $type ): void {
		$type = Generator::TYPE_STOCK === $type ? Generator::TYPE_STOCK : Generator::TYPE_FULL;

		self::mark_dirty( $type );

		$hook  = Generator::TYPE_FULL === $type ? self::HOOK_QUEUE_FULL : self::HOOK_QUEUE_STOCK;
		$delay = max( 30, (int) apply_filters( 'wps_pepita_debounce_seconds', 120 ) );
		$when  = time() + $delay;

		if ( self::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_single_action( $when, $hook, array(), self::GROUP, true );
			}

			return;
		}

		if ( false === wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( $when, $hook );
		}
	}

	public static function schedule_continuation( string $type ): void {
		$type = Generator::TYPE_STOCK === $type ? Generator::TYPE_STOCK : Generator::TYPE_FULL;
		$hook = Generator::TYPE_FULL === $type ? self::HOOK_CONTINUE_FULL : self::HOOK_CONTINUE_STOCK;

		if ( self::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_single_action( time() + 5, $hook, array(), self::GROUP, true );
			}

			return;
		}

		if ( false === wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + 5, $hook );
		}
	}

	public static function mark_dirty( string $type ): void {
		$option = Generator::TYPE_FULL === $type ? self::DIRTY_FULL : self::DIRTY_STOCK;

		update_option( $option, time(), false );
	}

	public static function clear_dirty( string $type ): void {
		delete_option( Generator::TYPE_FULL === $type ? self::DIRTY_FULL : self::DIRTY_STOCK );
	}

	public static function is_dirty( string $type ): bool {
		return false !== get_option( Generator::TYPE_FULL === $type ? self::DIRTY_FULL : self::DIRTY_STOCK, false );
	}

	public static function dirty_since( string $type ): int {
		return (int) get_option( Generator::TYPE_FULL === $type ? self::DIRTY_FULL : self::DIRTY_STOCK, 0 );
	}

	public function run_full(): void {
		$this->run( Generator::TYPE_FULL );
	}

	public function run_stock(): void {
		$this->run( Generator::TYPE_STOCK );
	}

	private function run( string $type ): void {
		if ( Lock::is_locked( $type ) ) {
			Logger::debug( 'feed_schedule', 'A generálás már fut, az ütemezett kérés kihagyva.', array( 'type' => $type ) );

			return;
		}

		$result = ( new Generator() )->run( $type );

		if ( 'error' === ( $result['status'] ?? '' ) ) {
			Logger::warning(
				'feed_schedule',
				'Ütemezett generálás hiba: ' . (string) ( $result['message'] ?? '' ),
				array( 'type' => $type )
			);
		}
	}

	public function run_purge(): void {
		$logs = Logger::purge_old();

		$keep_payloads = (bool) Settings::get( 'order_api_keep_payloads', false );
		$payloads      = Order_Repository::purge_payloads(
			(int) Settings::get( 'log_retention_days', 30 ),
			! $keep_payloads
		);

		$labels = Order_Repository::purge_labels(
			(int) Settings::get( 'order_api_label_retention_days', 30 )
		);

		Logger::debug(
			'log_maintenance',
			'Napló karbantartás lefutott.',
			array(
				'logs_deleted'    => $logs,
				'payloads_cleared' => $payloads,
				'labels_deleted'  => $labels,
			)
		);
	}

	private static function has_action_scheduler(): bool {
		$available = function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' );

		/**
		 * Allows a site to force the WP-Cron fallback even when Action
		 * Scheduler is available (for example, to avoid a second queue).
		 *
		 * @param bool $available Whether Action Scheduler can be used.
		 */
		return (bool) apply_filters( 'wps_pepita_use_action_scheduler', $available );
	}

	/**
	 * @return string[]
	 */
	private static function all_hooks(): array {
		return array(
			self::HOOK_FULL,
			self::HOOK_STOCK,
			self::HOOK_PURGE,
			self::HOOK_QUEUE_FULL,
			self::HOOK_QUEUE_STOCK,
			self::HOOK_CONTINUE_FULL,
			self::HOOK_CONTINUE_STOCK,
		);
	}

	private static function schedule_signature(): string {
		return md5(
			implode(
				'|',
				array(
					(string) Settings::full_feed_interval(),
					(string) Settings::stock_feed_interval(),
					self::has_action_scheduler() ? 'as' : 'cron',
				)
			)
		);
	}
}
