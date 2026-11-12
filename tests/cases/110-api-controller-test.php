<?php
/**
 * Order API controller: authentication, transport, limits and responses.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Orders\Api_Controller;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
$payload = static function ( array $overrides = array() ): array {
	return array_merge(
		array(
			'origin'               => 'pepita.hu',
			'id'                   => 'PEP-API-1',
			'payment_mode'         => 'transfer',
			'payment_status'       => 'paid',
			'total_shipping_price' => 0,
			'delivery_mod'         => 'gls',
			'customer'             => array(
				'email'      => 'api@example.test',
				'first_name' => 'Api',
				'last_name'  => 'Teszt',
			),
			'products'             => array(
				array(
					'sku'      => 'SKU-API',
					'currency' => 'HUF',
					'quantity' => 1,
					'price'    => 1000,
					'vat'      => 27,
				),
			),
		),
		$overrides
	);
};

$seed = static function (): void {
	WPS_Test_State::add_product(
		array(
			'id'    => 900,
			'name'  => 'API termék',
			'sku'   => 'SKU-API',
			'price' => '1000',
		)
	);
};

/**
 * @param array<string, mixed> $payload
 * @return WP_REST_Response
 */
$call = static function ( array $payload, array $params = array(), array $headers = array(), ?string $body = null ) {
	$request = new WP_REST_Request(
		$params,
		null === $body ? (string) wp_json_encode( $payload ) : $body,
		$headers
	);

	return ( new Api_Controller() )->handle( $request );
};

$suite->add(
	'api: the documented REST route is registered',
	static function (): void {
		( new Api_Controller() )->register_routes();

		WPS_Assert::true( isset( WPS_Test_State::$rest_routes['wps/v1/pepita/order'] ) );
		WPS_Assert::same( 'POST', WPS_Test_State::$rest_routes['wps/v1/pepita/order']['methods'] );
	}
);

$suite->add(
	'api: an unconfigured or invalid API key is rejected',
	static function () use ( $call, $payload ): void {
		$response = $call( $payload() );

		WPS_Assert::same( 401, $response->get_status() );
		WPS_Assert::same( true, $response->get_data()['isError'] );
		WPS_Assert::contains( 'nincs konfigurálva', $response->get_data()['message'] );

		Settings::update( array( 'order_api_key' => 'secret-key' ) );

		$response = $call( $payload(), array( 'apikey' => 'wrong' ) );

		WPS_Assert::same( 401, $response->get_status() );
		WPS_Assert::same( 401, $response->get_data()['responseCode'] );
	}
);

$suite->add(
	'api: the API key is accepted as a query parameter',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update( array( 'order_api_key' => 'secret-key' ) );

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ) );
		$data     = $response->get_data();

		WPS_Assert::same( 200, $response->get_status() );
		WPS_Assert::same( false, $data['isError'] );
		WPS_Assert::same( 200, $data['responseCode'] );
		WPS_Assert::true( $data['orderId'] > 0 );
		WPS_Assert::same( 1, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'api: the API key is accepted in either supported header',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update( array( 'order_api_key' => 'secret-key' ) );

		$response = $call( $payload( array( 'id' => 'PEP-API-H1' ) ), array(), array( 'X-WPS-Api-Key' => 'secret-key' ) );

		WPS_Assert::same( 200, $response->get_status() );

		$response = $call( $payload( array( 'id' => 'PEP-API-H2' ) ), array(), array( 'X-Api-Key' => 'secret-key' ) );

		WPS_Assert::same( 200, $response->get_status() );
		WPS_Assert::same( 2, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'api: a repeated notification returns a successful duplicate response',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update( array( 'order_api_key' => 'secret-key' ) );

		$first  = $call( $payload(), array( 'apikey' => 'secret-key' ) );
		$second = $call( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 200, $first->get_status() );
		WPS_Assert::same( 200, $second->get_status() );
		WPS_Assert::same( false, $second->get_data()['isError'] );
		WPS_Assert::contains( 'korábban', $second->get_data()['message'] );
		WPS_Assert::same( 1, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'api: the endpoint can be switched off',
	static function () use ( $call, $payload ): void {
		Settings::update(
			array(
				'order_api_key'     => 'secret-key',
				'order_api_enabled' => false,
			)
		);

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 503, $response->get_status() );
		WPS_Assert::same( true, $response->get_data()['isError'] );
	}
);

$suite->add(
	'api: HTTPS can be enforced, including behind a trusted proxy',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update(
			array(
				'order_api_key'           => 'secret-key',
				'order_api_require_https' => true,
			)
		);

		WPS_Test_State::$ssl = false;

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 403, $response->get_status() );

		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		WPS_Assert::same( 200, $call( $payload( array( 'id' => 'PEP-API-PROXY' ) ), array( 'apikey' => 'secret-key' ) )->get_status() );

		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		WPS_Test_State::$ssl = true;

		WPS_Assert::same( 200, $call( $payload( array( 'id' => 'PEP-API-SSL' ) ), array( 'apikey' => 'secret-key' ) )->get_status() );
	}
);

$suite->add(
	'api: an IP allowlist blocks other sources',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update(
			array(
				'order_api_key'          => 'secret-key',
				'order_api_allowed_ips'  => '10.0.0.1',
			)
		);

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 401, $response->get_status() );
		WPS_Assert::contains( 'IP', $response->get_data()['message'] );

		Settings::update( array( 'order_api_allowed_ips' => '127.0.0.1, 10.0.0.1' ) );

		WPS_Assert::same( 200, $call( $payload(), array( 'apikey' => 'secret-key' ) )->get_status() );
	}
);

$suite->add(
	'api: the per minute rate limit returns 429',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update( array( 'order_api_key' => 'secret-key' ) );

		set_transient( 'wps_pepita_api_rate_' . md5( '127.0.0.1' ), 120, MINUTE_IN_SECONDS );

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 429, $response->get_status() );
		WPS_Assert::contains( 'Túl sok kérés', $response->get_data()['message'] );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'api: oversized, empty and malformed bodies are rejected',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update(
			array(
				'order_api_key'        => 'secret-key',
				'order_api_max_body_kb' => 16,
			)
		);

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ), array(), str_repeat( 'a', 17 * KB_IN_BYTES ) );

		WPS_Assert::same( 413, $response->get_status() );

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ), array(), '   ' );

		WPS_Assert::same( 400, $response->get_status() );

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ), array(), '{not json' );

		WPS_Assert::same( 400, $response->get_status() );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );
	}
);

$suite->add(
	'api: business validation errors are returned with a 422',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update( array( 'order_api_key' => 'secret-key' ) );

		$response = $call(
			$payload(
				array(
					'products' => array(
						array(
							'sku'      => 'MISSING',
							'currency' => 'HUF',
							'quantity' => 1,
							'price'    => 100,
						),
					),
				)
			),
			array( 'apikey' => 'secret-key' )
		);

		WPS_Assert::same( 422, $response->get_status() );
		WPS_Assert::same( true, $response->get_data()['isError'] );
		WPS_Assert::same( 422, $response->get_data()['responseCode'] );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );
	}
);
