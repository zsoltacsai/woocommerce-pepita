<?php
/**
 * Performance and scale: a 1000 product catalog must generate safely within
 * bounded, resumable runs and stay consistent between the full and stock feed.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Catalog\Validator;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

$catalog_size = 1000;

/**
 * Seed a catalog of the given size once per case.
 */
$seed_catalog = static function ( int $size ): void {
	WPS_Test_State::add_term( 10, 'Nagy kategória' );
	WPS_Test_State::add_attachment( 500, 'https://example.test/wp-content/uploads/termek.jpg' );

	Settings::update(
		array(
			'default_included' => true,
			'batch_size'       => 100,
			'batches_per_run'  => 2,
			'max_runtime'      => 0,
		)
	);

	for ( $i = 1; $i <= $size; $i++ ) {
		WPS_Test_State::add_product(
			array(
				'id'          => $i,
				'name'        => 'Nagy tétel ' . $i,
				'description' => 'Leírás a(z) ' . $i . '. termékhez.',
				'price'       => (string) ( 500 + ( $i % 50 ) * 100 ),
				'sku'         => 'BULK-' . $i,
				'image_id'    => 500,
			)
		);
		WPS_Test_State::assign_term( $i, 10 );
	}
};

/**
 * @return array{result: array<string, mixed>, runs: int, elapsed: float, peak: int}
 */
$generate = static function ( string $type ): array {
	$generator = new Generator();
	$started   = microtime( true );
	$result    = $generator->run( $type, array( 'max_runtime' => 0 ) );
	$runs      = 1;

	while ( 'running' === ( $result['status'] ?? '' ) && $runs < 500 ) {
		$result = $generator->run( $type, array( 'max_runtime' => 0 ) );
		++$runs;
	}

	return array(
		'result'  => $result,
		'runs'    => $runs,
		'elapsed' => microtime( true ) - $started,
		'peak'    => memory_get_peak_usage( true ),
	);
};

/**
 * @return string[]
 */
$ids = static function ( string $path ): array {
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
	'performance: 1000 products generate in bounded, resumable runs',
	static function () use ( $catalog_size, $seed_catalog, $generate, $ids ): void {
		$seed_catalog( $catalog_size );

		$outcome = $generate( 'full' );
		$result  = $outcome['result'];

		WPS_Assert::same( 'completed', $result['status'] );
		WPS_Assert::same( $catalog_size, $result['counts']['exported'] );
		WPS_Assert::same( 0, $result['counts']['invalid'] );

		// 1000 items with 200 per run means the work must be split.
		WPS_Assert::true(
			$outcome['runs'] >= 5,
			'Expected at least 5 bounded runs, got ' . $outcome['runs']
		);
		WPS_Assert::true(
			$outcome['elapsed'] < 60.0,
			'Generation took ' . round( $outcome['elapsed'], 2 ) . 's, which exceeds the 60s budget.'
		);
		WPS_Assert::true(
			$outcome['peak'] < 128 * MB_IN_BYTES,
			'Peak memory ' . round( $outcome['peak'] / MB_IN_BYTES, 1 ) . 'MB exceeds 128MB.'
		);

		$published = $ids( Storage::feed_path( 'full' ) );

		WPS_Assert::count( $catalog_size, $published );
		WPS_Assert::count( $catalog_size, array_unique( $published ) );
		WPS_Assert::same( 'wps-1', $published[0] );
		WPS_Assert::same( 'wps-' . $catalog_size, $published[ $catalog_size - 1 ] );

		$size = (int) Storage::size( 'full' );

		WPS_Assert::true( $size > 0 );
		WPS_Assert::true(
			$size < $catalog_size * 4096,
			'Feed is larger than 4KB per product: ' . $size . ' bytes.'
		);
	}
);

$suite->add(
	'performance: the generated 1000 product feed passes structural validation',
	static function () use ( $catalog_size, $seed_catalog, $generate ): void {
		$seed_catalog( $catalog_size );
		$generate( 'full' );

		$validation = Validator::validate_xml_file( Storage::feed_path( 'full' ) );

		WPS_Assert::same( true, $validation['valid'] );
		WPS_Assert::same( $catalog_size, $validation['products'] );
		WPS_Assert::count( 0, $validation['errors'] );
	}
);

$suite->add(
	'performance: the 1000 product stock feed matches the full feed identifiers',
	static function () use ( $catalog_size, $seed_catalog, $generate, $ids ): void {
		$seed_catalog( $catalog_size );
		$generate( 'full' );

		$outcome = $generate( 'stock' );

		WPS_Assert::same( 'completed', $outcome['result']['status'] );
		WPS_Assert::true( $outcome['runs'] >= 5 );
		WPS_Assert::true( $outcome['elapsed'] < 60.0 );
		WPS_Assert::true( $outcome['peak'] < 128 * MB_IN_BYTES );

		$full  = $ids( Storage::feed_path( 'full' ) );
		$stock = $ids( Storage::feed_path( 'stock' ) );

		WPS_Assert::count( $catalog_size, $stock );
		WPS_Assert::same( $full, $stock );

		$full_state  = ( new Generator() )->get_feed_state( 'full' );
		$stock_state = ( new Generator() )->get_feed_state( 'stock' );

		WPS_Assert::same( (string) $full_state['signature'], (string) $stock_state['signature'] );
	}
);

$suite->add(
	'performance: regenerating an unchanged 1000 product catalog is idempotent',
	static function () use ( $catalog_size, $seed_catalog, $generate, $ids ): void {
		$seed_catalog( $catalog_size );
		$generate( 'full' );

		$first_ids = $ids( Storage::feed_path( 'full' ) );

		$outcome = $generate( 'full' );

		WPS_Assert::same( 'completed', $outcome['result']['status'] );
		WPS_Assert::same( $catalog_size, $outcome['result']['counts']['exported'] );

		$second_ids = $ids( Storage::feed_path( 'full' ) );

		WPS_Assert::same( $first_ids, $second_ids );
		WPS_Assert::count( $catalog_size, array_unique( $second_ids ) );

		WPS_Assert::same( $catalog_size, Validator::validate_xml_file( Storage::feed_path( 'full' ) )['products'] );
	}
);

$suite->add(
	'performance: bulk exclusion of half the catalog keeps the feed consistent',
	static function () use ( $catalog_size, $seed_catalog, $generate, $ids ): void {
		$seed_catalog( $catalog_size );
		$generate( 'full' );

		for ( $i = 1; $i <= $catalog_size; $i += 2 ) {
			update_post_meta( $i, '_wps_include', 'exclude' );
		}

		Product_Selection::clear_cache();


		$outcome = $generate( 'full' );

		WPS_Assert::same( 'completed', $outcome['result']['status'] );
		WPS_Assert::same( (int) ( $catalog_size / 2 ), $outcome['result']['counts']['exported'] );

		$published = $ids( Storage::feed_path( 'full' ) );

		WPS_Assert::count( (int) ( $catalog_size / 2 ), $published );
		WPS_Assert::false( in_array( 'wps-1', $published, true ) );
		WPS_Assert::true( in_array( 'wps-2', $published, true ) );
	}
);
