<?php
/**
 * Feed validation: per product rules and generated XML file checks.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Validator;

$suite = $GLOBALS['wps_suite'];

/**
 * @return array<string, mixed>
 */
$valid_product = static function ( array $overrides = array() ): array {
	return array_merge(
		array(
			'product_id'  => 1,
			'export_id'   => 'wps-1',
			'type'        => 'simple',
			'name'        => 'Teszt termék',
			'description' => 'Leírás',
			'currency'    => 'HUF',
			'price'       => 1000.0,
			'vat_percent' => 27.0,
			'categories'  => array( array( array( 'id' => 10, 'name' => 'Kategória' ) ) ),
			'photos'      => array(
				array(
					'url'     => 'https://example.test/wp-content/uploads/a.jpg',
					'primary' => true,
				),
			),
			'errors'      => array(),
			'warnings'    => array(),
		),
		$overrides
	);
};

/**
 * @param array<string, mixed> $data
 * @return string[]
 */
$codes = static function ( array $data ): array {
	return array_column( Validator::validate( $data )['errors'], 'code' );
};

$suite->add(
	'validator: a complete product passes without errors or warnings',
	static function () use ( $valid_product ): void {
		$result = Validator::validate( $valid_product() );

		WPS_Assert::same( true, $result['valid'] );
		WPS_Assert::count( 0, $result['errors'] );
		WPS_Assert::count( 0, $result['warnings'] );
	}
);

$suite->add(
	'validator: identifier, name, description, price, VAT and currency are required',
	static function () use ( $valid_product, $codes ): void {
		$data = $valid_product(
			array(
				'export_id'   => '',
				'name'        => '   ',
				'description' => '',
				'price'       => 0,
				'vat_percent' => 101,
				'currency'    => 'forint',
			)
		);

		$found = $codes( $data );

		foreach ( array( 'missing_identifier', 'missing_name', 'missing_description', 'invalid_price', 'invalid_vat', 'invalid_currency' ) as $code ) {
			WPS_Assert::true( in_array( $code, $found, true ), 'Missing error code: ' . $code );
		}

		WPS_Assert::same( false, Validator::validate( $data )['valid'] );
	}
);

$suite->add(
	'validator: over long identifiers are rejected, long names only warn',
	static function () use ( $valid_product, $codes ): void {
		$data = $valid_product(
			array(
				'export_id' => str_repeat( 'x', 192 ),
				'name'      => str_repeat( 'N', 501 ),
			)
		);

		$result = Validator::validate( $data );

		WPS_Assert::true( in_array( 'identifier_too_long', array_column( $result['errors'], 'code' ), true ) );
		WPS_Assert::true( in_array( 'long_name', array_column( $result['warnings'], 'code' ), true ) );
	}
);

$suite->add(
	'validator: category and image are mandatory and image URLs are checked',
	static function () use ( $valid_product, $codes ): void {
		$found = $codes( $valid_product( array( 'categories' => array(), 'photos' => array() ) ) );

		WPS_Assert::true( in_array( 'missing_category', $found, true ) );
		WPS_Assert::true( in_array( 'missing_image', $found, true ) );

		$found = $codes(
			$valid_product(
				array(
					'photos' => array(
						array( 'url' => 'not-a-url', 'primary' => true ),
						array( 'url' => 'ftp://example.test/a.jpg', 'primary' => false ),
					),
				)
			)
		);

		WPS_Assert::true( in_array( 'invalid_image_url', $found, true ) );
	}
);

$suite->add(
	'validator: a missing primary image is a warning, not an error',
	static function () use ( $valid_product ): void {
		$data = $valid_product(
			array(
				'photos' => array(
					array( 'url' => 'https://example.test/a.jpg', 'primary' => false ),
				),
			)
		);

		$result = Validator::validate( $data );

		WPS_Assert::same( true, $result['valid'] );
		WPS_Assert::true( in_array( 'no_primary_image', array_column( $result['warnings'], 'code' ), true ) );
	}
);

$suite->add(
	'validator: invalid video URLs produce warnings only',
	static function () use ( $valid_product ): void {
		$result = Validator::validate(
			$valid_product(
				array( 'video_links' => 'https://www.youtube.com/watch?v=abc, javascript:alert(1)' )
			)
		);

		WPS_Assert::same( true, $result['valid'] );
		WPS_Assert::true( in_array( 'invalid_video_url', array_column( $result['warnings'], 'code' ), true ) );
	}
);

$suite->add(
	'validator: malformed GTIN is an error and valid lengths pass',
	static function () use ( $valid_product, $codes ): void {
		WPS_Assert::same( true, Validator::validate( $valid_product( array( 'gtin' => '5901234123457' ) ) )['valid'] );
		WPS_Assert::true( in_array( 'invalid_gtin', $codes( $valid_product( array( 'gtin' => '12345' ) ) ), true ) );
		WPS_Assert::true( in_array( 'invalid_gtin', $codes( $valid_product( array( 'gtin' => '59012341234' ) ) ), true ) );
	}
);

