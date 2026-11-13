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
 * Trust model:
 * - The client address is REMOTE_ADDR unless the direct peer is listed in the
 *   explicit `order_api_trusted_proxies` setting. Forwarding headers are never
 *   read for an untrusted peer.
 * - Transport is checked before the API key is read, so a plaintext request
 *   never has its key compared (or logged).
 * - Failed authentication is throttled before the key comparison, and log
 *   writes are bounded per minute.
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
	 * Authenticated requests per minute per client. Filters allow tuning for
	 * high volume shops.
	 */
	private const DEFAULT_RATE_LIMIT = 120;

	/**
	 * Failed authentication attempts per minute per client before the endpoint
	 * answers 429 without comparing the key.
	 */
	private const DEFAULT_FAILED_AUTH_LIMIT = 30;

	/**
	 * Maximum number of rejection log rows written per minute across all
	 * clients, regardless of how many source addresses are used.
	 */
	private const LOG_BUDGET_PER_MINUTE = 120;

	/**
	 * Minimum length accepted for a key defined in wp-config.php.
	 */
	private const MIN_CONSTANT_KEY_LENGTH = 32;

	/**
	 * @var array<string, string[]>
	 */
	private static array $proxy_cache = array();

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
		if ( Settings::is_beta_feed_only() ) {
			return $this->error( 503, 'A Pepita rendelésfogadás a béta időszakban ki van kapcsolva (feed-only béta).' );
		}

		if ( ! (bool) Settings::get( 'order_api_enabled', true ) ) {
			return $this->error( 503, 'A Pepita rendelésfogadás ki van kapcsolva ebben az áruházban.' );
		}

		$ip = $this->client_ip();

		// Transport first: never read, compare or log a credential that arrived
		// over a connection the operator asked us to refuse.
		if ( ! $this->transport_allowed() ) {
			return $this->error( 403, 'A rendelésfogadás csak HTTPS kapcsolaton engedélyezett.' );
		}

		$failed = $this->check_failed_auth_limit( $ip );

		if ( true !== $failed ) {
			return $this->error( 429, $failed );
		}

		$auth = $this->authorize( $request, $ip );

		if ( true !== $auth ) {
			return $this->error( 401, $auth );
		}

		$rate = $this->check_rate_limit( $ip );

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
	private function authorize( \WP_REST_Request $request, string $ip ) {
		$expected = Settings::secret( 'order_api_key' );

		if ( '' === $expected ) {
			$this->log_rejected( 'not_configured', array(), $ip );

			return 'A rendelésfogadó API nincs konfigurálva. Kérjük, értesítse az áruház üzemeltetőjét.';
		}

		if ( Settings::using_constant( 'order_api_key' ) && strlen( $expected ) < self::MIN_CONSTANT_KEY_LENGTH ) {
			$this->log_rejected( 'weak_configured_key', array(), $ip );

			return 'A rendelésfogadó API kulcs konfigurációja érvénytelen. Kérjük, értesítse az áruház üzemeltetőjét.';
		}

		$provided = (string) $request->get_param( 'apikey' );

		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x-wps-api-key' );
		}

		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x-api-key' );
		}

		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			$this->record_failed_auth( $ip );
			$this->log_rejected( 'invalid_apikey', array(), $ip );

			return 'Érvénytelen vagy hiányzó API kulcs.';
		}

		$allowed = $this->allowed_ips();

		if ( array() !== $allowed ) {
			if ( ! in_array( $ip, $allowed, true ) ) {
				$this->log_rejected( 'ip_not_allowed', array( 'ip' => $ip ), $ip );

				return 'A kérés forrás IP címe nincs engedélyezve.';
			}
		}

		return true;
	}

	/**
	 * @return true|string
	 */
	private function transport_allowed() {
		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			return true;
		}

		if ( ! (bool) Settings::get( 'order_api_require_https', true ) ) {
			return true;
		}

		$remote = $this->remote_addr();

		if ( ! $this->is_trusted_proxy( $remote ) ) {
			return false;
		}

		$proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
			: '';

		// A chain may look like "https,http"; the value added by the trusted
		// proxy closest to us is the rightmost one.
		$parts = array_filter( array_map( 'trim', explode( ',', $proto ) ) );

		return 'https' === (string) end( $parts );
	}

	/**
	 * @return true|string
	 */
	private function check_failed_auth_limit( string $ip ) {
		$limit = (int) apply_filters( 'wps_pepita_order_failed_auth_limit', self::DEFAULT_FAILED_AUTH_LIMIT );

		if ( $limit <= 0 ) {
			return true;
		}

		$state = get_transient( $this->failed_auth_key( $ip ) );

		if ( ! is_array( $state ) ) {
			return true;
		}

		$reset = (int) ( $state['reset'] ?? 0 );
		$count = (int) ( $state['count'] ?? 0 );

		if ( $reset <= time() || $count < $limit ) {
			return true;
		}

		$this->log_rejected( 'failed_auth_limited', array( 'ip' => $ip ), $ip );

		return 'Túl sok sikertelen hitelesítési kísérlet. Kérjük, próbálja újra egy perc múlva.';
	}

	private function record_failed_auth( string $ip ): void {
		$key   = $this->failed_auth_key( $ip );
		$now   = time();
		$state = get_transient( $key );
		$reset = is_array( $state ) ? (int) ( $state['reset'] ?? 0 ) : 0;
		$count = is_array( $state ) ? (int) ( $state['count'] ?? 0 ) : 0;

		if ( $reset <= $now ) {
			$count = 0;
			$reset = $now + MINUTE_IN_SECONDS;
		}

		set_transient(
			$key,
			array(
				'count' => $count + 1,
				'reset' => $reset,
			),
			2 * MINUTE_IN_SECONDS
		);
	}

	private function failed_auth_key( string $ip ): string {
		return 'wps_pepita_api_fail_' . md5( $ip );
	}

	/**
	 * @return true|string
	 */
	private function check_rate_limit( string $ip ) {
		$limit = (int) apply_filters( 'wps_pepita_order_rate_limit', self::DEFAULT_RATE_LIMIT );

		if ( $limit <= 0 ) {
			return true;
		}

		$key = 'wps_pepita_api_rate_' . md5( $ip );

		$current = (int) get_transient( $key );

		if ( $current >= $limit ) {
			$this->log_rejected( 'rate_limited', array( 'ip' => $ip ), $ip );

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

	/**
	 * Explicit trusted reverse proxies (IP addresses or CIDR ranges).
	 *
	 * @return string[]
	 */
	private function trusted_proxies(): array {
		$raw = (string) Settings::get( 'order_api_trusted_proxies', '' );

		if ( isset( self::$proxy_cache[ $raw ] ) ) {
			return self::$proxy_cache[ $raw ];
		}

		$proxies = array();

		foreach ( preg_split( '/[\s,;]+/', $raw ) ?: array() as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			if ( $this->is_valid_proxy_entry( $part ) ) {
				$proxies[] = $part;
			}
		}

		self::$proxy_cache[ $raw ] = $proxies;

		return $proxies;
	}

	private function is_valid_proxy_entry( string $entry ): bool {
		if ( false !== filter_var( $entry, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		if ( ! str_contains( $entry, '/' ) ) {
			return false;
		}

		list( $ip, $prefix ) = explode( '/', $entry, 2 );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) || ! ctype_digit( $prefix ) ) {
			return false;
		}

		$max = str_contains( $ip, ':' ) ? 128 : 32;

		// A zero-length prefix would trust every address; never allow it.
		return (int) $prefix >= 1 && (int) $prefix <= $max;
	}

	private function is_trusted_proxy( string $ip ): bool {
		$proxies = $this->trusted_proxies();

		if ( array() === $proxies || '' === $ip ) {
			return false;
		}

		foreach ( $proxies as $entry ) {
			if ( $this->ip_in_range( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IP belongs to an address or CIDR range.
	 */
	private function ip_in_range( string $ip, string $entry ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( ! str_contains( $entry, '/' ) ) {
			return hash_equals( $entry, $ip );
		}

		list( $range, $prefix ) = explode( '/', $entry, 2 );

		$ip_bin    = inet_pton( $ip );
		$range_bin = inet_pton( $range );

		if ( false === $ip_bin || false === $range_bin || strlen( $ip_bin ) !== strlen( $range_bin ) ) {
			return false;
		}

		$bits  = (int) $prefix;
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $range_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = chr( ( 0xFF << ( 8 - $rest ) ) & 0xFF );

		return ( $ip_bin[ $bytes ] & $mask ) === ( $range_bin[ $bytes ] & $mask );
	}

	private function remote_addr(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '0.0.0.0';
		}

		$value = trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '0.0.0.0';
	}

	/**
	 * Resolve the client address without trusting client-supplied headers.
	 *
	 * Forwarding headers are honoured only when the direct peer is an
	 * explicitly configured trusted proxy. In that case the chain is walked
	 * from the right (closest to us) and the first untrusted hop is the client.
	 */
	private function client_ip(): string {
		$remote = $this->remote_addr();

		if ( ! $this->is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		$chain = array();

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$chain = array( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$chain = array( (string) wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}

		$chain[] = $remote;

		foreach ( array_reverse( $chain ) as $candidate ) {
			$candidate = self::normalize_ip( (string) $candidate );

			// An unparseable hop means the chain cannot be trusted. Fail
			// closed with a sentinel that never matches an allowlist and gets
			// its own rate-limit bucket, instead of silently falling back to
			// the proxy address (which an allowlist may contain).
			if ( null === $candidate ) {
				return '0.0.0.0';
			}

			if ( $this->is_trusted_proxy( $candidate ) ) {
				continue;
			}

			return $candidate;
		}

		return $remote;
	}

	/**
	 * Normalise a forwarding-hop value: accept a bare IP or the common
	 * `ip:port` / `[ipv6]:port` forms. Returns null when the value is not an
	 * address, so the caller can fail closed.
	 */
	private static function normalize_ip( string $value ): ?string {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		if ( false !== filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return $value;
		}

		if ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $match ) ) {
			return false !== filter_var( $match[1], FILTER_VALIDATE_IP ) ? $match[1] : null;
		}

		if ( preg_match( '/^\[([0-9a-fA-F:]+)\]:\d+$/', $value, $match ) ) {
			return false !== filter_var( $match[1], FILTER_VALIDATE_IP ) ? $match[1] : null;
		}

		return null;
	}

	/**
	 * Write at most one rejection row per client/reason/minute, and at most
	 * LOG_BUDGET_PER_MINUTE rows per minute overall.
	 *
	 * @param array<string, mixed> $context
	 */
	private function log_rejected( string $reason, array $context = array(), string $ip = '' ): void {
		if ( '' === $ip ) {
			$ip = $this->client_ip();
		}

		$key = 'wps_pepita_api_log_' . md5( $ip . '|' . $reason );

		if ( false !== get_transient( $key ) ) {
			return;
		}

		$budget_key = 'wps_pepita_api_log_budget';
		$now        = time();
		$budget     = get_transient( $budget_key );

		if ( ! is_array( $budget ) || (int) ( $budget['reset'] ?? 0 ) <= $now ) {
			$budget = array(
				'count' => 0,
				'reset' => $now + MINUTE_IN_SECONDS,
			);
		}

		if ( (int) $budget['count'] >= self::LOG_BUDGET_PER_MINUTE ) {
			return;
		}

		$budget['count'] = (int) $budget['count'] + 1;

		set_transient( $budget_key, $budget, 2 * MINUTE_IN_SECONDS );
		set_transient( $key, 1, MINUTE_IN_SECONDS );

		Logger::warning(
			'order_api',
			'Pepita rendelés API kérés elutasítva.',
			array_merge(
				array(
					'reason' => $reason,
					'ip'     => $ip,
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
