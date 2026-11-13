<?php
/**
 * Feed generation: atomic publish, resumable batches, stock consistency and
 * removal policies.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Catalog\Validator;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Feed\Lock;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

$base = static function (): void {
	Settings::update( array( 'default_included' => true ) );
	WPS_Test_State::add_term( 10, 'Kategória' );
	WPS_Test_State::add_attachment( 500, 'https://example.test/wp-content/uploads/termek.jpg' );
};

$product = static function ( int $id, array $overrides = array() ): WC_Product {
	$product = WPS_Test_State::add_product(
		array_merge(
			array(
				'id'          => $id,
				'name'        => 'Termék ' . $id,
				'description' => 'Leírás ' . $id,
				'price'       => '1000',
				'sku'         => 'SKU-' . $id,
				'image_id'    => 500,
			),
			$overrides
		)
	);

	WPS_Test_State::assign_term( $id, 10 );

	return $product;
};

/**
 * Run a generation to completion, guarding against an infinite loop.
 *
 * @return array{result: array<string, mixed>, runs: int}
 */
$run = static function ( string $type, ?Generator $generator = null ): array {
	$generator = $generator ?? new Generator();
	$result    = $generator->run( $type, array( 'max_runtime' => 0 ) );
	$runs      = 1;

	while ( 'running' === ( $result['status'] ?? '' ) && $runs < 500 ) {
		$result = $generator->run( $type, array( 'max_runtime' => 0 ) );
		++$runs;
	}

	return array(
		'result' => $result,
		'runs'   => $runs,
	);
};

/**
 * @return string[]
 */
$ids_in = static function ( string $path ): array {
	$document = simplexml_load_file( $path );

	if ( false === $document ) {
		return array();
	}

	$document->registerXPathNamespace( 'p', 'https://pepita.hu/feed/1.0' );
	$ids = array();

	foreach ( $document->xpath( '//p:Product/p:Id' ) as $node ) {
		$ids[] = (string) $node;
	}

	return $ids;
};

$suite->add(
	'feed: full generation publishes a valid, complete feed',
	static function () use ( $base, $product, $run ): void {
		$base();

		for ( $i = 1; $i <= 3; $i++ ) {
			$product( $i );
		}

		$outcome = $run( 'full' );
		$result  = $outcome['result'];

		WPS_Assert::same( 'completed', $result['status'] );
		WPS_Assert::same( 3, $result['counts']['exported'] );
		WPS_Assert::same( 0, $result['counts']['invalid'] );
		WPS_Assert::same( true, Storage::exists( 'full' ) );
		WPS_Assert::false( is_file( Storage::previous_feed_path( 'full' ) ) );

		$validation = Validator::validate_xml_file( Storage::feed_path( 'full' ) );

		WPS_Assert::same( true, $validation['valid'] );
		WPS_Assert::same( 3, $validation['products'] );

		$state = ( new Generator() )->get_feed_state( 'full' );

		WPS_Assert::truthy( $state['last_success'] );
		WPS_Assert::same( '', $state['last_error'] );
		WPS_Assert::matches( '/^[a-f0-9]{64}$/', (string) $state['hash'] );
		WPS_Assert::same( 3, $state['planned'] );
	}
);

$suite->add(
	'feed: invalid products are skipped, counted and logged',
	static function () use ( $base, $product, $run ): void {
		$base();

		for ( $i = 1; $i <= 3; $i++ ) {
			$product( $i );
		}

		WPS_Test_State::add_product(
			array(
				'id'          => 4,
				'name'        => 'Kép nélkül',
				'description' => 'Leírás',
				'price'       => '1000',
			)
		);
		WPS_Test_State::assign_term( 4, 10 );

		$result = $run( 'full' )['result'];

		WPS_Assert::same( 'completed', $result['status'] );
		WPS_Assert::same( 3, $result['counts']['exported'] );
		WPS_Assert::same( 1, $result['counts']['invalid'] );

		$state = ( new Generator() )->get_feed_state( 'full' );

		WPS_Assert::same( 'missing_image', $state['errors'][0]['code'] );
		WPS_Assert::same( 4, $state['errors'][0]['product_id'] );

		$ids = array();

		foreach ( simplexml_load_file( Storage::feed_path( 'full' ) )->xpath( '//*[local-name()="Id"]' ) as $node ) {
			$ids[] = (string) $node;
		}

		WPS_Assert::true( in_array( 'wps-1', $ids, true ) );
		WPS_Assert::false( in_array( 'wps-4', $ids, true ) );
	}
);

