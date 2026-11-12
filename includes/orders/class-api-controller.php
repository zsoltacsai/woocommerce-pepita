<?php
/**
 * REST endpoint receiving Pepita order push notifications.
 *
 * Route: POST /wp-json/wps/v1/pepita/order?apikey=...
 *
 * The shared secret is accepted from the documented `apikey` query parameter
 * and, as a convenience for proxies, from the X-WPS-Api-Key header. Responses
 * follow the documented Pepita structure: {isError, responseCode, message}.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Orders;

use WPS\Pepita\Logger;
use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Api_Controller {

	public const REST_NAMESPACE = 'wps/v1';
	public const REST_ROUTE     = '/pepita/order';

	/**
	 * Requests per minute per IP. Filters allow tuning for high volume shops.
	 */
	private const DEFAULT_RATE_LIMIT = 120;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Authentication happens in handle().
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! (bool) Settings::get( 'order_api_enabled', true ) ) {
			return $this->error( 503, 'A Pepita rendelésfogadás ki van kapcsolva ebben az áruházban.' );
		}

		$auth = $this->authorize( $request );

		if ( true !== $auth ) {
			return $this->error( 401, $auth );
		}

		if ( ! $this->transport_allowed() ) {
			return $this->error( 403, 'A rendelésfogadás csak HTTPS kapcsolaton engedélyezett.' );
		}

		$rate = $this->check_rate_limit();

		if ( true !== $rate ) {
			return $this->error( 429, $rate );
		}

		$body = (string) $request->get_body();

		$max_kb = max( 16, (int) Settings::get( 'order_api_max_body_kb', 512 ) );

		if ( strlen( $body ) > $max_kb * KB_IN_BYTES ) {
			return $this->error( 413, sprintf( 'A kérés törzse túl nagy (max. %d KB).', $max_kb ) );
		}

		if ( '' === trim( $body ) ) {
			return $this->error( 400, 'Üres kérés érkezett.' );
		}

		$payload = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			return $this->error( 400, 'A kérés törzse nem érvényes JSON objektum.' );
		}

		$result = ( new Importer() )->import( $payload );

		if ( 'created' === $result['status'] || 'duplicate' === $result['status'] ) {
			return $this->success( (string) $result['message'], (int) $result['order_id'] );
		}

		return $this->error( (int) $result['http'], (string) $result['message'] );
	}

	/**
	 * @return true|string
	 */
	private function authorize( \WP_REST_Request $request ) {
		$expected = Settings::secret( 'order_api_key' );

		if ( '' === $expected ) {
			Logger::error( 'order_api', 'A rendelésfogadó API kulcs nincs beállítva, minden kérés elutasítva.' );

			return 'A rendelésfogadó API nincs konfigurálva. Kérjük, értesítse az áruház üzemeltetőjét.';
		}

		$provided = (string) $request->get_param( 'apikey' );

		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x-wps-api-key' );
		}

		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x-api-key' );
		}

		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			$this->log_rejected( 'invalid_apikey' );

			return 'Érvénytelen vagy hiányzó API kulcs.';
		}

		$allowed = $this->allowed_ips();

		if ( array() !== $allowed ) {
			$ip = $this->client_ip();

			if ( ! in_array( $ip, $allowed, true ) ) {
				$this->log_rejected( 'ip_not_allowed', array( 'ip' => $ip ) );

				return 'A kérés forrás IP címe nincs engedélyezve.';
			}
		}

		return true;
	}

	/**
	 * @return true|string
	 */
	private function transport_allowed() {
		if ( ! (bool) Settings::get( 'order_api_require_https', false ) ) {
			return true;
		}

		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			return true;
		}

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
			: '';

		return 'https' === $forwarded;
	}

	/**
	 * @return true|string
	 */
	private function check_rate_limit() {
		$limit = (int) apply_filters( 'wps_pepita_order_rate_limit', self::DEFAULT_RATE_LIMIT );

		if ( $limit <= 0 ) {
			return true;
		}

		$key = 'wps_pepita_api_rate_' . md5( $this->client_ip() );

		$current = (int) get_transient( $key );

		if ( $current >= $limit ) {
			$this->log_rejected( 'rate_limited', array( 'ip' => $this->client_ip() ) );

			return 'Túl sok kérés érkezett. Kérjük, próbálja újra egy perc múlva.';
		}

		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * @return string[]
	 */
	private function allowed_ips(): array {
		$raw = (string) Settings::get( 'order_api_allowed_ips', '' );

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$parts = preg_split( '/[\s,;]+/', $raw ) ?: array();
		$ips   = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			if ( false !== filter_var( $part, FILTER_VALIDATE_IP ) ) {
				$ips[] = $part;
			}
		}

		return array_values( array_unique( $ips ) );
	}

	private function client_ip(): string {
		$candidates = array();

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = (string) wp_unslash( $_SERVER[ $key ] );
			$value = trim( explode( ',', $value )[0] );

			if ( '' !== $value && false !== filter_var( $value, FILTER_VALIDATE_IP ) ) {
				$candidates[] = $value;
			}
		}

		/**
		 * The trusted proxy list is intentionally narrow: forwarded headers are
		 * only honoured when the administrator has explicitly configured the
		 * allowed IP list. Otherwise REMOTE_ADDR is authoritative.
		 */
		if ( array() !== $this->allowed_ips() && array() !== $candidates ) {
			return $candidates[0];
		}

		return isset( $_SERVER['REMOTE_ADDR'] )
			? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] )
			: '0.0.0.0';
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log_rejected( string $reason, array $context = array() ): void {
		$key = 'wps_pepita_api_log_' . md5( $this->client_ip() . '|' . $reason );

		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, MINUTE_IN_SECONDS );

		Logger::warning(
			'order_api',
			'Pepita rendelés API kérés elutasítva.',
			array_merge(
				array(
					'reason' => $reason,
					'ip'     => $this->client_ip(),
				),
				$context
			)
		);
	}

	private function success( string $message, int $order_id ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'isError'      => false,
				'responseCode' => 200,
				'message'      => '' !== $message ? $message : 'A rendelés sikeresen fogadva.',
				'orderId'      => $order_id,
			),
			200
		);
	}

	private function error( int $status, string $message ): \WP_REST_Response {
		$status = max( 400, min( 599, $status ) );

		return new \WP_REST_Response(
			array(
				'isError'      => true,
				'responseCode' => $status,
				'message'      => $message,
			),
			$status
		);
	}
}
