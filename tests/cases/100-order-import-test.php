<?php
/**
 * Pepita order import: mapping, totals, statuses, idempotency and safety.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
$payload = static function ( array $overrides = array() ): array {
	$base = array(
		'origin'                        => 'pepita.hu',
		'id'                            => 'PEP-1001',
		'date'                          => '2026-11-10 10:00:00',
		'payment_mode'                  => 'transfer',
		'payment_status'                => 'paid',
		'status'                        => 'NEW',
		'total_shipping_price'          => 1490,
		'total_shipping_price_currency' => 'HUF',
		'voucher'                       => 0,
		'delivery_mod'                  => 'gls',
		'customer'                      => array(
			'email'                 => 'vasarlo@example.test',
			'first_name'            => 'Anna',
			'last_name'             => 'Kis',
			'phone'                 => '+36301234567',
			'billing_country'       => 'HU',
			'billing_city'          => 'Budapest',
			'billing_postal_code'   => '1011',
			'billing_street'        => 'Fő utca 1.',
			'billing_house_number'  => '1',
			'shipping_country'      => 'HU',
			'shipping_city'         => 'Budapest',
			'shipping_postal_code'  => '1011',
			'shipping_street'       => 'Fő utca 1.',
			'tax_number'            => '12345678-1-41',
		),
		'products'                      => array(
			array(
				'id'       => 'wps-501',
				'sku'      => 'SKU-1',
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

$seed = static function ( int $id = 501, string $sku = 'SKU-1' ): WC_Product {
	return WPS_Test_State::add_product(
		array(
			'id'    => $id,
			'name'  => 'Termék ' . $id,
			'sku'   => $sku,
			'price' => '1000',
		)
	);
};

$suite->add(
	'import: a paid order is created with totals, status, payment and metadata',
	static function () use ( $payload, $seed ): void {
		$seed();
		WPS_Test_State::add_user( 'vasarlo@example.test', 'Anna', 'Kis' );

		$result = ( new Importer() )->import( $payload() );

		WPS_Assert::same( 'created', $result['status'] );
		WPS_Assert::same( 200, $result['http'] );
		WPS_Assert::true( $result['order_id'] > 0 );

		$order = WPS_Test_State::$orders[ $result['order_id'] ];

		WPS_Assert::same( 'processing', $order->get_status() );
		WPS_Assert::equals( 3490.0, $order->get_total() );
		WPS_Assert::same( 'HUF', $order->get_currency() );
		WPS_Assert::same( 'bacs', $order->get_payment_method() );
		WPS_Assert::same( 'Átutalás', $order->get_payment_method_title() );
		WPS_Assert::same( 'wps-pepita', $order->get_created_via() );
		WPS_Assert::truthy( $order->get_date_paid() );
		WPS_Assert::same( '2026-11-10', gmdate( 'Y-m-d', $order->get_date_created()->getTimestamp() ) );
		WPS_Assert::same( 1, $order->get_customer_id() );
		WPS_Assert::same( 'PEP-1001', $order->get_meta( '_wps_pepita_order_id' ) );
		WPS_Assert::same( 'paid', $order->get_meta( '_wps_pepita_payment_status' ) );
		WPS_Assert::same( 'gls', $order->get_meta( '_wps_pepita_delivery_mode' ) );
		WPS_Assert::same( '12345678-1-41', $order->get_meta( '_billing_vat_number' ) );
		WPS_Assert::same( 'HU', $order->get_shipping()['country'] );
		WPS_Assert::same( 'Fő utca 1.', $order->get_shipping()['address_1'] );

		$shipping_items = $order->get_shipping_methods();

		WPS_Assert::count( 1, $shipping_items );
		WPS_Assert::equals( 1490.0, reset( $shipping_items )->get_total() );
		WPS_Assert::same( 'GLS házhozszállítás', reset( $shipping_items )->get_method_title() );

		$items = $order->get_items( 'line_item' );

		WPS_Assert::count( 1, $items );
		WPS_Assert::same( 'wps-501', reset( $items )->get_meta( '_wps_pepita_product_id' ) );
		WPS_Assert::same( '27', reset( $items )->get_meta( '_wps_pepita_vat' ) );

		WPS_Assert::same( 1, Order_Repository::count_completed() );
		WPS_Assert::same( 1, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'import: the payload hash is stored for auditing',
	static function () use ( $payload, $seed ): void {
		$seed();

		$payload_data = $payload();
		$expected     = hash(
			'sha256',
			wp_json_encode( $payload_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		$result = ( new Importer() )->import( $payload_data );
		$order  = WPS_Test_State::$orders[ $result['order_id'] ];

		WPS_Assert::same( $expected, $order->get_meta( '_wps_pepita_payload_hash' ) );
	}
);

$suite->add(
	'import: an unpaid cash-on-delivery order stays on hold',
	static function () use ( $payload, $seed ): void {
		$seed();

		$result = ( new Importer() )->import(
			$payload(
				array(
					'payment_mode'   => 'cod',
					'payment_status' => 'unpaid',
				)
			)
		);

		$order = WPS_Test_State::$orders[ $result['order_id'] ];

		WPS_Assert::same( 'on-hold', $order->get_status() );
		WPS_Assert::is_null( $order->get_date_paid() );
		WPS_Assert::same( 'cod', $order->get_payment_method() );
	}
);

$suite->add(
	'import: a repeated notification never creates a second order',
	static function () use ( $payload, $seed ): void {
		$seed();

		$first  = ( new Importer() )->import( $payload() );
		$second = ( new Importer() )->import( $payload() );

		WPS_Assert::same( 'created', $first['status'] );
		WPS_Assert::same( 'duplicate', $second['status'] );
		WPS_Assert::same( $first['order_id'], $second['order_id'] );
		WPS_Assert::same( 1, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'import: an existing WooCommerce order is adopted after a crash',
	static function () use ( $payload, $seed ): void {
		$seed();

		$order = new WC_Order();
		$order->update_meta_data( '_wps_pepita_order_id', 'PEP-1001' );
		WPS_Test_State::add_order( $order );

		$result = ( new Importer() )->import( $payload() );

		WPS_Assert::same( 'duplicate', $result['status'] );
		WPS_Assert::same( $order->get_id(), $result['order_id'] );
		WPS_Assert::same( 'completed', Order_Repository::get( 'PEP-1001' )['status'] );
		WPS_Assert::same( 1, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'import: a rejected order is retryable once the data is fixed',
	static function () use ( $payload, $seed ): void {
		$seed( 501, 'SKU-1' );

		$bad = ( new Importer() )->import(
			$payload(
				array(
					'id'       => 'PEP-RETRY',
					'products' => array(
						array(
							'sku'      => 'UNKNOWN',
							'currency' => 'HUF',
							'quantity' => 1,
							'price'    => 100,
						),
					),
				)
			)
		);

		WPS_Assert::same( 'error', $bad['status'] );
		WPS_Assert::same( 422, $bad['http'] );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );
		WPS_Assert::same( 'failed', Order_Repository::get( 'PEP-RETRY' )['status'] );

		$seed( 502, 'SKU-2' );

		$good = ( new Importer() )->import(
			$payload(
				array(
					'id'       => 'PEP-RETRY',
					'products' => array(
						array(
							'sku'      => 'SKU-2',
							'currency' => 'HUF',
							'quantity' => 1,
							'price'    => 100,
						),
					),
				)
			)
		);

		WPS_Assert::same( 'created', $good['status'] );
		WPS_Assert::same( 1, count( WPS_Test_State::$orders ) );
		WPS_Assert::same( 2, (int) Order_Repository::get( 'PEP-RETRY' )['attempts'] );
	}
);

$suite->add(
	'import: validation rejects incomplete or unsafe payloads',
	static function () use ( $payload, $seed ): void {
		$seed();

		$cases = array(
			'missing id'         => array( 'id' => '' ),
			'no products'        => array( 'products' => array() ),
			'missing email'      => array( 'customer' => array( 'email' => '' ) ),
			'invalid email'      => array( 'customer' => array( 'email' => 'not-an-email' ) ),
			'zero quantity'      => array( 'products' => array( array( 'sku' => 'SKU-1', 'quantity' => 0, 'price' => 100 ) ) ),
			'zero price'         => array( 'products' => array( array( 'sku' => 'SKU-1', 'quantity' => 1, 'price' => 0 ) ) ),
			'bad vat'            => array( 'products' => array( array( 'sku' => 'SKU-1', 'quantity' => 1, 'price' => 100, 'vat' => 120 ) ) ),
			'bad currency'       => array( 'products' => array( array( 'sku' => 'SKU-1', 'quantity' => 1, 'price' => 100, 'currency' => 'FT' ) ) ),
			'mixed currencies'   => array( 'products' => array(
				array( 'sku' => 'SKU-1', 'quantity' => 1, 'price' => 100, 'currency' => 'HUF' ),
				array( 'sku' => 'SKU-1', 'quantity' => 1, 'price' => 100, 'currency' => 'EUR' ),
			) ),
			'negative shipping'  => array( 'total_shipping_price' => -1 ),
			'negative voucher'   => array( 'voucher' => -1 ),
			'unknown payment'    => array( 'payment_mode' => 'bitcoin' ),
			'unknown delivery'   => array( 'delivery_mod' => 'drone' ),
			'unknown product'    => array( 'products' => array( array( 'sku' => 'NOPE', 'quantity' => 1, 'price' => 100 ) ) ),
		);

		foreach ( $cases as $label => $overrides ) {
			$result = ( new Importer() )->import( $payload( $overrides ) );

			WPS_Assert::same( 'error', $result['status'], 'Case should fail: ' . $label );
			WPS_Assert::true( $result['http'] >= 400, 'Case should return an error code: ' . $label );
		}

		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'import: variable parent and unpublished products are rejected',
	static function () use ( $payload ): void {
		WPS_Test_State::add_product(
			array(
				'id'    => 600,
				'name'  => 'Variációs',
				'sku'   => 'VAR-1',
				'type'  => 'variable',
				'price' => '1000',
			)
		);
		WPS_Test_State::add_product(
			array(
				'id'     => 601,
				'name'   => 'Piszkozat',
				'sku'    => 'DRAFT-1',
				'status' => 'draft',
				'price'  => '1000',
			)
		);

		$variation = $payload(
			array( 'products' => array( array( 'sku' => 'VAR-1', 'quantity' => 1, 'price' => 1000 ) ) )
		);
		$draft     = $payload(
			array( 'id' => 'PEP-DRAFT', 'products' => array( array( 'sku' => 'DRAFT-1', 'quantity' => 1, 'price' => 1000 ) ) )
		);

		WPS_Assert::same( 422, ( new Importer() )->import( $variation )['http'] );
		WPS_Assert::same( 422, ( new Importer() )->import( $draft )['http'] );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'import: vouchers become a negative fee and reduce the total',
	static function () use ( $payload, $seed ): void {
		$seed();

		$result = ( new Importer() )->import( $payload( array( 'voucher' => 500 ) ) );
		$order  = WPS_Test_State::$orders[ $result['order_id'] ];

		WPS_Assert::equals( 2990.0, $order->get_total() );
		WPS_Assert::same( '500', $order->get_meta( '_wps_pepita_voucher' ) );

		$fees = $order->get_items( 'fee' );

		WPS_Assert::count( 1, $fees );
		WPS_Assert::equals( -500.0, reset( $fees )->get_total() );
	}
);

$suite->add(
	'import: the package label is stored as a PDF file',
	static function () use ( $payload, $seed ): void {
		$seed();

		$binary = "%PDF-1.4\nPepita label\n";
		$result = ( new Importer() )->import(
			$payload( array( 'package_label' => base64_encode( $binary ) ) )
		);

		$order = WPS_Test_State::$orders[ $result['order_id'] ];
		$file  = (string) $order->get_meta( '_wps_pepita_package_label' );

		WPS_Assert::true( '' !== $file );
		WPS_Assert::true( is_file( $file ) );
		WPS_Assert::same( $binary, file_get_contents( $file ) );

		@unlink( $file );
	}
);

$suite->add(
	'import: invalid labels are ignored and label saving can be disabled',
	static function () use ( $payload, $seed ): void {
		$seed();

		$result = ( new Importer() )->import( $payload( array( 'package_label' => '!!!not-base64!!!' ) ) );
		$order  = WPS_Test_State::$orders[ $result['order_id'] ];

		WPS_Assert::same( '', $order->get_meta( '_wps_pepita_package_label' ) );

		Settings::update( array( 'order_api_save_label' => false ) );

		$result = ( new Importer() )->import(
			$payload(
				array(
					'id'            => 'PEP-1002',
					'package_label' => base64_encode( '%PDF-1.4' ),
				)
			)
		);

		$order = WPS_Test_State::$orders[ $result['order_id'] ];

		WPS_Assert::same( '', $order->get_meta( '_wps_pepita_package_label' ) );
	}
);

$suite->add(
	'import: customer e-mails are suppressed during save and restored afterwards',
	static function () use ( $payload, $seed ): void {
		$seed();

		( new Importer() )->import( $payload() );

		WPS_Assert::same( false, WPS_Test_State::$last_save_email_enabled );
		WPS_Assert::false( has_action( 'woocommerce_email_enabled_new_order', '__return_false' ) );
		WPS_Assert::false( has_action( 'woocommerce_email_enabled_customer_processing_order', '__return_false' ) );
	}
);

$suite->add(
	'import: the exporter product identifier resolves a product without SKU',
	static function () use ( $payload ): void {
		WPS_Test_State::add_product(
			array(
				'id'    => 700,
				'name'  => 'Exportált',
				'price' => '1000',
			)
		);
		update_post_meta( 700, '_wps_export_id', 'wps-700' );

		$result = ( new Importer() )->import(
			$payload(
				array(
					'products' => array(
						array(
							'id'       => 'wps-700',
							'currency' => 'HUF',
							'quantity' => 1,
							'price'    => 1000,
						),
					),
				)
			)
		);

		WPS_Assert::same( 'created', $result['status'] );
	}
);
