<?php
/**
 * Full and stock feed generation against the real catalog.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

/**
 * Run a feed generation to completion, simulating repeated scheduler slices.
 *
 * @return array<string, mixed>
 */
function wps_it_generate( string $type ): array {
	$generator = new Generator();

	for ( $attempt = 0; $attempt < 30; $attempt++ ) {
		$result = $generator->run( $type, array( 'max_runtime' => 0 ) );
		$status = (string) ( $result['status'] ?? '' );

		if ( 'running' !== $status ) {
			return $result;
		}
	}

	throw new RuntimeException( 'Feed generation did not finish in 30 slices.' );
}

/**
 * Map variation value => node.
 *
 * @return array<string, DOMElement>
 */
function wps_it_variations( DOMXPath $xpath, DOMElement $product ): array {
	$items = array();

	foreach ( $xpath->query( './p:Variations/p:Variation', $product ) ?: array() as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}

		$value = WPS_IT::text( $xpath, $node, './p:PrimaryVariation' );

		if ( '' !== $value ) {
			$items[ $value ] = $node;
		}
	}

	return $items;
}

WPS_IT::line( 'Feed generation' );

$seed = WPS_IT::seed();

WPS_IT::test(
	'The seeded catalog matches the documented fixture',
	static function () use ( $seed ): void {
		WPS_IT::same( 5, count( $seed['product_ids'] ), 'Expected five parent products.' );
		WPS_IT::same( 2, count( $seed['variation_ids'] ), 'Expected two variations.' );
		WPS_IT::not_same( $seed['p1'], $seed['p2'], 'Seed product ids must differ.' );
		WPS_IT::same( 'WPS-IT-001', $seed['sku_p1'], 'Unexpected primary SKU.' );
	}
);

WPS_IT::test(
	'Product selection resolves include, exclude and default rules',
	static function () use ( $seed ): void {
		Product_Selection::clear_cache();

		WPS_IT::true( Product_Selection::is_included( (int) $seed['p1'] ), 'p1 must be included by its category.' );
		WPS_IT::true( Product_Selection::is_included( (int) $seed['p2'] ), 'p2 must be included by its category.' );
		WPS_IT::false( Product_Selection::is_included( (int) $seed['p3'] ), 'p3 must be explicitly excluded.' );
		WPS_IT::true( Product_Selection::is_included( (int) $seed['p4'] ), 'p4 must be selected before validation rejects it.' );
		WPS_IT::true( Product_Selection::is_included( (int) $seed['variable'] ), 'The variable parent must be included.' );
	}
);

$full_result = wps_it_generate( Generator::TYPE_FULL );

WPS_IT::test(
	'The full feed generation completes with exact counters',
	static function () use ( $full_result ): void {
		WPS_IT::same( 'completed', (string) ( $full_result['status'] ?? '' ), 'Full feed did not complete: ' . (string) ( $full_result['message'] ?? '' ) );
		WPS_IT::same( 4, (int) $full_result['counts']['processed'], 'Unexpected processed count.' );
		WPS_IT::same( 3, (int) $full_result['counts']['exported'], 'Unexpected exported count.' );
		WPS_IT::same( 1, (int) $full_result['counts']['invalid'], 'Unexpected invalid count.' );
		WPS_IT::same( 1, (int) $full_result['counts']['excluded'], 'Unexpected excluded count.' );
		WPS_IT::same( 1, (int) $full_result['counts']['warnings'], 'The sale-price warning was not counted.' );
		WPS_IT::greater( 0, (int) $full_result['file_size'], 'The full feed is empty.' );
	}
);

