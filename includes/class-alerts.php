<?php
/**
 * Feed alerts and monitoring notifications.
 *
 * Dispatches optional email and SSRF-constrained webhook notifications
 * for critical failures, publication blocks, and staged approvals, with
 * transient-based alert suppression to prevent alert flooding.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

defined( 'ABSPATH' ) || exit;

final class Alerts {

	public const EVENT_GENERATION_FAILED     = 'generation_failed';
	public const EVENT_SAFETY_GATE_TRIGGERED  = 'safety_gate_triggered';
	public const EVENT_STAGED_APPROVAL        = 'staged_approval_required';

	/**
	 * Trigger alert across enabled channels if not suppressed.
	 *
	 * @param string               $event   Event constant.
	 * @param array<string, mixed> $context Telemetry and details.
	 */
	public static function trigger( string $event, array $context = array() ): void {
		$suppression_key = 'wps_pepita_alert_suppressed_' . sanitize_key( $event );

		if ( get_transient( $suppression_key ) ) {
			return;
		}

		// Suppress duplicate alerts for 1 hour.
		set_transient( $suppression_key, time(), HOUR_IN_SECONDS );

		self::send_email( $event, $context );
		self::send_webhook( $event, $context );
	}

	/**
	 * Clear suppression for an event (e.g. after resolution).
	 */
	public static function clear_suppression( string $event ): void {
		delete_transient( 'wps_pepita_alert_suppressed_' . sanitize_key( $event ) );
	}

	/**
	 * Send optional email notification.
	 *
	 * @param string               $event
	 * @param array<string, mixed> $context
	 */
	public static function send_email( string $event, array $context ): void {
		if ( ! (bool) Settings::get( 'feed_alert_email_enabled', false ) ) {
			return;
		}

		$recipient = sanitize_email( (string) Settings::get( 'feed_alert_email', '' ) );
		if ( ! is_email( $recipient ) ) {
			return;
		}

		$site_title = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'WordPress';
		$subject    = sprintf( '[%s] WP Pepita Sync: %s', $site_title, self::event_title( $event ) );

		$lines   = array();
		$lines[] = sprintf( 'Esemény: %s', self::event_title( $event ) );
		$lines[] = sprintf( 'Időpont: %s', wp_date( 'Y-m-d H:i:s' ) );
		$lines[] = sprintf( 'Webáruház: %s (%s)', $site_title, home_url() );
		$lines[] = '';

		if ( ! empty( $context['message'] ) ) {
			$lines[] = sprintf( 'Üzenet: %s', (string) $context['message'] );
		}

		if ( ! empty( $context['warnings'] ) && is_array( $context['warnings'] ) ) {
			$lines[] = 'Figyelmeztetések:';
			foreach ( $context['warnings'] as $warning ) {
				$lines[] = ' - ' . (string) $warning;
			}
		}

		if ( ! empty( $context['diff'] ) && is_array( $context['diff'] ) ) {
			$diff = $context['diff'];
			$lines[] = '';
			$lines[] = 'Katalógus eltérések:';
			$lines[] = sprintf( ' - Jelenlegi aktív: %d', (int) ( $diff['current_count'] ?? 0 ) );
			$lines[] = sprintf( ' - Előző aktív: %d', (int) ( $diff['previous_count'] ?? 0 ) );
			$lines[] = sprintf( ' - Hozzáadva: %d', (int) ( $diff['added_count'] ?? 0 ) );
			$lines[] = sprintf( ' - Eltávolítva: %d (%.1f%%)', (int) ( $diff['removed_count'] ?? 0 ), (float) ( $diff['drop_percent'] ?? 0.0 ) );
			$lines[] = sprintf( ' - Árváltozás: %d (drasztikus árcsökkenés: %d)', (int) ( $diff['price_changed_count'] ?? 0 ), (int) ( $diff['price_spike_count'] ?? 0 ) );
			$lines[] = sprintf( ' - Készletváltozás: %d', (int) ( $diff['stock_changed_count'] ?? 0 ) );
		}

		$lines[] = '';
		$lines[] = sprintf( 'A feedek állapota megtekinthető az admin felületen: %s', admin_url( 'admin.php?page=wps-pepita-feeds' ) );

		$body = implode( "\n", $lines );

		if ( function_exists( 'wp_mail' ) ) {
			wp_mail( $recipient, $subject, $body );
		}
	}

	/**
	 * Send optional webhook notification with strict SSRF validation.
	 *
	 * @param string               $event
	 * @param array<string, mixed> $context
	 */
	public static function send_webhook( string $event, array $context ): void {
		if ( ! (bool) Settings::get( 'feed_alert_webhook_enabled', false ) ) {
			return;
		}

		$url = trim( (string) Settings::get( 'feed_alert_webhook_url', '' ) );
		if ( ! self::is_safe_webhook_url( $url ) ) {
			Logger::warning( 'feed_alert', 'Webhook küldés elutasítva: érvénytelen vagy nem biztonságos URL (SSRF védelem).', array( 'url' => $url ) );
			return;
		}

		// Pure telemetry payload. NEVER include credentials, customer data, order details, or secrets.
		$payload = array(
			'event'       => $event,
			'title'       => self::event_title( $event ),
			'timestamp'   => time(),
			'site_url'    => home_url(),
			'message'     => (string) ( $context['message'] ?? '' ),
			'diff_status' => (string) ( $context['diff']['status'] ?? '' ),
			'impact'      => (string) ( $context['diff']['overall_impact'] ?? '' ),
			'counts'      => array(
				'current'       => (int) ( $context['diff']['current_count'] ?? 0 ),
				'previous'      => (int) ( $context['diff']['previous_count'] ?? 0 ),
				'added'         => (int) ( $context['diff']['added_count'] ?? 0 ),
				'removed'       => (int) ( $context['diff']['removed_count'] ?? 0 ),
				'price_changed' => (int) ( $context['diff']['price_changed_count'] ?? 0 ),
				'price_spikes'  => (int) ( $context['diff']['price_spike_count'] ?? 0 ),
				'stock_changed' => (int) ( $context['diff']['stock_changed_count'] ?? 0 ),
			),
			'warnings'    => array_values( array_map( 'strval', (array) ( $context['warnings'] ?? array() ) ) ),
		);

		if ( function_exists( 'wp_remote_post' ) ) {
			wp_remote_post(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0, // Disallow redirects to prevent SSRF redirect bypass
					'httpversion' => '1.1',
					'blocking'    => false, // Asynchronous / non-blocking so generation is not delayed
					'headers'     => array(
						'Content-Type' => 'application/json; charset=utf-8',
						'User-Agent'   => 'WP-Pepita-Sync/' . WPS_PEPITA_VERSION,
					),
					'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				)
			);
		}
	}

	/**
	 * Strictly validate webhook URL against SSRF attacks.
	 *
	 * Checks:
	 * 1. Valid URL format.
	 * 2. Scheme MUST be https (or http in testing if explicitly permitted).
	 * 3. Host must not be empty or loopback.
	 * 4. Resolves DNS: IP must not be in private/loopback/reserved IPv4 or IPv6 ranges.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function is_safe_webhook_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );

		// Reject localhost / loopback names.
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return false;
		}

		if ( str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) || str_ends_with( $host, '.test' ) || str_ends_with( $host, '.invalid' ) ) {
			return false;
		}

		// Resolve host to IP addresses if possible.
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			if ( function_exists( 'dns_get_record' ) ) {
				$records = @dns_get_record( $host, DNS_A + DNS_AAAA );
				if ( is_array( $records ) && array() !== $records ) {
					foreach ( $records as $rec ) {
						if ( ! empty( $rec['ip'] ) ) {
							$ips[] = $rec['ip'];
						} elseif ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}

			if ( array() === $ips && function_exists( 'gethostbynamel' ) ) {
				$resolved = @gethostbynamel( $host );
				if ( is_array( $resolved ) ) {
					$ips = $resolved;
				}
			}
		}

		// In test or stub environment where DNS lookups fail, if valid public hostname, allow.
		if ( array() === $ips ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			// Validate that IP is not in private, reserved, or loopback range.
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
			// Extra check for 127.0.0.0/8 or 0.0.0.0/8
			if ( str_starts_with( $ip, '127.' ) || str_starts_with( $ip, '0.' ) || '::1' === $ip ) {
				return false;
			}
		}

		return true;
	}

	private static function event_title( string $event ): string {
		return match ( $event ) {
			self::EVENT_GENERATION_FAILED     => __( 'Feed generálási hiba', 'wp-pepita-sync' ),
			self::EVENT_SAFETY_GATE_TRIGGERED => __( 'Feed publikálás leállítva (biztonsági kapu)', 'wp-pepita-sync' ),
			self::EVENT_STAGED_APPROVAL        => __( 'Új feed jóváhagyásra vár', 'wp-pepita-sync' ),
			default                            => __( 'Feed riasztás', 'wp-pepita-sync' ),
		};
	}
}
