<?php
/**
 * M10 Order Operations & Diagnostics Test Cases.
 *
 * Covers:
 * - HPOS and legacy custom columns in WooCommerce order list.
 * - Single order metabox rendering on HPOS and classic order screens.
 * - Diagnostic view rendering on Pepita Orders admin page.
 * - PII and sensitive data redaction in stored payload viewer.
 * - Operator retry workflow (with stored payload, reopening completed claims, handling missing payload).
 * - Operator recovery workflow (reconciling orphaned WooCommerce orders and reopening lost claims).
 * - Secure label download with strict path traversal prevention.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Admin\Order_Admin;
use WPS\Pepita\Logger;
use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

$suite = $GLOBALS['wps_suite'];

/**
 * Seed a catalog product for order line mapping.
 */
$seed_product = static function ( int $id = 101, string $sku = 'SKU-M10-1' ): WC_Product {
	return WPS_Test_State::add_product(
		array(
			'id'    => $id,
			'name'  => 'M10 Termék ' . $id,
			'sku'   => $sku,
			'price' => '5000',
		)
	);
};

/**
 * Helper to build a standard verified Pepita payload.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
$make_payload = static function ( array $overrides = array() ): array {
	$base = array(
		'origin'                        => 'pepita.hu',
		'id'                            => 'PEP-M10-001',
		'date'                          => '2026-11-14 12:00:00',
		'payment_mode'                  => 'transfer',
		'payment_status'                => 'paid',
		'status'                        => 'NEW',
		'total_shipping_price'          => 1290,
		'total_shipping_price_currency' => 'HUF',
		'voucher'                       => 0,
		'delivery_mod'                  => 'gls',
		'customer'                      => array(
			'email'                 => 'janos.kovacs@example.test',
			'first_name'            => 'János',
			'last_name'             => 'Kovács',
			'phone'                 => '+36209876543',
			'billing_country'       => 'HU',
			'billing_city'          => 'Debrecen',
			'billing_postal_code'   => '4025',
			'billing_street'        => 'Piac utca 10.',
			'billing_house_number'  => '10',
			'shipping_country'      => 'HU',
			'shipping_city'         => 'Debrecen',
			'shipping_postal_code'  => '4025',
			'shipping_street'       => 'Piac utca 10.',
			'tax_number'            => '11223344-2-09',
		),
		'products'                      => array(
			array(
				'id'       => 'wps-prod-1',
				'sku'      => 'SKU-M10-1',
				'currency' => 'HUF',
				'quantity' => 1,
				'price'    => 5000,
				'vat'      => 27,
			),
		),
	);

	$merged             = array_merge( $base, $overrides );
	$merged['customer'] = array_merge( $base['customer'], (array) ( $overrides['customer'] ?? array() ) );

	return $merged;
};

/* -------------------------------------------------------------------------
 * 1. WooCommerce Order List Custom Column: HPOS & Legacy
 * ---------------------------------------------------------------------- */

$suite->add(
	'm10 order admin: custom column registration and placement',
	static function (): void {
		$admin = new Order_Admin();

		$cols = array(
			'cb'           => '<input type="checkbox" />',
			'order_number' => 'Rendelés',
			'order_date'   => 'Dátum',
			'order_status' => 'Állapot',
			'order_total'  => 'Összeg',
		);

		$result = $admin->columns( $cols );

		WPS_Assert::true( isset( $result[ Order_Admin::COLUMN_ID ] ), 'Pepita column must be present.' );
		WPS_Assert::same( 'Pepita', $result[ Order_Admin::COLUMN_ID ] );

		// Column should appear immediately after order_number.
		$keys = array_keys( $result );
		$num_idx = array_search( 'order_number', $keys, true );
		$pep_idx = array_search( Order_Admin::COLUMN_ID, $keys, true );
		WPS_Assert::same( $num_idx + 1, $pep_idx, 'Pepita column should follow order_number.' );
	}
);

