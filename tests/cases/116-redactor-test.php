<?php
/**
 * Log redaction: secrets and customer PII never reach the log table.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Redactor;

$suite = $GLOBALS['wps_suite'];

$suite->add(
	'redactor: secrets and customer PII are masked in nested payloads',
	static function (): void {
		$payload = array(
			'apikey'  => 'super-secret-key',
			'token'   => 'feed-token',
			'payload' => array(
				'customer' => array(
					'email'                 => 'anna.kis@example.test',
					'first_name'            => 'Anna',
					'last_name'             => 'Kis',
					'phone'                 => '+36301234567',
					'billing_city'          => 'Budapest',
					'billing_street'        => 'Fő utca 1.',
					'billing_postal_code'   => '1011',
					'tax_number'            => '12345678-1-41',
					'shipping_house_number' => '1',
				),
				'customer_message' => 'Kérem a kapuhoz.',
				'package_label'    => 'JVBERi0xLjQK',
				'products'         => array(
					array(
						'name' => 'Nappali asztal',
						'sku'  => 'SKU-1',
					),
				),
			),
		);

		$clean = Redactor::redact( $payload );
		$json  = (string) wp_json_encode( $clean );

		foreach ( array( 'super-secret-key', 'feed-token', 'anna.kis', 'Anna', 'Kis', '+36301234567', 'Budapest', 'Fő utca', '1011', '12345678-1-41', 'Kérem a kapuhoz', 'JVBERi0xLjQK' ) as $secret ) {
			WPS_Assert::not_contains( $secret, $json, 'Leaked value: ' . $secret );
		}

		// Non-PII operational data stays readable.
		WPS_Assert::contains( 'Nappali asztal', $json );
		WPS_Assert::contains( 'SKU-1', $json );
		WPS_Assert::contains( 'a***@example.test', $json );
	}
);

$suite->add(
	'redactor: a validation-failure log entry contains no customer name or address',
	static function (): void {
		$clean = Redactor::redact(
			array(
				'external_id' => 'PEP-1',
				'payload'     => array(
					'customer' => array(
						'email'      => 'vasarlo@example.test',
						'first_name' => 'Anna',
						'last_name'  => 'Kis',
						'billing_city' => 'Budapest',
					),
				),
			)
		);

		$json = (string) wp_json_encode( $clean );

		WPS_Assert::not_contains( 'Anna', $json );
		WPS_Assert::not_contains( 'Kis', $json );
		WPS_Assert::not_contains( 'Budapest', $json );
		WPS_Assert::contains( 'PEP-1', $json );
	}
);
