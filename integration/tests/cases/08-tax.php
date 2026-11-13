<?php
/**
 * Tax-enabled WooCommerce stores: a gross Pepita price must not gain VAT.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

WPS_IT::line( 'Tax handling' );

$run = WPS_IT::run_id();

$tax_rate_id = \WC_Tax::_insert_tax_rate(
	array(
		'tax_rate_country'  => '',
		'tax_rate_state'    => '',
		'tax_rate'          => '27.0000',
		'tax_rate_name'     => 'ÁFA',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 0,
		'tax_rate_order'    => 0,
		'tax_rate_class'    => '',
	)
);

$previous = array(
	'woocommerce_calc_taxes'       => get_option( 'woocommerce_calc_taxes' ),
	'woocommerce_prices_include_tax' => get_option( 'woocommerce_prices_include_tax' ),
);

update_option( 'woocommerce_calc_taxes', 'yes' );

try {
	foreach ( array( 'yes', 'no' ) as $prices_include_tax ) {
		update_option( 'woocommerce_prices_include_tax', $prices_include_tax );

		WPS_IT::test(
			'A gross Pepita total is preserved when prices_include_tax=' . $prices_include_tax,
			static function () use ( $run, $prices_include_tax ): void {
				$external_id = 'IT-TAX-' . $prices_include_tax . '-' . $run;
				$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );

				$response = WPS_IT::post_order( $payload );

				WPS_IT::same( 200, $response['code'], 'The tax-enabled import failed: ' . $response['body'] );

				$order = wc_get_order( (int) $response['json']['orderId'] );

				WPS_IT::instance( \WC_Order::class, $order, 'The imported order is missing.' );

				// Fixture: 2 x 1000 gross + 1490 shipping - 500 voucher = 2990.
				WPS_IT::close( 2990.0, (float) $order->get_total(), 0.01, 'The gross total changed.' );
				WPS_IT::close( 0.0, (float) $order->get_total_tax(), 0.01, 'VAT was applied on top of a gross price.' );
				WPS_IT::same( 1, count( $order->get_items( 'line_item' ) ), 'The line item is missing.' );
				WPS_IT::same( 1, count( $order->get_items( 'shipping' ) ), 'The shipping line is missing.' );
			}
		);
	}
} finally {
	update_option( 'woocommerce_calc_taxes', $previous['woocommerce_calc_taxes'] );
	update_option( 'woocommerce_prices_include_tax', $previous['woocommerce_prices_include_tax'] );

	if ( $tax_rate_id ) {
		\WC_Tax::_delete_tax_rate( $tax_rate_id );
	}
}
