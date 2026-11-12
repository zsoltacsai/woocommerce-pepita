<?php
/**
 * Plugin Name: WPS Integration Environment
 * Description: Test-only URL resolution for the isolated integration stack.
 *
 * WordPress is reached under different hostnames depending on where a request
 * originates: `localhost:8080/8081` from the host, `wordpress-hpos` /
 * `wordpress-legacy` from the WP-CLI container. Resolving home/siteurl from
 * the request keeps canonical redirects from breaking the HTTP tests.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current base URL for this request.
 */
function wps_it_env_url(): string {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		$url = (string) getenv( 'WPS_IT_SITE_URL' );

		return '' !== $url ? rtrim( $url, '/' ) : 'http://localhost';
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';

	$https = false;

	if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
		$https = true;
	} elseif ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] ) {
		$https = true;
	} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
		$https = true;
	}

	return ( $https ? 'https://' : 'http://' ) . $host;
}

add_filter( 'pre_option_home', 'wps_it_env_url' );
add_filter( 'pre_option_siteurl', 'wps_it_env_url' );
