<?php
/**
 * Order import through the real REST endpoint and WooCommerce CRUD API.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

WPS_IT::line( 'Order import' );

$run = WPS_IT::run_id();

WPS_IT::test(
	'An unpaid transfer order is imported with the documented mapping',
	static function () use ( $run ): void {
		$external_id = 'IT-ORDER-UNPAID-' . $run;
		$payload     = WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id );
		$response    = WPS_IT::post_order( $payload );

		WPS_IT::same( 200, $response['code'], 'Import failed: ' . $response['body'] );
		WPS_IT::same( false, $response['json']['isError'], 'The import reported an error.' );
		WPS_IT::same( 200, (int) $response['json']['responseCode'], 'Unexpected responseCode.' );
		WPS_IT::greater( 0, (int) $response['json']['orderId'], 'The response did not contain an order id.' );

		$order_id = (int) $response['json']['orderId'];
		$order    = wc_get_order( $order_id );

		WPS_IT::instance( \WC_Order::class, $order, 'WooCommerce could not load the imported order.' );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'Exactly one order must exist.' );

		WPS_IT::same( 'on-hold', $order->get_status(), 'An unpaid order must be on-hold.' );
		WPS_IT::same( 'bacs', $order->get_payment_method(), 'The transfer payment mode did not map to bacs.' );
		WPS_IT::same( 'Átutalás', $order->get_payment_method_title(), 'The payment title is wrong.' );
		WPS_IT::same( 'HUF', $order->get_currency(), 'The order currency is wrong.' );

		WPS_IT::close( 2990.0, (float) $order->get_total(), 0.01, 'The order total is wrong.' );
		WPS_IT::close( 2000.0, (float) $order->get_subtotal(), 0.01, 'The item subtotal is wrong.' );
		WPS_IT::close( 1490.0, (float) $order->get_shipping_total(), 0.01, 'The shipping total is wrong.' );
		$fees      = $order->get_items( 'fee' );
		$fee_total = array_sum( array_map( static fn ( $fee ): float => (float) $fee->get_total(), $fees ) );
		WPS_IT::close( -500.0, $fee_total, 0.01, 'The voucher fee is wrong.' );

		WPS_IT::same( 'Anna', $order->get_billing_first_name(), 'Billing first name is wrong.' );
		WPS_IT::same( 'Kis', $order->get_billing_last_name(), 'Billing last name is wrong.' );
		WPS_IT::same( 'vasarlo@example.test', $order->get_billing_email(), 'Billing e-mail is wrong.' );
		WPS_IT::same( '+36301234567', $order->get_billing_phone(), 'Billing phone is wrong.' );
		WPS_IT::same( 'HU', $order->get_billing_country(), 'Billing country is wrong.' );
		WPS_IT::same( 'Budapest', $order->get_billing_city(), 'Billing city is wrong.' );
		WPS_IT::same( '1011', $order->get_billing_postcode(), 'Billing postcode is wrong.' );
		WPS_IT::same( 'Fő utca 1.', $order->get_billing_address_1(), 'Billing street is wrong.' );
		WPS_IT::same( '1', $order->get_billing_address_2(), 'Billing house number is wrong.' );
		WPS_IT::same( '12345678-1-41', (string) $order->get_meta( '_billing_vat_number' ), 'The tax number was not stored.' );

		WPS_IT::same( 'HU', $order->get_shipping_country(), 'Shipping country is wrong.' );
		WPS_IT::same( 'Budapest', $order->get_shipping_city(), 'Shipping city is wrong.' );
		WPS_IT::same( '1011', $order->get_shipping_postcode(), 'Shipping postcode is wrong.' );
		WPS_IT::same( 'Fő utca 1.', $order->get_shipping_address_1(), 'Shipping street is wrong.' );
		WPS_IT::same( '1', $order->get_shipping_address_2(), 'Shipping house number is wrong.' );

		WPS_IT::same( $external_id, (string) $order->get_meta( Importer::META_ORDER_ID ), 'The external id meta is missing.' );
		WPS_IT::same( 'pepita.hu', (string) $order->get_meta( Importer::META_ORIGIN ), 'The origin meta is missing.' );
		WPS_IT::same( 'unpaid', (string) $order->get_meta( Importer::META_PAYMENT_STATUS ), 'The payment status meta is missing.' );
		WPS_IT::same( 'gls', (string) $order->get_meta( Importer::META_DELIVERY_MODE ), 'The delivery mode meta is missing.' );
		WPS_IT::same( '2990', (string) $order->get_meta( Importer::META_RAW_TOTAL ), 'The raw total meta is wrong.' );
		WPS_IT::same( '500', (string) $order->get_meta( Importer::META_VOUCHER ), 'The voucher meta is wrong.' );
		WPS_IT::same( 'Kérem a kapuhoz.', (string) $order->get_meta( Importer::META_CUSTOMER_MSG ), 'The customer message meta is wrong.' );
		WPS_IT::same( 'Hívjon telefonon.', (string) $order->get_meta( Importer::META_COURIER_MSG ), 'The courier message meta is wrong.' );
		WPS_IT::not_same( '', (string) $order->get_meta( Importer::META_IMPORTED_AT ), 'The import timestamp is missing.' );
		WPS_IT::same( 64, strlen( (string) $order->get_meta( Importer::META_PAYLOAD_HASH ) ), 'The payload hash is not a SHA-256 hex string.' );

		$items = $order->get_items();

		WPS_IT::same( 1, count( $items ), 'Expected one order line.' );

		$item = reset( $items );

		WPS_IT::instance( \WC_Order_Item_Product::class, $item, 'The line is not a product line.' );
		WPS_IT::same( (int) WPS_IT::seed()['p1'], $item->get_product_id(), 'The line points at the wrong product.' );
		WPS_IT::same( 2, $item->get_quantity(), 'The line quantity is wrong.' );
		WPS_IT::close( 2000.0, (float) $item->get_total(), 0.01, 'The line total is wrong.' );
		WPS_IT::same( 'WPS-IT-001', (string) $item->get_meta( '_wps_pepita_sku' ), 'The Pepita SKU line meta is missing.' );
		WPS_IT::same( (string) WPS_IT::seed()['export_p1'], (string) $item->get_meta( '_wps_pepita_product_id' ), 'The Pepita product id line meta is missing.' );
		WPS_IT::same( '27', (string) $item->get_meta( '_wps_pepita_vat' ), 'The VAT line meta is missing.' );

		$shipping = $order->get_items( 'shipping' );

		WPS_IT::same( 1, count( $shipping ), 'Expected one shipping line.' );

		$shipping_item = reset( $shipping );

		WPS_IT::same( 'flat_rate', $shipping_item->get_method_id(), 'The GLS delivery mode did not map to flat_rate.' );
		WPS_IT::same( 'GLS házhozszállítás', $shipping_item->get_method_title(), 'The shipping title is wrong.' );
		WPS_IT::close( 1490.0, (float) $shipping_item->get_total(), 0.01, 'The shipping line total is wrong.' );

		$fees = $order->get_items( 'fee' );

		WPS_IT::same( 1, count( $fees ), 'Expected one voucher fee line.' );

		$fee = reset( $fees );

		WPS_IT::same( 'Pepita utalvány', $fee->get_name(), 'The voucher fee name is wrong.' );
		WPS_IT::close( -500.0, (float) $fee->get_total(), 0.01, 'The voucher fee total is wrong.' );

		$notes = wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 100 ) );
		$text  = '';

		foreach ( $notes as $note ) {
			$text .= ' ' . (string) $note->content;
		}

		WPS_IT::contains( $external_id, $text, 'The order note does not mention the Pepita order id.' );
		WPS_IT::contains( 'Kérem a kapuhoz.', $text, 'The customer message note is missing.' );
		WPS_IT::contains( 'Hívjon telefonon.', $text, 'The courier message note is missing.' );

		$label = (string) $order->get_meta( Importer::META_PACKAGE_LABEL );

		WPS_IT::not_same( '', $label, 'The package label was not saved.' );
		WPS_IT::true( is_file( $label ), 'The saved label file does not exist.' );
		WPS_IT::true( str_starts_with( (string) file_get_contents( $label ), '%PDF-1.4' ), 'The saved label is not the fixture PDF.' );

		$mail = get_option( 'wps_it_mail_log', array() );
		WPS_IT::same( 0, is_array( $mail ) ? count( $mail ) : 0, 'The import must not send e-mail.' );

		$gates = get_option( 'wps_it_email_gate_log', array() );

		foreach ( is_array( $gates ) ? $gates : array() as $gate ) {
			WPS_IT::same( false, (bool) $gate['enabled'], 'A WooCommerce e-mail gate was left enabled during import.' );
		}

		$row = Order_Repository::get( $external_id );

		WPS_IT::true( is_array( $row ), 'The durable claim row is missing.' );
		WPS_IT::same( 'completed', (string) $row['status'], 'The claim is not completed.' );
		WPS_IT::same( $order_id, (int) $row['wc_order_id'], 'The claim points at the wrong order.' );
		WPS_IT::same( 1, (int) $row['attempts'], 'The first import must use one attempt.' );

		$stored = json_decode( (string) $row['payload'], true );

		WPS_IT::true( is_array( $stored ), 'The stored payload is not valid JSON.' );
		WPS_IT::same( $external_id, (string) ( $stored['id'] ?? '' ), 'The stored payload lost the external id.' );
		WPS_IT::same( hash( 'sha256', (string) $row['payload'] ), (string) $row['payload_hash'], 'The stored payload hash does not match.' );
	}
);

WPS_IT::test(
	'A paid cash-on-delivery order is imported as processing',
	static function () use ( $run ): void {
		$external_id = 'IT-ORDER-COD-' . $run;
		$response    = WPS_IT::post_order( WPS_IT::order_payload( 'order-cod-paid.json', $external_id ) );

		WPS_IT::same( 200, $response['code'], 'Import failed: ' . $response['body'] );

		$order = wc_get_order( (int) $response['json']['orderId'] );

		WPS_IT::instance( \WC_Order::class, $order, 'The COD order could not be loaded.' );
		WPS_IT::same( 'processing', $order->get_status(), 'A paid order must be processing.' );
		WPS_IT::same( 'cod', $order->get_payment_method(), 'The COD payment mode did not map to cod.' );
		WPS_IT::same( 'Utánvét', $order->get_payment_method_title(), 'The COD title is wrong.' );
		WPS_IT::close( 5990.0, (float) $order->get_total(), 0.01, 'The COD total is wrong.' );

		$paid = $order->get_date_paid();

		WPS_IT::true( $paid instanceof \DateTimeInterface, 'A paid order must have a paid date.' );
		WPS_IT::greater( 0, $paid->getTimestamp(), 'The paid date is not a real timestamp.' );
		WPS_IT::same( 'paid', (string) $order->get_meta( Importer::META_PAYMENT_STATUS ), 'The paid meta is wrong.' );

		$shipping = $order->get_items( 'shipping' );
		$item     = reset( $shipping );

		WPS_IT::same( 'MPL házhozszállítás', $item->get_method_title(), 'The MPL shipping title is wrong.' );
		WPS_IT::same( '', (string) $order->get_meta( Importer::META_PACKAGE_LABEL ), 'An empty label must not create a file.' );
	}
);

WPS_IT::test(
	'A variation SKU resolves to the concrete WooCommerce variation',
	static function () use ( $run ): void {
		$seed        = WPS_IT::seed();
		$external_id = 'IT-ORDER-VARIATION-' . $run;
		$response    = WPS_IT::post_order( WPS_IT::order_payload( 'order-variable-variation.json', $external_id ) );

		WPS_IT::same( 200, $response['code'], 'Variation import failed: ' . $response['body'] );

		$order = wc_get_order( (int) $response['json']['orderId'] );
		$items = $order->get_items();

		WPS_IT::same( 1, count( $items ), 'Expected one variation line.' );

		$item = reset( $items );

		WPS_IT::same( (int) $seed['variable'], $item->get_product_id(), 'The variation line has the wrong parent.' );
		WPS_IT::same( (int) $seed['variation_ids'][0], $item->get_variation_id(), 'The variation line has the wrong variation.' );
		WPS_IT::same( 1, $item->get_quantity(), 'The variation quantity is wrong.' );
		WPS_IT::close( 12000.0, (float) $item->get_total(), 0.01, 'The variation total is wrong.' );
		WPS_IT::same( 'WPS-IT-VAR-S', (string) $item->get_meta( '_wps_pepita_sku' ), 'The variation SKU meta is wrong.' );
	}
);

WPS_IT::test(
	'An invalid product is rejected without an order and the claim is retryable',
	static function () use ( $run ): void {
		$external_id = 'IT-ORDER-RETRY-' . $run;
		$invalid     = WPS_IT::post_order( WPS_IT::order_payload( 'order-invalid-product.json', $external_id ) );

		WPS_IT::same( 422, $invalid['code'], 'An unknown product must return 422.' );
		WPS_IT::same( true, $invalid['json']['isError'], 'The rejection must be an error envelope.' );
		WPS_IT::contains( 'nem azonosítható', (string) $invalid['json']['message'], 'The rejection message is not the documented one.' );
		WPS_IT::same( 0, count( WPS_IT::orders_by_external_id( $external_id ) ), 'A rejected import must not create an order.' );

		$row = Order_Repository::get( $external_id );

		WPS_IT::true( is_array( $row ), 'The failed claim was not recorded.' );
		WPS_IT::same( 'failed', (string) $row['status'], 'The failed claim status is wrong.' );
		WPS_IT::not_same( '', (string) $row['last_error'], 'The failed claim has no error.' );

		$retry = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id ) );

		WPS_IT::same( 200, $retry['code'], 'A failed claim must be retryable: ' . $retry['body'] );
		WPS_IT::same( 1, count( WPS_IT::orders_by_external_id( $external_id ) ), 'The retry must create exactly one order.' );

		$row = Order_Repository::get( $external_id );

		WPS_IT::same( 'completed', (string) $row['status'], 'The retried claim is not completed.' );
		WPS_IT::same( 2, (int) $row['attempts'], 'The retry attempt counter is wrong.' );
	}
);

WPS_IT::test(
	'Documented validation failures return 422 and create no order',
	static function () use ( $run ): void {
		$cases = array();

		$cases['missing email'] = static function ( array $payload ): array {
			unset( $payload['customer']['email'] );

			return $payload;
		};

		$cases['missing products'] = static function ( array $payload ): array {
			unset( $payload['products'] );

			return $payload;
		};

		$cases['missing customer'] = static function ( array $payload ): array {
			unset( $payload['customer'] );

			return $payload;
		};

		$cases['zero quantity'] = static function ( array $payload ): array {
			$payload['products'][0]['quantity'] = 0;

			return $payload;
		};

		$cases['zero price'] = static function ( array $payload ): array {
			$payload['products'][0]['price'] = 0;

			return $payload;
		};

		$cases['negative voucher'] = static function ( array $payload ): array {
			$payload['voucher'] = -1;

			return $payload;
		};

		$cases['unknown payment mode'] = static function ( array $payload ): array {
			$payload['payment_mode'] = 'bitcoin';

			return $payload;
		};

		$cases['unknown delivery mode'] = static function ( array $payload ): array {
			$payload['delivery_mod'] = 'drone';

			return $payload;
		};

		$cases['invalid VAT'] = static function ( array $payload ): array {
			$payload['products'][0]['vat'] = 150;

			return $payload;
		};

		foreach ( $cases as $label => $mutator ) {
			$external_id = 'IT-ORDER-INVALID-' . md5( $label ) . '-' . $run;
			$payload     = $mutator( WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id ) );
			$response    = WPS_IT::post_order( $payload );

			WPS_IT::same( 422, $response['code'], $label . ' must return 422: ' . $response['body'] );
			WPS_IT::same( true, $response['json']['isError'], $label . ' must return an error envelope.' );
			WPS_IT::same( 0, count( WPS_IT::orders_by_external_id( $external_id ) ), $label . ' created an order.' );
		}

		$mixed_id = 'IT-ORDER-MIXED-' . $run;
		$mixed    = WPS_IT::post_order( WPS_IT::order_payload( 'order-mixed-currency.json', $mixed_id ) );

		WPS_IT::same( 422, $mixed['code'], 'Mixed currencies must return 422.' );
		WPS_IT::contains( 'pénznem', (string) $mixed['json']['message'], 'The mixed-currency message is wrong.' );
		WPS_IT::same( 0, count( WPS_IT::orders_by_external_id( $mixed_id ) ), 'Mixed currencies created an order.' );
	}
);

WPS_IT::test(
	'The package label can be disabled without affecting the order',
	static function () use ( $run ): void {
		Settings::update( array( 'order_api_save_label' => false ) );

		try {
			$external_id = 'IT-ORDER-NOLABEL-' . $run;
			$response    = WPS_IT::post_order( WPS_IT::order_payload( 'order-transfer-unpaid.json', $external_id ) );

			WPS_IT::same( 200, $response['code'], 'The import failed with labels disabled: ' . $response['body'] );

			$order = wc_get_order( (int) $response['json']['orderId'] );

			WPS_IT::same( '', (string) $order->get_meta( Importer::META_PACKAGE_LABEL ), 'A label was saved although the feature is disabled.' );
		} finally {
			Settings::update( array( 'order_api_save_label' => true ) );
		}
	}
);
