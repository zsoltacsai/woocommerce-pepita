<?php
/**
 * Product to feed data mapping (simple and variable products).
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/**
 * Prepare category 10 and a featured image.
 */
$prepare = static function (): void {
	WPS_Test_State::add_term( 10, 'Kategória' );
	WPS_Test_State::add_attachment( 500, 'https://example.test/wp-content/uploads/termek.jpg' );
};

$simple = static function ( int $id = 301, array $overrides = array() ): WC_Product {
	return WPS_Test_State::add_product(
		array_merge(
			array(
				'id'                => $id,
				'name'              => 'Termék',
				'description'       => '<p>Leírás</p>',
				'short_description' => 'Rövid leírás',
				'price'             => '1000',
				'sku'               => 'SKU-' . $id,
				'image_id'          => 500,
			),
			$overrides
		)
	);
};

$suite->add(
	'mapper: reusable Pepita attribute-name aliases change only the mapped attribute label',
	static function () use ( $prepare ): void {
		$prepare();
		WPS_Test_State::add_attribute_label( 'pa_color', 'Szín' );
		WPS_Test_State::add_attribute_label( 'pa_size', 'Méret' );
		WPS_Test_State::add_term( 351, 'Piros', 0, 'pa_color' );
		WPS_Test_State::add_term( 352, 'L', 0, 'pa_size' );
		Settings::update(
			array(
				'attribute_name_map' => "pa_color=Pepita szín\nAnyag=Anyagösszetétel\npa_size=Méret (Pepita)\ninvalid-rule\n=Üres forrás\npa_empty=",
			)
		);
		$product = WPS_Test_State::add_product(
			array(
				'id'          => 350,
				'name'        => 'Attribútumos termék',
				'description' => 'Leírás',
				'price'       => '1200',
				'image_id'    => 500,
				'attributes'  => array(
					new WC_Product_Attribute(
						array(
							'name'      => 'pa_color',
							'options'   => array( 'piros' ),
							'taxonomy'  => true,
							'variation' => false,
						)
					),
					new WC_Product_Attribute(
						array(
							'name'      => 'Anyag',
							'options'   => array( '100% pamut' ),
							'taxonomy'  => false,
							'variation' => false,
						)
					),
					new WC_Product_Attribute(
						array(
							'name'      => 'Garancia',
							'options'   => array( '2 év' ),
							'taxonomy'  => false,
							'variation' => false,
						)
					),
				),
			)
		);
		WPS_Test_State::assign_term( 350, 10 );
		WPS_Test_State::assign_term( 350, 351, 'pa_color' );

		$data = Product_Mapper::map( 350 );
		WPS_Assert::same(
			array(
				array( 'name' => 'Pepita szín', 'value' => 'Piros' ),
				array( 'name' => 'Anyagösszetétel', 'value' => '100% pamut' ),
				array( 'name' => 'Garancia', 'value' => '2 év' ),
			),
			$data['attributes']
		);
		WPS_Assert::same( 'pa_color', $product->get_attributes()[0]->get_name(), 'The source product attribute must remain unchanged.' );
		WPS_Assert::same( 'Anyag', $product->get_attributes()[1]->get_name(), 'The custom attribute name must remain unchanged.' );

		// Test variable product variation axis mapping.
		$var_parent = WPS_Test_State::add_product(
			array(
				'id'          => 360,
				'name'        => 'Variációs termék',
				'description' => 'Leírás',
				'type'        => 'variable',
				'price'       => '3000',
				'image_id'    => 500,
				'attributes'  => array(
					new WC_Product_Attribute(
						array(
							'name'      => 'pa_size',
							'options'   => array( 'l' ),
							'position'  => 0,
							'variation' => true,
							'taxonomy'  => true,
						)
					),
				),
			)
		);
		WPS_Test_State::assign_term( 360, 10 );
		WPS_Test_State::add_variation(
			360,
			array(
				'id'             => 361,
				'name'           => 'Variációs termék – L',
				'status'         => 'publish',
				'price'          => '3000',
				'stock_status'   => 'instock',
				'manage_stock'   => true,
				'stock_quantity' => 5,
				'variation'      => array( 'attribute_pa_size' => 'l' ),
				'image_id'       => 0,
			)
		);

		$var_data = Product_Mapper::map( 360 );
		WPS_Assert::same( 'Méret (Pepita)', $var_data['variations']['axes'][0]['name'] );
	}
);

