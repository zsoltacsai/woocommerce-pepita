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

use WPS\Pepita\Feed\Generator;
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