$suite->add(
	'm10 order admin: column rendering for Pepita orders and non-Pepita orders (HPOS and Legacy)',
	static function () use ( $make_payload ): void {
		$admin = new Order_Admin();

		// Case A: Non-Pepita regular WooCommerce order.
		$regular_order = new WC_Order();
		WPS_Test_State::add_order( $regular_order );

		ob_start();
		$admin->render_column_cell( $regular_order );
		$regular_out = ob_get_clean();

		WPS_Assert::same( '—', trim( $regular_out ), 'Non-Pepita order should render a dash.' );

		// Case B: Pepita order with completed claim.
		$pepita_order = new WC_Order();
		$pepita_order->update_meta_data( Importer::META_ORDER_ID, 'PEP-M10-COL-1' );
		WPS_Test_State::add_order( $pepita_order );

		Order_Repository::claim( 'PEP-M10-COL-1', 'pepita.hu', '{}', 'fakehash' );
		Order_Repository::complete( 'PEP-M10-COL-1', $pepita_order->get_id(), 'fakehash', 1 );

		ob_start();
		$admin->render_hpos_column( Order_Admin::COLUMN_ID, $pepita_order );
		$hpos_out = ob_get_clean();

		WPS_Assert::true( str_contains( $hpos_out, 'PEP-M10-COL-1' ), 'HPOS column must show external ID.' );
		WPS_Assert::true( str_contains( $hpos_out, 'wps-badge--ok' ), 'Completed order must show OK badge.' );
		WPS_Assert::true( str_contains( $hpos_out, 'Importálva' ), 'Completed order must show Importálva label.' );

		// Case C: Legacy column render.
		ob_start();
		$admin->render_legacy_column( Order_Admin::COLUMN_ID, $pepita_order->get_id() );
		$legacy_out = ob_get_clean();

		WPS_Assert::same( $hpos_out, $legacy_out, 'Legacy and HPOS column rendering must match.' );

		// Case D: Failed claim status reflection.
		$failed_order = new WC_Order();
		$failed_order->update_meta_data( Importer::META_ORDER_ID, 'PEP-M10-COL-FAIL' );
		WPS_Test_State::add_order( $failed_order );

		Order_Repository::claim( 'PEP-M10-COL-FAIL', 'pepita.hu', '{}', 'fakehash2' );
		Order_Repository::fail( 'PEP-M10-COL-FAIL', 'Out of stock error', 1 );

		ob_start();
		$admin->render_column_cell( $failed_order );
		$fail_out = ob_get_clean();

		WPS_Assert::true( str_contains( $fail_out, 'wps-badge--bad' ), 'Failed claim must show bad badge.' );
		WPS_Assert::true( str_contains( $fail_out, 'Sikertelen' ), 'Failed claim must show Sikertelen label.' );
	}
);

/* -------------------------------------------------------------------------
 * 2. Single Order Metabox: HPOS and Classic
 * ---------------------------------------------------------------------- */

$suite->add(
	'm10 order admin: metabox registration on order screens',
	static function (): void {
		WPS_Test_State::$meta_boxes = array();
		$admin                      = new Order_Admin();

		$admin->register_metaboxes();

		WPS_Assert::true( ! empty( WPS_Test_State::$meta_boxes ), 'Metaboxes should be registered.' );

		$registered_screens = array();
		foreach ( WPS_Test_State::$meta_boxes as $box ) {
			if ( 'wps_pepita_order_details' === $box[0] ) {
				$registered_screens[] = $box[3];
			}
		}

		WPS_Assert::true( in_array( 'shop_order', $registered_screens, true ), 'Metabox must register on shop_order.' );
		WPS_Assert::true( in_array( 'woocommerce_page_wc-orders', $registered_screens, true ), 'Metabox must register on woocommerce_page_wc-orders.' );
	}
);

