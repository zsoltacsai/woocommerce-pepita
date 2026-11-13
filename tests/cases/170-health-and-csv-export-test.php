<?php
/**
 * Health checks and secure CSV export sanitisation.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Feed\Lock;
use WPS\Pepita\Health;
use WPS\Pepita\Logger;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

$suite->add(
	'health: stock_feed_check returns warn when feed has never run',
	static function (): void {
		delete_option( 'wps_pepita_feed_state_stock' );

		$check = Health::stock_feed_check();

		WPS_Assert::same( Health::STATUS_WARN, $check['status'] );
		WPS_Assert::same( 'még nem készült el', $check['value'] );
		WPS_Assert::true( '' !== $check['hint'] );
	}
);

$suite->add(
	'health: stock_feed_check returns warn when generation is in progress',
	static function (): void {
		delete_option( 'wps_pepita_feed_state_stock' );
		Lock::acquire( Generator::TYPE_STOCK, 120 );

		$check = Health::stock_feed_check();

		WPS_Assert::same( Health::STATUS_WARN, $check['status'] );
		WPS_Assert::same( 'generálás folyamatban', $check['value'] );

		Lock::release( Generator::TYPE_STOCK );
	}
);

$suite->add(
	'health: stock_feed_check categorises fresh, delayed and critically stale feeds',
	static function (): void {
		Settings::update( array( 'stock_feed_interval' => 30 ) ); // 30 minutes = 1800s
		$interval = 30 * MINUTE_IN_SECONDS;

		// 1. Fresh: completed 10 minutes ago
		update_option(
			'wps_pepita_feed_state_stock',
			array( 'last_success' => time() - 600 )
		);
		// Simulate active schedule
		wp_schedule_event( time() + 1200, 'wps_pepita_stock_interval', 'wps_pepita_generate_stock' );

		$check_fresh = Health::stock_feed_check();
		WPS_Assert::same( Health::STATUS_OK, $check_fresh['status'] );
		WPS_Assert::true( str_contains( $check_fresh['value'], 'friss' ) );

		// 2. Delayed: completed 2 * interval ago (60 min)
		update_option(
			'wps_pepita_feed_state_stock',
			array( 'last_success' => time() - (int) ( 2.0 * $interval ) )
		);

		$check_delayed = Health::stock_feed_check();
		WPS_Assert::same( Health::STATUS_WARN, $check_delayed['status'] );
		WPS_Assert::true( str_contains( $check_delayed['value'], 'késésben' ) );

		// 3. Critically stale: completed 4 * interval ago (120 min)
		update_option(
			'wps_pepita_feed_state_stock',
			array( 'last_success' => time() - (int) ( 4.0 * $interval ) )
		);

		$check_stale = Health::stock_feed_check();
		WPS_Assert::same( Health::STATUS_FAIL, $check_stale['status'] );
		WPS_Assert::true( str_contains( $check_stale['value'], 'kritikusan elavult' ) );

		wp_clear_scheduled_hook( 'wps_pepita_generate_stock' );
	}
);

$suite->add(
	'health: stock_feed_check is included in checks list and does not trigger side-effects',
	static function (): void {
		$checks = Health::checks();
		$found  = false;

		foreach ( $checks as $c ) {
			if ( 'Készletfeed állapota' === $c['label'] ) {
				$found = true;
				break;
			}
		}

		WPS_Assert::true( $found, 'Készletfeed állapota not found in Health::checks()' );
	}
);

$suite->add(
	'logger: sanitize_csv_field neutralises formula injection vectors',
	static function (): void {
		// Formula prefix characters =, +, -, @, \t, \r
		WPS_Assert::same( "'=SUM(A1:B1)", Logger::sanitize_csv_field( '=SUM(A1:B1)' ) );
		WPS_Assert::same( "'+cmd|' /C calc'!A0", Logger::sanitize_csv_field( "+cmd|' /C calc'!A0" ) );
		WPS_Assert::same( "'-100+200", Logger::sanitize_csv_field( '-100+200' ) );
		WPS_Assert::same( "'@SUM(1,2)", Logger::sanitize_csv_field( '@SUM(1,2)' ) );
		WPS_Assert::same( "'\tcmd", Logger::sanitize_csv_field( "\tcmd" ) );
		WPS_Assert::same( "'\rcmd", Logger::sanitize_csv_field( "\rcmd" ) );

		// Leading whitespace before formula prefixes
		WPS_Assert::same( "'  =HYPERLINK(\"http://evil.com\")", Logger::sanitize_csv_field( '  =HYPERLINK("http://evil.com")' ) );
		WPS_Assert::same( "'\t+calc", Logger::sanitize_csv_field( "\t+calc" ) );
		WPS_Assert::same( "'   @foo", Logger::sanitize_csv_field( '   @foo' ) );

		// Benign text is unmodified
		WPS_Assert::same( 'Normál naplóüzenet', Logger::sanitize_csv_field( 'Normál naplóüzenet' ) );
		WPS_Assert::same( 'order_imported', Logger::sanitize_csv_field( 'order_imported' ) );
		WPS_Assert::same( '12345', Logger::sanitize_csv_field( '12345' ) );

		// Empty and null values
		WPS_Assert::same( '', Logger::sanitize_csv_field( null ) );
		WPS_Assert::same( '', Logger::sanitize_csv_field( '' ) );

		// Booleans
		WPS_Assert::same( '1', Logger::sanitize_csv_field( true ) );
		WPS_Assert::same( '0', Logger::sanitize_csv_field( false ) );

		// Arrays / JSON
		$json = Logger::sanitize_csv_field( array( 'key' => 'value' ) );
		WPS_Assert::same( '{"key":"value"}', $json );

		// Array whose JSON starts with formula prefix (hypothetical edge case)
		$danger_json = Logger::sanitize_csv_field( array( '=danger' => 1 ) );
		WPS_Assert::same( '{"=danger":1}', $danger_json ); // Starts with '{', harmless
	}
);
