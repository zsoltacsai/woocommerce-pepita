<?php
/**
 * XML output: structure, namespace, optional elements and stock feed shape.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Feed\Xml_Writer;

$suite = $GLOBALS['wps_suite'];

/**
 * Parse a product fragment in the context of the real feed root element so
 * that the default Pepita namespace applies, exactly as it does on the wire.
 *
 * @return \SimpleXMLElement|false
 */
$parse = static function ( string $xml ) {
	$document = simplexml_load_string(
		'<Catalog xmlns="https://pepita.hu/feed/1.0">' . $xml . '</Catalog>'
	);

	if ( false !== $document ) {
		$document->registerXPathNamespace( 'p', 'https://pepita.hu/feed/1.0' );
	}

	return $document;
};

/**
 * @return array<string, mixed>
 */
$product_data = static function ( array $overrides = array() ): array {
	return array_merge(
		array(
			'product_id'     => 1,
			'export_id'      => 'wps-1',
			'type'           => 'simple',
			'name'           => 'Teszt termék',
			'description'    => "Első sor\nMásodik sor",
			'brand'          => 'Márka',
			'manufacturer'   => 'Gyártó',
			'mpn'            => 'MPN-1',
			'gtin'           => '5901234123457',
			'last_mod'       => 1700000000,
			'currency'       => 'HUF',
			'price'          => 12700.0,
			'sale_price'     => 9900.0,
			'vat_percent'    => 27.0,
			'purchase_net'   => 10000.0,
			'shipping_price' => 1590.0,
			'shipping_delay' => 2,
			'warranty'       => array(
				'type'     => 'Year',
				'duration' => '2',
			),
			'categories'     => array(
				array(
					array( 'id' => 10, 'name' => 'Root' ),
					array( 'id' => 11, 'name' => 'Child' ),
				),
			),
			'photos'         => array(
				array(
					'url'        => 'https://example.test/wp-content/uploads/a.jpg',
					'primary'    => true,
					'title'      => '',
					'variations' => array(),
				),
			),
			'video_links'    => 'https://www.youtube.com/watch?v=abc',
			'product_url'    => 'https://example.test/product/teszt/',
			'available'      => true,
			'quantity'       => 12,
			'restock_date'   => '2026-01-10',
			'attributes'     => array(
				array(
					'name'  => 'Szín',
					'value' => 'Piros',
				),
			),
			'dimensions'     => array(
				'width'  => 10.0,
				'height' => 20.0,
				'length' => 30.0,
				'weight' => 1.5,
			),
			'variations'     => null,
			'errors'         => array(),
			'warnings'       => array(),
		),
		$overrides
	);
};