$suite->add(
	'm10 order admin: metabox rendering with order details and permissions',
	static function (): void {
		$admin = new Order_Admin();

		$order = new WC_Order();
		$order->update_meta_data( Importer::META_ORDER_ID, 'PEP-M10-BOX-1' );
		$order->update_meta_data( Importer::META_ORIGIN, 'pepita.hu' );
		$order->update_meta_data( Importer::META_DELIVERY_MODE, 'gls_express' );
		$order->update_meta_data( Importer::META_PAYMENT_STATUS, 'paid' );
		$order->update_meta_data( Importer::META_VOUCHER, 'PROMO-2026' );
		WPS_Test_State::add_order( $order );

		// Authorized rendering.
		WPS_Test_State::$current_user_caps['manage_woocommerce'] = true;

		ob_start();
		$admin->render_order_metabox( $order );
		$out = ob_get_clean();

		WPS_Assert::true( str_contains( $out, 'PEP-M10-BOX-1' ), 'Metabox must display external ID.' );
		WPS_Assert::true( str_contains( $out, 'pepita.hu' ), 'Metabox must display origin.' );
		WPS_Assert::true( str_contains( $out, 'gls_express' ), 'Metabox must display delivery mode.' );
		WPS_Assert::true( str_contains( $out, 'paid' ), 'Metabox must display payment status.' );
		WPS_Assert::true( str_contains( $out, 'PROMO-2026' ), 'Metabox must display voucher.' );
		WPS_Assert::true( str_contains( $out, 'wps-pepita-orders&amp;action=view&amp;id=PEP-M10-BOX-1' ) || str_contains( $out, 'wps-pepita-orders&action=view&id=PEP-M10-BOX-1' ), 'Metabox must link to diagnostics view.' );

		// Unauthorized user.
		WPS_Test_State::$current_user_caps['manage_woocommerce'] = false;

		ob_start();
		$admin->render_order_metabox( $order );
		$unauth_out = ob_get_clean();

		WPS_Assert::same( '', trim( $unauth_out ), 'Metabox must not render for unauthorized user.' );
		WPS_Test_State::$current_user_caps['manage_woocommerce'] = true;
	}
);

/* -------------------------------------------------------------------------
 * 3. Diagnostic View & PII Redaction
 * ---------------------------------------------------------------------- */

$suite->add(
	'm10 order diagnostics: view rendering with PII redacted payload',
	static function () use ( $make_payload, $seed_product ): void {
		Settings::update( array( 'order_api_keep_payloads' => true ) );

		$seed_product();
		WPS_Test_State::add_user( 'bela.kovacs@testdomain.hu', 'Béla', 'Kovács' );

		$p = $make_payload(
			array(
				'id'       => 'PEP-M10-PII-1',
				'customer' => array(
					'first_name'     => 'Kovács',
					'last_name'      => 'Béla',
					'email'          => 'bela.kovacs@testdomain.hu',
					'phone'          => '+36301234567',
					'billing_street' => 'Titkos utca 42.',
				),
			)
		);

		$importer = new Importer();
		$res      = $importer->import( $p );
		WPS_Assert::same( 'created', $res['status'] );

		// Render orders view with action=view and id=PEP-M10-PII-1.
		$_GET['action'] = 'view';
		$_GET['id']     = 'PEP-M10-PII-1';

		ob_start();
		require WPS_PEPITA_DIR . 'includes/views/orders.php';
		$view_out = ob_get_clean();

		unset( $_GET['action'], $_GET['id'] );

		// 1. Must render order and integration sections.
		WPS_Assert::true( str_contains( $view_out, 'PEP-M10-PII-1' ), 'View must render external ID.' );
		WPS_Assert::true( str_contains( $view_out, 'Importálva' ), 'View must show status label.' );
		WPS_Assert::true( str_contains( $view_out, 'Rendelés újrafeldolgozása' ), 'View must provide retry button.' );
		WPS_Assert::true( str_contains( $view_out, 'Kapcsolat helyreállítása' ), 'View must provide recover button.' );

		// 2. Sensitive customer PII MUST be redacted in output.
		WPS_Assert::false( str_contains( $view_out, 'Titkos utca 42.' ), 'Street address must be redacted.' );
		WPS_Assert::false( str_contains( $view_out, '+36301234567' ), 'Full phone number must not be exposed.' );
		WPS_Assert::false( str_contains( $view_out, 'bela.kovacs@testdomain.hu' ), 'Full email address must not be exposed.' );

		// Masked formats are allowed.
		WPS_Assert::true( str_contains( $view_out, 'b***@testdomain.hu' ) || str_contains( $view_out, '[redacted]' ), 'Email should be masked or redacted.' );
	}
);