$suite->add(
	'mapper: simple product produces a complete feed record',
	static function () use ( $prepare, $simple ): void {
		$prepare();
		$simple();
		WPS_Test_State::assign_term( 301, 10 );

		$data = Product_Mapper::map( 301 );

		WPS_Assert::same( 'wps-301', $data['export_id'] );
		WPS_Assert::same( 'simple', $data['type'] );
		WPS_Assert::same( 'Termék', $data['name'] );
		WPS_Assert::same( 'Leírás', $data['description'] );
		WPS_Assert::same( 'HUF', $data['currency'] );
		WPS_Assert::equals( 1000.0, $data['price'] );
		WPS_Assert::equals( 0.0, $data['vat_percent'] );
		WPS_Assert::same( true, $data['available'] );
		WPS_Assert::is_null( $data['quantity'] );
		WPS_Assert::count( 1, $data['categories'] );
		WPS_Assert::same( 'Kategória', $data['categories'][0][0]['name'] );
		WPS_Assert::count( 1, $data['photos'] );
		WPS_Assert::same( true, $data['photos'][0]['primary'] );
		WPS_Assert::same( 'https://example.test/product/product-301/', $data['product_url'] );
		WPS_Assert::count( 0, $data['errors'] );
		WPS_Assert::count( 0, $data['warnings'] );
	}
);

$suite->add(
	'mapper: export identifier is persisted on first export and never depends on SKU',
	static function () use ( $prepare, $simple ): void {
		$prepare();
		$product = $simple( 302 );
		WPS_Test_State::assign_term( 302, 10 );

		WPS_Assert::same( '', get_post_meta( 302, '_wps_export_id', true ) );

		$first = Product_Mapper::ensure_export_id( 302 );

		WPS_Assert::same( 'wps-302', $first );
		WPS_Assert::same( 'wps-302', get_post_meta( 302, '_wps_export_id', true ) );

		update_post_meta( 302, '_wps_export_id', 'legacy-42' );

		WPS_Assert::same( 'legacy-42', Product_Mapper::export_id( 302 ) );
		WPS_Assert::same( 'legacy-42', Product_Mapper::ensure_export_id( 302 ) );
	}
);

$suite->add(
	'mapper: managed stock quantity is exported and zero stock means unavailable',
	static function () use ( $prepare, $simple ): void {
		$prepare();
		$simple(
			303,
			array(
				'manage_stock'   => true,
				'stock_quantity' => 4,
			)
		);
		WPS_Test_State::assign_term( 303, 10 );

		$data = Product_Mapper::map( 303 );

		WPS_Assert::same( 4, $data['quantity'] );
		WPS_Assert::same( true, $data['available'] );
	}
);

$suite->add(
	'mapper: sale price is used when lower, otherwise a warning is recorded',
	static function () use ( $prepare, $simple ): void {
		$prepare();
		$simple( 304, array( 'sale_price' => '800' ) );
		WPS_Test_State::assign_term( 304, 10 );

		$data = Product_Mapper::map( 304 );

		WPS_Assert::equals( 800.0, $data['sale_price'] );

		$simple( 305, array( 'sale_price' => '1200' ) );
		WPS_Test_State::assign_term( 305, 10 );

		$data = Product_Mapper::map( 305 );

		WPS_Assert::is_null( $data['sale_price'] );
		WPS_Assert::same( 'sale_price_ignored', $data['warnings'][0]['code'] );
	}
);