$suite->add(
	'feed: generation resumes across bounded batches without duplicates',
	static function () use ( $base, $product, $run, $ids_in ): void {
		$base();
		Settings::update(
			array(
				'batch_size'      => 10,
				'batches_per_run' => 1,
			)
		);

		for ( $i = 1; $i <= 25; $i++ ) {
			$product( $i );
		}

		$outcome = $run( 'full' );
		$result  = $outcome['result'];

		WPS_Assert::same( 'completed', $result['status'] );
		WPS_Assert::same( 25, $result['counts']['exported'] );
		WPS_Assert::true( $outcome['runs'] >= 3, 'Expected multiple bounded runs, got ' . $outcome['runs'] );

		$ids = $ids_in( Storage::feed_path( 'full' ) );

		WPS_Assert::count( 25, $ids );
		WPS_Assert::count( 25, array_unique( $ids ) );

		$validation = Validator::validate_xml_file( Storage::feed_path( 'full' ) );

		WPS_Assert::same( true, $validation['valid'] );
		WPS_Assert::same( 25, $validation['products'] );
	}
);

$suite->add(
	'feed: stock feed mirrors the full feed and updates prices',
	static function () use ( $base, $product, $run, $ids_in ): void {
		$base();

		for ( $i = 1; $i <= 3; $i++ ) {
			$product( $i );
		}

		$run( 'full' );
		$full_ids = $ids_in( Storage::feed_path( 'full' ) );

		WPS_Test_State::$products[1]->set_price( '2500' );

		$result = $run( 'stock' )['result'];

		WPS_Assert::same( 'completed', $result['status'] );
		WPS_Assert::same( true, Storage::exists( 'stock' ) );

		$stock_ids = $ids_in( Storage::feed_path( 'stock' ) );

		WPS_Assert::same( $full_ids, $stock_ids );

		$document = simplexml_load_file( Storage::feed_path( 'stock' ) );
		$document->registerXPathNamespace( 'p', 'https://pepita.hu/feed/1.0' );

		WPS_Assert::same( '2500', (string) $document->xpath( '//p:Product[p:Id="wps-1"]/p:Prices/p:Price' )[0] );
		WPS_Assert::same( 0, count( $document->xpath( '//p:Descriptions' ) ) );
		WPS_Assert::same( 0, count( $document->xpath( '//p:VatPercent' ) ) );

		$full_state  = ( new Generator() )->get_feed_state( 'full' );
		$stock_state = ( new Generator() )->get_feed_state( 'stock' );

		WPS_Assert::same( (string) $full_state['signature'], (string) $stock_state['signature'] );
	}
);

$suite->add(
	'feed: stock feed refuses to publish when the catalog no longer matches',
	static function () use ( $base, $product, $run ): void {
		$base();

		for ( $i = 1; $i <= 3; $i++ ) {
			$product( $i );
		}

		$run( 'full' );
		$before = file_get_contents( Storage::feed_path( 'full' ) );

		update_post_meta( 2, '_wps_include', 'exclude' );
		Product_Selection::clear_cache();

		$result = $run( 'stock' )['result'];

		WPS_Assert::same( 'error', $result['status'] );
		WPS_Assert::contains( 'eltér', (string) $result['message'] );
		WPS_Assert::same( $before, file_get_contents( Storage::feed_path( 'full' ) ) );
		WPS_Assert::false( Storage::exists( 'stock' ) );
		WPS_Assert::same( true, Scheduler::is_dirty( 'full' ) );
	}
);

