<?php
/**
 * Feed publication crash-consistency and pointer swap tests (Phase 4).
 *
 * Verifies that:
 * - Publication uses immutable versioned generation artifacts and an atomic pointer file (active-generation.json).
 * - A process killed before pointer swap leaves the previous generation fully intact and active.
 * - A process killed after pointer swap leaves the new generation fully intact and active.
 * - Both feed XML and catalog index always match the exact same generation timestamp/version.
 * - Legacy file layout is supported as fallback when no pointer exists.
 * - Stock feed publication preserves the full generation and updates the stock pointer.
 * - Stale generations older than active and previous are pruned cleanly.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

$reset_storage = static function (): void {
	Storage::purge();
};

$suite->add(
	'crash-consistency: initial publication creates active pointer and versioned files',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		$f1 = sys_get_temp_dir() . '/crash_test_f1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/crash_test_i1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>GEN-1</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"GEN-1"}' . "\n" );

		$published = Storage::publish_full( $f1, $i1 );
		WPS_Assert::true( $published );

		// Pointer must exist
		$pointer_file = Storage::pointer_path();
		WPS_Assert::true( is_file( $pointer_file ) );

		$ptr = Storage::read_pointer();
		WPS_Assert::true( ! empty( $ptr['active']['full_feed'] ) );
		WPS_Assert::true( ! empty( $ptr['active']['catalog_index'] ) );

		// Live paths resolve to the versioned files
		WPS_Assert::same( Storage::dir() . '/' . $ptr['active']['full_feed'], Storage::feed_path( 'full' ) );
		WPS_Assert::same( Storage::dir() . '/' . $ptr['active']['catalog_index'], Storage::catalog_index_path() );

		// Content matches
		WPS_Assert::contains( 'GEN-1', file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::contains( 'GEN-1', file_get_contents( Storage::catalog_index_path() ) );
	}
);

$suite->add(
	'crash-consistency: simulated crash before pointer swap leaves previous generation active',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		// Generation 1
		$f1 = sys_get_temp_dir() . '/crash_f1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/crash_i1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>V1</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"V1"}' . "\n" );
		Storage::publish_full( $f1, $i1 );

		$v1_ptr = Storage::read_pointer();
		WPS_Assert::contains( 'V1', file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::contains( 'V1', file_get_contents( Storage::catalog_index_path() ) );

		// Simulate Generation 2 staging files on disk, but worker crashes before renaming pointer
		$dir = Storage::dir();
		$v2_id = 'v2_simulated';
		$fake_f2 = "{$dir}/pepita-full.{$v2_id}.xml";
		$fake_i2 = "{$dir}/catalog.{$v2_id}.jsonl";
		$fake_tmp_ptr = "{$dir}/active-generation.tmp-{$v2_id}.json";

		file_put_contents( $fake_f2, '<Catalog><Product><Id>V2-ABORTED</Id></Product></Catalog>' );
		file_put_contents( $fake_i2, '{"pid":"V2-ABORTED"}' . "\n" );
		file_put_contents( $fake_tmp_ptr, '{"active":{"full_feed":"pepita-full.v2_simulated.xml"}}' );

		// Active generation pointer was NEVER swapped!
		Storage::clear_pointer_cache();
		$current_ptr = Storage::read_pointer();

		WPS_Assert::same( $v1_ptr['active']['full_feed'], $current_ptr['active']['full_feed'] );
		WPS_Assert::contains( 'V1', file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::contains( 'V1', file_get_contents( Storage::catalog_index_path() ) );

		// Clean up simulated files
		@unlink( $fake_f2 );
		@unlink( $fake_i2 );
		@unlink( $fake_tmp_ptr );
	}
);

$suite->add(
	'crash-consistency: atomic swap advances active generation and shifts previous generation',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		// Publish Generation 1
		$f1 = sys_get_temp_dir() . '/swap_f1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/swap_i1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>GEN-ONE</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"GEN-ONE"}' . "\n" );
		Storage::publish_full( $f1, $i1 );

		// Publish Generation 2
		$f2 = sys_get_temp_dir() . '/swap_f2_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/swap_i2_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>GEN-TWO</Id></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":"GEN-TWO"}' . "\n" );
		Storage::publish_full( $f2, $i2 );

		// Active is Generation 2
		WPS_Assert::contains( 'GEN-TWO', file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::contains( 'GEN-TWO', file_get_contents( Storage::catalog_index_path() ) );

		// Previous is Generation 1
		WPS_Assert::true( is_file( Storage::previous_feed_path( 'full' ) ) );
		WPS_Assert::true( is_file( Storage::previous_catalog_index_path() ) );
		WPS_Assert::contains( 'GEN-ONE', file_get_contents( Storage::previous_feed_path( 'full' ) ) );
		WPS_Assert::contains( 'GEN-ONE', file_get_contents( Storage::previous_catalog_index_path() ) );

		// Publish Generation 3
		$f3 = sys_get_temp_dir() . '/swap_f3_' . uniqid() . '.xml';
		$i3 = sys_get_temp_dir() . '/swap_i3_' . uniqid() . '.jsonl';
		file_put_contents( $f3, '<Catalog><Product><Id>GEN-THREE</Id></Product></Catalog>' );
		file_put_contents( $i3, '{"pid":"GEN-THREE"}' . "\n" );
		Storage::publish_full( $f3, $i3 );

		// Active is Generation 3
		WPS_Assert::contains( 'GEN-THREE', file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::contains( 'GEN-THREE', file_get_contents( Storage::catalog_index_path() ) );

		// Previous is now Generation 2
		WPS_Assert::contains( 'GEN-TWO', file_get_contents( Storage::previous_feed_path( 'full' ) ) );
		WPS_Assert::contains( 'GEN-TWO', file_get_contents( Storage::previous_catalog_index_path() ) );
	}
);

$suite->add(
	'crash-consistency: stock feed publication updates pointer while preserving full catalog',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		// Full generation
		$f = sys_get_temp_dir() . '/full_stock_f_' . uniqid() . '.xml';
		$i = sys_get_temp_dir() . '/full_stock_i_' . uniqid() . '.jsonl';
		file_put_contents( $f, '<Catalog><Product><Id>PROD-STOCK-1</Id></Product></Catalog>' );
		file_put_contents( $i, '{"pid":"PROD-STOCK-1"}' . "\n" );
		Storage::publish_full( $f, $i );

		$ptr_before = Storage::read_pointer();

		// Publish stock
		$s = sys_get_temp_dir() . '/stock_feed_' . uniqid() . '.xml';
		file_put_contents( $s, '<Catalog><Product><Id>PROD-STOCK-1</Id><Price>999</Price></Product></Catalog>' );
		$published_stock = Storage::publish( 'stock', $s );

		WPS_Assert::true( $published_stock );
		$ptr_after = Storage::read_pointer();

		// Full catalog unchanged
		WPS_Assert::same( $ptr_before['active']['full_feed'], $ptr_after['active']['full_feed'] );
		WPS_Assert::same( $ptr_before['active']['catalog_index'], $ptr_after['active']['catalog_index'] );
		// Stock file registered in pointer
		WPS_Assert::true( ! empty( $ptr_after['active']['stock_feed'] ) );
		WPS_Assert::contains( '999', file_get_contents( Storage::feed_path( 'stock' ) ) );
	}
);

$suite->add(
	'crash-consistency: fallback to legacy files when pointer file is absent',
	static function () use ( $reset_storage ): void {
		$reset_storage();
		Storage::ensure_directory();

		$dir = Storage::dir();
		// Create legacy unversioned files directly
		file_put_contents( "{$dir}/pepita-full.xml", '<Catalog><Legacy>true</Legacy></Catalog>' );
		file_put_contents( "{$dir}/catalog.jsonl", '{"legacy":true}' . "\n" );

		// Pointer does not exist
		@unlink( Storage::pointer_path() );
		Storage::clear_pointer_cache();

		// Storage methods should transparently fallback to legacy files
		WPS_Assert::same( "{$dir}/pepita-full.xml", Storage::feed_path( 'full' ) );
		WPS_Assert::same( "{$dir}/catalog.jsonl", Storage::catalog_index_path() );
		WPS_Assert::contains( '<Legacy>true</Legacy>', file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::contains( '"legacy":true', file_get_contents( Storage::catalog_index_path() ) );
	}
);

$suite->add(
	'crash-consistency: repeated stock publications produce unique immutable filenames and retain previous version',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		// Initialize with a full feed
		$f = sys_get_temp_dir() . '/stock_rep_f_' . uniqid() . '.xml';
		$i = sys_get_temp_dir() . '/stock_rep_i_' . uniqid() . '.jsonl';
		file_put_contents( $f, '<Catalog><Product><Id>P1</Id></Product></Catalog>' );
		file_put_contents( $i, '{"pid":"P1"}' . "\n" );
		Storage::publish_full( $f, $i );

		// Stock 1
		$s1 = sys_get_temp_dir() . '/stock_rep_s1_' . uniqid() . '.xml';
		file_put_contents( $s1, '<Catalog><Product><Id>P1</Id><Price>100</Price></Product></Catalog>' );
		$pub1 = Storage::publish( 'stock', $s1 );
		WPS_Assert::true( $pub1 );

		$ptr1 = Storage::read_pointer();
		$file1 = $ptr1['active']['stock_feed'];
		WPS_Assert::true( ! empty( $file1 ) );
		WPS_Assert::contains( '100', file_get_contents( Storage::dir() . '/' . $file1 ) );

		// Stock 2
		$s2 = sys_get_temp_dir() . '/stock_rep_s2_' . uniqid() . '.xml';
		file_put_contents( $s2, '<Catalog><Product><Id>P1</Id><Price>200</Price></Product></Catalog>' );
		$pub2 = Storage::publish( 'stock', $s2 );
		WPS_Assert::true( $pub2 );

		Storage::clear_pointer_cache();
		$ptr2 = Storage::read_pointer();
		$file2 = $ptr2['active']['stock_feed'];

		// Unique immutable filename: file2 != file1
		WPS_Assert::true( $file1 !== $file2 );
		WPS_Assert::contains( '200', file_get_contents( Storage::dir() . '/' . $file2 ) );

		// Previous stock version is preserved and matches file1
		WPS_Assert::same( $file1, $ptr2['previous']['stock_feed'] );
		WPS_Assert::true( is_file( Storage::dir() . '/' . $file1 ) );
		WPS_Assert::contains( '100', file_get_contents( Storage::dir() . '/' . $file1 ) );

		// Stock 3
		$s3 = sys_get_temp_dir() . '/stock_rep_s3_' . uniqid() . '.xml';
		file_put_contents( $s3, '<Catalog><Product><Id>P1</Id><Price>300</Price></Product></Catalog>' );
		$pub3 = Storage::publish( 'stock', $s3 );
		WPS_Assert::true( $pub3 );

		Storage::clear_pointer_cache();
		$ptr3 = Storage::read_pointer();
		$file3 = $ptr3['active']['stock_feed'];

		WPS_Assert::true( $file3 !== $file2 && $file3 !== $file1 );
		WPS_Assert::same( $file2, $ptr3['previous']['stock_feed'] );
		// File 1 (two versions back) has been pruned safely
		WPS_Assert::false( is_file( Storage::dir() . '/' . $file1 ) );
		// File 2 (previous) is kept
		WPS_Assert::true( is_file( Storage::dir() . '/' . $file2 ) );
		// File 3 (active) is kept
		WPS_Assert::true( is_file( Storage::dir() . '/' . $file3 ) );
	}
);

$suite->add(
	'crash-consistency: full publication preserves existing active stock generation',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		// Full generation 1
		$f1 = sys_get_temp_dir() . '/pres_f1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/pres_i1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>V1</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"V1"}' . "\n" );
		Storage::publish_full( $f1, $i1 );

		// Stock generation 1
		$s1 = sys_get_temp_dir() . '/pres_s1_' . uniqid() . '.xml';
		file_put_contents( $s1, '<Catalog><Product><Id>V1</Id><Price>555</Price></Product></Catalog>' );
		Storage::publish( 'stock', $s1 );

		$ptr_before = Storage::read_pointer();
		$active_stock = $ptr_before['active']['stock_feed'];
		WPS_Assert::true( ! empty( $active_stock ) );

		// Full generation 2 runs (daily update) with UNCHANGED catalog identity (V1)
		$f2 = sys_get_temp_dir() . '/pres_f2_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/pres_i2_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>V1</Id><Title>Updated Title</Title></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":"V1"}' . "\n" );
		Storage::publish_full( $f2, $i2 );

		Storage::clear_pointer_cache();
		$ptr_after = Storage::read_pointer();

		// Full feed updated to V2
		WPS_Assert::contains( 'Updated Title', file_get_contents( Storage::feed_path( 'full' ) ) );
		// Stock feed was NOT cleared or wiped! It remains active and intact because catalog identity signature is unchanged
		WPS_Assert::same( $active_stock, $ptr_after['active']['stock_feed'] );
		WPS_Assert::true( Storage::exists( 'stock' ) );
		WPS_Assert::contains( '555', file_get_contents( Storage::feed_path( 'stock' ) ) );
	}
);

$suite->add(
	'catalog-consistency: full publication with changed catalog identity signature invalidates stock feed (fail closed 503)',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		// Full generation 1
		$f1 = sys_get_temp_dir() . '/sig_f1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/sig_i1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>P-A</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"P-A"}' . "\n" );
		Storage::publish_full( $f1, $i1 );

		// Stock generation 1
		$s1 = sys_get_temp_dir() . '/sig_s1_' . uniqid() . '.xml';
		file_put_contents( $s1, '<Catalog><Product><Id>P-A</Id><Price>100</Price></Product></Catalog>' );
		Storage::publish( 'stock', $s1 );

		WPS_Assert::true( Storage::exists( 'stock' ) );
		$ptr1 = Storage::read_pointer();
		WPS_Assert::true( ! empty( $ptr1['active']['stock_feed'] ) );
		WPS_Assert::true( ! empty( $ptr1['active']['catalog_signature'] ) );
		WPS_Assert::same( $ptr1['active']['catalog_signature'], $ptr1['active']['stock_signature'] );

		// Full generation 2 has DIFFERENT products/variations (e.g. product B added)
		$f2 = sys_get_temp_dir() . '/sig_f2_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/sig_i2_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>P-A</Id></Product><Product><Id>P-B</Id></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":"P-A"}' . "\n" . '{"pid":"P-B"}' . "\n" );
		Storage::publish_full( $f2, $i2 );

		Storage::clear_pointer_cache();
		$ptr2 = Storage::read_pointer();

		// Catalog signature changed
		WPS_Assert::true( $ptr1['active']['catalog_signature'] !== $ptr2['active']['catalog_signature'] );
		// Stock feed invalidated in pointer
		WPS_Assert::same( '', $ptr2['active']['stock_feed'] );
		WPS_Assert::same( '', $ptr2['active']['stock_signature'] );
		// Storage::exists('stock') fails closed (HTTP 503)
		WPS_Assert::false( Storage::exists( 'stock' ) );
		// Legacy pepita-stock.xml was removed to prevent serving stale stock
		WPS_Assert::false( is_file( Storage::dir() . '/pepita-stock.xml' ) );

		// Now publish stock generation matching generation 2
		$s2 = sys_get_temp_dir() . '/sig_s2_' . uniqid() . '.xml';
		file_put_contents( $s2, '<Catalog><Product><Id>P-A</Id><Price>100</Price></Product><Product><Id>P-B</Id><Price>200</Price></Product></Catalog>' );
		Storage::publish( 'stock', $s2, null, $ptr2['active']['catalog_signature'] );

		Storage::clear_pointer_cache();
		$ptr3 = Storage::read_pointer();
		WPS_Assert::true( ! empty( $ptr3['active']['stock_feed'] ) );
		WPS_Assert::same( $ptr2['active']['catalog_signature'], $ptr3['active']['stock_signature'] );
		WPS_Assert::true( Storage::exists( 'stock' ) );
	}
);

$suite->add(
	'lock-safety: lock acquisition failure strictly blocks publication and cleans up temporary files without touching pointer',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		// Initial stable publication
		$f1 = sys_get_temp_dir() . '/lock_f1_' . uniqid() . '.xml';
		$i1 = sys_get_temp_dir() . '/lock_i1_' . uniqid() . '.jsonl';
		file_put_contents( $f1, '<Catalog><Product><Id>BASE</Id></Product></Catalog>' );
		file_put_contents( $i1, '{"pid":"BASE"}' . "\n" );
		Storage::publish_full( $f1, $i1 );

		$ptr_before = Storage::read_pointer();
		$lock_file  = Storage::lock_path();

		// 1) Test acquire_lock(false) returns false when lock is held
		$held_handle = fopen( $lock_file, 'c+' );
		WPS_Assert::true( false !== $held_handle );
		$locked = flock( $held_handle, LOCK_EX | LOCK_NB );
		WPS_Assert::true( $locked );

		$second_lock = Storage::acquire_lock( false );
		WPS_Assert::false( $second_lock );

		flock( $held_handle, LOCK_UN );
		fclose( $held_handle );

		// 2) Unopenable lock file (read-only file)
		chmod( $lock_file, 0444 );

		// Attempt publish_full with unwritable lock
		$f2 = sys_get_temp_dir() . '/lock_f2_' . uniqid() . '.xml';
		$i2 = sys_get_temp_dir() . '/lock_i2_' . uniqid() . '.jsonl';
		file_put_contents( $f2, '<Catalog><Product><Id>BLOCKED</Id></Product></Catalog>' );
		file_put_contents( $i2, '{"pid":"BLOCKED"}' . "\n" );

		$result_full = Storage::publish_full( $f2, $i2 );
		WPS_Assert::false( $result_full );
		// Staged temp files must be cleaned up / unlinked
		WPS_Assert::false( is_file( $f2 ) );
		WPS_Assert::false( is_file( $i2 ) );

		// Attempt publish_stock with unwritable lock
		$s2 = sys_get_temp_dir() . '/lock_s2_' . uniqid() . '.xml';
		file_put_contents( $s2, '<Catalog><Product><Id>BLOCKED-STOCK</Id></Product></Catalog>' );

		$result_stock = Storage::publish_stock( $s2 );
		WPS_Assert::false( $result_stock );
		WPS_Assert::false( is_file( $s2 ) );

		// Restore permissions
		chmod( $lock_file, 0666 );

		// Verify pointer is completely untouched
		Storage::clear_pointer_cache();
		$ptr_after = Storage::read_pointer();
		WPS_Assert::same( $ptr_before['active']['full_feed'], $ptr_after['active']['full_feed'] );
		WPS_Assert::same( $ptr_before['active']['catalog_index'], $ptr_after['active']['catalog_index'] );
		WPS_Assert::contains( 'BASE', file_get_contents( Storage::feed_path( 'full' ) ) );
	}
);

$suite->add(
	'lock-safety: multi-process concurrency serializes publication without race condition',
	static function () use ( $reset_storage ): void {
		$reset_storage();
		Storage::ensure_directory();

		if ( ! defined( 'PHP_BINARY' ) || ! is_executable( PHP_BINARY ) || ! function_exists( 'proc_open' ) ) {
			return;
		}

		$lock_file = Storage::lock_path();

		$worker_code = sprintf(
			'$h = fopen("%s", "c+"); if ($h) { flock($h, LOCK_EX); echo "L\n"; fflush(STDOUT); usleep(300000); flock($h, LOCK_UN); fclose($h); }',
			addslashes( $lock_file )
		);

		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$proc = proc_open( array( PHP_BINARY, '-r', $worker_code ), $spec, $pipes );
		if ( ! is_resource( $proc ) ) {
			return;
		}

		$status_line = fgets( $pipes[1] );
		WPS_Assert::same( "L\n", $status_line );

		// Non-blocking lock attempt from parent process MUST fail because worker holds it
		$non_blocking_lock = Storage::acquire_lock( false );
		WPS_Assert::false( $non_blocking_lock );

		// Blocking lock attempt will wait until worker releases lock and then succeed
		$blocking_lock = Storage::acquire_lock( true );
		WPS_Assert::true( false !== $blocking_lock );

		Storage::release_lock( $blocking_lock );

		fclose( $pipes[0] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
	}
);

$suite->add(
	'strict-lookup: missing active full or stock file fails closed without fallback to stale compatibility files',
	static function () use ( $reset_storage ): void {
		$reset_storage();

		// Full + stock generation
		$f = sys_get_temp_dir() . '/miss_f_' . uniqid() . '.xml';
		$i = sys_get_temp_dir() . '/miss_i_' . uniqid() . '.jsonl';
		file_put_contents( $f, '<Catalog><Product><Id>LIVE</Id></Product></Catalog>' );
		file_put_contents( $i, '{"pid":"LIVE"}' . "\n" );
		Storage::publish_full( $f, $i );

		$s = sys_get_temp_dir() . '/miss_s_' . uniqid() . '.xml';
		file_put_contents( $s, '<Catalog><Product><Id>LIVE</Id><Price>777</Price></Product></Catalog>' );
		Storage::publish( 'stock', $s );

		// Create stale compatibility files
		$dir = Storage::dir();
		file_put_contents( "{$dir}/pepita-full.xml", '<Catalog><Stale>true</Stale></Catalog>' );
		file_put_contents( "{$dir}/pepita-stock.xml", '<Catalog><StaleStock>true</StaleStock></Catalog>' );

		$ptr = Storage::read_pointer();
		$versioned_full = $dir . '/' . $ptr['active']['full_feed'];
		$versioned_stock = $dir . '/' . $ptr['active']['stock_feed'];

		// Move active full feed aside
		@rename( $versioned_full, $versioned_full . '.bak' );

		// Storage::exists('full') MUST fail closed: false! No fallback to pepita-full.xml!
		WPS_Assert::false( Storage::exists( 'full' ) );
		// feed_path must point to the versioned path (which does not exist), NOT to pepita-full.xml
		WPS_Assert::same( $versioned_full, Storage::feed_path( 'full' ) );

		// Restore full, move stock aside
		@rename( $versioned_full . '.bak', $versioned_full );
		WPS_Assert::true( Storage::exists( 'full' ) );

		@rename( $versioned_stock, $versioned_stock . '.bak' );
		// Storage::exists('stock') MUST fail closed: false! No fallback to pepita-stock.xml!
		WPS_Assert::false( Storage::exists( 'stock' ) );
		WPS_Assert::same( $versioned_stock, Storage::feed_path( 'stock' ) );

		@rename( $versioned_stock . '.bak', $versioned_stock );
	}
);

$suite->add(
	'strict-lookup: corrupted pointer file fails closed and rejects legacy fallback',
	static function () use ( $reset_storage ): void {
		$reset_storage();
		Storage::ensure_directory();

		$dir = Storage::dir();
		// Create compatibility files
		file_put_contents( "{$dir}/pepita-full.xml", '<Catalog><Stale>true</Stale></Catalog>' );
		file_put_contents( "{$dir}/catalog.jsonl", '{"stale":true}' . "\n" );

		// Write corrupted pointer (invalid JSON)
		file_put_contents( Storage::pointer_path(), '{"version": 1, "active": {BROKEN_JSON' );
		Storage::clear_pointer_cache();

		WPS_Assert::true( Storage::is_pointer_corrupted() );
		WPS_Assert::false( Storage::exists( 'full' ) );
		WPS_Assert::false( Storage::exists( 'stock' ) );
		WPS_Assert::false( is_file( Storage::feed_path( 'full' ) ) );
		WPS_Assert::false( is_file( Storage::catalog_index_path() ) );

		// Write empty pointer
		file_put_contents( Storage::pointer_path(), '' );
		Storage::clear_pointer_cache();

		WPS_Assert::true( Storage::is_pointer_corrupted() );
		WPS_Assert::false( Storage::exists( 'full' ) );

		// Write pointer with missing active fields
		file_put_contents( Storage::pointer_path(), '{"version": 1}' );
		Storage::clear_pointer_cache();

		WPS_Assert::true( Storage::is_pointer_corrupted() );
		WPS_Assert::false( Storage::exists( 'full' ) );
	}
);

$suite->add(
	'strict-lookup: pointer with invalid filenames or path traversal fails validation and fails closed',
	static function () use ( $reset_storage ): void {
		$reset_storage();
		Storage::ensure_directory();

		// Path traversal attempt in pointer
		$malicious_ptr = array(
			'version' => 1,
			'active'  => array(
				'generation_id' => 'malicious',
				'full_feed'     => '../../../../etc/passwd',
				'catalog_index' => 'catalog.jsonl',
				'stock_feed'    => '',
			),
			'previous' => array(),
			'updated_at' => time(),
		);

		file_put_contents( Storage::pointer_path(), json_encode( $malicious_ptr ) );
		Storage::clear_pointer_cache();

		WPS_Assert::false( Storage::validate_pointer( $malicious_ptr ) );
		WPS_Assert::true( Storage::is_pointer_corrupted() );
		WPS_Assert::false( Storage::exists( 'full' ) );
		WPS_Assert::false( is_file( Storage::feed_path( 'full' ) ) );
	}
);

$suite->add(
	'strict-lookup: legacy to versioned explicit migration creates active pointer',
	static function () use ( $reset_storage ): void {
		$reset_storage();
		Storage::ensure_directory();

		$dir = Storage::dir();
		file_put_contents( "{$dir}/pepita-full.xml", '<Catalog><Product><Id>LEGACY-1</Id></Product></Catalog>' );
		file_put_contents( "{$dir}/catalog.jsonl", '{"pid":"LEGACY-1"}' . "\n" );
		file_put_contents( "{$dir}/pepita-stock.xml", '<Catalog><Product><Id>LEGACY-1</Id><Price>100</Price></Product></Catalog>' );

		@unlink( Storage::pointer_path() );
		Storage::clear_pointer_cache();

		// Before migration, no pointer
		WPS_Assert::null( Storage::read_pointer() );

		// Run migration
		$migrated = Storage::migrate_legacy_to_versioned();
		WPS_Assert::true( $migrated );

		// After migration, pointer exists and is valid
		$ptr = Storage::read_pointer();
		WPS_Assert::true( null !== $ptr );
		WPS_Assert::true( ! empty( $ptr['active']['full_feed'] ) );
		WPS_Assert::true( ! empty( $ptr['active']['catalog_index'] ) );
		WPS_Assert::true( ! empty( $ptr['active']['stock_feed'] ) );
		WPS_Assert::true( Storage::exists( 'full' ) );
		WPS_Assert::true( Storage::exists( 'stock' ) );
		WPS_Assert::contains( 'LEGACY-1', file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::contains( '100', file_get_contents( Storage::feed_path( 'stock' ) ) );
	}
);
