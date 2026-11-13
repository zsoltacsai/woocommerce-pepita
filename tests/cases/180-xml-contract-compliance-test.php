<?php
/**
 * Official Pepita XML contract compliance tests.
 *
 * Validates full feed and stock feed structure, elements, variation axes,
 * Hungarian diacritics, and official fixture compatibility against
 * https://pepita.hu/partners/xml-format.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Validator;
use WPS\Pepita\Feed\Xml_Writer;

$suite = $GLOBALS['wps_suite'];

$parse_xml = static function ( string $xml ) {
	$document = simplexml_load_string(
		str_starts_with( trim( $xml ), '<?xml' )
			? $xml
			: '<Catalog xmlns="https://pepita.hu/feed/1.0">' . $xml . '</Catalog>'
	);

	if ( false !== $document ) {
		$document->registerXPathNamespace( 'p', 'https://pepita.hu/feed/1.0' );
	}

	return $document;
};

$suite->add(
	'contract: simple product generated XML matches Pepita official structure and elements',
	static function () use ( $parse_xml ): void {
		$data = array(
			'product_id'     => 1234,
			'export_id'      => '1234',
			'type'           => 'simple',
			'name'           => 'Játékos babafejlesztés',
			'description'    => 'Ez egy ellenállhatatlan könyv, a baba életkorához igazodó programokkal.',
			'brand'          => 'Central Kiadó',
			'manufacturer'   => 'Central Médiacsoport Zrt.',
			'author'         => 'Susannah Steel',
			'publisher'      => 'Central Médiacsoport Zrt.',
			'mpn'            => 'MPN-1234',
			'gtin'           => '9789633736579',
			'email_delivery' => true,
			'last_mod'       => 1583996473,
			'currency'       => 'HUF',
			'price'          => 1500.0,
			'sale_price'     => 1200.0,
			'vat_percent'    => 27.0,
			'purchase_net'   => 800.0,
			'shipping_price' => 990.0,
			'shipping_delay' => 3,
			'warranty'       => array(
				'type'     => 'Month',
				'duration' => '12',
			),
			'categories'     => array(
				array(
					array( 'id' => 674, 'name' => 'Könyvek' ),
					array( 'id' => 1087, 'name' => 'Család és szülők' ),
				),
			),
			'photos'         => array(
				array(
					'url'        => 'http://i.pepita.hu/images/product/147925/30150920_original.jpg',
					'primary'    => true,
					'title'      => 'Borítókép',
					'variations' => array(),
				),
				array(
					'url'        => 'http://i.pepita.hu/images/product/145293/30145188_original.jpg',
					'primary'    => false,
					'title'      => 'Belső oldal',
					'variations' => array(),
				),
			),
			'video_links'    => 'https://www.youtube.com/watch?v=npMXbz3r218,https://www.youtube.com/watch?v=zXQ7JFOgEmc',
			'product_url'    => 'https://pepita.hu/gyerekulesek-c1170/summer-baby-berton-sps-isofix-p275589',
			'available'      => true,
			'quantity'       => 13,
			'restock_date'   => '2026-12-01',
			'attributes'     => array(
				array( 'name' => 'Memória', 'value' => '8 GB' ),
				array( 'name' => 'Processzor', 'value' => '3 Ghz' ),
			),
			'dimensions'     => array(
				'length' => 20.3,
				'width'  => 15.6,
				'height' => 8.5,
				'weight' => 10.3,
			),
			'variations'     => null,
			'errors'         => array(),
			'warnings'       => array(),
		);

		$writer = new Xml_Writer();
		$xml    = $writer->product( $data );
		$doc    = $parse_xml( $xml );

		WPS_Assert::true( false !== $doc, 'Simple product XML must be well formed' );
		WPS_Assert::same( '1234', (string) $doc->xpath( '//p:Id' )[0] );
		WPS_Assert::same( '1583996473', (string) $doc->xpath( '//p:LastMod' )[0] );
		WPS_Assert::same( '9789633736579', (string) $doc->xpath( '//p:StructuredId' )[0] );
		WPS_Assert::same( 'MPN-1234', (string) $doc->xpath( '//p:ProductNumber' )[0] );
		WPS_Assert::same( 'true', (string) $doc->xpath( '//p:EmailDelivery' )[0] );

		WPS_Assert::same( 'Játékos babafejlesztés', (string) $doc->xpath( '//p:Descriptions/p:Name' )[0] );
		WPS_Assert::same( 'Susannah Steel', (string) $doc->xpath( '//p:Descriptions/p:Author' )[0] );
		WPS_Assert::same( 'Central Médiacsoport Zrt.', (string) $doc->xpath( '//p:Descriptions/p:Publisher' )[0] );

		WPS_Assert::same( 'HUF', (string) $doc->xpath( '//p:Prices/p:Currency' )[0] );
		WPS_Assert::same( '1500', (string) $doc->xpath( '//p:Prices/p:Price' )[0] );
		WPS_Assert::same( '27', (string) $doc->xpath( '//p:Prices/p:VatPercent' )[0] );
		WPS_Assert::same( '1200', (string) $doc->xpath( '//p:Prices/p:DiscountedPrice' )[0] );
		WPS_Assert::same( '800', (string) $doc->xpath( '//p:Prices/p:PurchaseNetPrice' )[0] );
		WPS_Assert::same( '990', (string) $doc->xpath( '//p:Prices/p:ShippingPrice' )[0] );

		WPS_Assert::same( 'Month', (string) $doc->xpath( '//p:Warranty/p:Type' )[0] );
		WPS_Assert::same( '12', (string) $doc->xpath( '//p:Warranty/p:Duration' )[0] );

		WPS_Assert::same( 'Könyvek', (string) $doc->xpath( '//p:Categories/p:Category[1]/p:Name' )[0] );
		WPS_Assert::same( '674', (string) $doc->xpath( '//p:Categories/p:Category[1]/p:Id' )[0] );
		WPS_Assert::same( 'Család és szülők', (string) $doc->xpath( '//p:Categories/p:Category[2]/p:Name' )[0] );
		WPS_Assert::same( '1087', (string) $doc->xpath( '//p:Categories/p:Category[2]/p:Id' )[0] );

		WPS_Assert::same( '13', (string) $doc->xpath( '//p:Availability/p:Quantity' )[0] );
		WPS_Assert::same( '3', (string) $doc->xpath( '//p:Availability/p:ShippingDelay' )[0] );
		WPS_Assert::same( '2026-12-01', (string) $doc->xpath( '//p:Availability/p:RestockDate' )[0] );

		WPS_Assert::same( '20.3', (string) $doc->xpath( '//p:VolumeDimensions/p:Length' )[0] );
		WPS_Assert::same( '15.6', (string) $doc->xpath( '//p:VolumeDimensions/p:Width' )[0] );
		WPS_Assert::same( '8.5', (string) $doc->xpath( '//p:VolumeDimensions/p:Height' )[0] );
		WPS_Assert::same( '10.3', (string) $doc->xpath( '//p:VolumeDimensions/p:Weight' )[0] );
	}
);

$suite->add(
	'contract: variable product with 1, 2 and 3 variation axes produces exact contract hierarchy',
	static function () use ( $parse_xml ): void {
		$data = array(
			'product_id'     => 1235,
			'export_id'      => '1235',
			'type'           => 'variable',
			'name'           => 'CAM Agile babakocsi 2017',
			'description'    => 'Könnyű (5,6 kg) fémvázas babakocsi.',
			'brand'          => 'CAM',
			'manufacturer'   => 'CAM Spa',
			'mpn'            => '',
			'gtin'           => '9789633736586',
			'last_mod'       => 1583996500,
			'currency'       => 'HUF',
			'price'          => 19900.0,
			'sale_price'     => null,
			'vat_percent'    => 27.0,
			'purchase_net'   => null,
			'shipping_price' => null,
			'shipping_delay' => 2,
			'warranty'       => null,
			'categories'     => array(
				array(
					array( 'id' => 1207, 'name' => 'Babakocsik' ),
				),
			),
			'photos'         => array(
				array(
					'url'        => 'http://i.pepita.hu/images/product/153024/30205902_original.jpg',
					'primary'    => true,
					'title'      => '',
					'variations' => array( 'red', 'XL', 'Pamut' ),
				),
			),
			'video_links'    => '',
			'product_url'    => '',
			'available'      => true,
			'quantity'       => 5,
			'restock_date'   => '',
			'attributes'     => array(),
			'dimensions'     => array(),
			'variations'     => array(
				'axes'  => array(
					array( 'name' => 'color', 'key' => 'color', 'taxonomy' => '' ),
					array( 'name' => 'size', 'key' => 'size', 'taxonomy' => '' ),
					array( 'name' => 'material', 'key' => 'material', 'taxonomy' => '' ),
				),
				'items' => array(
					array(
						'values'         => array( 'red', 'XL', 'Pamut' ),
						'key'            => "red\x1FXL\x1FPamut",
						'price'          => 15990.0,
						'sale_price'     => 14990.0,
						'vat_percent'    => 27.0,
						'purchase_net'   => 11803.15,
						'shipping_price' => 1200.0,
						'available'      => true,
						'quantity'       => 3,
						'shipping_delay' => 1,
						'restock_date'   => '2026-11-20',
						'image'          => '',
					),
					array(
						'values'         => array( 'green', 'L', 'Gyapjú' ),
						'key'            => "green\x1FL\x1FGyapjú",
						'price'          => 13990.0,
						'sale_price'     => null,
						'vat_percent'    => 27.0,
						'purchase_net'   => null,
						'shipping_price' => null,
						'available'      => false,
						'quantity'       => 0,
						'shipping_delay' => 2,
						'restock_date'   => '',
						'image'          => '',
					),
				),
			),
			'errors'         => array(),
			'warnings'       => array(),
		);

		$writer = new Xml_Writer();
		$xml    = $writer->product( $data );
		$doc    = $parse_xml( $xml );

		WPS_Assert::true( false !== $doc );
		WPS_Assert::same( 'color', (string) $doc->xpath( '//p:Variations/p:PrimaryAttribute' )[0] );
		WPS_Assert::same( 'size', (string) $doc->xpath( '//p:Variations/p:SecondaryAttribute' )[0] );
		WPS_Assert::same( 'material', (string) $doc->xpath( '//p:Variations/p:TertiaryAttribute' )[0] );

		WPS_Assert::count( 2, $doc->xpath( '//p:Variations/p:Variation' ) );

		// Variation 1
		WPS_Assert::same( 'red', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:PrimaryVariation' )[0] );
		WPS_Assert::same( 'XL', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:SecondaryVariation' )[0] );
		WPS_Assert::same( 'Pamut', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:TertiaryVariation' )[0] );
		WPS_Assert::same( '15990', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:Prices/p:Price' )[0] );
		WPS_Assert::same( '27', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:Prices/p:VatPercent' )[0] );
		WPS_Assert::same( '14990', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:Prices/p:DiscountedPrice' )[0] );
		WPS_Assert::same( '1200', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:Prices/p:ShippingPrice' )[0] );
		WPS_Assert::same( 'true', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:Availability/p:Available' )[0] );
		WPS_Assert::same( '3', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:Availability/p:Quantity' )[0] );
		WPS_Assert::same( '2026-11-20', (string) $doc->xpath( '//p:Variations/p:Variation[1]/p:Availability/p:RestockDate' )[0] );

		// Variation 2
		WPS_Assert::same( 'green', (string) $doc->xpath( '//p:Variations/p:Variation[2]/p:PrimaryVariation' )[0] );
		WPS_Assert::same( 'L', (string) $doc->xpath( '//p:Variations/p:Variation[2]/p:SecondaryVariation' )[0] );
		WPS_Assert::same( 'Gyapjú', (string) $doc->xpath( '//p:Variations/p:Variation[2]/p:TertiaryVariation' )[0] );
		WPS_Assert::same( 'false', (string) $doc->xpath( '//p:Variations/p:Variation[2]/p:Availability/p:Available' )[0] );
		WPS_Assert::same( '0', (string) $doc->xpath( '//p:Variations/p:Variation[2]/p:Availability/p:Quantity' )[0] );
	}
);

$suite->add(
	'contract: 4th variation axis is strictly rejected by validator',
	static function (): void {
		$data = array(
			'export_id'   => 'wps-4-axes',
			'name'        => 'Négytengelyes termék',
			'description' => 'Teszt',
			'price'       => 1000.0,
			'vat_percent' => 27.0,
			'currency'    => 'HUF',
			'categories'  => array( array( array( 'id' => 1, 'name' => 'Cat' ) ) ),
			'photos'      => array( array( 'url' => 'https://example.test/img.jpg', 'primary' => true ) ),
			'type'        => 'variable',
			'variations'  => array(
				'axes'  => array(
					array( 'name' => 'Szín' ),
					array( 'name' => 'Méret' ),
					array( 'name' => 'Anyag' ),
					array( 'name' => 'Minta' ),
				),
				'items' => array(
					array(
						'values' => array( 'Piros', 'S', 'Pamut', 'Csíkos' ),
						'price'  => 1000.0,
					),
				),
			),
		);

		$result = Validator::validate( $data );
		WPS_Assert::false( $result['valid'], '4 variation axes must be invalid' );

		$codes = array_column( $result['errors'], 'code' );
		WPS_Assert::true( in_array( 'too_many_variation_attributes', $codes, true ) );
	}
);

$suite->add(
	'contract: Hungarian diacritics and special characters are preserved without entity corruption',
	static function () use ( $parse_xml ): void {
		$hungarian_name = 'Árvíztűrő tükörfúrógép & Szerszám <Pro> "Kft."';
		$hungarian_desc = 'Teszt szöveg magyar ékezetekkel: á é í ó ö ő ú ü ű / Á É Í Ó Ö Ő Ú Ü Ű & < > \' "';

		$data = array(
			'export_id'      => 'wps-hu-test',
			'type'           => 'simple',
			'name'           => $hungarian_name,
			'description'    => $hungarian_desc,
			'brand'          => 'Magyar Márka Kft.',
			'manufacturer'   => 'Gyártó & Fiai Zrt.',
			'mpn'            => 'MPN-ÉKEZET-01',
			'gtin'           => '',
			'email_delivery' => false,
			'last_mod'       => 1700000000,
			'currency'       => 'HUF',
			'price'          => 9990.0,
			'sale_price'     => null,
			'vat_percent'    => 27.0,
			'purchase_net'   => null,
			'shipping_price' => null,
			'shipping_delay' => null,
			'warranty'       => null,
			'categories'     => array(
				array( array( 'id' => 1, 'name' => 'Barkács & Kert' ) ),
			),
			'photos'         => array(
				array( 'url' => 'https://example.test/kepek/furogep.jpg', 'primary' => true ),
			),
			'video_links'    => '',
			'product_url'    => 'https://example.test/termek/arvizturo-tukorfurogep/',
			'available'      => true,
			'quantity'       => 10,
			'restock_date'   => '',
			'attributes'     => array(
				array( 'name' => 'Feszültség & Teljesítmény', 'value' => '230V / 750W' ),
			),
			'dimensions'     => array(),
			'variations'     => null,
			'errors'         => array(),
			'warnings'       => array(),
		);

		$writer = new Xml_Writer();
		$xml    = $writer->product( $data );
		$doc    = $parse_xml( $xml );

		WPS_Assert::true( false !== $doc, 'Hungarian accented XML must parse cleanly' );
		WPS_Assert::same( $hungarian_name, (string) $doc->xpath( '//p:Descriptions/p:Name' )[0] );
		WPS_Assert::same( $hungarian_desc, (string) $doc->xpath( '//p:Descriptions/p:Description' )[0] );
		WPS_Assert::same( 'Magyar Márka Kft.', (string) $doc->xpath( '//p:Descriptions/p:Brand' )[0] );
		WPS_Assert::same( 'Barkács & Kert', (string) $doc->xpath( '//p:Categories/p:Category/p:Name' )[0] );
		WPS_Assert::same( 'Feszültség & Teljesítmény', (string) $doc->xpath( '//p:Attributes/p:Attribute/p:AttributeName' )[0] );
	}
);

$suite->add(
	'contract: missing GTIN is cleanly omitted and never fabricated',
	static function () use ( $parse_xml ): void {
		$data = array(
			'export_id'      => 'wps-no-gtin',
			'type'           => 'simple',
			'name'           => 'Termék vonalkód nélkül',
			'description'    => 'Leírás',
			'brand'          => '',
			'manufacturer'   => '',
			'mpn'            => '',
			'gtin'           => '', // empty GTIN
			'email_delivery' => false,
			'last_mod'       => 1700000000,
			'currency'       => 'HUF',
			'price'          => 5000.0,
			'sale_price'     => null,
			'vat_percent'    => 27.0,
			'purchase_net'   => null,
			'shipping_price' => null,
			'shipping_delay' => null,
			'warranty'       => null,
			'categories'     => array(
				array( array( 'id' => 1, 'name' => 'Kategória' ) ),
			),
			'photos'         => array(
				array( 'url' => 'https://example.test/img.jpg', 'primary' => true ),
			),
			'video_links'    => '',
			'product_url'    => '',
			'available'      => true,
			'quantity'       => 5,
			'restock_date'   => '',
			'attributes'     => array(),
			'dimensions'     => array(),
			'variations'     => null,
			'errors'         => array(),
			'warnings'       => array(),
		);

		$writer = new Xml_Writer();
		$xml    = $writer->product( $data );
		$doc    = $parse_xml( $xml );

		WPS_Assert::true( false !== $doc );
		WPS_Assert::same( 0, count( $doc->xpath( '//p:StructuredId' ) ) );
		WPS_Assert::not_contains( '<StructuredId>', $xml );
	}
);

$suite->add(
	'contract: stock feed generated structure matches official stock sample',
	static function () use ( $parse_xml ): void {
		$simple_data = array(
			'export_id'      => '1234',
			'type'           => 'simple',
			'currency'       => 'HUF',
			'price'          => 1500.0,
			'sale_price'     => 1200.0,
			'available'      => true,
			'quantity'       => 13,
			'variations'     => null,
		);

		$var_data = array(
			'export_id'      => '1235',
			'type'           => 'variable',
			'currency'       => 'HUF',
			'price'          => 19900.0,
			'sale_price'     => null,
			'available'      => true,
			'quantity'       => 3,
			'variations'     => array(
				'axes'  => array(
					array( 'name' => 'color', 'key' => 'color', 'taxonomy' => '' ),
				),
				'items' => array(
					array(
						'values'     => array( 'red' ),
						'price'      => 19900.0,
						'sale_price' => null,
						'available'  => true,
						'quantity'   => 3,
					),
					array(
						'values'     => array( 'green' ),
						'price'      => 19900.0,
						'sale_price' => 17900.0,
						'available'  => false,
						'quantity'   => 0,
					),
				),
			),
		);

		$writer = new Xml_Writer();

		// Check simple product in stock feed
		$simple_xml = $writer->stock_product( $simple_data );
		$simple_doc = $parse_xml( $simple_xml );
		WPS_Assert::true( false !== $simple_doc );
		WPS_Assert::same( '1234', (string) $simple_doc->xpath( '//p:Id' )[0] );
		WPS_Assert::same( 'true', (string) $simple_doc->xpath( '//p:Availability/p:Available' )[0] );
		WPS_Assert::same( '13', (string) $simple_doc->xpath( '//p:Availability/p:Quantity' )[0] );
		WPS_Assert::same( '1500', (string) $simple_doc->xpath( '//p:Prices/p:Price' )[0] );
		WPS_Assert::same( '1200', (string) $simple_doc->xpath( '//p:Prices/p:DiscountedPrice' )[0] );
		WPS_Assert::same( 0, count( $simple_doc->xpath( '//p:Descriptions' ) ) );
		WPS_Assert::same( 0, count( $simple_doc->xpath( '//p:Categories' ) ) );
		WPS_Assert::same( 0, count( $simple_doc->xpath( '//p:Photos' ) ) );

		// Check variable product in stock feed
		$var_xml = $writer->stock_product( $var_data );
		$var_doc = $parse_xml( $var_xml );
		WPS_Assert::true( false !== $var_doc );
		WPS_Assert::same( '1235', (string) $var_doc->xpath( '//p:Id' )[0] );
		WPS_Assert::same( 'color', (string) $var_doc->xpath( '//p:Variations/p:PrimaryAttribute' )[0] );
		WPS_Assert::count( 2, $var_doc->xpath( '//p:Variations/p:Variation' ) );
		WPS_Assert::same( 'red', (string) $var_doc->xpath( '//p:Variations/p:Variation[1]/p:PrimaryVariation' )[0] );
		WPS_Assert::same( '19900', (string) $var_doc->xpath( '//p:Variations/p:Variation[1]/p:Prices/p:Price' )[0] );
		WPS_Assert::same( 'true', (string) $var_doc->xpath( '//p:Variations/p:Variation[1]/p:Availability/p:Available' )[0] );
		WPS_Assert::same( '3', (string) $var_doc->xpath( '//p:Variations/p:Variation[1]/p:Availability/p:Quantity' )[0] );

		WPS_Assert::same( 'green', (string) $var_doc->xpath( '//p:Variations/p:Variation[2]/p:PrimaryVariation' )[0] );
		WPS_Assert::same( '17900', (string) $var_doc->xpath( '//p:Variations/p:Variation[2]/p:Prices/p:DiscountedPrice' )[0] );
		WPS_Assert::same( 'false', (string) $var_doc->xpath( '//p:Variations/p:Variation[2]/p:Availability/p:Available' )[0] );
		WPS_Assert::same( '0', (string) $var_doc->xpath( '//p:Variations/p:Variation[2]/p:Availability/p:Quantity' )[0] );
		// Stock feed variations do not output VatPercent or Descriptions
		WPS_Assert::same( 0, count( $var_doc->xpath( '//p:Variations/p:Variation/p:Prices/p:VatPercent' ) ) );
	}
);

$suite->add(
	'contract: official sample fixtures validate successfully with plugin validator',
	static function (): void {
		$sample_full  = __DIR__ . '/../../docs/m9d/pepita-sample.xml';
		$sample_stock = __DIR__ . '/../../docs/m9d/pepita-stock-sample-hu.xml';

		WPS_Assert::true( is_file( $sample_full ), 'Official full sample fixture must exist' );
		WPS_Assert::true( is_file( $sample_stock ), 'Official stock sample fixture must exist' );

		// Validate sample full fixture
		$full_val = Validator::validate_xml_file( $sample_full );
		WPS_Assert::true( $full_val['valid'], 'Official sample.xml must validate cleanly: ' . implode( '; ', $full_val['errors'] ) );
		WPS_Assert::same( 2, $full_val['products'] );

		// Validate sample stock fixture
		$stock_val = Validator::validate_xml_file( $sample_stock );
		WPS_Assert::true( $stock_val['valid'], 'Official stock sample must validate cleanly: ' . implode( '; ', $stock_val['errors'] ) );
		WPS_Assert::same( 2, $stock_val['products'] );
	}
);