$suite->add(
	'validator: variable products require axes, values and unique combinations',
	static function () use ( $valid_product, $codes ): void {
		$axes = array(
			array(
				'name'     => 'Szín',
				'key'      => 'pa_color',
				'taxonomy' => 'pa_color',
			),
		);

		$found = $codes( $valid_product( array( 'type' => 'variable', 'variations' => null ) ) );
		WPS_Assert::true( in_array( 'missing_variations', $found, true ) );

		$found = $codes( $valid_product( array( 'type' => 'variable', 'variations' => array( 'axes' => array(), 'items' => array() ) ) ) );
		WPS_Assert::true( in_array( 'invalid_variation_structure', $found, true ) );

		$found = $codes(
			$valid_product(
				array(
					'type'       => 'variable',
					'variations' => array( 'axes' => $axes, 'items' => array() ),
				)
			)
		);
		WPS_Assert::true( in_array( 'no_published_variations', $found, true ) );

		$item = array(
			'values'    => array( 'Piros' ),
			'price'     => 1000.0,
			'available' => true,
			'quantity'  => 1,
		);

		$found = $codes(
			$valid_product(
				array(
					'type'       => 'variable',
					'variations' => array(
						'axes'  => $axes,
						'items' => array( $item, $item ),
					),
				)
			)
		);
		WPS_Assert::true( in_array( 'duplicate_variation', $found, true ) );

		$found = $codes(
			$valid_product(
				array(
					'type'       => 'variable',
					'variations' => array(
						'axes'  => $axes,
						'items' => array(
							array(
								'values' => array( 'Piros', 'Extra' ),
								'price'  => 1000.0,
							),
						),
					),
				)
			)
		);
		WPS_Assert::true( in_array( 'invalid_variation_structure', $found, true ) );

		$found = $codes(
			$valid_product(
				array(
					'type'       => 'variable',
					'variations' => array(
						'axes'  => $axes,
						'items' => array(
							array(
								'values' => array( 'Piros' ),
								'price'  => 0,
							),
						),
					),
				)
			)
		);
		WPS_Assert::true( in_array( 'invalid_variation_price', $found, true ) );
	}
);

$suite->add(
	'validator: more than three variation axes are rejected',
	static function () use ( $valid_product, $codes ): void {
		$axes = array();

		for ( $i = 1; $i <= 4; $i++ ) {
			$axes[] = array(
				'name'     => 'Attr ' . $i,
				'key'      => 'pa_attr' . $i,
				'taxonomy' => 'pa_attr' . $i,
			);
		}

		$found = $codes(
			$valid_product(
				array(
					'type'       => 'variable',
					'variations' => array(
						'axes'  => $axes,
						'items' => array(
							array(
								'values' => array( 'a', 'b', 'c', 'd' ),
								'price'  => 100.0,
							),
						),
					),
				)
			)
		);

		WPS_Assert::true( in_array( 'too_many_variation_attributes', $found, true ) );
	}
);

$suite->add(
	'validator: image URL helper accepts uploads and common extensions',
	static function (): void {
		WPS_Assert::true( Validator::is_valid_image_url( 'https://example.test/wp-content/uploads/2026/01/a.jpg' ) );
		WPS_Assert::true( Validator::is_valid_image_url( 'https://cdn.example.test/a.webp?x=1' ) );
		WPS_Assert::false( Validator::is_valid_image_url( 'https://example.test/a.pdf' ) );
		WPS_Assert::false( Validator::is_valid_image_url( 'https://example.test/a b.jpg' ) );
		WPS_Assert::false( Validator::is_valid_image_url( '/wp-content/uploads/a.jpg' ) );
	}
);

$suite->add(
	'validator: generated XML file is checked for structure, namespace and duplicates',
	static function (): void {
		$path = WP_CONTENT_DIR . '/feed.xml';

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<Catalog xmlns="https://pepita.hu/feed/1.0">' . "\n"
			. '  <Product><Id>wps-1</Id></Product>' . "\n"
			. '  <Product><Id>wps-2</Id></Product>' . "\n"
			. '</Catalog>' . "\n";

		file_put_contents( $path, $xml );

		$result = Validator::validate_xml_file( $path );

		WPS_Assert::same( true, $result['valid'] );
		WPS_Assert::same( 2, $result['products'] );

		file_put_contents(
			$path,
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<Catalog xmlns="https://pepita.hu/feed/1.0">' . "\n"
			. '  <Product><Id>wps-1</Id></Product>' . "\n"
			. '  <Product><Id>wps-1</Id></Product>' . "\n"
			. '</Catalog>' . "\n"
		);

		$result = Validator::validate_xml_file( $path );

		WPS_Assert::same( false, $result['valid'] );
		WPS_Assert::same( 2, $result['products'] );
	}
);

$suite->add(
	'validator: malformed XML, wrong root and missing files are rejected',
	static function (): void {
		$path = WP_CONTENT_DIR . '/feed.xml';

		file_put_contents( $path, '<?xml version="1.0"?><Catalog xmlns="https://pepita.hu/feed/1.0"><Product>' );

		WPS_Assert::same( false, Validator::validate_xml_file( $path )['valid'] );

		file_put_contents( $path, '<?xml version="1.0"?><Feed xmlns="https://pepita.hu/feed/1.0"></Feed>' );

		$result = Validator::validate_xml_file( $path );
		WPS_Assert::same( false, $result['valid'] );
		WPS_Assert::true( in_array( 'A gyökér elem nem <Catalog>.', $result['errors'], true ) );

		file_put_contents( $path, '<?xml version="1.0"?><Catalog xmlns="urn:wrong"></Catalog>' );

		$result = Validator::validate_xml_file( $path );
		WPS_Assert::same( false, $result['valid'] );

		WPS_Assert::same( false, Validator::validate_xml_file( WP_CONTENT_DIR . '/missing.xml' )['valid'] );
	}
);
