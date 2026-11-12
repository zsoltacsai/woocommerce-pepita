<?php
/**
 * Integration test bootstrap.
 *
 * Loaded by `wp eval-file` inside the WP-CLI container, so WordPress,
 * WooCommerce and the plugin are fully bootstrapped. Tests use the real
 * database, the real WooCommerce CRUD API and real HTTP requests to the
 * Apache container.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal assertion and HTTP helper for the integration suite.
 */
final class WPS_IT {

	public static int $passed = 0;
	public static int $failed = 0;

	/** @var string[] */
	public static array $failures = array();

	private static string $run_id = '';

	public static function base_url(): string {
		$url = (string) getenv( 'WPS_IT_BASE_URL' );

		if ( '' === $url ) {
			$url = home_url();
		}

		return rtrim( $url, '/' );
	}

	public static function fixtures(): string {
		return '/integration/fixtures';
	}

	public static function run_id(): string {
		if ( '' === self::$run_id ) {
			self::$run_id = gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 4, false, false ) );
		}

		return self::$run_id;
	}

	public static function test( string $name, callable $callback ): void {
		try {
			$callback();
			++self::$passed;
			self::line( '  PASS  ' . $name );
		} catch ( \Throwable $throwable ) {
			++self::$failed;
			self::$failures[] = $name . ': ' . $throwable->getMessage();
			self::line( '  FAIL  ' . $name );
			self::line( '        ' . $throwable->getMessage() );
		}
	}

	public static function line( string $message ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::log( $message );
		} else {
			echo $message . "\n";
		}
	}

	public static function true( $condition, string $message = 'Expected true.' ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	public static function false( $condition, string $message = 'Expected false.' ): void {
		if ( $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	public static function same( $expected, $actual, string $message = '' ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				'' !== $message
					? $message . ' Expected ' . self::export( $expected ) . ', got ' . self::export( $actual ) . '.'
					: 'Expected ' . self::export( $expected ) . ', got ' . self::export( $actual ) . '.'
			);
		}
	}

	public static function not_same( $unexpected, $actual, string $message = '' ): void {
		if ( $unexpected === $actual ) {
			throw new \RuntimeException(
				'' !== $message ? $message : 'Did not expect ' . self::export( $actual ) . '.'
			);
		}
	}

	public static function contains( string $needle, string $haystack, string $message = '' ): void {
		if ( ! str_contains( $haystack, $needle ) ) {
			throw new \RuntimeException(
				'' !== $message
					? $message
					: 'Expected to find "' . $needle . '" in a ' . strlen( $haystack ) . '-byte string.'
			);
		}
	}

	public static function not_contains( string $needle, string $haystack, string $message = '' ): void {
		if ( str_contains( $haystack, $needle ) ) {
			throw new \RuntimeException(
				'' !== $message ? $message : 'Did not expect to find "' . $needle . '".'
			);
		}
	}

	public static function count( int $expected, array $actual, string $message = '' ): void {
		if ( count( $actual ) !== $expected ) {
			throw new \RuntimeException(
				'' !== $message
					? $message . ' Expected ' . $expected . ', got ' . count( $actual ) . '.'
					: 'Expected ' . $expected . ' items, got ' . count( $actual ) . '.'
			);
		}
	}

	public static function greater( $minimum, $actual, string $message = '' ): void {
		if ( $actual <= $minimum ) {
			throw new \RuntimeException(
				'' !== $message
					? $message
					: 'Expected a value greater than ' . self::export( $minimum ) . ', got ' . self::export( $actual ) . '.'
			);
		}
	}

	public static function instance( string $class, $value, string $message = '' ): void {
		if ( ! $value instanceof $class ) {
			throw new \RuntimeException(
				'' !== $message ? $message : 'Expected an instance of ' . $class . '.'
			);
		}
	}

	/**
	 * Perform a real HTTP request against the WordPress container.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Path beginning with `/`.
	 * @param array<string, mixed> $args   wp_remote_request() arguments.
	 * @return array{code: int, headers: array<string, string>, body: string, json: mixed}
	 */
	public static function http( string $method, string $path, array $args = array() ): array {
		$url = str_starts_with( $path, 'http' ) ? $path : self::base_url() . $path;

		$defaults = array(
			'method'      => strtoupper( $method ),
			'timeout'     => 60,
			'redirection' => 0,
			'sslverify'   => false,
		);

		$response = wp_remote_request( $url, array_merge( $defaults, $args ) );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'HTTP request failed: ' . $response->get_error_message() . ' (' . $url . ')' );
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => array_change_key_case( $headers, CASE_LOWER ),
			'body'    => $body,
			'json'    => json_decode( $body, true ),
		);
	}

	/**
	 * POST a JSON payload.
	 *
	 * @param string               $path Path.
	 * @param array<string, mixed> $data Payload.
	 * @param array<string, mixed> $args Extra arguments.
	 * @return array{code: int, headers: array<string, string>, body: string, json: mixed}
	 */
	public static function post_json( string $path, array $data, array $args = array() ): array {
		return self::http(
			'POST',
			$path,
			array_merge(
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $data ),
				),
				$args
			)
		);
	}

	/**
	 * Load a fixture and substitute placeholders.
	 *
	 * @param string                $name         Fixture filename.
	 * @param array<string, string> $replacements Placeholder => value (without braces).
	 * @return array<string, mixed>
	 */
	public static function fixture( string $name, array $replacements = array() ): array {
		$path = self::fixtures() . '/' . $name;

		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( 'Missing fixture: ' . $path );
		}

		$raw = (string) file_get_contents( $path );

		foreach ( $replacements as $key => $value ) {
			$raw = str_replace( '{{' . $key . '}}', (string) $value, $raw );
		}

		$data = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			throw new \RuntimeException( 'Fixture is not valid JSON: ' . $name );
		}

		return $data;
	}

	public static function label_base64(): string {
		$path = self::fixtures() . '/label.pdf';

		return base64_encode( (string) file_get_contents( $path ) );
	}

	/**
	 * Build a feed URL against the HTTP target, not the WP-CLI hostname.
	 */
	public static function feed_url( string $type, ?string $token = null ): string {
		$args = array( 'wps_feed' => $type );

		if ( null !== $token ) {
			$args['token'] = $token;
		}

		return add_query_arg( $args, self::base_url() . '/' );
	}

	/**
	 * Parse an XML document, failing the test on malformed input.
	 */
	public static function xml( string $body ): \DOMDocument {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadXML( $body, LIBXML_NONET );
		$errors   = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			$first = $errors[0] ?? null;

			throw new \RuntimeException(
				'Malformed XML' . ( $first ? ': ' . trim( $first->message ) : '.' )
			);
		}

		return $document;
	}

	public static function xpath( \DOMDocument $document ): \DOMXPath {
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'p', \WPS\Pepita\Feed\Xml_Writer::NAMESPACE_URI );

		return $xpath;
	}

	/**
	 * @return array<string, \DOMElement> Product nodes keyed by Pepita export id.
	 */
	public static function products( \DOMDocument $document ): array {
		$xpath    = self::xpath( $document );
		$products = array();

		foreach ( $xpath->query( '//p:Product' ) ?: array() as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$id = self::text( $xpath, $node, './p:Id' );

			if ( '' !== $id ) {
				$products[ $id ] = $node;
			}
		}

		return $products;
	}

	public static function text( \DOMXPath $xpath, \DOMNode $context, string $path ): string {
		$nodes = $xpath->query( $path, $context );

		if ( false === $nodes || 0 === $nodes->length ) {
			return '';
		}

		return trim( (string) $nodes->item( 0 )->textContent );
	}

	public static function node_count( \DOMXPath $xpath, \DOMNode $context, string $path ): int {
		$nodes = $xpath->query( $path, $context );

		return false === $nodes ? 0 : $nodes->length;
	}

	/**
	 * @return string[]
	 */
	public static function texts( \DOMXPath $xpath, \DOMNode $context, string $path ): array {
		$nodes = $xpath->query( $path, $context );

		if ( false === $nodes ) {
			return array();
		}

		$values = array();

		foreach ( $nodes as $node ) {
			$values[] = trim( (string) $node->textContent );
		}

		return $values;
	}

	public static function close( float $expected, float $actual, float $delta = 0.01, string $message = '' ): void {
		if ( abs( $expected - $actual ) > $delta ) {
			throw new \RuntimeException(
				'' !== $message
					? $message . ' Expected ' . $expected . ' ± ' . $delta . ', got ' . $actual . '.'
					: 'Expected ' . $expected . ' ± ' . $delta . ', got ' . $actual . '.'
			);
		}
	}

	/**
	 * Orders created by the plugin for one Pepita external id.
	 *
	 * @return \WC_Order[]
	 */
	public static function orders_by_external_id( string $external_id ): array {
		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'type'       => 'shop_order',
				'meta_key'   => \WPS\Pepita\Orders\Importer::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	public static function order_by_external_id( string $external_id ): ?\WC_Order {
		$orders = self::orders_by_external_id( $external_id );

		return $orders[0] ?? null;
	}

	public static function clear_rate_limit( string $ip ): void {
		delete_transient( 'wps_pepita_api_rate_' . md5( $ip ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function seed(): array {
		$seed = get_option( 'wps_it_seed', array() );

		if ( ! is_array( $seed ) || empty( $seed['p1'] ) ) {
			throw new \RuntimeException( 'The integration seed is missing. Run seed.php first.' );
		}

		return $seed;
	}

	/**
	 * Build a documented Pepita payload fixture for this seeded store.
	 *
	 * @param array<string, string> $replacements Extra placeholder overrides.
	 * @return array<string, mixed>
	 */
	public static function order_payload( string $fixture, string $order_id, array $replacements = array() ): array {
		$seed = self::seed();

		$defaults = array(
			'ORDER_ID'      => $order_id,
			'EXPORT_ID'     => (string) $seed['export_p1'],
			'SKU'           => (string) $seed['sku_p1'],
			'EXPORT_ID_VAR'   => (string) $seed['export_var'],
			'EXPORT_ID_VAR_S' => 'wps-' . (int) ( $seed['variation_ids'][0] ?? 0 ),
			'SKU_VAR'         => (string) $seed['sku_var'],
			'SKU_VAR_S'       => 'WPS-IT-VAR-S',
			'LABEL_BASE64'    => self::label_base64(),
		);

		return self::fixture( $fixture, array_merge( $defaults, $replacements ) );
	}

	/**
	 * POST an order payload with the configured API key.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param array<string, mixed> $args    Extra request arguments.
	 * @return array{code: int, headers: array<string, string>, body: string, json: mixed}
	 */
	public static function post_order( array $payload, array $args = array() ): array {
		$path = add_query_arg(
			'apikey',
			rawurlencode( \WPS\Pepita\Settings::secret( 'order_api_key' ) ),
			'/wp-json/wps/v1/pepita/order'
		);

		return self::post_json( $path, $payload, $args );
	}

	public static function remote_ip(): string {
		$response = self::http( 'GET', '/wp-json/wps-it/v1/remote-addr' );
		$ip       = is_array( $response['json'] ) ? (string) ( $response['json']['ip'] ?? '' ) : '';

		if ( '' === $ip ) {
			throw new \RuntimeException( 'Could not determine the source IP through the diagnostics route.' );
		}

		return $ip;
	}

	public static function summary(): int {
		self::line( '' );
		self::line( sprintf( '%d passed, %d failed', self::$passed, self::$failed ) );

		if ( self::$failed > 0 ) {
			self::line( '' );
			self::line( 'Failures:' );

			foreach ( self::$failures as $failure ) {
				self::line( '  - ' . $failure );
			}
		}

		return self::$failed > 0 ? 1 : 0;
	}

	private static function export( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_null( $value ) ) {
			return 'null';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return wp_json_encode( $value );
	}
}

/**
 * Load the plugin classes used by the suite.
 */
require_once WPS_PEPITA_DIR . 'includes/autoload.php';
