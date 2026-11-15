<?php
/**
 * Order API controller: authentication, transport, limits and responses.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Logger;
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
 * Run a request over a TLS connection (the default the plugin now requires).
 *
 * @param array<string, mixed> $payload
 * @return WP_REST_Response
 */
$call = static function ( array $payload, array $params = array(), array $headers = array(), ?string $body = null ) {
	WPS_Test_State::$ssl = true;
	// The API is OFF by default; these tests exercise an explicitly enabled endpoint unless a test opts out.
	if ( empty( $GLOBALS['wps_api_test_keep_state'] ) ) {
		Settings::update( array( 'order_api_enabled' => true ) );
	}

	$request = new WP_REST_Request(
		$params,
		null === $body ? (string) wp_json_encode( $payload ) : $body,
		$headers
	);

	return ( new Api_Controller() )->handle( $request );
};

/**
 * Run a request without TLS, keeping whatever forwarding headers are set.
 *
 * @param array<string, mixed> $payload
 * @return WP_REST_Response
 */
$call_plain = static function ( array $payload, array $params = array(), array $headers = array() ) {
	WPS_Test_State::$ssl = false;
	if ( empty( $GLOBALS['wps_api_test_keep_state'] ) ) {
		Settings::update( array( 'order_api_enabled' => true ) );
	}

	$request = new WP_REST_Request( $params, (string) wp_json_encode( $payload ), $headers );

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

		$GLOBALS['wps_api_test_keep_state'] = true;
		$response                           = $call( $payload(), array( 'apikey' => 'secret-key' ) );
		unset( $GLOBALS['wps_api_test_keep_state'] );

		WPS_Assert::same( 503, $response->get_status() );
		WPS_Assert::same( true, $response->get_data()['isError'] );
	}
);

$suite->add(
	'api: plain HTTP is rejected before the key is compared and spoofed HTTPS is ignored',
	static function () use ( $call_plain, $payload, $seed ): void {
		$seed();
		Settings::update(
			array(
				'order_api_key'           => 'secret-key',
				'order_api_require_https' => true,
			)
		);

		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		// A correct key over plain HTTP must fail on transport, not succeed.
		$response = $call_plain( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 403, $response->get_status() );
		WPS_Assert::contains( 'HTTPS', $response->get_data()['message'] );

		// A client-set forwarding header is not evidence of TLS.
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		$response = $call_plain( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 403, $response->get_status() );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );

		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
	}
);

$suite->add(
	'api: a trusted proxy may terminate TLS for a plaintext backend',
	static function () use ( $call_plain, $payload, $seed ): void {
		$seed();
		Settings::update(
			array(
				'order_api_key'             => 'secret-key',
				'order_api_require_https'   => true,
				'order_api_trusted_proxies' => '10.0.0.5',
			)
		);

		$_SERVER['REMOTE_ADDR']             = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_PROTO']  = 'https';

		WPS_Assert::same( 200, $call_plain( $payload( array( 'id' => 'PEP-API-PROXY' ) ), array( 'apikey' => 'secret-key' ) )->get_status() );

		// A non-HTTPS value from the trusted proxy is still refused.
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

		WPS_Assert::same( 403, $call_plain( $payload( array( 'id' => 'PEP-API-PROXY-2' ) ), array( 'apikey' => 'secret-key' ) )->get_status() );

		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}
);

$suite->add(
	'api: spoofed forwarding headers cannot satisfy the IP allowlist',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update(
			array(
				'order_api_key'             => 'secret-key',
				'order_api_allowed_ips'     => '10.0.0.1',
				'order_api_trusted_proxies' => '',
			)
		);

		$_SERVER['REMOTE_ADDR']             = '203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_FOR']    = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP']   = '10.0.0.1';
		$_SERVER['HTTP_X_REAL_IP']          = '10.0.0.1';

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 401, $response->get_status() );
		WPS_Assert::contains( 'IP', $response->get_data()['message'] );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ) );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}
);

