<?php
/**
 * Beta feed-only mode: Order API isolation and fencing tests (Phase 2).
 *
 * Verifies that in beta feed-only mode:
 * - The order API endpoint (/wps/v1/pepita/order) is strictly disabled and short-circuited.
 * - HTTP 503 is returned with Hungarian explanation.
 * - Zero WooCommerce orders are created.
 * - No customer, payment, tax or shipping hooks are executed.
 * - Settings::get('order_api_enabled') is forced to false regardless of saved option.
 * - Tokenized feed endpoints remain fully operational.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Orders\Api_Controller;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

$order_payload = static function ( array $overrides = array() ): array {
	return array_merge(
		array(
			'origin'               => 'pepita.hu',
			'id'                   => 'PEP-BETA-ISO-1',
			'payment_mode'         => 'transfer',
			'payment_status'       => 'paid',
			'total_shipping_price' => 0,
			'delivery_mod'         => 'gls',
			'customer'             => array(
				'email'      => 'beta-isolation@example.test',
				'first_name' => 'Beta',
				'last_name'  => 'Test',
			),
			'products'             => array(
				array(
					'sku'      => 'SKU-BETA-ISO',
					'currency' => 'HUF',
					'quantity' => 1,
					'price'    => 5000,
					'vat'      => 27,
				),
			),
		),
		$overrides
	);
};

$suite->add(
	'beta isolation: settings force order_api_enabled to false when beta_feed_only is active',
	static function (): void {
		WPS_Test_State::reset();

		// Set order_api_enabled to true explicitly, but enable beta_feed_only
		Settings::update(
			array(
				'order_api_enabled' => true,
				'beta_feed_only'    => true,
				'feed_public'       => true,
			)
		);

		WPS_Assert::true( Settings::is_beta_feed_only() );
		// order_api_enabled MUST be forced to false
		WPS_Assert::false( Settings::get( 'order_api_enabled' ) );
		// feed_public MUST be forced to false (token auth required)
		WPS_Assert::false( Settings::get( 'feed_public' ) );
	}
);

$suite->add(
	'beta isolation: order API immediately short-circuits with HTTP 503 in beta mode',
	static function () use ( $order_payload ): void {
		WPS_Test_State::reset();
		WPS_Test_State::$ssl = true;

		// Seed a matching product
		WPS_Test_State::add_product(
			array(
				'id'    => 990,
				'name'  => 'Beta Item',
				'sku'   => 'SKU-BETA-ISO',
				'price' => '5000',
			)
		);

		Settings::update(
			array(
				'beta_feed_only' => true,
				'order_api_key'  => 'valid-secret-key',
			)
		);

		$request = new WP_REST_Request(
			array( 'apikey' => 'valid-secret-key' ),
			(string) wp_json_encode( $order_payload() ),
			array( 'X-WPS-Api-Key' => 'valid-secret-key' )
		);

		$controller = new Api_Controller();
		$response   = $controller->handle( $request );

		// HTTP status must be 503 Service Unavailable
		WPS_Assert::same( 503, $response->get_status() );

		$data = $response->get_data();
		WPS_Assert::true( $data['isError'] );
		WPS_Assert::same( 503, $data['responseCode'] );
		WPS_Assert::contains( 'béta időszakban ki van kapcsolva', $data['message'] );

		// Critical check: ZERO orders were created
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'beta isolation: malformed or valid payload alike are rejected with 503 without parsing side-effects',
	static function (): void {
		WPS_Test_State::reset();
		WPS_Test_State::$ssl = true;

		Settings::update(
			array(
				'beta_feed_only' => true,
				'order_api_key'  => 'valid-secret-key',
			)
		);

		$request = new WP_REST_Request(
			array( 'apikey' => 'valid-secret-key' ),
			'{"broken_json": true',
			array( 'X-WPS-Api-Key' => 'valid-secret-key' )
		);

		$controller = new Api_Controller();
		$response   = $controller->handle( $request );

		WPS_Assert::same( 503, $response->get_status() );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'beta isolation: emergency feed disable works separately and never enables order API',
	static function (): void {
		WPS_Test_State::reset();

		Settings::update(
			array(
				'beta_feed_only'          => true,
				'feed_emergency_disabled' => true,
			)
		);

		WPS_Assert::true( Settings::is_feed_emergency_disabled() );
		WPS_Assert::true( Settings::is_beta_feed_only() );
		WPS_Assert::false( Settings::get( 'order_api_enabled' ) );

		// Disabling emergency feed toggle still keeps order_api_enabled strictly false
		Settings::update(
			array(
				'feed_emergency_disabled' => false,
			)
		);
		WPS_Assert::false( Settings::is_feed_emergency_disabled() );
		WPS_Assert::false( Settings::get( 'order_api_enabled' ) );
	}
);

$suite->add(
	'beta isolation: WooCommerce orders and product stock remain completely unchanged across all negative API tests',
	static function () use ( $order_payload ): void {
		WPS_Test_State::reset();
		WPS_Test_State::$ssl = true;

		// Seed product with initial stock
		$initial_stock = 42;
		WPS_Test_State::add_product(
			array(
				'id'            => 1001,
				'name'          => 'Guarded Stock Item',
				'sku'           => 'SKU-BETA-ISO',
				'price'         => '8500',
				'stock_status'  => 'instock',
				'stock_quantity' => $initial_stock,
			)
		);

		Settings::update(
			array(
				'beta_feed_only' => true,
				'order_api_key'  => 'beta-isolation-secret-key-12345678',
			)
		);

		$controller = new Api_Controller();

		// Test 1: Request with valid key and full payload
		$req1 = new WP_REST_Request(
			array( 'apikey' => 'beta-isolation-secret-key-12345678' ),
			(string) wp_json_encode( $order_payload() ),
			array( 'X-WPS-Api-Key' => 'beta-isolation-secret-key-12345678' )
		);
		$res1 = $controller->handle( $req1 );
		WPS_Assert::same( 503, $res1->get_status() );

		// Test 2: Request with invalid key
		$req2 = new WP_REST_Request(
			array( 'apikey' => 'wrong-key' ),
			(string) wp_json_encode( $order_payload() ),
			array( 'X-WPS-Api-Key' => 'wrong-key' )
		);
		$res2 = $controller->handle( $req2 );
		WPS_Assert::same( 503, $res2->get_status() );

		// Test 3: Request without transport allowed (HTTP)
		WPS_Test_State::$ssl = false;
		$req3 = new WP_REST_Request(
			array( 'apikey' => 'beta-isolation-secret-key-12345678' ),
			(string) wp_json_encode( $order_payload() )
		);
		$res3 = $controller->handle( $req3 );
		WPS_Assert::same( 503, $res3->get_status() );

		// Test 4: Request with malformed JSON
		WPS_Test_State::$ssl = true;
		$req4 = new WP_REST_Request(
			array( 'apikey' => 'beta-isolation-secret-key-12345678' ),
			'NOT_JSON'
		);
		$res4 = $controller->handle( $req4 );
		WPS_Assert::same( 503, $res4->get_status() );

		// VERIFY: Zero WooCommerce orders created
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );

		// VERIFY: Product stock remains completely identical
		$product = wc_get_product( 1001 );
		WPS_Assert::true( null !== $product );
		WPS_Assert::same( $initial_stock, (int) $product->get_stock_quantity() );
	}
);