$suite->add(
	'mapper: product overrides, warranty, shipping and dimensions are mapped',
	static function () use ( $prepare, $simple ): void {
		$prepare();
		$simple(
			306,
			array(
				'length' => '100',
				'width'  => '200',
				'height' => '300',
				'weight' => '1500',
			)
		);
		WPS_Test_State::assign_term( 306, 10 );

		update_option( 'woocommerce_dimension_unit', 'mm' );
		update_option( 'woocommerce_weight_unit', 'g' );
		update_post_meta( 306, '_wps_title_override', 'Egyedi név' );
		update_post_meta( 306, '_wps_description_override', '<p>Egyedi leírás</p>' );
		update_post_meta( 306, '_wps_gtin', '590-1234-123457' );
		update_post_meta( 306, '_wps_brand', 'Márka' );
		update_post_meta( 306, '_wps_mpn', 'MPN-9' );
		update_post_meta( 306, '_wps_shipping_delay', '4' );
		update_post_meta( 306, '_wps_shipping_price', '1290' );
		update_post_meta( 306, '_wps_warranty_type', 'Year' );
		update_post_meta( 306, '_wps_warranty_duration', '3' );
		update_post_meta( 306, '_wps_restock_date', '2026-03-01' );

		$data = Product_Mapper::map( 306 );

		WPS_Assert::same( 'Egyedi név', $data['name'] );
		WPS_Assert::same( 'Egyedi leírás', $data['description'] );
		WPS_Assert::same( '5901234123457', $data['gtin'] );
		WPS_Assert::same( 'Márka', $data['brand'] );
		WPS_Assert::same( 'MPN-9', $data['mpn'] );
		WPS_Assert::same( 4, $data['shipping_delay'] );
		WPS_Assert::equals( 1290.0, $data['shipping_price'] );
		WPS_Assert::same( array( 'type' => 'Year', 'duration' => '3' ), $data['warranty'] );
		WPS_Assert::same( '2026-03-01', $data['restock_date'] );
		WPS_Assert::equals( 10.0, $data['dimensions']['length'] );
		WPS_Assert::equals( 20.0, $data['dimensions']['width'] );
		WPS_Assert::equals( 30.0, $data['dimensions']['height'] );
		WPS_Assert::equals( 1.5, $data['dimensions']['weight'] );
	}
);

$suite->add(
	'mapper: brand can come from a taxonomy',
	static function () use ( $prepare, $simple ): void {
		$prepare();
		WPS_Test_State::add_term( 40, 'Acme', 0, 'product_brand' );
		$simple( 307 );
		WPS_Test_State::assign_term( 307, 10 );
		WPS_Test_State::assign_term( 307, 40, 'product_brand' );

		Settings::update(
			array(
				'brand_source'   => 'taxonomy',
				'brand_taxonomy' => 'product_brand',
			)
		);

		$data = Product_Mapper::map( 307 );

		WPS_Assert::same( 'Acme', $data['brand'] );
	}
);

$suite->add(
	'mapper: GTIN required by category blocks an unidentifiable product',
	static function () use ( $prepare, $simple ): void {
		$prepare();
		$simple( 308 );
		WPS_Test_State::assign_term( 308, 10 );
		Settings::update( array( 'gtin_required_categories' => array( 10 ) ) );

		$data = Product_Mapper::map( 308 );

		$codes = array_column( $data['errors'], 'code' );

		WPS_Assert::true( in_array( 'gtin_required', $codes, true ) );
	}
);

$suite->add(
	'mapper: disallowed status and missing content produce errors',
	static function () use ( $prepare ): void {
		$prepare();
		WPS_Test_State::add_product(
			array(
				'id'     => 309,
				'status' => 'draft',
			)
		);
		WPS_Test_State::assign_term( 309, 10 );

		$data = Product_Mapper::map( 309 );
		$codes = array_column( $data['errors'], 'code' );

		foreach ( array( 'product_status', 'missing_name', 'missing_description', 'missing_image', 'invalid_price' ) as $code ) {
			WPS_Assert::true( in_array( $code, $codes, true ), 'Missing error code: ' . $code );
		}
	}
);

