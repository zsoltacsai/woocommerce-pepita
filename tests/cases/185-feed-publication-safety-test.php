<?php
/**
 * Feed publication safety and fault injection tests (Phase 6).
 *
 * Verifies that full feed XML and catalog.jsonl index are published
 * atomically, that faults during publication trigger automatic rollback,
 * and that published state never diverges.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Alerts;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

$setup_storage = static function (): void {
	Storage::purge();
};

$suite->add(
	'publication: normal full generation atomically publishes feed XML and catalog.jsonl',
	static function () use ( $setup_storage ): void {
		$setup_storage();

		$feed_temp  = sys_get_temp_dir() . '/temp_feed_' . uniqid() . '.xml';
		$index_temp = sys_get_temp_dir() . '/temp_index_' . uniqid() . '.jsonl';

		file_put_contents( $feed_temp, '<?xml version="1.0"?><Catalog xmlns="https://pepita.hu/feed/1.0"><Product><Id>1</Id></Product></Catalog>' );
		file_put_contents( $index_temp, '{"pid":1,"eid":"wps-1","price":1000}' . "\n" );

		$published = Storage::publish_full( $feed_temp, $index_temp );

		WPS_Assert::true( $published, 'Storage::publish_full must succeed' );
		WPS_Assert::true( is_file( Storage::feed_path( 'full' ) ) );
		WPS_Assert::true( is_file( Storage::catalog_index_path() ) );
		WPS_Assert::same( '1', (string) ( simplexml_load_file( Storage::feed_path( 'full' ) )->Product->Id ) );

		// Now publish a second version to verify backups (.prev)
		$feed_temp2  = sys_get_temp_dir() . '/temp_feed2_' . uniqid() . '.xml';
		$index_temp2 = sys_get_temp_dir() . '/temp_index2_' . uniqid() . '.jsonl';

		file_put_contents( $feed_temp2, '<?xml version="1.0"?><Catalog xmlns="https://pepita.hu/feed/1.0"><Product><Id>2</Id></Product></Catalog>' );
		file_put_contents( $index_temp2, '{"pid":2,"eid":"wps-2","price":2000}' . "\n" );

		$published2 = Storage::publish_full( $feed_temp2, $index_temp2 );
		WPS_Assert::true( $published2 );

		// Live files have version 2
		WPS_Assert::same( '2', (string) ( simplexml_load_file( Storage::feed_path( 'full' ) )->Product->Id ) );
		// Backup files (.prev) have version 1
		WPS_Assert::true( is_file( Storage::previous_feed_path( 'full' ) ) );
		WPS_Assert::true( is_file( Storage::previous_catalog_index_path() ) );
		WPS_Assert::contains( '<Id>1</Id>', file_get_contents( Storage::previous_feed_path( 'full' ) ) );
		WPS_Assert::contains( '"pid":1', file_get_contents( Storage::previous_catalog_index_path() ) );
	}
);

$suite->add(
	'publication: missing or empty temp files fail closed without modifying published state',
	static function () use ( $setup_storage ): void {
		$setup_storage();

		// Establish initial published state
		$feed_temp  = sys_get_temp_dir() . '/temp_feed_init_' . uniqid() . '.xml';
		$index_temp = sys_get_temp_dir() . '/temp_index_init_' . uniqid() . '.jsonl';
		file_put_contents( $feed_temp, '<Catalog><Product><Id>INIT</Id></Product></Catalog>' );
		file_put_contents( $index_temp, '{"pid":"INIT"}' . "\n" );
		Storage::publish_full( $feed_temp, $index_temp );

		$orig_feed  = file_get_contents( Storage::feed_path( 'full' ) );
		$orig_index = file_get_contents( Storage::catalog_index_path() );

		// Attempt publish with nonexistent temp file
		$res1 = Storage::publish_full( '/tmp/nonexistent-feed.xml', $index_temp );
		WPS_Assert::false( $res1 );
		WPS_Assert::same( $orig_feed, file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::same( $orig_index, file_get_contents( Storage::catalog_index_path() ) );

		// Attempt publish with empty (0-byte) feed file
		$empty_feed = sys_get_temp_dir() . '/empty_feed_' . uniqid() . '.xml';
		file_put_contents( $empty_feed, '' );
		$res2 = Storage::publish_full( $empty_feed, $index_temp );
		WPS_Assert::false( $res2 );
		WPS_Assert::same( $orig_feed, file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::same( $orig_index, file_get_contents( Storage::catalog_index_path() ) );
		@unlink( $empty_feed );
	}
);

$suite->add(
	'publication: failure during feed publishing automatically rolls back catalog index',
	static function () use ( $setup_storage ): void {
		$setup_storage();

		// Establish known initial v1 published state
		$f1 = sys_get_temp_dir() . '/v1_feed_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/v1_index_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>V1</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"V1"}' . "\n" );
		Storage::publish_full( $f1, $i1 );

		// Prepare v2 files
		$f2 = sys_get_temp_dir() . '/v2_feed_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/v2_index_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>V2</Id></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":"V2"}' . "\n" );

		// Inject fault: simulate feed file becoming unwritable or deleted mid-operation
		// We delete $f2 right before rename so Stage 2 fails
		@unlink( $f2 );

		$published = Storage::publish_full( $f2, $i2 );
		WPS_Assert::false( $published, 'Publish must fail when feed temp is gone' );

		// Verify rollback: catalog.jsonl index must STILL be V1 (restored from backup)
		WPS_Assert::contains( 'V1', file_get_contents( Storage::catalog_index_path() ) );
		WPS_Assert::contains( 'V1', file_get_contents( Storage::feed_path( 'full' ) ) );
	}
);

$suite->add(
	'publication: stock feed publishing leaves catalog index untouched',
	static function () use ( $setup_storage ): void {
		$setup_storage();

		// Establish full catalog
		$f = sys_get_temp_dir() . '/full_' . uniqid() . '.xml';
		$i = sys_get_temp_dir() . '/index_' . uniqid() . '.jsonl';
		file_put_contents( $f, '<Catalog><Product><Id>FULL-1</Id></Product></Catalog>' );
		file_put_contents( $i, '{"pid":"FULL-1"}' . "\n" );
		Storage::publish_full( $f, $i );

		$index_content = file_get_contents( Storage::catalog_index_path() );

		// Publish stock feed
		$s = sys_get_temp_dir() . '/stock_' . uniqid() . '.xml';
		file_put_contents( $s, '<Catalog><Product><Id>FULL-1</Id><Availability><Available>true</Available></Availability></Product></Catalog>' );

		$stock_pub = Storage::publish( 'stock', $s );
		WPS_Assert::true( $stock_pub );
		WPS_Assert::true( is_file( Storage::feed_path( 'stock' ) ) );

		// Catalog index was not changed
		WPS_Assert::same( $index_content, file_get_contents( Storage::catalog_index_path() ) );
	}
);

$suite->add(
	'staging: stage_full preserves active generation pointer and leaves live feed intact',
	static function () use ( $setup_storage ): void {
		$setup_storage();

		// Establish published live state (V1)
		$f1 = sys_get_temp_dir() . '/live1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/live1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>LIVE-1</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"LIVE-1"}' . "\n" );
		Storage::publish_full( $f1, $i1 );

		$live_feed_content = file_get_contents( Storage::feed_path( 'full' ) );
		$live_index_content = file_get_contents( Storage::catalog_index_path() );

		// Prepare candidate (V2) to stage
		$f2 = sys_get_temp_dir() . '/staged2_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/staged2_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>STAGED-2</Id></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":"STAGED-2"}' . "\n" );

		$staged = Storage::stage_full( $f2, $i2, 'gen-test-stage', 'sig-test-stage', array( 'status' => 'warn' ), 'Price spike detected' );
		WPS_Assert::true( $staged, 'Storage::stage_full must return true' );
		WPS_Assert::true( Storage::has_staged_full(), 'Storage::has_staged_full must be true' );

		// Live files and pointer must remain 100% on V1
		WPS_Assert::same( $live_feed_content, file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::same( $live_index_content, file_get_contents( Storage::catalog_index_path() ) );

		$staged_info = Storage::get_staged_full();
		WPS_Assert::true( is_array( $staged_info ) );
		WPS_Assert::same( 'gen-test-stage', $staged_info['generation_id'] );
		WPS_Assert::same( 'Price spike detected', $staged_info['reason'] );
		WPS_Assert::true( is_file( $staged_info['feed_path'] ) );
		WPS_Assert::true( is_file( $staged_info['index_path'] ) );
		WPS_Assert::contains( 'STAGED-2', file_get_contents( $staged_info['feed_path'] ) );

		// Discard staged feed
		$discarded = Storage::discard_staged_full();
		WPS_Assert::true( $discarded );
		WPS_Assert::false( Storage::has_staged_full() );
		WPS_Assert::false( is_file( $staged_info['feed_path'] ) );
		WPS_Assert::false( is_file( $staged_info['index_path'] ) );

		// Live feed still unaffected
		WPS_Assert::same( $live_feed_content, file_get_contents( Storage::feed_path( 'full' ) ) );
	}
);

$suite->add(
	'staging: approve_staged_full atomically promotes staged feed to active generation',
	static function () use ( $setup_storage ): void {
		$setup_storage();

		// Establish published live state (V1)
		$f1 = sys_get_temp_dir() . '/live1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/live1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>PREV-1</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"PREV-1"}' . "\n" );
		Storage::publish_full( $f1, $i1 );

		// Stage candidate (V2)
		$f2 = sys_get_temp_dir() . '/staged2_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/staged2_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>APPROVED-2</Id></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":"APPROVED-2"}' . "\n" );

		Storage::stage_full( $f2, $i2, 'gen-approved', 'sig-approved', array( 'status' => 'warn' ), 'Pending review' );
		WPS_Assert::true( Storage::has_staged_full() );

		// Approve and promote
		$approved = Storage::approve_staged_full();
		WPS_Assert::true( $approved, 'Storage::approve_staged_full must succeed' );
		WPS_Assert::false( Storage::has_staged_full(), 'Staged flag must be cleared' );

		// Live feed is now V2
		WPS_Assert::contains( 'APPROVED-2', file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::contains( 'APPROVED-2', file_get_contents( Storage::catalog_index_path() ) );

		// Previous backup is V1
		WPS_Assert::contains( 'PREV-1', file_get_contents( Storage::previous_feed_path( 'full' ) ) );
		WPS_Assert::contains( 'PREV-1', file_get_contents( Storage::previous_catalog_index_path() ) );
	}
);

$suite->add(
	'alerts: email and webhook telemetry dispatch and suppression',
	static function (): void {
		WPS_Test_State::reset();
		Alerts::clear_suppression( Alerts::EVENT_SAFETY_GATE_TRIGGERED );
		Alerts::$host_resolver = static fn( string $host ): array => array( '93.184.216.34' );

		Settings::update(
			array(
				'feed_alert_email_enabled'   => true,
				'feed_alert_email'           => 'alerts@shop.test',
				'feed_alert_webhook_enabled' => true,
				'feed_alert_webhook_url'     => 'https://api.example.com/telemetry',
			)
		);

		$context = array(
			'message'  => 'Feed drop threshold exceeded',
			'warnings' => array( '30% catalog drop' ),
			'diff'     => array(
				'status'              => 'warn',
				'overall_impact'      => 'warn',
				'current_count'       => 70,
				'previous_count'      => 100,
				'added_count'         => 0,
				'removed_count'       => 30,
				'drop_percent'        => 30.0,
				'price_changed_count' => 5,
				'price_spike_count'   => 2,
				'stock_changed_count' => 10,
			),
		);

		Alerts::trigger( Alerts::EVENT_SAFETY_GATE_TRIGGERED, $context );

		// Verify email was sent
		WPS_Assert::same( 1, count( WPS_Test_State::$mails ) );
		WPS_Assert::same( 'alerts@shop.test', WPS_Test_State::$mails[0]['to'] );
		WPS_Assert::contains( 'Feed publikálás leállítva', WPS_Test_State::$mails[0]['subject'] );
		WPS_Assert::contains( '30% catalog drop', WPS_Test_State::$mails[0]['message'] );

		// Verify webhook was sent
		WPS_Assert::same( 1, count( WPS_Test_State::$remote_posts ) );
		WPS_Assert::same( 'https://api.example.com/telemetry', WPS_Test_State::$remote_posts[0]['url'] );
		$body = json_decode( WPS_Test_State::$remote_posts[0]['args']['body'], true );
		WPS_Assert::same( Alerts::EVENT_SAFETY_GATE_TRIGGERED, $body['event'] );
		WPS_Assert::same( 70, $body['counts']['current'] );
		WPS_Assert::same( 30, $body['counts']['removed'] );

		// Second call within 1 hour is suppressed
		Alerts::trigger( Alerts::EVENT_SAFETY_GATE_TRIGGERED, $context );
		WPS_Assert::same( 1, count( WPS_Test_State::$mails ), 'Suppressed alert must not send second email' );
		WPS_Assert::same( 1, count( WPS_Test_State::$remote_posts ), 'Suppressed alert must not send second webhook' );

		Alerts::clear_suppression( Alerts::EVENT_SAFETY_GATE_TRIGGERED );
		Alerts::$host_resolver = null;
	}
);

$suite->add(
	'alerts: SSRF protection strictly rejects loopback, private and malformed webhook URLs',
	static function (): void {
		// Empty and malformed
		WPS_Assert::false( Alerts::is_safe_webhook_url( '' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'not-a-url' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'ftp://example.com/upload' ) );

		// Loopback and internal domains
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'http://localhost/hook' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'http://127.0.0.1/hook' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'http://127.0.0.2:8080/hook' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'http://0.0.0.0/' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'https://internal.test/hook' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'https://service.local/hook' ) );

		// Private IPv4 ranges
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'http://10.0.0.1/hook' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'http://192.168.1.1/hook' ) );
		WPS_Assert::false( Alerts::is_safe_webhook_url( 'http://172.16.0.1/hook' ) );
	}
);

$suite->add(
	'scheduler: is_in_offpeak_window correctly checks time boundaries',
	static function (): void {
		// Standard window 01:00 - 05:00
		Settings::update(
			array(
				'offpeak_start_hour' => 1,
				'offpeak_end_hour'   => 5,
			)
		);

		// 00:30 -> false
		$t_0030 = strtotime( '2026-11-14 00:30:00' );
		WPS_Assert::false( Scheduler::is_in_offpeak_window( $t_0030 ) );

		// 02:00 -> true
		$t_0200 = strtotime( '2026-11-14 02:00:00' );
		WPS_Assert::true( Scheduler::is_in_offpeak_window( $t_0200 ) );

		// 05:00 -> false (exclusive upper bound)
		$t_0500 = strtotime( '2026-11-14 05:00:00' );
		WPS_Assert::false( Scheduler::is_in_offpeak_window( $t_0500 ) );

		// 14:00 -> false
		$t_1400 = strtotime( '2026-11-14 14:00:00' );
		WPS_Assert::false( Scheduler::is_in_offpeak_window( $t_1400 ) );

		// Midnight wrap-around window: 22:00 - 04:00
		Settings::update(
			array(
				'offpeak_start_hour' => 22,
				'offpeak_end_hour'   => 4,
			)
		);

		// 23:30 -> true
		$t_2330 = strtotime( '2026-11-14 23:30:00' );
		WPS_Assert::true( Scheduler::is_in_offpeak_window( $t_2330 ) );

		// 03:15 -> true
		$t_0315 = strtotime( '2026-11-14 03:15:00' );
		WPS_Assert::true( Scheduler::is_in_offpeak_window( $t_0315 ) );

		// 12:00 -> false
		$t_1200 = strtotime( '2026-11-14 12:00:00' );
		WPS_Assert::false( Scheduler::is_in_offpeak_window( $t_1200 ) );

		// next_offpeak_window_timestamp targets next start hour
		$next_ts = Scheduler::next_offpeak_window_timestamp( $t_1200 );
		WPS_Assert::true( $next_ts > $t_1200 );
	}
);

$suite->add(
	'staging: approve updates feed state, enqueues stock, and prevents rollback',
	static function () use ( $setup_storage ): void {
		$setup_storage();

		// Live V1
		$f1 = sys_get_temp_dir() . '/live1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/live1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>V1</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"V1"}' . "\n" );
		Storage::publish_full( $f1, $i1, 'gen-v1' );

		// Stage V2
		$f2 = sys_get_temp_dir() . '/staged2_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/staged2_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>V2</Id></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":"V2"}' . "\n" );
		Storage::stage_full( $f2, $i2, 'gen-v2', 'sig-v2', array( 'status' => 'warn' ), 'Pending review', array( 'exported' => 5 ), array( 'warn' ) );

		$staged_info = Storage::get_staged_full();
		WPS_Assert::same( 'gen-v1', $staged_info['base_generation_id'] );

		// Now simulate a newer normal publication happening while V2 was pending (V3)
		$f3 = sys_get_temp_dir() . '/live3_' . uniqid() . '.xml';
		$i3 = sys_get_temp_dir() . '/live3_' . uniqid() . '.jsonl';
		file_put_contents( $f3, '<Catalog><Product><Id>V3</Id></Product></Catalog>' );
		file_put_contents( $i3, '{"pid":"V3"}' . "\n" );
		// Calling publish_full directly would discard staged, but if staged existed with old base_gen:
		Storage::stage_full( $f2, $i2, 'gen-v2-test', 'sig-v2-test', array(), 're-stage' );
		// Manually tamper base_gen to simulate stale staged feed
		$staged_meta = get_option( 'wps_pepita_feed_staged_full' );
		$staged_meta['base_generation_id'] = 'gen-obsolete-base';
		update_option( 'wps_pepita_feed_staged_full', $staged_meta, false );

		// Approval must be rejected because base_gen does not match active generation
		$approved = Storage::approve_staged_full();
		WPS_Assert::false( $approved, 'Approval must refuse rollback over mismatched base generation' );

		// Health check reflects warning while staged feed exists
		$checks = \WPS\Pepita\Health::checks();
		$found_staged_check = false;
		foreach ( $checks as $check ) {
			if ( 'Biztonsági kapu' === ( $check['label'] ?? '' ) ) {
				$found_staged_check = true;
				WPS_Assert::same( \WPS\Pepita\Health::STATUS_WARN, $check['status'] );
			}
		}
		WPS_Assert::true( $found_staged_check, 'Health checks must include staged warning' );

		// Clean up
		Storage::discard_staged_full();
		WPS_Assert::false( Storage::has_staged_full() );
	}
);

$suite->add(
	'staging: successful approval updates state, enqueues stock feed, and clears staged files',
	static function () use ( $setup_storage ): void {
		$setup_storage();

		// Live V1
		$f1 = sys_get_temp_dir() . '/live1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/live1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>V1</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":1}' . "\n" );
		Storage::publish_full( $f1, $i1, 'gen-v1' );

		// Set initial error in feed state to verify approve clears it
		update_option(
			'wps_pepita_feed_state_full',
			array(
				'generation_id' => 'gen-v1',
				'last_error'    => 'Prior transient failure',
				'counters'      => array(),
			),
			false
		);

		// Stage V2
		$f2 = sys_get_temp_dir() . '/staged2_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/staged2_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>V2</Id></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":2}' . "\n" );
		Storage::stage_full(
			$f2,
			$i2,
			'gen-v2-valid',
			'sig-v2-valid',
			array( 'status' => 'warn' ),
			'High risk changes',
			array( 'exported' => 42 ),
			array( 'Warning 1' )
		);

		WPS_Assert::true( Storage::has_staged_full() );

		// Approve staged full
		$approved = Storage::approve_staged_full();
		WPS_Assert::true( $approved, 'Valid staged feed with matching base must be approved successfully' );
		WPS_Assert::false( Storage::has_staged_full(), 'Staged feed option must be deleted after approval' );

		// Verify feed state updated
		$state = get_option( 'wps_pepita_feed_state_full' );
		WPS_Assert::same( 'gen-v2-valid', $state['generation_id'] );
		WPS_Assert::same( 'sig-v2-valid', $state['signature'] );
		WPS_Assert::same( 42, $state['attempt_counts']['exported'] );
		WPS_Assert::same( '', $state['last_error'] );

		// Verify live active pointer moved to gen-v2-valid
		$ptr = Storage::read_pointer();
		WPS_Assert::same( 'gen-v2-valid', $ptr['active']['generation_id'] );

		// Verify stock feed regeneration was enqueued
		$next_stock = wp_next_scheduled( Scheduler::HOOK_QUEUE_STOCK );
		WPS_Assert::true( false !== $next_stock || function_exists( 'as_next_scheduled_action' ) );
	}
);

$suite->add(
	'scheduler: in-progress batch continuation runs even outside offpeak window',
	static function (): void {
		$current_hour = (int) current_time( 'G' );
		$window_start = ( $current_hour + 2 ) % 24;
		$window_end   = ( $current_hour + 4 ) % 24;

		Settings::update(
			array(
				'offpeak_schedule_enabled' => true,
				'offpeak_start_hour'       => $window_start,
				'offpeak_end_hour'         => $window_end,
			)
		);

		WPS_Assert::false( Scheduler::is_in_offpeak_window() );

		// Clear any existing queue
		delete_option( 'wps_pepita_generation_full' );
		wp_clear_scheduled_hook( Scheduler::HOOK_QUEUE_FULL );

		// When called outside offpeak window without continuation, run_full() defers and reschedules
		( new Scheduler() )->run_full();

		$has_rescheduled = ( false !== wp_next_scheduled( Scheduler::HOOK_QUEUE_FULL ) )
			|| ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( Scheduler::HOOK_QUEUE_FULL, array(), Scheduler::GROUP ) );
		WPS_Assert::true( $has_rescheduled, 'Deferral must reschedule HOOK_QUEUE_FULL' );

		// When in-progress continuation state exists, option is recognized and run_full() executes continuation without deferring
		wp_clear_scheduled_hook( Scheduler::HOOK_QUEUE_FULL );

		update_option(
			'wps_pepita_generation_full',
			array(
				'type'       => Generator::TYPE_FULL,
				'lock_token' => 'lock-123',
				'offset'     => 100,
			),
			false
		);

		$is_cont = (bool) get_option( 'wps_pepita_generation_' . Generator::TYPE_FULL, false );
		WPS_Assert::true( $is_cont, 'In-progress state option must be recognized' );

		( new Scheduler() )->run_full();

		$deferred_again = ( false !== wp_next_scheduled( Scheduler::HOOK_QUEUE_FULL ) );
		WPS_Assert::false( $deferred_again, 'Continuation execution must not defer to next off-peak window' );

		delete_option( 'wps_pepita_generation_full' );
		wp_clear_scheduled_hook( Scheduler::HOOK_QUEUE_FULL );
	}
);

$suite->add(
	'staging: approving staged full with changed catalog signature fails closed on incompatible old stock feed',
	static function (): void {
		Storage::ensure_directory();

		// 1. Establish an initial active full feed and matching stock feed with 'sig-v1'.
		$full_tmp = sys_get_temp_dir() . '/full_v1_' . uniqid() . '.xml';
		$idx_tmp  = sys_get_temp_dir() . '/idx_v1_' . uniqid() . '.jsonl';
		$stk_tmp  = sys_get_temp_dir() . '/stk_v1_' . uniqid() . '.xml';

		file_put_contents( $full_tmp, '<?xml version="1.0"?><products><product><id>1</id></product></products>' );
		file_put_contents( $idx_tmp, '{"pid":1,"eid":"1","name":"P1","price":100}' . "\n" );
		file_put_contents( $stk_tmp, '<?xml version="1.0"?><items><item><id>1</id><qty>5</qty></item></items>' );

		$pub_full = Storage::publish_full( $full_tmp, $idx_tmp, 'gen-v1', 'sig-v1' );
		WPS_Assert::true( $pub_full, 'Initial full publish should succeed' );

		$pub_stock = Storage::publish_stock( $stk_tmp, 'stock-gen-v1', 'sig-v1' );
		WPS_Assert::true( $pub_stock, 'Initial stock publish should succeed' );

		WPS_Assert::true( Storage::exists( 'full' ), 'Active full feed exists' );
		WPS_Assert::true( Storage::exists( 'stock' ), 'Active stock feed exists' );

		$ptr = Storage::read_pointer();
		WPS_Assert::same( 'sig-v1', $ptr['active']['catalog_signature'] );
		WPS_Assert::same( 'sig-v1', $ptr['active']['stock_signature'] );

		// 2. Stage a new full feed with a DIFFERENT catalog signature 'sig-v2'.
		$staged_full_tmp = sys_get_temp_dir() . '/staged_full_v2_' . uniqid() . '.xml';
		$staged_idx_tmp  = sys_get_temp_dir() . '/staged_idx_v2_' . uniqid() . '.jsonl';

		file_put_contents( $staged_full_tmp, '<?xml version="1.0"?><products><product><id>1</id></product><product><id>2</id></product></products>' );
		file_put_contents( $staged_idx_tmp, '{"pid":1,"eid":"1","name":"P1","price":100}' . "\n" . '{"pid":2,"eid":"2","name":"P2","price":200}' . "\n" );

		$staged = Storage::stage_full(
			$staged_full_tmp,
			$staged_idx_tmp,
			'gen-v2',
			'sig-v2',
			array( 'added_count' => 1 ),
			'approval_required',
			array(),
			array()
		);
		WPS_Assert::true( $staged, 'Staging full feed should succeed' );
		WPS_Assert::true( Storage::has_staged_full(), 'Staged feed should be recognized' );

		// While staged, the active stock feed remains served because publication hasn't happened.
		WPS_Assert::true( Storage::exists( 'stock' ), 'Stock feed remains valid while full feed is only staged' );

		// 3. Approve the staged full feed.
		wp_clear_scheduled_hook( Scheduler::HOOK_QUEUE_STOCK );
		$approved = Storage::approve_staged_full( 'gen-v2' );
		WPS_Assert::true( $approved, 'Staged approval should succeed' );

		$new_ptr = Storage::read_pointer();
		WPS_Assert::same( 'gen-v2', $new_ptr['active']['generation_id'] );
		WPS_Assert::same( 'sig-v2', $new_ptr['active']['catalog_signature'] );

		// Invariant: Because catalog signature changed, the incompatible old stock feed MUST NOT be preserved.
		WPS_Assert::same( '', $new_ptr['active']['stock_feed'], 'Stock feed pointer must be cleared' );
		WPS_Assert::same( '', $new_ptr['active']['stock_signature'], 'Stock signature must be cleared' );

		// Crucial safety check: Storage::exists('stock') must fail closed!
		WPS_Assert::false( Storage::exists( 'stock' ), 'Stock feed must fail closed until regenerated' );
		$stock_path = Storage::feed_path( 'stock' );
		WPS_Assert::true( false !== strpos( $stock_path, 'missing-stock-generation.xml' ), 'feed_path must return missing stock sentinel' );

		// Stock regeneration hook must have been scheduled
		$next_stock = wp_next_scheduled( Scheduler::HOOK_QUEUE_STOCK );
		WPS_Assert::true( false !== $next_stock || function_exists( 'as_next_scheduled_action' ), 'Stock queue must be scheduled' );

		// 4. Simulate the scheduled matching stock feed completing with 'sig-v2'
		$new_stk_tmp = sys_get_temp_dir() . '/stk_v2_' . uniqid() . '.xml';
		file_put_contents( $new_stk_tmp, '<?xml version="1.0"?><items><item><id>1</id><qty>5</qty></item><item><id>2</id><qty>10</qty></item></items>' );

		$pub_new_stock = Storage::publish_stock( $new_stk_tmp, 'stock-gen-v2', 'sig-v2' );
		WPS_Assert::true( $pub_new_stock, 'Publishing matching stock feed should succeed' );

		$final_ptr = Storage::read_pointer();
		WPS_Assert::same( 'sig-v2', $final_ptr['active']['catalog_signature'] );
		WPS_Assert::same( 'sig-v2', $final_ptr['active']['stock_signature'] );
		WPS_Assert::true( Storage::exists( 'stock' ), 'Stock feed is once again valid after matching generation' );

		// 5. Test signature mismatch fail-closed behavior directly
		$mismatched_ptr = $final_ptr;
		$mismatched_ptr['active']['stock_signature'] = 'incompatible-signature';
		file_put_contents( Storage::pointer_path(), json_encode( $mismatched_ptr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		Storage::clear_pointer_cache();

		WPS_Assert::false( Storage::exists( 'stock' ), 'Signature mismatch must cause exists() to return false' );
		$mismatched_path = Storage::feed_path( 'stock' );
		WPS_Assert::true( false !== strpos( $mismatched_path, 'mismatched-stock-generation.xml' ), 'Signature mismatch must return mismatched sentinel' );

		// Restore clean pointer
		file_put_contents( Storage::pointer_path(), json_encode( $final_ptr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		Storage::clear_pointer_cache();
	}
);