WPS_IT::test(
	'The published full feed is a valid Pepita XML document',
	static function () use ( $full_result, $seed ): void {
		WPS_IT::true( Storage::exists( Generator::TYPE_FULL ), 'The full feed file is missing.' );

		$body = (string) file_get_contents( Storage::feed_path( Generator::TYPE_FULL ) );
		$doc  = WPS_IT::xml( $body );
		$xp   = WPS_IT::xpath( $doc );
		$all  = WPS_IT::products( $doc );

		WPS_IT::same( 3, count( $all ), 'Expected three product nodes.' );
		WPS_IT::true( isset( $all[ $seed['export_p1'] ] ), 'p1 is missing from the feed.' );
		WPS_IT::true( isset( $all[ $seed['export_p2'] ] ), 'p2 is missing from the feed.' );
		WPS_IT::true( isset( $all[ $seed['export_var'] ] ), 'The variable parent is missing from the feed.' );
		WPS_IT::false( isset( $all[ 'wps-' . $seed['p3'] ] ), 'The excluded product leaked into the feed.' );
		WPS_IT::false( isset( $all[ 'wps-' . $seed['p4'] ] ), 'The invalid product leaked into the feed.' );

		$state = ( new Generator() )->get_feed_state( Generator::TYPE_FULL );

		WPS_IT::same( hash( 'sha256', $body ), (string) $state['hash'], 'The published hash does not match the file.' );
		WPS_IT::same( strlen( $body ), (int) $state['file_size'], 'The published size does not match the file.' );
		WPS_IT::same( 4, (int) $state['planned'], 'The planned count was not persisted.' );
		WPS_IT::same( 3, (int) $state['counts']['exported'], 'The persisted exported count is wrong.' );
		WPS_IT::same( '', (string) $state['last_error'], 'A successful feed must not carry an error.' );

		// The document must only contain the declared namespace.
		WPS_IT::same( 'Catalog', $doc->documentElement->localName, 'Unexpected root element.' );
		WPS_IT::same( 'https://pepita.hu/feed/1.0', $doc->documentElement->namespaceURI, 'Unexpected feed namespace.' );
		WPS_IT::same( 3, WPS_IT::node_count( $xp, $doc, '//p:Product' ), 'Unexpected Product count.' );
	}
);

WPS_IT::test(
	'The simple product is mapped with prices, identifiers and stock',
	static function () use ( $seed ): void {
		$body = (string) file_get_contents( Storage::feed_path( Generator::TYPE_FULL ) );
		$doc  = WPS_IT::xml( $body );
		$xp   = WPS_IT::xpath( $doc );
		$all  = WPS_IT::products( $doc );
		$node = $all[ $seed['export_p1'] ];

		WPS_IT::same( 'WPS Integration Simple Product', WPS_IT::text( $xp, $node, './p:Descriptions/p:Name' ) );
		WPS_IT::same( 'Integration description.', WPS_IT::text( $xp, $node, './p:Descriptions/p:Description' ) );
		WPS_IT::same( 'WPS Integration Brand', WPS_IT::text( $xp, $node, './p:Descriptions/p:Brand' ) );
		WPS_IT::same( 'WPS Integration Manufacturer', WPS_IT::text( $xp, $node, './p:Descriptions/p:Manufacturer' ) );
		WPS_IT::same( 'MPN-WPS-IT-001', WPS_IT::text( $xp, $node, './p:ProductNumber' ) );
		WPS_IT::same( '1234567890123', WPS_IT::text( $xp, $node, './p:StructuredId' ) );

		WPS_IT::same( 'HUF', WPS_IT::text( $xp, $node, './p:Prices/p:Currency' ) );
		WPS_IT::same( '8000', WPS_IT::text( $xp, $node, './p:Prices/p:Price' ) );
		WPS_IT::same( '27', WPS_IT::text( $xp, $node, './p:Prices/p:VatPercent' ) );
		WPS_IT::same( '6299.21', WPS_IT::text( $xp, $node, './p:Prices/p:PurchaseNetPrice' ) );
		WPS_IT::same( '990', WPS_IT::text( $xp, $node, './p:Prices/p:ShippingPrice' ) );
		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:Prices/p:DiscountedPrice' ), 'A sale price equal to the active price must be omitted.' );

		WPS_IT::same( 'true', WPS_IT::text( $xp, $node, './p:Availability/p:Available' ) );
		WPS_IT::same( '25', WPS_IT::text( $xp, $node, './p:Availability/p:Quantity' ) );
		WPS_IT::same( '2', WPS_IT::text( $xp, $node, './p:Availability/p:ShippingDelay' ) );

		WPS_IT::same( 'Year', WPS_IT::text( $xp, $node, './p:Warranty/p:Type' ) );
		WPS_IT::same( '2', WPS_IT::text( $xp, $node, './p:Warranty/p:Duration' ) );

		WPS_IT::same(
			array( 'WPS Integration', 'WPS Integration Child' ),
			WPS_IT::texts( $xp, $node, './p:Categories/p:Category/p:Name' ),
			'The category path is wrong.'
		);

		$photos = WPS_IT::texts( $xp, $node, './p:Photos/p:Photo/p:Url' );
		WPS_IT::same( 1, count( $photos ), 'Expected one product photo.' );
		WPS_IT::contains( '/wp-content/uploads/', $photos[0], 'The photo URL does not point at the uploads directory.' );
		WPS_IT::same( 'true', WPS_IT::text( $xp, $node, './p:Photos/p:Photo/p:IsPrimary' ) );

		WPS_IT::contains( 'http', WPS_IT::text( $xp, $node, './p:ProductUrl' ), 'The product URL is missing.' );
		WPS_IT::greater( 0, (int) WPS_IT::text( $xp, $node, './p:LastMod' ), 'LastMod must be a positive timestamp.' );
	}
);

