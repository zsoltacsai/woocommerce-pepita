<?php
/**
 * WordPress REST API registration, authentication, transport and limits.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Settings;

WPS_IT::line( 'REST API' );

$api_key  = Settings::secret( 'order_api_key' );
$endpoint = '/wp-json/wps/v1/pepita/order';
$run      = WPS_IT::run_id();

WPS_IT::test(
	'The order route is registered in the WordPress REST server',
	static function () use ( $endpoint ): void {
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );
		$routes = $server->get_routes();

		WPS_IT::true( isset( $routes[ '/wps/v1/pepita/order' ] ), 'The Pepita order route is not registered.' );
	}
);

WPS_IT::test(
	'A missing API key returns the documented 401 envelope',
	static function () use ( $endpoint, $run ): void {
		$payload  = WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-NOKEY-' . $run );
		$response = WPS_IT::post_json( $endpoint, $payload );

		WPS_IT::same( 401, $response['code'], 'Expected 401.' );
		WPS_IT::true( is_array( $response['json'] ), 'The response is not JSON.' );
		WPS_IT::same( true, $response['json']['isError'], 'isError must be true.' );
		WPS_IT::same( 401, (int) $response['json']['responseCode'], 'responseCode must match the HTTP status.' );
		WPS_IT::not_same( '', (string) $response['json']['message'], 'The error message is empty.' );
	}
);

WPS_IT::test(
	'An invalid API key returns 401',
	static function () use ( $endpoint, $run ): void {
		$payload  = WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-BADKEY-' . $run );
		$response = WPS_IT::post_json( add_query_arg( 'apikey', 'wrong-key', $endpoint ), $payload );

		WPS_IT::same( 401, $response['code'], 'Expected 401.' );
		WPS_IT::same( true, $response['json']['isError'], 'isError must be true.' );
	}
);

WPS_IT::test(
	'The API key is accepted from X-WPS-Api-Key and X-Api-Key',
	static function () use ( $endpoint, $run, $api_key ): void {
		$first = WPS_IT::post_json(
			$endpoint,
			WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-HEADER-' . $run ),
			array( 'headers' => array( 'Content-Type' => 'application/json', 'X-WPS-Api-Key' => $api_key ) )
		);

		WPS_IT::same( 200, $first['code'], 'X-WPS-Api-Key was rejected: ' . $first['body'] );
		WPS_IT::same( false, $first['json']['isError'], 'The header-authenticated order failed.' );

		$second = WPS_IT::post_json(
			$endpoint,
			WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-XAPIKEY-' . $run ),
			array( 'headers' => array( 'Content-Type' => 'application/json', 'X-Api-Key' => $api_key ) )
		);

		WPS_IT::same( 200, $second['code'], 'X-Api-Key was rejected: ' . $second['body'] );
	}
);

WPS_IT::test(
	'Malformed and empty bodies return 400',
	static function () use ( $endpoint, $api_key ): void {
		$path = add_query_arg( 'apikey', rawurlencode( $api_key ), $endpoint );

		$malformed = WPS_IT::http(
			'POST',
			$path,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => '{"id":',
			)
		);

		WPS_IT::same( 400, $malformed['code'], 'Malformed JSON must return 400.' );
		WPS_IT::true( is_array( $malformed['json'] ), 'Malformed JSON must return a JSON error.' );
		WPS_IT::same( 'rest_invalid_json', (string) $malformed['json']['code'], 'Unexpected malformed JSON error code.' );

		$empty = WPS_IT::http(
			'POST',
			$path,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => '   ',
			)
		);

		WPS_IT::same( 400, $empty['code'], 'An empty body must return 400.' );
	}
);

WPS_IT::test(
	'An oversized body returns 413 before parsing',
	static function () use ( $endpoint, $api_key, $run ): void {
		Settings::update( array( 'order_api_max_body_kb' => 16 ) );

		try {
			$response = WPS_IT::post_json(
				add_query_arg( 'apikey', rawurlencode( $api_key ), $endpoint ),
				array(
					'id'      => 'IT-REST-BIG-' . $run,
					'padding' => str_repeat( 'x', 17 * 1024 ),
				)
			);

			WPS_IT::same( 413, $response['code'], 'Expected 413 for a 17 KB body.' );
			WPS_IT::same( true, $response['json']['isError'], 'Expected an error envelope.' );
		} finally {
			Settings::update( array( 'order_api_max_body_kb' => 512 ) );
		}
	}
);

WPS_IT::test(
	'A disabled order API returns 503 and can be re-enabled',
	static function () use ( $endpoint, $api_key, $run ): void {
		Settings::update( array( 'order_api_enabled' => false ) );

		try {
			$response = WPS_IT::post_json(
				add_query_arg( 'apikey', rawurlencode( $api_key ), $endpoint ),
				WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-DISABLED-' . $run )
			);

			WPS_IT::same( 503, $response['code'], 'A disabled API must return 503.' );
		} finally {
			Settings::enable_order_api( 1, 'integration-test' );
		}

		$enabled = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-REENABLED-' . $run ) );
		WPS_IT::same( 200, $enabled['code'], 'The API did not recover after re-enabling.' );
	}
);

WPS_IT::test(
	'HTTPS enforcement rejects plain HTTP and can be disabled',
	static function () use ( $endpoint, $api_key, $run ): void {
		Settings::update( array( 'order_api_require_https' => true ) );

		try {
			$response = WPS_IT::post_json(
				add_query_arg( 'apikey', rawurlencode( $api_key ), $endpoint ),
				WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-HTTPS-' . $run )
			);

			WPS_IT::same( 403, $response['code'], 'Plain HTTP must be rejected when HTTPS is required.' );
		} finally {
			Settings::update( array( 'order_api_require_https' => false ) );
		}
	}
);

WPS_IT::test(
	'The IP allowlist accepts the real source address and rejects others',
	static function () use ( $endpoint, $api_key, $run ): void {
		$ip = WPS_IT::remote_ip();

		Settings::update( array( 'order_api_allowed_ips' => $ip ) );

		try {
			$allowed = WPS_IT::post_json(
				add_query_arg( 'apikey', rawurlencode( $api_key ), $endpoint ),
				WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-IPOK-' . $run )
			);

			WPS_IT::same( 200, $allowed['code'], 'The allowlisted IP was rejected: ' . $allowed['body'] );

			Settings::update( array( 'order_api_allowed_ips' => '203.0.113.99' ) );

			$denied = WPS_IT::post_json(
				add_query_arg( 'apikey', rawurlencode( $api_key ), $endpoint ),
				WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-IPNO-' . $run )
			);

			WPS_IT::same( 401, $denied['code'], 'A non-allowlisted IP must be rejected.' );
		} finally {
			Settings::update( array( 'order_api_allowed_ips' => '' ) );
		}
	}
);

WPS_IT::test(
	'The per-IP rate limit returns 429 after the configured number of requests',
	static function () use ( $endpoint, $api_key, $run ): void {
		$ip = WPS_IT::remote_ip();

		update_option( 'wps_it_rate_limit', 1, false );
		WPS_IT::clear_rate_limit( $ip );

		try {
			$first = WPS_IT::post_json(
				add_query_arg( 'apikey', rawurlencode( $api_key ), $endpoint ),
				WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-RATE1-' . $run )
			);

			WPS_IT::same( 200, $first['code'], 'The first request should pass the rate limit.' );

			$second = WPS_IT::post_json(
				add_query_arg( 'apikey', rawurlencode( $api_key ), $endpoint ),
				WPS_IT::order_payload( 'order-transfer-unpaid.json', 'IT-REST-RATE2-' . $run )
			);

			WPS_IT::same( 429, $second['code'], 'The second request should be rate limited.' );
			WPS_IT::same( 429, (int) $second['json']['responseCode'], 'responseCode must be 429.' );
		} finally {
			delete_option( 'wps_it_rate_limit' );
			WPS_IT::clear_rate_limit( $ip );
		}
	}
);
