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

		$action_id = as_schedule_single_action( time() - 1, Scheduler::HOOK_QUEUE_FULL, array(), Scheduler::GROUP, false );
		WPS_IT::greater( 0, (int) $action_id, 'The test generation action was not queued.' );

		\ActionScheduler_QueueRunner::instance()->run();
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
		wp_cron();

		WPS_IT::true( Storage::exists( 'full' ), 'WP-Cron did not publish the full feed.' );
		WPS_IT::false( Scheduler::is_dirty( 'full' ), 'WP-Cron left the full feed marked dirty.' );

		update_option( 'wps_it_disable_as', 'no', false );
		Scheduler::unschedule_all();
		Scheduler::schedule_all();
		Settings::update( array( 'batch_size' => $previous_batch_size, 'batches_per_run' => $previous_batches ) );
	}
);