WPS_IT::test(
	'The out-of-stock product keeps its price but reports zero availability',
	static function () use ( $seed ): void {
		$body = (string) file_get_contents( Storage::feed_path( Generator::TYPE_FULL ) );
		$doc  = WPS_IT::xml( $body );
		$xp   = WPS_IT::xpath( $doc );
		$node = WPS_IT::products( $doc )[ $seed['export_p2'] ];

		WPS_IT::same( '5000', WPS_IT::text( $xp, $node, './p:Prices/p:Price' ) );
		WPS_IT::same( 'false', WPS_IT::text( $xp, $node, './p:Availability/p:Available' ) );
		WPS_IT::same( '0', WPS_IT::text( $xp, $node, './p:Availability/p:Quantity' ) );
		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:StructuredId' ), 'A missing GTIN must not be fabricated.' );
	}
);

WPS_IT::test(
	'The variable product exports its axes and both variations',
	static function () use ( $seed ): void {
		$body = (string) file_get_contents( Storage::feed_path( Generator::TYPE_FULL ) );
		$doc  = WPS_IT::xml( $body );
		$xp   = WPS_IT::xpath( $doc );
		$node = WPS_IT::products( $doc )[ $seed['export_var'] ];

		WPS_IT::same( 'Size', WPS_IT::text( $xp, $node, './p:Variations/p:PrimaryAttribute' ) );
		WPS_IT::same( '12000', WPS_IT::text( $xp, $node, './p:Prices/p:Price' ) );
		WPS_IT::same( 'true', WPS_IT::text( $xp, $node, './p:Availability/p:Available' ) );
		WPS_IT::same( '5', WPS_IT::text( $xp, $node, './p:Availability/p:Quantity' ) );

		$variations = wps_it_variations( $xp, $node );

		WPS_IT::same( 2, count( $variations ), 'Expected two variation nodes.' );
		WPS_IT::true( isset( $variations['S'] ), 'Variation S is missing.' );
		WPS_IT::true( isset( $variations['M'] ), 'Variation M is missing.' );

		WPS_IT::same( '12000', WPS_IT::text( $xp, $variations['S'], './p:Prices/p:Price' ) );
		WPS_IT::same( 'true', WPS_IT::text( $xp, $variations['S'], './p:Availability/p:Available' ) );
		WPS_IT::same( '5', WPS_IT::text( $xp, $variations['S'], './p:Availability/p:Quantity' ) );

		WPS_IT::same( '13000', WPS_IT::text( $xp, $variations['M'], './p:Prices/p:Price' ) );
		WPS_IT::same( 'false', WPS_IT::text( $xp, $variations['M'], './p:Availability/p:Available' ) );
		WPS_IT::same( '0', WPS_IT::text( $xp, $variations['M'], './p:Availability/p:Quantity' ) );
	}
);

