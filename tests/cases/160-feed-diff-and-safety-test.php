<?php
/**
 * Feed difference reporting and safety checks.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Feed\Catalog_Diff;
use WPS\Pepita\Feed\Catalog_Index;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

$write_catalog = static function ( string $path, array $rows ): void {
	$fp = fopen( $path, 'wb' );
	if ( ! $fp ) {
		throw new RuntimeException( 'Failed to open ' . $path );
	}
	foreach ( $rows as $row ) {
		fwrite( $fp, wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	fclose( $fp );
};

$suite->add(
	'diff: empty or missing catalog returns no_current status',
	static function (): void {
		$diff = Catalog_Diff::compare( '/tmp/nonexistent-catalog-1.jsonl', '/tmp/nonexistent-catalog-2.jsonl' );

		WPS_Assert::same( 'no_current', $diff['status'] );
		WPS_Assert::same( false, $diff['has_current'] );
		WPS_Assert::same( 0, $diff['current_count'] );
	}
);

$suite->add(
	'diff: first generation with no previous snapshot returns initial status',
	static function () use ( $write_catalog ): void {
		$curr = sys_get_temp_dir() . '/wps-diff-curr-' . uniqid() . '.jsonl';

		$write_catalog(
			$curr,
			array(
				array( 'pid' => 1, 'eid' => 'SKU-1', 'name' => 'T1', 'price' => 1000.0, 'retired_at' => 0 ),
				array( 'pid' => 2, 'eid' => 'SKU-2', 'name' => 'T2', 'price' => 2000.0, 'retired_at' => 0 ),
				array( 'pid' => 3, 'eid' => 'SKU-3', 'name' => 'T3', 'price' => 3000.0, 'retired_at' => 1234567 ),
			)
		);

		$diff = Catalog_Diff::compare( $curr, '/tmp/nonexistent-prev.jsonl' );

		WPS_Assert::same( 'initial', $diff['status'] );
		WPS_Assert::true( $diff['is_initial'] );
		WPS_Assert::same( 2, $diff['current_count'] );
		WPS_Assert::same( 1, $diff['unavailable_count'] );
		WPS_Assert::same( 2, $diff['added_count'] );
		WPS_Assert::same( 0, $diff['removed_count'] );
		WPS_Assert::same( 0, count( $diff['warnings'] ) );

		@unlink( $curr );
	}
);

$suite->add(
	'diff: tracks additions, removals, price, variation and tax/currency changes',
	static function () use ( $write_catalog ): void {
		$prev = sys_get_temp_dir() . '/wps-diff-prev-' . uniqid() . '.jsonl';
		$curr = sys_get_temp_dir() . '/wps-diff-curr-' . uniqid() . '.jsonl';

		$write_catalog(
			$prev,
			array(
				array( 'pid' => 1, 'eid' => 'SKU-1', 'name' => 'Termék 1', 'price' => 1000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 0 ),
				array( 'pid' => 2, 'eid' => 'SKU-2', 'name' => 'Termék 2', 'price' => 2000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array( 'v1' ), 'retired_at' => 0 ),
				array( 'pid' => 3, 'eid' => 'SKU-3', 'name' => 'Termék 3', 'price' => 3000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 0 ),
				array( 'pid' => 4, 'eid' => 'SKU-4', 'name' => 'Termék 4', 'price' => 4000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 0 ),
			)
		);

		$write_catalog(
			$curr,
			array(
				// Product 1: price changed
				array( 'pid' => 1, 'eid' => 'SKU-1', 'name' => 'Termék 1', 'price' => 1250.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 0 ),
				// Product 2: currency and variations changed
				array( 'pid' => 2, 'eid' => 'SKU-2', 'name' => 'Termék 2', 'price' => 2000.0, 'vat' => 27.0, 'currency' => 'EUR', 'vars' => array( 'v1', 'v2' ), 'retired_at' => 0 ),
				// Product 3 is missing completely -> removed
				// Product 4: transitioned to retired/unavailable
				array( 'pid' => 4, 'eid' => 'SKU-4', 'name' => 'Termék 4', 'price' => 4000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 1700000000 ),
				// Product 5: newly added
				array( 'pid' => 5, 'eid' => 'SKU-5', 'name' => 'Termék 5', 'price' => 5000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 0 ),
			)
		);

		$diff = Catalog_Diff::compare( $curr, $prev, array( 'drop_threshold' => 50 ) );

		WPS_Assert::same( 'warn', $diff['status'] ); // Variation change triggers warn by default
		WPS_Assert::same( 3, $diff['current_count'] ); // 1, 2, 5 active
		WPS_Assert::same( 4, $diff['previous_count'] ); // 1, 2, 3, 4 active
		WPS_Assert::same( 1, $diff['added_count'] ); // 5
		WPS_Assert::same( 1, $diff['removed_count'] ); // 3
		WPS_Assert::same( 1, $diff['unavailable_count'] ); // 4
		WPS_Assert::same( 1, $diff['price_changed_count'] ); // 1
		WPS_Assert::same( 1, $diff['variation_changed_count'] ); // 2
		WPS_Assert::same( 1, $diff['tax_currency_changed_count'] ); // 2
		WPS_Assert::equals( 25.0, $diff['drop_percent'] ); // 1 removed out of 4

		// Check samples
		WPS_Assert::same( 1, count( $diff['samples']['added'] ) );
		WPS_Assert::same( 5, $diff['samples']['added'][0]['pid'] );
		WPS_Assert::same( 1, count( $diff['samples']['removed'] ) );
		WPS_Assert::same( 3, $diff['samples']['removed'][0]['pid'] );
		WPS_Assert::same( 1, count( $diff['samples']['unavailable'] ) );
		WPS_Assert::same( 4, $diff['samples']['unavailable'][0]['pid'] );
		WPS_Assert::same( 1, count( $diff['samples']['price_changed'] ) );
		WPS_Assert::same( 1, $diff['samples']['price_changed'][0]['pid'] );
		WPS_Assert::same( 1, count( $diff['samples']['variation_changed'] ) );
		WPS_Assert::same( 2, $diff['samples']['variation_changed'][0]['pid'] );
		WPS_Assert::same( 1, count( $diff['samples']['tax_currency_changed'] ) );
		WPS_Assert::same( 2, $diff['samples']['tax_currency_changed'][0]['pid'] );

		@unlink( $prev );
		@unlink( $curr );
	}
);

$suite->add(
	'diff: drop threshold warns on unexpected removals',
	static function () use ( $write_catalog ): void {
		$prev = sys_get_temp_dir() . '/wps-diff-prev-' . uniqid() . '.jsonl';
		$curr = sys_get_temp_dir() . '/wps-diff-curr-' . uniqid() . '.jsonl';

		$prev_rows = array();
		for ( $i = 1; $i <= 10; ++$i ) {
			$prev_rows[] = array( 'pid' => $i, 'eid' => 'SKU-' . $i, 'name' => 'T' . $i, 'price' => 1000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 0 );
		}
		$write_catalog( $prev, $prev_rows );

		// Current only retains 7 items (3 removed = 30% drop)
		$curr_rows = array();
		for ( $i = 1; $i <= 7; ++$i ) {
			$curr_rows[] = array( 'pid' => $i, 'eid' => 'SKU-' . $i, 'name' => 'T' . $i, 'price' => 1000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 0 );
		}
		$write_catalog( $curr, $curr_rows );

		// Test threshold = 20% (drop is 30% -> warning)
		$diff = Catalog_Diff::compare( $curr, $prev, array( 'drop_threshold' => 20, 'warn_variation_changes' => false ) );
		WPS_Assert::same( 'warn', $diff['status'] );
		WPS_Assert::same( 3, $diff['removed_count'] );
		WPS_Assert::equals( 30.0, $diff['drop_percent'] );
		WPS_Assert::true( count( $diff['warnings'] ) >= 1 );

		// Test threshold = 40% (drop is 30% -> ok)
		$diff2 = Catalog_Diff::compare( $curr, $prev, array( 'drop_threshold' => 40, 'warn_variation_changes' => false ) );
		WPS_Assert::same( 'ok', $diff2['status'] );
		WPS_Assert::same( 0, count( $diff2['warnings'] ) );

		@unlink( $prev );
		@unlink( $curr );
	}
);

$suite->add(
	'diff: empty catalog against populated previous triggers critical fail',
	static function () use ( $write_catalog ): void {
		$prev = sys_get_temp_dir() . '/wps-diff-prev-' . uniqid() . '.jsonl';
		$curr = sys_get_temp_dir() . '/wps-diff-curr-' . uniqid() . '.jsonl';

		$prev_rows = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$prev_rows[] = array( 'pid' => $i, 'eid' => 'SKU-' . $i, 'name' => 'T' . $i, 'price' => 1000.0, 'vat' => 27.0, 'currency' => 'HUF', 'vars' => array(), 'retired_at' => 0 );
		}
		$write_catalog( $prev, $prev_rows );
		// Current only has retired products (0 active)
		$write_catalog( $curr, array( array( 'pid' => 99, 'eid' => 'SKU-99', 'name' => 'T99', 'price' => 100.0, 'retired_at' => 123456 ) ) );

		$diff = Catalog_Diff::compare( $curr, $prev );
		WPS_Assert::same( 'fail', $diff['status'] );
		WPS_Assert::same( 0, $diff['current_count'] );
		WPS_Assert::same( 5, $diff['previous_count'] );
		WPS_Assert::true( count( $diff['warnings'] ) >= 1 );

		@unlink( $prev );
		@unlink( $curr );
	}
);

$suite->add(
	'diff: cache helpers persist and clear diff option',
	static function (): void {
		Catalog_Diff::clear_cache();
		WPS_Assert::same( false, get_option( Catalog_Diff::OPTION_DIFF_FULL, false ) );

		update_option( Catalog_Diff::OPTION_DIFF_FULL, array( 'status' => 'ok', 'current_count' => 42 ) );
		$cached = Catalog_Diff::get_cached_diff();
		WPS_Assert::same( 'ok', $cached['status'] );
		WPS_Assert::same( 42, $cached['current_count'] );

		Catalog_Diff::clear_cache();
		WPS_Assert::same( false, get_option( Catalog_Diff::OPTION_DIFF_FULL, false ) );
	}
);
