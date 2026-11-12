<?php
/**
 * Plugin Name: WPS Integration Test Hooks
 * Description: Test-only switches for the scheduler fallback and the order API
 *              rate limit. Both default to production behaviour.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wps_pepita_use_action_scheduler',
	static function ( $enabled ) {
		return 'yes' === (string) get_option( 'wps_it_disable_as', 'no' ) ? false : $enabled;
	}
);

add_filter(
	'wps_pepita_order_rate_limit',
	static function ( $limit ) {
		$override = get_option( 'wps_it_rate_limit', null );

		return null === $override || '' === $override ? $limit : (int) $override;
	}
);