$suite->add(
	'xml: full product contains the documented structure and namespace',
	static function () use ( $product_data, $parse ): void {
		$xml = ( new Xml_Writer() )->product( $product_data() );

		WPS_Assert::contains( '<?xml version="1.0" encoding="UTF-8"?>', ( new Xml_Writer() )->header() );
		WPS_Assert::contains( 'xmlns="https://pepita.hu/feed/1.0"', ( new Xml_Writer() )->header() );

		$document = $parse( $xml );

		WPS_Assert::true( false !== $document, 'Generated XML is not well formed.' );

		WPS_Assert::same( 'wps-1', (string) $document->xpath( '//p:Id' )[0] );
		WPS_Assert::same( '1700000000', (string) $document->xpath( '//p:LastMod' )[0] );
		WPS_Assert::same( '5901234123457', (string) $document->xpath( '//p:StructuredId' )[0] );
		WPS_Assert::same( 'MPN-1', (string) $document->xpath( '//p:ProductNumber' )[0] );
		WPS_Assert::same( 'Teszt termék', (string) $document->xpath( '//p:Descriptions/p:Name' )[0] );
		WPS_Assert::same( 'Márka', (string) $document->xpath( '//p:Descriptions/p:Brand' )[0] );
		WPS_Assert::same( 'HUF', (string) $document->xpath( '//p:Prices/p:Currency' )[0] );
		WPS_Assert::same( '12700', (string) $document->xpath( '//p:Prices/p:Price' )[0] );
		WPS_Assert::same( '9900', (string) $document->xpath( '//p:Prices/p:DiscountedPrice' )[0] );
		WPS_Assert::same( '27', (string) $document->xpath( '//p:Prices/p:VatPercent' )[0] );
		WPS_Assert::same( '10000', (string) $document->xpath( '//p:Prices/p:PurchaseNetPrice' )[0] );
		WPS_Assert::same( '1590', (string) $document->xpath( '//p:Prices/p:ShippingPrice' )[0] );
		WPS_Assert::same( 'Root', (string) $document->xpath( '//p:Categories/p:Category[1]/p:Name' )[0] );
		WPS_Assert::same( 'Child', (string) $document->xpath( '//p:Categories/p:Category[2]/p:Name' )[0] );
		WPS_Assert::same( 'true', (string) $document->xpath( '//p:Photos/p:Photo/p:IsPrimary' )[0] );
		WPS_Assert::same( 'true', (string) $document->xpath( '//p:Availability/p:Available' )[0] );
		WPS_Assert::same( '12', (string) $document->xpath( '//p:Availability/p:Quantity' )[0] );
		WPS_Assert::same( '2', (string) $document->xpath( '//p:Availability/p:ShippingDelay' )[0] );
		WPS_Assert::same( '2026-01-10', (string) $document->xpath( '//p:Availability/p:RestockDate' )[0] );
		WPS_Assert::same( 'Year', (string) $document->xpath( '//p:Warranty/p:Type' )[0] );
		WPS_Assert::same( 'Szín', (string) $document->xpath( '//p:Attributes/p:Attribute/p:AttributeName' )[0] );
		WPS_Assert::same( '10', (string) $document->xpath( '//p:VolumeDimensions/p:Width' )[0] );
	}
);

$suite->add(
	'xml: optional elements are omitted when empty',
	static function () use ( $product_data ): void {
		$data = $product_data(
			array(
				'gtin'           => '',
				'mpn'            => '',
				'brand'          => '',
				'manufacturer'   => '',
				'sale_price'     => null,
				'purchase_net'   => null,
				'shipping_price' => null,
				'video_links'    => '',
				'product_url'    => '',
				'warranty'       => null,
				'attributes'     => array(),
				'dimensions'     => array(),
				'restock_date'   => '',
				'quantity'       => null,
			)
		);

		$xml = ( new Xml_Writer() )->product( $data );

		WPS_Assert::not_contains( '<StructuredId>', $xml );
		WPS_Assert::not_contains( '<ProductNumber>', $xml );
		WPS_Assert::not_contains( '<Brand>', $xml );
		WPS_Assert::not_contains( '<DiscountedPrice>', $xml );
		WPS_Assert::not_contains( '<PurchaseNetPrice>', $xml );
		WPS_Assert::not_contains( '<ShippingPrice>', $xml );
		WPS_Assert::not_contains( '<VideoLinks>', $xml );
		WPS_Assert::not_contains( '<ProductUrl>', $xml );
		WPS_Assert::not_contains( '<Warranty>', $xml );
		WPS_Assert::not_contains( '<Attributes>', $xml );
		WPS_Assert::not_contains( '<VolumeDimensions>', $xml );
		WPS_Assert::not_contains( '<Quantity>', $xml );
		WPS_Assert::not_contains( '<RestockDate>', $xml );
	}
);

$suite->add(
	'xml: text values are escaped and illegal characters removed',
	static function () use ( $product_data, $parse ): void {
		$data = $product_data(
			array(
				'name'        => 'A & B <C> "D"',
				'description' => "Control\x01char",
			)
		);

		$xml      = ( new Xml_Writer() )->product( $data );
		$document = $parse( $xml );

		WPS_Assert::true( false !== $document );

		WPS_Assert::same( 'A & B <C> "D"', (string) $document->xpath( '//p:Descriptions/p:Name' )[0] );
		WPS_Assert::same( 'Controlchar', (string) $document->xpath( '//p:Descriptions/p:Description' )[0] );
	}
);