$suite->add(
	'feed: stock feed cannot start without a successful full feed',
	static function () use ( $base, $product, $run ): void {
		$base();
		$product( 1 );

		$result = $run( 'stock' )['result'];

		WPS_Assert::same( 'error', $result['status'] );
		WPS_Assert::contains( 'teljes feed', (string) $result['message'] );
		WPS_Assert::false( Storage::exists( 'stock' ) );
	}
);

$suite->add(
	'feed: a failed generation preserves the last good feed',
	static function () use ( $base, $product, $run ): void {
		$base();
		$product( 1 );
		$product( 2 );

		$run( 'full' );

		$before = file_get_contents( Storage::feed_path( 'full' ) );
		$state  = ( new Generator() )->get_feed_state( 'full' );

		update_post_meta( 1, '_wps_include', 'exclude' );
		update_post_meta( 2, '_wps_include', 'exclude' );
		Product_Selection::clear_cache();

		$result = $run( 'full' )['result'];

		WPS_Assert::same( 'error', $result['status'] );
		WPS_Assert::contains( 'üres', (string) $result['message'] );
		WPS_Assert::same( $before, file_get_contents( Storage::feed_path( 'full' ) ) );

		$after = ( new Generator() )->get_feed_state( 'full' );

		WPS_Assert::same( $state['last_success'], $after['last_success'] );
		WPS_Assert::truthy( $after['last_error'] );
		WPS_Assert::same( 0, $after['attempt_counts']['exported'] );
	}
);

$suite->add(
	'feed: the previous published feed is kept as a snapshot',
	static function () use ( $base, $product, $run ): void {
		$base();
		$product( 1 );

		$run( 'full' );
		$first = file_get_contents( Storage::feed_path( 'full' ) );

		$product( 2 );

		$run( 'full' );
		$second = file_get_contents( Storage::feed_path( 'full' ) );

		WPS_Assert::true( $first !== $second );
		WPS_Assert::same( $first, file_get_contents( Storage::previous_feed_path( 'full' ) ) );
	}
);

$suite->add(
	'feed: removal policy omit drops retired products from the feed',
	static function () use ( $base, $product, $run, $ids_in ): void {
		$base();
		Settings::update( array( 'removal_policy' => 'omit' ) );

		for ( $i = 1; $i <= 3; $i++ ) {
			$product( $i );
		}

		$run( 'full' );

		update_post_meta( 3, '_wps_include', 'exclude' );
		Product_Selection::clear_cache();

		$result = $run( 'full' )['result'];

		WPS_Assert::same( 'completed', $result['status'] );
		WPS_Assert::same( 2, $result['counts']['exported'] );

		$ids = $ids_in( Storage::feed_path( 'full' ) );

		WPS_Assert::false( in_array( 'wps-3', $ids, true ) );
	}
);

$suite->add(
	'feed: removal policy zero_stock keeps retired products unavailable',
	static function () use ( $base, $product, $run ): void {
		$base();
		Settings::update(
			array(
				'removal_policy'      => 'zero_stock',
				'removal_grace_days'  => 3,
			)
		);

		for ( $i = 1; $i <= 3; $i++ ) {
			$product( $i );
		}

		$run( 'full' );

		update_post_meta( 3, '_wps_include', 'exclude' );
		Product_Selection::clear_cache();

		$result = $run( 'full' )['result'];

		WPS_Assert::same( 'completed', $result['status'] );
		WPS_Assert::same( 3, $result['counts']['exported'] );

		$document = simplexml_load_file( Storage::feed_path( 'full' ) );
		$document->registerXPathNamespace( 'p', 'https://pepita.hu/feed/1.0' );

		WPS_Assert::same( 'false', (string) $document->xpath( '//p:Product[p:Id="wps-3"]/p:Availability/p:Available' )[0] );
		WPS_Assert::same( '0', (string) $document->xpath( '//p:Product[p:Id="wps-3"]/p:Availability/p:Quantity' )[0] );
	}
);