$suite->add(
	'api: a trusted proxy resolves the client address for the allowlist and the rate limit',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update(
			array(
				'order_api_key'             => 'secret-key',
				'order_api_allowed_ips'     => '203.0.113.9',
				'order_api_trusted_proxies' => '10.0.0.5',
			)
		);

		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 203.0.113.9';

		WPS_Assert::same( 200, $call( $payload(), array( 'apikey' => 'secret-key' ) )->get_status() );

		// The rate limiter must use the same resolved client address.
		set_transient( 'wps_pepita_api_rate_' . md5( '203.0.113.9' ), 120, MINUTE_IN_SECONDS );

		$response = $call( $payload( array( 'id' => 'PEP-API-PROXY-RATE' ) ), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 429, $response->get_status() );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
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
	'api: failed authentication is throttled before the key comparison',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update( array( 'order_api_key' => 'secret-key' ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		// Below the limit a correct key still works: legitimate retries stay possible.
		for ( $i = 0; $i < 5; $i++ ) {
			WPS_Assert::same( 401, $call( $payload(), array( 'apikey' => 'wrong' ) )->get_status() );
		}

		WPS_Assert::same( 200, $call( $payload(), array( 'apikey' => 'secret-key' ) )->get_status() );

		// Exhaust the failure budget for this address.
		for ( $i = 0; $i < 30; $i++ ) {
			$call( $payload( array( 'id' => 'PEP-API-FAIL-' . $i ) ), array( 'apikey' => 'wrong' ) );
		}

		$response = $call( $payload( array( 'id' => 'PEP-API-FAIL-AFTER' ) ), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 429, $response->get_status() );
		WPS_Assert::contains( 'sikertelen', $response->get_data()['message'] );

		// Logging stays bounded: one row per client/reason/minute plus the
		// per-minute global budget, never one row per failed request. 36
		// failed attempts from one address must not produce 36 rows.
		$logged = Logger::query( array( 'event' => 'order_api' ) );

		WPS_Assert::true( $logged['total'] > 0 );
		WPS_Assert::true( $logged['total'] <= 5, 'Failed-auth logging is not bounded: ' . (int) $logged['total'] . ' rows.' );

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}
);

$suite->add(
	'api: an unparseable forwarding hop fails closed instead of trusting the proxy address',
	static function () use ( $call, $payload, $seed ): void {
		$seed();
		Settings::update(
			array(
				'order_api_key'             => 'secret-key',
				// The allowlist contains the proxy, so a fallback to the proxy
				// address would wrongly admit the request.
				'order_api_allowed_ips'     => '10.0.0.5',
				'order_api_trusted_proxies' => '10.0.0.5',
			)
		);

		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';

		$response = $call( $payload(), array( 'apikey' => 'secret-key' ) );

		WPS_Assert::same( 401, $response->get_status() );
		WPS_Assert::contains( 'IP', $response->get_data()['message'] );

		// A well-formed ip:port hop is normalised and then evaluated by the
		// allowlist. Allowlisting that exact address proves normalisation
		// happened (a fail-closed result would be 401, not 200).
		Settings::update( array( 'order_api_allowed_ips' => '203.0.113.9' ) );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9:4321';

		WPS_Assert::same( 200, $call( $payload( array( 'id' => 'PEP-API-PORT' ) ), array( 'apikey' => 'secret-key' ) )->get_status() );

		// A /0 trusted range must never be accepted: it would trust everyone.
		Settings::update(
			array(
				'order_api_allowed_ips'     => '203.0.113.9',
				'order_api_trusted_proxies' => '0.0.0.0/0',
			)
		);
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';

		WPS_Assert::same( 401, $call( $payload( array( 'id' => 'PEP-API-SLASH0' ) ), array( 'apikey' => 'secret-key' ) )->get_status() );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
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