$suite->add(
	'mapper: variable product exports axes, variations and aggregate stock',
	static function () use ( $prepare ): void {
		$prepare();
		WPS_Test_State::add_attribute_label( 'pa_color', 'Szín' );
		WPS_Test_State::add_term( 30, 'Piros', 0, 'pa_color' );
		WPS_Test_State::add_term( 31, 'Kék', 0, 'pa_color' );

		$parent = WPS_Test_State::add_product(
			array(
				'id'          => 310,
				'name'        => 'Póló',
				'description' => '<p>Póló leírás</p>',
				'type'        => 'variable',
				'price'       => '5000',
				'image_id'    => 500,
				'attributes'  => array(
					new WC_Product_Attribute(
						array(
							'name'      => 'pa_color',
							'options'   => array( 'piros', 'kek' ),
							'position'  => 0,
							'variation' => true,
							'taxonomy'  => true,
						)
					),
				),
			)
		);

		WPS_Test_State::assign_term( 310, 10 );

		WPS_Test_State::add_variation(
			310,
			array(
				'id'             => 311,
				'name'           => 'Póló – Piros',
				'status'         => 'publish',
				'price'          => '5000',
				'stock_status'   => 'instock',
				'manage_stock'   => true,
				'stock_quantity' => 3,
				'variation'      => array( 'attribute_pa_color' => 'piros' ),
				'image_id'       => 0,
			)
		);
		WPS_Test_State::add_variation(
			310,
			array(
				'id'             => 312,
				'name'           => 'Póló – Kék',
				'status'         => 'publish',
				'price'          => '5200',
				'stock_status'   => 'instock',
				'manage_stock'   => true,
				'stock_quantity' => 2,
				'variation'      => array( 'attribute_pa_color' => 'kek' ),
				'image_id'       => 0,
			)
		);

		$data = Product_Mapper::map( 310 );

		WPS_Assert::same( 'variable', $data['type'] );
		WPS_Assert::count( 1, $data['variations']['axes'] );
		WPS_Assert::same( 'Szín', $data['variations']['axes'][0]['name'] );
		WPS_Assert::count( 2, $data['variations']['items'] );
		WPS_Assert::same( array( 'Piros' ), $data['variations']['items'][0]['values'] );
		WPS_Assert::equals( 5000.0, $data['variations']['items'][0]['price'] );
		WPS_Assert::equals( 5200.0, $data['variations']['items'][1]['price'] );
		WPS_Assert::same( 5, $data['quantity'] );
		WPS_Assert::same( true, $data['available'] );
		WPS_Assert::equals( 5000.0, $data['price'] );
	}
);

$suite->add(
	'mapper: duplicate variation values and too many axes are rejected',
	static function () use ( $prepare ): void {
		$prepare();
		WPS_Test_State::add_term( 30, 'Piros', 0, 'pa_color' );

		$parent = WPS_Test_State::add_product(
			array(
				'id'          => 320,
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
		WPS_Test_State::assign_term( 320, 10 );

		foreach ( array( 321, 322 ) as $variation_id ) {
			WPS_Test_State::add_variation(
				320,
				array(
					'id'           => $variation_id,
					'status'       => 'publish',
					'price'        => '5000',
					'stock_status' => 'instock',
					'variation'    => array( 'attribute_pa_color' => 'piros' ),
				)
			);
		}

		$data  = Product_Mapper::map( 320 );
		$codes = array_column( $data['errors'], 'code' );

		WPS_Assert::true( in_array( 'duplicate_variation', $codes, true ) );

		$attributes = array();

		for ( $i = 1; $i <= 4; $i++ ) {
			$attributes[] = new WC_Product_Attribute(
				array(
					'name'      => 'pa_attr' . $i,
					'options'   => array( 'a' ),
					'position'  => $i,
					'variation' => true,
					'taxonomy'  => true,
				)
			);
		}

		WPS_Test_State::add_product(
			array(
				'id'         => 330,
				'name'       => 'Négy tengely',
				'description' => 'Leírás',
				'type'       => 'variable',
				'price'      => '100',
				'image_id'   => 500,
				'attributes' => $attributes,
			)
		);
		WPS_Test_State::assign_term( 330, 10 );

		$data  = Product_Mapper::map( 330 );
		$codes = array_column( $data['errors'], 'code' );

		WPS_Assert::true( in_array( 'too_many_variation_attributes', $codes, true ) );
	}
);

$suite->add(
	'mapper: variable product without published variations is invalid',
	static function () use ( $prepare ): void {
		$prepare();
		WPS_Test_State::add_term( 30, 'Piros', 0, 'pa_color' );

		WPS_Test_State::add_product(
			array(
				'id'          => 340,
				'name'        => 'Üres variációs',
				'description' => 'Leírás',
				'type'        => 'variable',
				'price'       => '100',
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
		WPS_Test_State::assign_term( 340, 10 );

		$data  = Product_Mapper::map( 340 );
		$codes = array_column( $data['errors'], 'code' );

		WPS_Assert::true( in_array( 'no_published_variations', $codes, true ) );
	}
);