$stock_result = wps_it_generate( Generator::TYPE_STOCK );

WPS_IT::test(
	'The stock feed generation completes and matches the full-feed signature',
	static function () use ( $stock_result ): void {
		WPS_IT::same( 'completed', (string) ( $stock_result['status'] ?? '' ), 'Stock feed did not complete: ' . (string) ( $stock_result['message'] ?? '' ) );
		WPS_IT::same( 3, (int) $stock_result['counts']['exported'], 'Unexpected stock exported count.' );

		$full  = ( new Generator() )->get_feed_state( Generator::TYPE_FULL );
		$stock = ( new Generator() )->get_feed_state( Generator::TYPE_STOCK );

		WPS_IT::same( (string) $full['signature'], (string) $stock['signature'], 'The stock feed signature drifted from the full feed.' );
		WPS_IT::same( hash( 'sha256', (string) file_get_contents( Storage::feed_path( Generator::TYPE_STOCK ) ) ), (string) $stock['hash'], 'The stock feed hash is wrong.' );
	}
);

WPS_IT::test(
	'The stock feed contains only the documented stock elements',
	static function () use ( $seed ): void {
		$body = (string) file_get_contents( Storage::feed_path( Generator::TYPE_STOCK ) );
		$doc  = WPS_IT::xml( $body );
		$xp   = WPS_IT::xpath( $doc );
		$all  = WPS_IT::products( $doc );

		WPS_IT::same( 3, count( $all ), 'Expected three stock product nodes.' );

		$node = $all[ $seed['export_p1'] ];

		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:Descriptions' ), 'Stock feeds must not contain Descriptions.' );
		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:Categories' ), 'Stock feeds must not contain Categories.' );
		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:Photos' ), 'Stock feeds must not contain Photos.' );
		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:LastMod' ), 'Stock feeds must not contain LastMod.' );
		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:Warranty' ), 'Stock feeds must not contain Warranty.' );
		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:ProductUrl' ), 'Stock feeds must not contain ProductUrl.' );

		WPS_IT::same( '8000', WPS_IT::text( $xp, $node, './p:Prices/p:Price' ) );
		WPS_IT::same( 0, WPS_IT::node_count( $xp, $node, './p:Prices/p:VatPercent' ), 'Stock feeds must not contain VatPercent.' );
		WPS_IT::same( 'true', WPS_IT::text( $xp, $node, './p:Availability/p:Available' ) );

		$variable = $all[ $seed['export_var'] ];

		WPS_IT::same( 2, count( wps_it_variations( $xp, $variable ) ), 'The stock feed lost the variations.' );
	}
);

WPS_IT::test(
	'The stock feed refuses to publish without a successful full feed',
	static function () use ( $seed ): void {
		$full_state = get_option( 'wps_pepita_feed_state_full' );
		$full_path  = Storage::feed_path( Generator::TYPE_FULL );
		$backup     = $full_path . '.it-backup';

		WPS_IT::true( is_array( $full_state ), 'The full feed state is missing before the fail-closed test.' );
		WPS_IT::true( rename( $full_path, $backup ), 'Could not move the full feed aside.' );
		delete_option( 'wps_pepita_feed_state_full' );

		try {
			$result = ( new Generator() )->run( Generator::TYPE_STOCK, array( 'max_runtime' => 0 ) );

			WPS_IT::same( 'error', (string) ( $result['status'] ?? '' ), 'Stock generation should fail without a full feed.' );
			WPS_IT::contains( 'teljes feed', (string) ( $result['message'] ?? '' ), 'The failure message is not the documented one.' );
		} finally {
			rename( $backup, $full_path );
			update_option( 'wps_pepita_feed_state_full', $full_state, false );
		}

		WPS_IT::true( Storage::exists( Generator::TYPE_FULL ), 'The full feed was not restored.' );
		WPS_IT::true( Storage::exists( Generator::TYPE_STOCK ), 'The previous stock feed must survive a failed attempt.' );
	}
);
