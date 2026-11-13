<?php
/**
 * Exercise the plugin scheduler through Action Scheduler and WP-Cron.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

WPS_IT::line( 'Scheduler execution' );

WPS_IT::test(
	'Action Scheduler runs a queued full-feed generation',
	static function (): void {
		WPS_IT::true( function_exists( 'as_schedule_single_action' ), 'WooCommerce Action Scheduler is unavailable.' );
		$previous_batch_size = Settings::get( 'batch_size', 50 );
		$previous_batches    = Settings::get( 'batches_per_run', 5 );
		Settings::update( array( 'batch_size' => 100, 'batches_per_run' => 100 ) );
		update_option( 'wps_it_disable_as', 'no', false );
		Scheduler::unschedule_all();
		Scheduler::schedule_all();

		$recurring = as_next_scheduled_action( Scheduler::HOOK_FULL, array(), Scheduler::GROUP );
		WPS_IT::true( false !== $recurring, 'The plugin did not schedule its recurring full feed action.' );

		Scheduler::mark_dirty( 'full' );
		$action_id = as_schedule_single_action( time() - 1, Scheduler::HOOK_QUEUE_FULL, array(), Scheduler::GROUP, false );
		WPS_IT::greater( 0, (int) $action_id, 'The test generation action was not queued.' );

		// A fresh runner is required in a long-lived CLI process: the shared
		// singleton measures its time budget from construction and would stop
		// after the first action of an earlier-served queue.
		( new \ActionScheduler_QueueRunner() )->run();

		WPS_IT::true( Storage::exists( 'full' ), 'Action Scheduler did not publish the full feed.' );
		WPS_IT::false( Scheduler::is_dirty( 'full' ), 'Action Scheduler left the full feed marked dirty.' );
		Scheduler::unschedule_all();
		Settings::update( array( 'batch_size' => $previous_batch_size, 'batches_per_run' => $previous_batches ) );
	}
);

WPS_IT::test(
	'WP-Cron fallback executes a full-feed generation when Action Scheduler is disabled',
	static function (): void {
		$previous_batch_size = Settings::get( 'batch_size', 50 );
		$previous_batches    = Settings::get( 'batches_per_run', 5 );
		Settings::update( array( 'batch_size' => 100, 'batches_per_run' => 100 ) );
		update_option( 'wps_it_disable_as', 'yes', false );
		Scheduler::unschedule_all();
		Scheduler::schedule_all();

		$recurring = wp_next_scheduled( Scheduler::HOOK_FULL );
		WPS_IT::true( false !== $recurring, 'The plugin did not schedule its recurring WP-Cron event.' );

		Scheduler::mark_dirty( 'full' );
		wp_schedule_single_event( time() - 1, Scheduler::HOOK_QUEUE_FULL );
		// Keep this request focused on the plugin's fallback event: Action
		// Scheduler's own per-minute WP-Cron event would otherwise run first.
		wp_clear_scheduled_hook( 'action_scheduler_run_queue' );
		delete_transient( 'doing_cron' );
		// Call wp-cron.php as an external scheduler: it takes the lock itself.
		// Sending a doing_wp_cron value after deleting the transient would fail
		// the lock check and return before running any event.
		$cron = WPS_IT::http( 'GET', WPS_IT::base_url() . '/wp-cron.php' );
		WPS_IT::true( in_array( $cron['code'], array( 200, 204 ), true ), 'wp-cron.php did not complete the cron request.' );

		// The generation ran in the web process; drop this process's cached
		// option values before asserting on the shared database state.
		wp_cache_flush();

		WPS_IT::true( Storage::exists( 'full' ), 'WP-Cron did not publish the full feed.' );
		WPS_IT::false( Scheduler::is_dirty( 'full' ), 'WP-Cron left the full feed marked dirty.' );

		update_option( 'wps_it_disable_as', 'no', false );
		Scheduler::unschedule_all();
		Scheduler::schedule_all();
		Settings::update( array( 'batch_size' => $previous_batch_size, 'batches_per_run' => $previous_batches ) );
	}
);
