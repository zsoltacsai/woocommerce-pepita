<?php
/**
 * Tax handling and NEW-1 tax representation unit tests.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
$tax_payload = static function ( array $overrides = array() ): array {
	$base = array(
		'origin'                        => 'pepita.hu',
		'id'                            => 'PEP-TAX-101',
		'date'                          => '2026-11-10 10:00:00',
		'payment_mode'                  => 'transfer',
		'payment_status'                => 'paid',
		'status'                        => 'NEW',
		'total_shipping_price'          => 1490,
		'total_shipping_price_currency' => 'HUF',
		'voucher'                       => 500,
		'delivery_mod'                  => 'gls',
		'customer'                      => array(
			'email'                => 'vasarlo.tax@example.test',
			'first_name'           => 'Eva',
			'last_name'            => 'Nagy',
			'phone'                => '+36301234567',
			'billing_country'      => 'HU',
			'billing_city'         => 'Budapest',
			'billing_postal_code'  => '1011',
			'billing_street'       => 'Fő utca 2.',
			'billing_house_number' => '2',
			'shipping_country'     => 'HU',
			'shipping_city'        => 'Budapest',
			'shipping_postal_code' => '1011',
			'shipping_street'      => 'Fő utca 2.',
			'tax_number'           => '12345678-2-41',
		),
		'products'                      => array(
			array(
				'id'       => 'wps-601',
				'sku'      => 'SKU-TAX-1',
				'currency' => 'HUF',
				'quantity' => 2,
				'price'    => 1000,
				'vat'      => 27,
			),
		),
	);

	$merged             = array_merge( $base, $overrides );
	$merged['customer'] = array_merge( $base['customer'], (array) ( $overrides['customer'] ?? array() ) );

	return $merged;
};

$suite->add(
	'tax: disabled taxes create zero-tax orders with exact gross totals',
	static function () use ( $tax_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'    => 601,
				'name'  => 'Tax Teszt Termék',
				'sku'   => 'SKU-TAX-1',
				'price' => '1000',
			)
		);

		$result = ( new Importer() )->import( $tax_payload( array( 'id' => 'PEP-NOTAX-1' ) ) );

		WPS_Assert::same( 'created', $result['status'] );
		WPS_Assert::same( 200, $result['http'] );

		$order = WPS_Test_State::$orders[ $result['order_id'] ];
		// 2 x 1000 + 1490 shipping - 500 voucher = 2990.
		WPS_Assert::equals( 2990.0, $order->get_total() );
		WPS_Assert::equals( 0.0, $order->get_total_tax() );
		WPS_Assert::equals( 0.0, $order->get_cart_tax() );
		WPS_Assert::equals( 0.0, $order->get_shipping_tax() );
		WPS_Assert::same( 0, count( $order->get_items( 'tax' ) ) );

		$items = $order->get_items( 'line_item' );
		WPS_Assert::same( 1, count( $items ) );
		WPS_Assert::equals( 2000.0, $items[0]->get_total() );
		WPS_Assert::equals( 0.0, $items[0]->get_total_tax() );
		WPS_Assert::same( '27', $items[0]->get_meta( '_wps_pepita_vat' ) );
	}
);

$suite->add(
	'tax: taxable products at 27% VAT preserve exact net, tax and gross lines',
	static function () use ( $tax_payload ): void {
		WPS_Test_State::$tax_enabled = true;
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );

		$rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_class'    => '',
				'tax_rate'          => '27.0000',
				'tax_rate_name'     => 'ÁFA 27%',
				'tax_rate_shipping' => 1,
			)
		);

		WPS_Test_State::add_product(
			array(
				'id'         => 602,
				'name'       => 'Taxable Termék 27%',
				'sku'        => 'SKU-TAX-2',
				'price'      => '1000',
				'tax_status' => 'taxable',
				'tax_class'  => '',
			)
		);

		$payload = $tax_payload(
			array(
				'id'       => 'PEP-TAX-27',
				'products' => array(
					array(
						'id'       => 'wps-602',
						'sku'      => 'SKU-TAX-2',
						'currency' => 'HUF',
						'quantity' => 2,
						'price'    => 1000,
						'vat'      => 27,
					),
				),
			)
		);

		$result = ( new Importer() )->import( $payload );

		WPS_Assert::same( 'created', $result['status'] );
		WPS_Assert::same( 200, $result['http'] );

		$order = WPS_Test_State::$orders[ $result['order_id'] ];

		// 2 x 1000 gross = 2000 gross. Net = 2000 / 1.27 = 1574.80. Tax = 425.20.
		// Shipping 1490 gross. Net = 1490 / 1.27 = 1173.23. Tax = 316.77.
		// Voucher = -500.
		// Total = 2990.00.
		WPS_Assert::equals( 2990.0, $order->get_total() );
		WPS_Assert::equals( 425.20, $order->get_cart_tax() );
		WPS_Assert::equals( 316.77, $order->get_shipping_tax() );
		WPS_Assert::equals( 741.97, $order->get_total_tax() );

		// Check line item
		$items = $order->get_items( 'line_item' );
		WPS_Assert::same( 1, count( $items ) );
		WPS_Assert::equals( 1574.80, $items[0]->get_total() );
		WPS_Assert::equals( 425.20, $items[0]->get_total_tax() );
		WPS_Assert::equals( 2000.0, round( $items[0]->get_total() + $items[0]->get_total_tax(), 2 ) );

		// Check shipping item
		$shippings = $order->get_items( 'shipping' );
		WPS_Assert::same( 1, count( $shippings ) );
		WPS_Assert::equals( 1173.23, $shippings[0]->get_total() );
		WPS_Assert::equals( 316.77, $shippings[0]->get_total_tax() );
		WPS_Assert::equals( 1490.0, round( $shippings[0]->get_total() + $shippings[0]->get_total_tax(), 2 ) );

		// Check tax item
		$tax_items = $order->get_items( 'tax' );
		WPS_Assert::same( 1, count( $tax_items ) );
		WPS_Assert::equals( 425.20, $tax_items[0]->get_tax_total() );
		WPS_Assert::equals( 316.77, $tax_items[0]->get_shipping_tax_total() );
		WPS_Assert::same( 'ÁFA 27%', $tax_items[0]->get_label() );

		WC_Tax::_delete_tax_rate( $rate_id );
	}
);

$suite->add(
	'tax: prices_include_tax=no does not add VAT a second time',
	static function () use ( $tax_payload ): void {
		WPS_Test_State::$tax_enabled = true;
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );

		$rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_class'    => '',
				'tax_rate'          => '27.0000',
				'tax_rate_name'     => 'ÁFA',
				'tax_rate_shipping' => 0,
			)
		);

		WPS_Test_State::add_product(
			array(
				'id'         => 603,
				'name'       => 'Taxable Termék Exclusive',
				'sku'        => 'SKU-TAX-3',
				'price'      => '787.40',
				'tax_status' => 'taxable',
				'tax_class'  => '',
			)
		);

		$payload = $tax_payload(
			array(
				'id'       => 'PEP-TAX-EXCL',
				'products' => array(
					array(
						'id'       => 'wps-603',
						'sku'      => 'SKU-TAX-3',
						'currency' => 'HUF',
						'quantity' => 2,
						'price'    => 1000,
						'vat'      => 27,
					),
				),
			)
		);

		$result = ( new Importer() )->import( $payload );

		WPS_Assert::same( 'created', $result['status'] );
		WPS_Assert::same( 200, $result['http'] );

		$order = WPS_Test_State::$orders[ $result['order_id'] ];

		// Total must stay exact 2990.00, not gain VAT on top!
		WPS_Assert::equals( 2990.0, $order->get_total() );
		WPS_Assert::equals( 425.20, $order->get_cart_tax() );
		WPS_Assert::equals( 0.0, $order->get_shipping_tax() );

		WC_Tax::_delete_tax_rate( $rate_id );
	}
);

$suite->add(
	'tax: mismatched VAT between Pepita and WooCommerce fails safely with 422',
	static function () use ( $tax_payload ): void {
		WPS_Test_State::$tax_enabled = true;
		update_option( 'woocommerce_calc_taxes', 'yes' );

		$rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_class' => '',
				'tax_rate'       => '27.0000',
				'tax_rate_name'  => 'ÁFA 27%',
			)
		);

		WPS_Test_State::add_product(
			array(
				'id'         => 604,
				'name'       => 'Standard 27% Termék',
				'sku'        => 'SKU-TAX-4',
				'price'      => '1000',
				'tax_status' => 'taxable',
				'tax_class'  => '',
			)
		);

		// Pepita payload claims 5% VAT on a 27% configured product
		$payload = $tax_payload(
			array(
				'id'       => 'PEP-TAX-MISMATCH',
				'products' => array(
					array(
						'id'       => 'wps-604',
						'sku'      => 'SKU-TAX-4',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 1000,
						'vat'      => 5,
					),
				),
			)
		);

		$result = ( new Importer() )->import( $payload );

		WPS_Assert::same( 'error', $result['status'] );
		WPS_Assert::same( 422, $result['http'] );
		WPS_Assert::contains( 'nem egyezik a WooCommerce-ben beállított adókulccsal', $result['message'] );

		WC_Tax::_delete_tax_rate( $rate_id );
	}
);

$suite->add(
	'tax: zero-rated products and tax-exempt orders create clean zero-tax lines',
	static function () use ( $tax_payload ): void {
		WPS_Test_State::$tax_enabled = true;
		update_option( 'woocommerce_calc_taxes', 'yes' );

		WPS_Test_State::add_product(
			array(
				'id'         => 605,
				'name'       => 'Adómentes Termék',
				'sku'        => 'SKU-ZERO-1',
				'price'      => '1000',
				'tax_status' => 'none',
			)
		);

		$payload = $tax_payload(
			array(
				'id'       => 'PEP-TAX-ZERO',
				'products' => array(
					array(
						'id'       => 'wps-605',
						'sku'      => 'SKU-ZERO-1',
						'currency' => 'HUF',
						'quantity' => 2,
						'price'    => 1000,
						'vat'      => 0,
					),
				),
			)
		);

		$result = ( new Importer() )->import( $payload );

		WPS_Assert::same( 'created', $result['status'] );
		WPS_Assert::same( 200, $result['http'] );

		$order = WPS_Test_State::$orders[ $result['order_id'] ];
		WPS_Assert::equals( 2990.0, $order->get_total() );
		WPS_Assert::equals( 0.0, $order->get_total_tax() );
		WPS_Assert::equals( 0.0, $order->get_cart_tax() );

		$items = $order->get_items( 'line_item' );
		WPS_Assert::equals( 2000.0, $items[0]->get_total() );
		WPS_Assert::equals( 0.0, $items[0]->get_total_tax() );
	}
);

$suite->add(
	'duplicate: changed-payload duplicate returns 409, keeps original order and adds operator note',
	static function () use ( $tax_payload ): void {
		WPS_Test_State::$tax_enabled = false;
		update_option( 'woocommerce_calc_taxes', 'no' );

		WPS_Test_State::add_product(
			array(
				'id'    => 606,
				'name'  => 'Duplicate Teszt',
				'sku'   => 'SKU-DUP-1',
				'price' => '1000',
			)
		);

		$first_payload = $tax_payload(
			array(
				'id'       => 'PEP-DUP-1',
				'products' => array(
					array(
						'id'       => 'wps-606',
						'sku'      => 'SKU-DUP-1',
						'currency' => 'HUF',
						'quantity' => 1,
						'price'    => 1000,
						'vat'      => 27,
					),
				),
			)
		);

		$importer = new Importer();
		$first    = $importer->import( $first_payload );
		WPS_Assert::same( 200, $first['http'] );
		$order_id = $first['order_id'];

		$order = WPS_Test_State::$orders[ $order_id ];
		$notes_count_before = count( $order->get_customer_notes() );

		// Changed delivery: different quantity and price
		$changed_payload = $first_payload;
		$changed_payload['products'][0]['quantity'] = 3;
		$changed_payload['status'] = 'PROCESSING';

		$second = $importer->import( $changed_payload );

		WPS_Assert::same( 409, $second['http'] );
		WPS_Assert::same( 'conflict', $second['status'] );

		// Order is preserved
		WPS_Assert::equals( 1990.0, $order->get_total() );

		// Operator note is added to the order
		$notes = $order->get_customer_notes();
		WPS_Assert::true( count( $notes ) > $notes_count_before );
		WPS_Assert::contains( 'NEW-2', end( $notes ) );
	}
);
