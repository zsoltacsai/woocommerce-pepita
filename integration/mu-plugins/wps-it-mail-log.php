<?php
/**
 * Plugin Name: WPS Integration Mail Recorder
 * Description: Records every wp_mail() call and every final WooCommerce e-mail
 *              enabled decision so the integration suite can prove that order
 *              import suppresses customer e-mails.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append a value to a bounded option log.
 *
 * @param string $option Option name.
 * @param mixed  $value  Value to append.
 */
function wps_it_log_append( string $option, $value ): void {
	$log = get_option( $option, array() );
	$log = is_array( $log ) ? $log : array();

	$log[] = $value;

	if ( count( $log ) > 100 ) {
		$log = array_slice( $log, -100 );
	}

	update_option( $option, $log, false );
}

add_filter(
	'wp_mail',
	static function ( $args ) {
		wps_it_log_append(
			'wps_it_mail_log',
			array(
				'to'      => is_array( $args ) ? ( $args['to'] ?? '' ) : '',
				'subject' => is_array( $args ) ? ( $args['subject'] ?? '' ) : '',
			)
		);

		return $args;
	},
	1
);

/**
 * Record the final value of the WooCommerce e-mail gates after the plugin's
 * suppression filter (priority 999) has run.
 */
add_action(
	'init',
	static function (): void {
		$hooks = array(
			'woocommerce_email_enabled_new_order',
			'woocommerce_email_enabled_customer_processing_order',
			'woocommerce_email_enabled_customer_on_hold_order',
			'woocommerce_email_enabled_customer_completed_order',
			'woocommerce_email_enabled_customer_invoice',
			'woocommerce_email_enabled_customer_note',
			'woocommerce_email_enabled_cancelled_order',
			'woocommerce_email_enabled_failed_order',
			'woocommerce_email_enabled_customer_refunded_order',
		);

		foreach ( $hooks as $hook ) {
			add_filter(
				$hook,
				static function ( $enabled, $email_id = '', $order = null ) use ( $hook ) {
					wps_it_log_append(
						'wps_it_email_gate_log',
						array(
							'hook'    => $hook,
							'enabled' => (bool) $enabled,
						)
					);

					return $enabled;
				},
				1000,
				3
			);
		}
	}
);