$suite->add(
	'feed: a product exported as variable cannot silently become simple',
	static function () use ( $base, $run ): void {
		$base();
		WPS_Test_State::add_attribute_label( 'pa_color', 'Szín' );
		WPS_Test_State::add_term( 30, 'Piros', 0, 'pa_color' );

		WPS_Test_State::add_product(
			array(
				'id'          => 10,
				'name'        => 'Póló',
				'description' => 'Leírás',
				'type'        => 'variable',
				'price'       => '5000',
				'image_id'    => 500,
				'attributes'  => array(
					new WC_Product_Attribute(
						array(
							'name'      => 'pa_color',
							'options'   => array( 'piros' ),
							'position'  => 0,
							'variation' => true,
							'taxonomy'  => true,
						)
					),
				),
			)
		);
		WPS_Test_State::assign_term( 10, 10 );
		WPS_Test_State::add_variation(
			10,
			array(
				'id'           => 11,
				'status'       => 'publish',
				'price'        => '5000',
				'stock_status' => 'instock',
				'variation'    => array( 'attribute_pa_color' => 'piros' ),
			)
		);

		WPS_Test_State::add_product(
			array(
				'id'          => 12,
				'name'        => 'Egyszerű',
				'description' => 'Leírás',
				'price'       => '1000',
				'image_id'    => 500,
			)
		);
		WPS_Test_State::assign_term( 12, 10 );

		$first = $run( 'full' )['result'];

		WPS_Assert::same( 'completed', $first['status'] );
		WPS_Assert::same( 2, $first['counts']['exported'] );

		WPS_Test_State::add_product(
			array(
				'id'          => 10,
				'name'        => 'Póló',
				'description' => 'Leírás',
				'type'        => 'simple',
				'price'       => '5000',
				'image_id'    => 500,
			)
		);
		WPS_Test_State::assign_term( 10, 10 );

		$second = $run( 'full' )['result'];

		WPS_Assert::same( 'completed', $second['status'] );
		WPS_Assert::same( 1, $second['counts']['exported'] );
		WPS_Assert::same( 1, $second['counts']['invalid'] );

		$state = ( new Generator() )->get_feed_state( 'full' );
		$codes = array_column( (array) $state['errors'], 'code' );

		WPS_Assert::true( in_array( 'variable_to_simple_forbidden', $codes, true ) );
	}
);

$suite->add(
	'feed: an empty feed is only published when explicitly allowed',
	static function () use ( $base, $run ): void {
		$base();

		$result = $run( 'full' )['result'];

		WPS_Assert::same( 'error', $result['status'] );
		WPS_Assert::false( Storage::exists( 'full' ) );

		Settings::update( array( 'allow_empty_feed' => true ) );

		$result = $run( 'full' )['result'];

		WPS_Assert::same( 'completed', $result['status'] );
		WPS_Assert::same( 0, $result['counts']['exported'] );
		WPS_Assert::same( true, Storage::exists( 'full' ) );

		$validation = Validator::validate_xml_file( Storage::feed_path( 'full' ) );

		WPS_Assert::same( true, $validation['valid'] );
		WPS_Assert::same( 0, $validation['products'] );
	}
);

$suite->add(
	'feed: an expired lock is reclaimed by exactly one process',
	static function (): void {
		$key = 'wps_pepita_lock_full';

		// Simulate a lock abandoned by a crashed process.
		update_option(
			$key,
			array(
				'token'   => 'stale-token',
				'expires' => time() - 60,
				'pid'     => 0,
			),
			false
		);

		WPS_Assert::false( Lock::is_locked( 'full' ) );

		$first = Lock::acquire( 'full', 60 );

		WPS_Assert::true( is_string( $first ) && '' !== $first );
		WPS_Assert::true( Lock::is_locked( 'full' ) );
		WPS_Assert::is_null( Lock::acquire( 'full', 60 ) );

		// A non-owner release must not remove the live lock.
		Lock::release( 'full', 'stale-token' );

		WPS_Assert::true( Lock::is_locked( 'full' ) );
		WPS_Assert::true( Lock::is_owner( 'full', (string) $first ) );

		Lock::release( 'full', (string) $first );

		WPS_Assert::false( Lock::is_locked( 'full' ) );
	}
);
