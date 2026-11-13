<?php
/**
 * Serves the pre-generated feeds with token authentication, ETag and 304
 * support. The feed is never rebuilt during an HTTP request.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Feed;

use WPS\Pepita\Logger;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

defined( 'ABSPATH' ) || exit;

final class Endpoint {

	public function register(): void {
		add_action( 'init', array( $this, 'maybe_serve' ), 0 );
	}

	public function maybe_serve(): void {
		if ( ! isset( $_GET['wps_feed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$requested = sanitize_key( wp_unslash( (string) $_GET['wps_feed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type      = $this->resolve_type( $requested );

		if ( null === $type ) {
			$this->deny( 404, 'unknown_feed' );
		}

		if ( ! $this->authorize() ) {
			$this->deny( 403, 'invalid_token' );
		}

		if ( Settings::is_feed_emergency_disabled() ) {
			$this->fail( 503, 'A feed kiszolgálás átmenetileg szünetel (vészhelyzeti leállítás aktív).', $type );
		}

		$state = ( new Generator() )->get_feed_state( $type );

		if ( ! Storage::exists( $type ) ) {
			$this->fail( 503, 'A feed még nem készült el. Kérjük, próbálja újra később.', $type );
		}

		$path = Storage::feed_path( $type );
		$size = Storage::size( $type );
		$mtime = Storage::modified( $type );

		if ( $size <= 0 || $mtime <= 0 ) {
			$this->fail( 503, 'A feed átmenetileg nem elérhető.', $type );
		}

		$hash  = (string) ( $state['hash'] ?? '' );
		$etag  = '"' . ( '' !== $hash ? substr( $hash, 0, 32 ) : substr( md5( $path . $mtime . $size ), 0, 32 ) ) . '-' . $size . '"';
		$max_age = max( 0, (int) Settings::get( 'feed_cache_max_age', 300 ) );

		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) )
			: '';

		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
			? (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
			: '';

		if ( $if_none_match === $etag ) {
			$this->send_common_headers( $type, $etag, $mtime, $max_age );
			status_header( 304 );
			exit;
		}

		if ( '' !== $if_modified_since ) {
			$since = strtotime( $if_modified_since );

			if ( false !== $since && $since >= $mtime ) {
				$this->send_common_headers( $type, $etag, $mtime, $max_age );
				status_header( 304 );
				exit;
			}
		}

		$this->send_common_headers( $type, $etag, $mtime, $max_age );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: inline; filename="' . ( Generator::TYPE_FULL === $type ? 'pepita-full.xml' : 'pepita-stock.xml' ) . '"' );
		status_header( 200 );

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			$this->fail( 503, 'A feed fájl nem olvasható.', $type );
		}

		fpassthru( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private function resolve_type( string $requested ): ?string {
		$full_slug  = sanitize_key( (string) Settings::get( 'feed_full_slug', 'full' ) );
		$stock_slug = sanitize_key( (string) Settings::get( 'feed_stock_slug', 'stock' ) );

		if ( Generator::TYPE_FULL === $requested || $full_slug === $requested ) {
			return Generator::TYPE_FULL;
		}

		if ( Generator::TYPE_STOCK === $requested || $stock_slug === $requested ) {
			return Generator::TYPE_STOCK;
		}

		return null;
	}

	private function authorize(): bool {
		if ( (bool) Settings::get( 'feed_public', false ) ) {
			return true;
		}

		$expected = Settings::secret( 'feed_token' );

		if ( '' === $expected ) {
			return false;
		}

		$provided = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $provided && isset( $_SERVER['HTTP_X_WPS_FEED_TOKEN'] ) ) {
			$provided = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_WPS_FEED_TOKEN'] ) );
		}

		if ( '' === $provided ) {
			return false;
		}

		return hash_equals( $expected, $provided );
	}

	private function send_common_headers( string $type, string $etag, int $mtime, int $max_age ): void {
		$public = (bool) Settings::get( 'feed_public', false );

		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );

		if ( $public ) {
			header( 'Cache-Control: public, max-age=' . $max_age . ', must-revalidate' );
			header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $max_age ) . ' GMT' );
		} else {
			// A token-protected feed must never be stored by a shared cache:
			// the token is part of the URL and the body is private catalog
			// data. Every request is revalidated against WordPress.
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'Pragma: no-cache' );
		}

		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-WPS-Feed-Type: ' . $type );
	}

	private function deny( int $status, string $reason ): void {
		$this->log_denied( $reason );

		status_header( $status );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo 'A kért feed nem érhető el.';
		exit;
	}

	private function fail( int $status, string $message, string $type ): void {
		$this->log_denied( 'feed_unavailable_' . $type );

		status_header( $status );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Retry-After: 300' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo esc_html( $message );
		exit;
	}

	private function log_denied( string $reason ): void {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$key = 'wps_pepita_feed_log_' . md5( $ip . '|' . $reason );

		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, MINUTE_IN_SECONDS );

		Logger::warning(
			'feed_access',
			'Feed kérés elutasítva vagy nem teljesíthető.',
			array(
				'reason' => $reason,
				'ip'     => $ip,
			)
		);
	}
}
