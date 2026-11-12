<?php
/**
 * Plugin Name: WPS Integration Diagnostics
 * Description: Test-only REST route that reports the source address WordPress
 *              sees, so the suite can verify the positive IP-allowlist path.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'wps-it/v1',
			'/remote-addr',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return array(
						'ip'   => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
						'host' => isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '',
					);
				},
			)
		);
	}
);
