<?php
/**
 * Feed delivery through real Apache, including auth, caching and fail-closed
 * behaviour.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

WPS_IT::line( 'Feed HTTP delivery' );

Settings::update( array( 'feed_public' => false ) );

$token = Settings::secret( 'feed_token' );

WPS_IT::test(
	'The full feed is served over HTTP with the correct headers',
	static function () use ( $token ): void {
		$response = WPS_IT::http( 'GET', WPS_IT::feed_url( 'full', $token ) );

		WPS_IT::same( 200, $response['code'], 'Expected HTTP 200 for the full feed.' );
		WPS_IT::same( 'application/xml; charset=UTF-8', (string) ( $response['headers']['content-type'] ?? '' ), 'Unexpected content type.' );
		WPS_IT::same( 'full', (string) ( $response['headers']['x-wps-feed-type'] ?? '' ), 'Unexpected feed type header.' );
		WPS_IT::contains( 'pepita-full.xml', (string) ( $response['headers']['content-disposition'] ?? '' ), 'Unexpected filename.' );
		WPS_IT::contains( 'noindex', (string) ( $response['headers']['x-robots-tag'] ?? '' ), 'Missing noindex header.' );
		WPS_IT::same( 'nosniff', (string) ( $response['headers']['x-content-type-options'] ?? '' ), 'Missing nosniff header.' );
		WPS_IT::contains( 'max-age=300', (string) ( $response['headers']['cache-control'] ?? '' ), 'Unexpected cache control.' );
		WPS_IT::true( isset( $response['headers']['etag'] ), 'Missing ETag.' );
		WPS_IT::true( isset( $response['headers']['last-modified'] ), 'Missing Last-Modified.' );

		$expected = (string) file_get_contents( Storage::feed_path( Generator::TYPE_FULL ) );

		WPS_IT::same( hash( 'sha256', $expected ), hash( 'sha256', $response['body'] ), 'The HTTP body differs from the published file.' );
		WPS_IT::same( strlen( $expected ), (int) ( $response['headers']['content-length'] ?? 0 ), 'The Content-Length header is wrong.' );
	}
);

WPS_IT::test(
	'The stock feed is served over HTTP with the stock filename',
	static function () use ( $token ): void {
		$response = WPS_IT::http( 'GET', WPS_IT::feed_url( 'stock', $token ) );

		WPS_IT::same( 200, $response['code'], 'Expected HTTP 200 for the stock feed.' );
		WPS_IT::same( 'stock', (string) ( $response['headers']['x-wps-feed-type'] ?? '' ), 'Unexpected feed type header.' );
		WPS_IT::contains( 'pepita-stock.xml', (string) ( $response['headers']['content-disposition'] ?? '' ), 'Unexpected filename.' );
		WPS_IT::same(
			hash( 'sha256', (string) file_get_contents( Storage::feed_path( Generator::TYPE_STOCK ) ) ),
			hash( 'sha256', $response['body'] ),
			'The HTTP body differs from the published stock file.'
		);
	}
);

WPS_IT::test(
	'The token is also accepted from the documented header',
	static function () use ( $token ): void {
		$response = WPS_IT::http(
			'GET',
			WPS_IT::feed_url( 'full' ),
			array( 'headers' => array( 'X-WPS-Feed-Token' => $token ) )
		);

		WPS_IT::same( 200, $response['code'], 'The header token was rejected.' );
	}
);

WPS_IT::test(
	'Missing and invalid tokens are rejected',
	static function (): void {
		$missing = WPS_IT::http( 'GET', WPS_IT::feed_url( 'full' ) );
		WPS_IT::same( 403, $missing['code'], 'A missing token must return 403.' );
		WPS_IT::contains( 'nem érhető el', $missing['body'], 'Unexpected rejection body.' );

		$invalid = WPS_IT::http( 'GET', WPS_IT::feed_url( 'full', 'definitely-not-the-token' ) );
		WPS_IT::same( 403, $invalid['code'], 'An invalid token must return 403.' );
	}
);

WPS_IT::test(
	'Unknown feed slugs return 404',
	static function (): void {
		$response = WPS_IT::http( 'GET', WPS_IT::feed_url( 'does-not-exist', Settings::secret( 'feed_token' ) ) );

		WPS_IT::same( 404, $response['code'], 'An unknown feed must return 404.' );
	}
);

WPS_IT::test(
	'ETag and Last-Modified produce conditional 304 responses',
	static function () use ( $token ): void {
		$first = WPS_IT::http( 'GET', WPS_IT::feed_url( 'full', $token ) );

		$etag = (string) ( $first['headers']['etag'] ?? '' );
		$last = (string) ( $first['headers']['last-modified'] ?? '' );

		WPS_IT::not_same( '', $etag, 'The first response has no ETag.' );
		WPS_IT::not_same( '', $last, 'The first response has no Last-Modified.' );

		$by_etag = WPS_IT::http(
			'GET',
			WPS_IT::feed_url( 'full', $token ),
			array( 'headers' => array( 'If-None-Match' => $etag ) )
		);

		WPS_IT::same( 304, $by_etag['code'], 'If-None-Match did not produce 304.' );
		WPS_IT::same( '', $by_etag['body'], 'A 304 response must not have a body.' );

		$by_date = WPS_IT::http(
			'GET',
			WPS_IT::feed_url( 'full', $token ),
			array( 'headers' => array( 'If-Modified-Since' => $last ) )
		);

		WPS_IT::same( 304, $by_date['code'], 'If-Modified-Since did not produce 304.' );
	}
);

WPS_IT::test(
	'A public feed can be enabled without a token and then disabled again',
	static function (): void {
		Settings::update( array( 'feed_public' => true ) );

		try {
			$response = WPS_IT::http( 'GET', WPS_IT::feed_url( 'full' ) );

			WPS_IT::same( 200, $response['code'], 'The public feed was not served without a token.' );
		} finally {
			Settings::update( array( 'feed_public' => false ) );
		}

		$private = WPS_IT::http( 'GET', WPS_IT::feed_url( 'full' ) );
		WPS_IT::same( 403, $private['code'], 'The feed did not become private again.' );
	}
);

WPS_IT::test(
	'A missing feed file fails closed with 503 and Retry-After',
	static function () use ( $token ): void {
		$path   = Storage::feed_path( Generator::TYPE_FULL );
		$backup = $path . '.it-http-backup';

		WPS_IT::true( rename( $path, $backup ), 'Could not move the feed aside.' );

		try {
			$response = WPS_IT::http( 'GET', WPS_IT::feed_url( 'full', $token ) );

			WPS_IT::same( 503, $response['code'], 'A missing feed must return 503.' );
			WPS_IT::same( '300', (string) ( $response['headers']['retry-after'] ?? '' ), 'Missing Retry-After.' );
		} finally {
			rename( $backup, $path );
		}

		$restored = WPS_IT::http( 'GET', WPS_IT::feed_url( 'full', $token ) );
		WPS_IT::same( 200, $restored['code'], 'The feed was not restored.' );
	}
);
