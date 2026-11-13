<?php
/**
 * Tax-enabled WooCommerce stores: NEW-1 tax-aware order creation.
 *
 * Tests:
 * - Taxes disabled (clean zero-tax representation)
 * - Taxable products at 27% VAT (prices_include_tax=yes)
 * - Taxable products at 27% VAT (prices_include_tax=no)
 * - Shipping tax calculation (tax_rate_shipping=1)
 * - Zero-rated products (0% VAT / none tax status)
 * - Tax-exempt orders (is_vat_exempt)
 * - Incompatible VAT validation (fail safely with 422)
 * - Discounts, vouchers and HUF rounding
 * - Verification of net, tax, gross and WC_Order_Item_Tax rows
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

WPS_IT::line( 'Tax handling' );

$run = WPS_IT::run_id();

$previous_options = array(
	'woocommerce_calc_taxes'          => get_option( 'woocommerce_calc_taxes' ),
	'woocommerce_prices_include_tax' => get_option( 'woocommerce_prices_include_tax' ),
);

// -------------------------------------------------------------------------
// Test 1: Taxes disabled
// -------------------------------------------------------------------------
WPS_IT::test(
	'Taxes disabled: imported orders create clean zero-tax orders with exact gross totals',
	static function () use ( $run ): void {
		update_option( 'woocommerce_calc_taxes', 'no' );

		$external_id = 'IT-TAX-DIS-' . $run;
		$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );

		$response = WPS_IT::post_order( $payload );
		WPS_IT::same( 200, $response['code'], 'Tax-disabled order import failed: ' . $response['body'] );

		$order = wc_get_order( (int) $response['json']['orderId'] );
		WPS_IT::instance( \WC_Order::class, $order, 'Order not found.' );

		// 2 x 1000 gross + 1490 shipping - 500 voucher = 2990
		WPS_IT::close( 2990.0, (float) $order->get_total(), 0.01, 'Order total changed.' );
		WPS_IT::close( 0.0, (float) $order->get_total_tax(), 0.01, 'Total tax must be zero when taxes are disabled.' );
		WPS_IT::close( 0.0, (float) $order->get_cart_tax(), 0.01, 'Cart tax must be zero.' );
		WPS_IT::close( 0.0, (float) $order->get_shipping_tax(), 0.01, 'Shipping tax must be zero.' );
		WPS_IT::same( 0, count( $order->get_items( 'tax' ) ), 'No tax rows should exist.' );

		$items = $order->get_items( 'line_item' );
		WPS_IT::same( 1, count( $items ), 'Line item missing.' );
		$item = reset( $items );
		WPS_IT::close( 2000.0, (float) $item->get_total(), 0.01, 'Line item net total must equal gross when taxes disabled.' );
		WPS_IT::close( 0.0, (float) $item->get_total_tax(), 0.01, 'Line item tax must be zero.' );
		WPS_IT::same( '27', (string) $item->get_meta( '_wps_pepita_vat' ), 'VAT meta missing.' );

		$shippings = $order->get_items( 'shipping' );
		WPS_IT::same( 1, count( $shippings ), 'Shipping line missing.' );
		$shipping = reset( $shippings );
		WPS_IT::close( 1490.0, (float) $shipping->get_total(), 0.01, 'Shipping total changed.' );
		WPS_IT::close( 0.0, (float) $shipping->get_total_tax(), 0.01, 'Shipping tax must be zero.' );

		$fees = $order->get_items( 'fee' );
		WPS_IT::same( 1, count( $fees ), 'Voucher fee missing.' );
		$fee = reset( $fees );
		WPS_IT::close( -500.0, (float) $fee->get_total(), 0.01, 'Voucher amount changed.' );
	}
);

// -------------------------------------------------------------------------
// Test 2 & 3: Taxable products at 27% VAT with prices_include_tax = yes / no
// -------------------------------------------------------------------------
$tax_rate_id = \WC_Tax::_insert_tax_rate(
	array(
		'tax_rate_country'  => '',
		'tax_rate_state'    => '',
		'tax_rate'          => '27.0000',
		'tax_rate_name'     => 'ÁFA 27%',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 0, // Non-taxable shipping for these two tests
		'tax_rate_order'    => 0,
		'tax_rate_class'    => '',
	)
);

update_option( 'woocommerce_calc_taxes', 'yes' );

try {
	foreach ( array( 'yes', 'no' ) as $prices_include_tax ) {
		update_option( 'woocommerce_prices_include_tax', $prices_include_tax );

		WPS_IT::test(
			'Taxable 27% VAT: net, tax, gross and tax rows are preserved when prices_include_tax=' . $prices_include_tax,
			static function () use ( $run, $prices_include_tax ): void {
				$external_id = 'IT-TAX-27-' . $prices_include_tax . '-' . $run;
				$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );

				$response = WPS_IT::post_order( $payload );
				WPS_IT::same( 200, $response['code'], 'Tax-enabled import failed: ' . $response['body'] );

				$order = wc_get_order( (int) $response['json']['orderId'] );
				WPS_IT::instance( \WC_Order::class, $order, 'Order not found.' );

				// Authoritative gross total: 2 x 1000 + 1490 - 500 = 2990
				WPS_IT::close( 2990.0, (float) $order->get_total(), 0.01, 'Authoritative gross total changed.' );

				// Line: 2000 gross / 1.27 = 1574.80 net, 425.20 tax
				$items = $order->get_items( 'line_item' );
				WPS_IT::same( 1, count( $items ), 'Line item missing.' );
				$item = reset( $items );

				WPS_IT::close( 1574.80, (float) $item->get_total(), 0.01, 'Line item net total is incorrect.' );
				WPS_IT::close( 425.20, (float) $item->get_total_tax(), 0.01, 'Line item tax total is incorrect.' );
				WPS_IT::close( 2000.0, (float) ( $item->get_total() + $item->get_total_tax() ), 0.01, 'Line item net + tax does not equal gross.' );

				// Order cart tax and shipping tax (shipping non-taxable in this test)
				WPS_IT::close( 425.20, (float) $order->get_cart_tax(), 0.01, 'Order cart tax is incorrect.' );
				WPS_IT::close( 0.0, (float) $order->get_shipping_tax(), 0.01, 'Shipping tax should be zero.' );
				WPS_IT::close( 425.20, (float) $order->get_total_tax(), 0.01, 'Order total tax is incorrect.' );

				// Verify WC_Order_Item_Tax row
				$tax_items = $order->get_items( 'tax' );
				WPS_IT::same( 1, count( $tax_items ), 'Expected exactly 1 tax row.' );
				$tax_row = reset( $tax_items );
				WPS_IT::close( 425.20, (float) $tax_row->get_tax_total(), 0.01, 'Tax row tax total is incorrect.' );
				WPS_IT::close( 0.0, (float) $tax_row->get_shipping_tax_total(), 0.01, 'Tax row shipping tax is incorrect.' );
				WPS_IT::same( 'ÁFA 27%', $tax_row->get_label(), 'Tax row label is incorrect.' );
			}
		);
	}
} finally {
	if ( $tax_rate_id ) {
		\WC_Tax::_delete_tax_rate( $tax_rate_id );
	}
}

// -------------------------------------------------------------------------
// Test 4: Shipping tax calculation (tax_rate_shipping = 1)
// -------------------------------------------------------------------------
$shipping_tax_rate_id = \WC_Tax::_insert_tax_rate(
	array(
		'tax_rate_country'  => '',
		'tax_rate_state'    => '',
		'tax_rate'          => '27.0000',
		'tax_rate_name'     => 'ÁFA 27%',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 1, // Shipping is taxable!
		'tax_rate_order'    => 0,
		'tax_rate_class'    => '',
	)
);

try {
	update_option( 'woocommerce_prices_include_tax', 'yes' );

	WPS_IT::test(
		'Shipping tax: taxable shipping partitions gross shipping into net and shipping tax',
		static function () use ( $run ): void {
			$external_id = 'IT-TAX-SHIP-' . $run;
			$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );

			$response = WPS_IT::post_order( $payload );
			WPS_IT::same( 200, $response['code'], 'Taxable shipping import failed: ' . $response['body'] );

			$order = wc_get_order( (int) $response['json']['orderId'] );
			WPS_IT::instance( \WC_Order::class, $order, 'Order not found.' );

			// Grand total must still be exact 2990.00
			WPS_IT::close( 2990.0, (float) $order->get_total(), 0.01, 'Grand total must remain authoritative.' );

			// Products: 2000 gross -> 1574.80 net, 425.20 tax
			WPS_IT::close( 425.20, (float) $order->get_cart_tax(), 0.01, 'Cart tax incorrect.' );

			// Shipping: 1490 gross / 1.27 = 1173.23 net, 316.77 shipping tax
			WPS_IT::close( 316.77, (float) $order->get_shipping_tax(), 0.01, 'Shipping tax incorrect.' );

			// Total tax = 425.20 + 316.77 = 741.97
			WPS_IT::close( 741.97, (float) $order->get_total_tax(), 0.01, 'Total order tax incorrect.' );

			// Check shipping item
			$shippings = $order->get_items( 'shipping' );
			$shipping  = reset( $shippings );
			WPS_IT::close( 1173.23, (float) $shipping->get_total(), 0.01, 'Shipping net total incorrect.' );
			WPS_IT::close( 316.77, (float) $shipping->get_total_tax(), 0.01, 'Shipping item tax incorrect.' );
			WPS_IT::close( 1490.0, (float) ( $shipping->get_total() + $shipping->get_total_tax() ), 0.01, 'Shipping net + tax must equal gross.' );

			// Check tax row
			$tax_items = $order->get_items( 'tax' );
			WPS_IT::same( 1, count( $tax_items ), 'Expected exactly 1 tax row.' );
			$tax_row = reset( $tax_items );
			WPS_IT::close( 425.20, (float) $tax_row->get_tax_total(), 0.01, 'Tax row cart tax incorrect.' );
			WPS_IT::close( 316.77, (float) $tax_row->get_shipping_tax_total(), 0.01, 'Tax row shipping tax incorrect.' );
		}
	);

	// -------------------------------------------------------------------------
	// Test 5: Tax-exempt order
	// -------------------------------------------------------------------------
	WPS_IT::test(
		'Tax-exempt order: customer tax exemption creates clean zero-tax order',
		static function () use ( $run ): void {
			$external_id = 'IT-TAX-EXEMPT-' . $run;
			$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );
			$payload['customer']['is_vat_exempt'] = 'yes';

			$response = WPS_IT::post_order( $payload );
			WPS_IT::same( 200, $response['code'], 'Tax-exempt order import failed: ' . $response['body'] );

			$order = wc_get_order( (int) $response['json']['orderId'] );
			WPS_IT::instance( \WC_Order::class, $order, 'Order not found.' );

			WPS_IT::close( 2990.0, (float) $order->get_total(), 0.01, 'Order total changed.' );
			WPS_IT::close( 0.0, (float) $order->get_total_tax(), 0.01, 'Tax-exempt order must have zero tax.' );
			WPS_IT::close( 0.0, (float) $order->get_cart_tax(), 0.01, 'Cart tax must be zero.' );
			WPS_IT::close( 0.0, (float) $order->get_shipping_tax(), 0.01, 'Shipping tax must be zero.' );
			WPS_IT::same( 0, count( $order->get_items( 'tax' ) ), 'Tax-exempt order must have no tax rows.' );
		}
	);

	WPS_IT::test(
		'Tax-exempt order: customer tax exemption with 0% payload VAT on taxable product creates clean zero-tax order',
		static function () use ( $run ): void {
			$external_id = 'IT-TAX-EXEMPT-ZERO-' . $run;
			$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );
			$payload['customer']['is_vat_exempt'] = 'yes';
			$payload['products'][0]['vat']         = 0;

			$response = WPS_IT::post_order( $payload );
			WPS_IT::same( 200, $response['code'], 'Tax-exempt order with 0% VAT failed: ' . $response['body'] );

			$order = wc_get_order( (int) $response['json']['orderId'] );
			WPS_IT::instance( \WC_Order::class, $order, 'Order not found.' );

			WPS_IT::close( 2990.0, (float) $order->get_total(), 0.01, 'Order total changed.' );
			WPS_IT::close( 0.0, (float) $order->get_total_tax(), 0.01, 'Tax-exempt order must have zero tax.' );
			WPS_IT::close( 0.0, (float) $order->get_cart_tax(), 0.01, 'Cart tax must be zero.' );
			WPS_IT::close( 0.0, (float) $order->get_shipping_tax(), 0.01, 'Shipping tax must be zero.' );
			WPS_IT::same( 0, count( $order->get_items( 'tax' ) ), 'Tax-exempt order must have no tax rows.' );
		}
	);

	// -------------------------------------------------------------------------
	// Test 6: Incompatible VAT validation fails safely with 422
	// -------------------------------------------------------------------------
	WPS_IT::test(
		'Incompatible VAT: payload VAT differing from WooCommerce tax rate fails safely with 422',
		static function () use ( $run ): void {
			$external_id = 'IT-TAX-MISMATCH-' . $run;
			$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );
			// Product has 27% VAT in WooCommerce, but Pepita claims 5%
			$payload['products'][0]['vat'] = 5;

			$response = WPS_IT::post_order( $payload );
			WPS_IT::same( 422, $response['code'], 'Incompatible VAT must return 422.' );
			WPS_IT::contains( 'nem egyezik a WooCommerce-ben beállított adókulccsal', (string) ( $response['json']['message'] ?? '' ), 'Wrong error message: ' . (string) $response['body'] );

			// No WooCommerce order created
			WPS_IT::same( 0, count( WPS_IT::orders_by_external_id( $external_id ) ), 'No order should be created on tax mismatch.' );
		}
	);
} finally {
	if ( $shipping_tax_rate_id ) {
		\WC_Tax::_delete_tax_rate( $shipping_tax_rate_id );
	}

	update_option( 'woocommerce_calc_taxes', $previous_options['woocommerce_calc_taxes'] );
	update_option( 'woocommerce_prices_include_tax', $previous_options['woocommerce_prices_include_tax'] );
}