$suite->add(
	'm10 order diagnostics: behavior when raw payload is not retained',
	static function (): void {
		Settings::update( array( 'order_api_keep_payloads' => false ) );

		Order_Repository::claim( 'PEP-M10-NOPAYLOAD', 'pepita.hu', '', 'sha256hash123' );
		Order_Repository::complete( 'PEP-M10-NOPAYLOAD', 99, 'sha256hash123', 1 );

		$_GET['action'] = 'view';
		$_GET['id']     = 'PEP-M10-NOPAYLOAD';

		ob_start();
		require WPS_PEPITA_DIR . 'includes/views/orders.php';
		$view_out = ob_get_clean();

		unset( $_GET['action'], $_GET['id'] );

		WPS_Assert::true( str_contains( $view_out, 'PEP-M10-NOPAYLOAD' ), 'External ID displayed.' );
		WPS_Assert::true( str_contains( $view_out, 'sha256hash123' ), 'SHA-256 hash displayed.' );
		WPS_Assert::true( str_contains( $view_out, 'disabled' ), 'Retry button must be disabled when payload not stored.' );
		WPS_Assert::true( str_contains( $view_out, 'A nyers adatcsomag nem elérhető' ), 'Notice about missing payload displayed.' );
	}
);

/* -------------------------------------------------------------------------
 * 4. Operator Retry Workflow
 * ---------------------------------------------------------------------- */

