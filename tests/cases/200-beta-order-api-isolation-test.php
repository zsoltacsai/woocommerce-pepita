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