$suite->add(
	'xml: variable product writes axes, variations and per variation prices',
	static function () use ( $product_data, $parse ): void {
		$data = $product_data(
			array(
				'type'       => 'variable',
				'price'      => 5000.0,
				'vat_percent' => 27.0,
				'variations' => array(
					'axes'  => array(
						array(
							'name'     => 'Szín',
							'key'      => 'pa_color',
							'taxonomy' => 'pa_color',
						),
						array(
							'name'     => 'Méret',
							'key'      => 'pa_size',
							'taxonomy' => 'pa_size',
						),
					),
					'items' => array(
						array(
							'values'         => array( 'Piros', 'S' ),
							'key'            => "Piros\x1FS",
							'price'          => 5000.0,
							'sale_price'     => 4500.0,
							'available'      => true,
							'quantity'       => 3,
							'shipping_delay' => 2,
							'image'          => '',
						),
						array(
							'values'         => array( 'Piros', 'M' ),
							'key'            => "Piros\x1FM",
							'price'          => 5200.0,
							'sale_price'     => null,
							'available'      => false,
							'quantity'       => 0,
							'shipping_delay' => 2,
							'image'          => '',
						),
					),
				),
			)
		);

		$xml = ( new Xml_Writer() )->product( $data );

		$document = $parse( $xml );
		WPS_Assert::true( false !== $document );

		WPS_Assert::same( 'Szín', (string) $document->xpath( '//p:Variations/p:PrimaryAttribute' )[0] );
		WPS_Assert::same( 'Méret', (string) $document->xpath( '//p:Variations/p:SecondaryAttribute' )[0] );
		WPS_Assert::count( 2, $document->xpath( '//p:Variations/p:Variation' ) );
		WPS_Assert::same( 'Piros', (string) $document->xpath( '//p:Variations/p:Variation[1]/p:PrimaryVariation' )[0] );
		WPS_Assert::same( 'S', (string) $document->xpath( '//p:Variations/p:Variation[1]/p:SecondaryVariation' )[0] );
		WPS_Assert::same( '4500', (string) $document->xpath( '//p:Variations/p:Variation[1]/p:Prices/p:DiscountedPrice' )[0] );
		WPS_Assert::same( 'false', (string) $document->xpath( '//p:Variations/p:Variation[2]/p:Availability/p:Available' )[0] );
		WPS_Assert::same( '0', (string) $document->xpath( '//p:Variations/p:Variation[2]/p:Availability/p:Quantity' )[0] );
	}
);

$suite->add(
	'xml: stock feed contains only Id, Availability, Prices and Variations',
	static function () use ( $product_data ): void {
		$data = $product_data(
			array(
				'type'       => 'variable',
				'variations' => array(
					'axes'  => array(
						array(
							'name'     => 'Szín',
							'key'      => 'pa_color',
							'taxonomy' => 'pa_color',
						),
					),
					'items' => array(
						array(
							'values'         => array( 'Piros' ),
							'key'            => 'Piros',
							'price'          => 5000.0,
							'sale_price'     => null,
							'available'      => true,
							'quantity'       => 3,
							'shipping_delay' => null,
							'image'          => '',
						),
					),
				),
			)
		);

		$xml = ( new Xml_Writer() )->stock_product( $data );

		WPS_Assert::contains( '<Id>wps-1</Id>', $xml );
		WPS_Assert::contains( '<Availability>', $xml );
		WPS_Assert::contains( '<Prices>', $xml );
		WPS_Assert::contains( '<Variations>', $xml );
		WPS_Assert::not_contains( '<Descriptions>', $xml );
		WPS_Assert::not_contains( '<VatPercent>', $xml );
		WPS_Assert::not_contains( '<Categories>', $xml );
		WPS_Assert::not_contains( '<Photos>', $xml );
		WPS_Assert::not_contains( '<ShippingPrice>', $xml );
	}
);