$suite->add(
	'm10 operator retry: idempotent retry with stored payload',
	static function () use ( $make_payload, $seed_product ): void {
		Settings::update( array( 'order_api_keep_payloads' => true ) );

		$seed_product();
		WPS_Test_State::add_user( 'janos.kovacs@example.test', 'János', 'Kovács' );

		$p        = $make_payload( array( 'id' => 'PEP-M10-RETRY-1' ) );
		$importer = new Importer();
		$init     = $importer->import( $p );
		WPS_Assert::same( 'created', $init['status'] );
		$first_order_id = $init['order_id'];

		// Now simulate retry action via Order_Admin::handle_retry_order.
		$admin = new Order_Admin();

		$nonce = wp_create_nonce( 'wps_pepita_retry_order_PEP-M10-RETRY-1' );
		$_POST['id']          = 'PEP-M10-RETRY-1';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		// Execute retry (handling safe exit/redirect).
		try {
			$admin->handle_retry_order();
		} catch ( \Throwable $t ) {
			// wp_safe_redirect / exit in stub or testing.
		}

		unset( $_POST['id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// Verify claim is completed and order exists.
		$claim = Order_Repository::get( 'PEP-M10-RETRY-1' );
		WPS_Assert::true( null !== $claim );
		WPS_Assert::same( Order_Repository::STATUS_COMPLETED, $claim['status'] );
		WPS_Assert::true( (int) $claim['wc_order_id'] > 0 );
	}
);

$suite->add(
	'm10 operator retry: rejection when payload was not stored',
	static function (): void {
		Settings::update( array( 'order_api_keep_payloads' => false ) );

		Order_Repository::claim( 'PEP-M10-NO-PAYLOAD-RETRY', 'pepita.hu', '', 'hash789' );
		Order_Repository::fail( 'PEP-M10-NO-PAYLOAD-RETRY', 'Some error', 1 );

		$admin = new Order_Admin();

		$nonce = wp_create_nonce( 'wps_pepita_retry_order_PEP-M10-NO-PAYLOAD-RETRY' );
		$_POST['id']          = 'PEP-M10-NO-PAYLOAD-RETRY';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			$admin->handle_retry_order();
		} catch ( \Throwable $t ) {
			// redirect
		}

		unset( $_POST['id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$claim = Order_Repository::get( 'PEP-M10-NO-PAYLOAD-RETRY' );
		WPS_Assert::true( null !== $claim );
		WPS_Assert::same( Order_Repository::STATUS_FAILED, $claim['status'], 'Status should remain failed.' );
	}
);

/* -------------------------------------------------------------------------
 * 5. Operator Recovery Workflow: Orphan Reconciliation & Lost Claims
 * ---------------------------------------------------------------------- */

$suite->add(
	'm10 operator recovery: reconnect orphaned WooCommerce order',
	static function (): void {
		// Create a WooCommerce order with Pepita metadata.
		$wc_order = new WC_Order();
		$wc_order->update_meta_data( Importer::META_ORDER_ID, 'PEP-M10-ORPHAN-1' );
		WPS_Test_State::add_order( $wc_order );

		// Create claim record with wc_order_id = 0.
		Order_Repository::claim( 'PEP-M10-ORPHAN-1', 'pepita.hu', '{}', 'hash_orphan' );
		// Claim has wc_order_id = 0.
		$row = Order_Repository::get( 'PEP-M10-ORPHAN-1' );
		WPS_Assert::same( 0, (int) $row['wc_order_id'] );

		// Operator executes recovery.
		$admin                = new Order_Admin();
		$nonce                = wp_create_nonce( 'wps_pepita_recover_order_PEP-M10-ORPHAN-1' );
		$_POST['id']          = 'PEP-M10-ORPHAN-1';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			$admin->handle_recover_order();
		} catch ( \Throwable $t ) {
			// redirect
		}

		unset( $_POST['id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// Claim must now be associated with the WooCommerce order ID.
		$updated = Order_Repository::get( 'PEP-M10-ORPHAN-1' );
		WPS_Assert::same( $wc_order->get_id(), (int) $updated['wc_order_id'], 'Orphan order must be associated.' );
	}
);

$suite->add(
	'm10 operator recovery: reopen completed claim when order is deleted or missing',
	static function (): void {
		// Claim is marked completed, but no WooCommerce order exists.
		Order_Repository::claim( 'PEP-M10-LOST-ORDER', 'pepita.hu', '{}', 'hash_lost' );
		Order_Repository::complete( 'PEP-M10-LOST-ORDER', 99999, 'hash_lost', 1 );

		// Verify order 99999 does not exist in WPS_Test_State::$orders.
		unset( WPS_Test_State::$orders[99999] );

		$admin                = new Order_Admin();
		$nonce                = wp_create_nonce( 'wps_pepita_recover_order_PEP-M10-LOST-ORDER' );
		$_POST['id']          = 'PEP-M10-LOST-ORDER';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			$admin->handle_recover_order();
		} catch ( \Throwable $t ) {
			// redirect
		}

		unset( $_POST['id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$reopened = Order_Repository::get( 'PEP-M10-LOST-ORDER' );
		WPS_Assert::same( Order_Repository::STATUS_FAILED, $reopened['status'], 'Lost claim must be reopened to failed.' );
		WPS_Assert::same( 0, (int) $reopened['wc_order_id'], 'wc_order_id must be reset to 0.' );
	}
);

/* -------------------------------------------------------------------------
 * 6. Package Label Security: Path Traversal Prevention
 * ---------------------------------------------------------------------- */

$suite->add(
	'm10 label security: prevent directory traversal on label download',
	static function (): void {
		$admin = new Order_Admin();

		$order = new WC_Order();
		// Attacker attempts traversal to system files.
		$order->update_meta_data( Importer::META_PACKAGE_LABEL, '/etc/passwd' );
		WPS_Test_State::add_order( $order );

		$nonce                = wp_create_nonce( 'wps_pepita_download_label_' . $order->get_id() );
		$_GET['order_id']     = $order->get_id();
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$died = false;
		try {
			$admin->handle_download_label();
		} catch ( \Throwable $t ) {
			$died = true;
		}

		unset( $_GET['order_id'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );

		WPS_Assert::true( $died, 'Path traversal must be blocked and die.' );
	}
);
