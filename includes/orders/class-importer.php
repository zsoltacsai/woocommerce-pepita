<?php
/**
 * Creates WooCommerce orders from verified Pepita order payloads.
 *
 * Everything goes through the WooCommerce CRUD API (HPOS safe). The importer
 * is all-or-nothing: a single unmappable line aborts the import before an
 * order is created, and the event stays retryable.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Orders;

use WPS\Pepita\Logger;
use WPS\Pepita\Redactor;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

defined( 'ABSPATH' ) || exit;

final class Importer {

	public const META_ORDER_ID       = '_wps_pepita_order_id';
	public const META_ORIGIN         = '_wps_pepita_origin';
	public const META_PAYMENT_STATUS = '_wps_pepita_payment_status';
	public const META_DELIVERY_MODE  = '_wps_pepita_delivery_mode';
	public const META_IMPORTED_AT    = '_wps_pepita_imported_at';
	public const META_PAYLOAD_HASH   = '_wps_pepita_payload_hash';
	public const META_VOUCHER        = '_wps_pepita_voucher';
	public const META_RAW_TOTAL      = '_wps_pepita_raw_total';
	public const META_PACKAGE_LABEL  = '_wps_pepita_package_label';
	public const META_CUSTOMER_MSG   = '_wps_pepita_customer_message';
	public const META_COURIER_MSG    = '_wps_pepita_courier_message';

	/**
	 * Import one payload.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{status: string, http: int, message: string, order_id: int}
	 */
	public function import( array $payload ): array {
		$external_id = $this->external_id( $payload );

		if ( '' === $external_id ) {
			return $this->result( 'error', 400, 'Hiányzik vagy érvénytelen a Pepita rendelésazonosító (id).' );
		}

		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$payload_json = is_string( $payload_json ) ? $payload_json : '';
		$payload_hash = hash( 'sha256', $payload_json );
		$payload['__hash'] = $payload_hash;

		$claim = Order_Repository::claim( $external_id, (string) ( $payload['origin'] ?? '' ), $payload_json, $payload_hash );

		if ( 'completed' === $claim['state'] ) {
			$order_id = (int) ( $claim['row']['wc_order_id'] ?? 0 );

			Logger::info(
				'order_duplicate',
				'Ismételt Pepita rendelésértesítés: a rendelés már importálva van, nem hoztunk létre újat.',
				array(
					'external_id' => $external_id,
					'order_id'    => $order_id,
				),
				'order',
				$order_id
			);

			return $this->result( 'duplicate', 200, 'A rendelés már korábban importálásra került.', $order_id );
		}

		if ( 'busy' === $claim['state'] ) {
			return $this->result( 'retry', 409, 'A rendelés feldolgozása folyamatban van. Kérjük, próbálja újra később.' );
		}

		if ( 'claimed' !== $claim['state'] ) {
			return $this->result( 'error', 500, 'A rendelés rögzítése átmenetileg nem lehetséges.' );
		}

		// Durable fallback: an order may already exist if a previous attempt
		// crashed after the WooCommerce order was saved.
		$existing = $this->find_order_by_external_id( $external_id );

		if ( $existing > 0 ) {
			Order_Repository::complete( $external_id, $existing );

			return $this->result( 'duplicate', 200, 'A rendelés már korábban importálásra került.', $existing );
		}

		try {
			$validation = $this->validate( $payload );

			if ( isset( $validation['error'] ) ) {
				Order_Repository::fail( $external_id, $validation['error'] );

				Logger::warning(
					'order_import',
					'Pepita rendelés elutasítva: ' . $validation['error'],
					array(
						'external_id' => $external_id,
						'payload'     => Redactor::redact( $payload ),
					),
					'order',
					0
				);

				return $this->result( 'error', 422, $validation['error'] );
			}

			$order_id = $this->create_order( $payload, $validation, $external_id );
			if ( is_wp_error( $order_id ) ) {
				Order_Repository::fail( $external_id, $order_id->get_error_message() );

				return $this->result( 'error', 500, 'A WooCommerce rendelés létrehozása sikertelen.' );
			}

			Order_Repository::complete( $external_id, (int) $order_id );

			return $this->result( 'created', 200, '', (int) $order_id );
		} catch ( \Throwable $throwable ) {
			Order_Repository::fail( $external_id, $throwable->getMessage() );

			Logger::error(
				'order_import',
				'Váratlan hiba a Pepita rendelés importálása közben.',
				array(
					'external_id' => $external_id,
					'exception'   => $throwable->getMessage(),
				)
			);

			return $this->result( 'error', 500, 'Váratlan hiba történt a rendelés feldolgozása során.' );
		}
	}

	/**
	 * Validate the documented payload contract.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|array{error: string}
	 */
	public function validate( array $payload ): array {
		if ( ! isset( $payload['products'] ) || ! is_array( $payload['products'] ) || array() === $payload['products'] ) {
			return array( 'error' => 'A rendelés nem tartalmaz terméktételeket.' );
		}

		if ( ! isset( $payload['customer'] ) || ! is_array( $payload['customer'] ) ) {
			return array( 'error' => 'Hiányzik a vásárlói adat (customer).' );
		}

		$email = (string) ( $payload['customer']['email'] ?? '' );

		if ( '' === $email || ! is_email( $email ) ) {
			return array( 'error' => 'Hiányzik vagy érvénytelen a vásárló e-mail címe.' );
		}

		$currency = '';

		foreach ( $payload['products'] as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel formátuma érvénytelen.', $index + 1 ) );
			}

			$quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;

			if ( $quantity <= 0 ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel mennyisége érvénytelen.', $index + 1 ) );
			}

			$price = isset( $item['price'] ) ? (float) $item['price'] : 0.0;

			if ( ! is_finite( $price ) || $price <= 0 ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel ára érvénytelen.', $index + 1 ) );
			}

			$item_currency = strtoupper( (string) ( $item['currency'] ?? '' ) );

			if ( '' !== $item_currency && ! preg_match( '/^[A-Z]{3}$/', $item_currency ) ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel pénzneme érvénytelen.', $index + 1 ) );
			}

			if ( '' === $currency && '' !== $item_currency ) {
				$currency = $item_currency;
			} elseif ( '' !== $item_currency && $currency !== $item_currency ) {
				return array( 'error' => 'A rendelés tételei eltérő pénznemben szerepelnek.' );
			}

			$vat = isset( $item['vat'] ) ? (float) $item['vat'] : 0.0;

			if ( ! is_finite( $vat ) || $vat < 0 || $vat > 100 ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel áfája érvénytelen.', $index + 1 ) );
			}
		}

		$shipping = $this->to_amount( $payload['total_shipping_price'] ?? 0 );

		if ( $shipping < 0 ) {
			return array( 'error' => 'A szállítási díj érvénytelen.' );
		}

		$voucher = $this->to_amount( $payload['voucher'] ?? 0 );

		if ( $voucher < 0 ) {
			return array( 'error' => 'Az utalvány összege érvénytelen.' );
		}

		$items_total = 0.0;

		foreach ( $payload['products'] as $item ) {
			$items_total += (float) $item['price'] * (int) $item['quantity'];
		}

		$total = $items_total + $shipping - $voucher;

		if ( $total < 0 ) {
			return array( 'error' => 'A rendelés számított végösszege negatív.' );
		}

		$payment_mode = sanitize_key( (string) ( $payload['payment_mode'] ?? '' ) );

		if ( '' !== $payment_mode && ! Mapper::is_known_payment( $payment_mode ) ) {
			return array( 'error' => sprintf( 'Ismeretlen fizetési mód: %s', $payment_mode ) );
		}

		$delivery_mode = sanitize_key( (string) ( $payload['delivery_mod'] ?? '' ) );

		if ( '' !== $delivery_mode && ! Mapper::is_known_shipping( $delivery_mode ) ) {
			return array( 'error' => sprintf( 'Ismeretlen szállítási mód: %s', $delivery_mode ) );
		}

		$resolved = $this->resolve_products( $payload['products'] );

		if ( isset( $resolved['error'] ) ) {
			return array( 'error' => (string) $resolved['error'] );
		}

		return array(
			'items_total'   => $items_total,
			'shipping'      => $shipping,
			'voucher'       => $voucher,
			'total'         => $total,
			'currency'      => '' !== $currency ? $currency : Settings::currency(),
			'payment_mode'  => $payment_mode,
			'delivery_mode' => $delivery_mode,
			'payment_status'=> sanitize_key( (string) ( $payload['payment_status'] ?? 'unpaid' ) ),
			'products'      => $resolved,
		);
	}

	/**
	 * Resolve every payload line to a WooCommerce product before creating anything.
	 *
	 * @param array<int, mixed> $items
	 * @return array<int, array<string, mixed>>|array{error: string}
	 */
	private function resolve_products( array $items ): array {
		$resolved = array();

		foreach ( $items as $index => $item ) {
			$sku       = trim( (string) ( $item['sku'] ?? '' ) );
			$export_id = trim( (string) ( $item['id'] ?? '' ) );
			$product_id = 0;

			if ( '' !== $sku ) {
				$product_id = (int) wc_get_product_id_by_sku( $sku );
			}

			if ( $product_id <= 0 && '' !== $export_id ) {
				$product_id = $this->find_product_by_export_id( $export_id );
			}

			if ( $product_id <= 0 ) {
				return array(
					'error' => sprintf(
						'A(z) %d. tétel nem azonosítható egyetlen WooCommerce termékkel sem (SKU: %s, Pepita azonosító: %s). A rendelés nem került létrehozásra.',
						$index + 1,
						'' !== $sku ? $sku : '—',
						'' !== $export_id ? $export_id : '—'
					),
				);
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof \WC_Product ) {
				return array( 'error' => sprintf( 'A(z) %d. tételhez tartozó termék nem tölthető be.', $index + 1 ) );
			}

			if ( $product->is_type( 'variable' ) ) {
				return array(
					'error' => sprintf(
						'A(z) %d. tétel egy variációs termék szülőtermékére mutat (SKU: %s), de a konkrét variáció nem azonosítható a Pepita adatokból. A rendelés nem került létrehozásra.',
						$index + 1,
						$sku
					),
				);
			}

			if ( 'publish' !== $product->get_status() ) {
				return array( 'error' => sprintf( 'A(z) %d. tételhez tartozó termék nem publikált.', $index + 1 ) );
			}

			$resolved[] = array(
				'product'    => $product,
				'quantity'   => (int) $item['quantity'],
				'price'      => (float) $item['price'],
				'vat'        => (float) ( $item['vat'] ?? 0 ),
				'currency'   => strtoupper( (string) ( $item['currency'] ?? '' ) ),
				'pepita_id'  => $export_id,
				'sku'        => $sku,
			);
		}

		return $resolved;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $validated
	 * @return int|\WP_Error
	 */
	private function create_order( array $payload, array $validated, string $external_id ) {
		$customer = is_array( $payload['customer'] ?? null ) ? $payload['customer'] : array();

		$order = wc_create_order(
			array(
				'created_via' => 'wps-pepita',
				'currency'    => (string) $validated['currency'],
			)
		);

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'wps_order_failed', 'A WooCommerce nem adott vissza rendelést.' );
		}

		$order->set_created_via( 'wps-pepita' );
		$order->set_currency( (string) $validated['currency'] );

		$this->apply_customer( $order, $customer );

		$email = (string) ( $customer['email'] ?? '' );
		$user  = '' !== $email ? get_user_by( 'email', $email ) : false;

		if ( $user instanceof \WP_User ) {
			$order->set_customer_id( (int) $user->ID );
		}

		foreach ( $validated['products'] as $line ) {
			$product = $line['product'];
			$total   = (float) $line['price'] * (int) $line['quantity'];

			$item_id = $order->add_product(
				$product,
				(int) $line['quantity'],
				array(
					'subtotal' => $total,
					'total'    => $total,
					'name'     => $product->get_name(),
				)
			);

			if ( ! $item_id ) {
				return new \WP_Error( 'wps_order_item_failed', 'A rendeléstétel hozzáadása sikertelen.' );
			}

			$item = $order->get_item( $item_id );

			if ( $item instanceof \WC_Order_Item_Product ) {
				$item->add_meta_data( '_wps_pepita_product_id', (string) $line['pepita_id'], true );
				$item->add_meta_data( '_wps_pepita_sku', (string) $line['sku'], true );
				$item->add_meta_data( '_wps_pepita_vat', (string) $line['vat'], true );
				$item->save();
			}
		}

		$shipping = Mapper::shipping( (string) $validated['delivery_mode'] );

		if ( (float) $validated['shipping'] > 0 ) {
			$shipping_item = new \WC_Order_Item_Shipping();
			$shipping_item->set_method_title( $shipping['title'] );
			$shipping_item->set_method_id( $shipping['id'] );
			$shipping_item->set_total( (float) $validated['shipping'] );
			$order->add_item( $shipping_item );
		}

		if ( (float) $validated['voucher'] > 0 ) {
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name( 'Pepita utalvány' );
			$fee->set_amount( -(float) $validated['voucher'] );
			$fee->set_total( -(float) $validated['voucher'] );
			$order->add_item( $fee );
		}

		$payment = Mapper::payment( (string) $validated['payment_mode'] );

		if ( '' !== $payment['id'] ) {
			$order->set_payment_method( $payment['id'] );
		}

		$order->set_payment_method_title( $payment['title'] );

		$order->update_meta_data( self::META_ORDER_ID, $external_id );
		$order->update_meta_data( self::META_ORIGIN, (string) ( $payload['origin'] ?? 'pepita.hu' ) );
		$order->update_meta_data( self::META_PAYMENT_STATUS, (string) $validated['payment_status'] );
		$order->update_meta_data( self::META_DELIVERY_MODE, (string) $validated['delivery_mode'] );
		$order->update_meta_data( self::META_IMPORTED_AT, current_time( 'mysql', true ) );
		$order->update_meta_data( self::META_PAYLOAD_HASH, (string) ( $payload['__hash'] ?? '' ) );
		$order->update_meta_data( self::META_RAW_TOTAL, (string) $validated['total'] );

		if ( (float) $validated['voucher'] > 0 ) {
			$order->update_meta_data( self::META_VOUCHER, (string) $validated['voucher'] );
		}

		if ( '' !== (string) ( $payload['customer_message'] ?? '' ) ) {
			$order->update_meta_data( self::META_CUSTOMER_MSG, (string) $payload['customer_message'] );
		}

		if ( '' !== (string) ( $payload['courier_message'] ?? '' ) ) {
			$order->update_meta_data( self::META_COURIER_MSG, (string) $payload['courier_message'] );
		}

		$created = $this->parse_date( (string) ( $payload['date'] ?? '' ) );

		if ( $created > 0 ) {
			$order->set_date_created( $created );
		}

		$order->calculate_totals();

		$paid = 'paid' === (string) $validated['payment_status'];
		$status = $paid
			? (string) Settings::get( 'order_api_status_paid', 'processing' )
			: (string) Settings::get( 'order_api_status_unpaid', 'on-hold' );

		$status = $this->safe_status( $status );

		if ( $paid ) {
			$order->set_date_paid( $created > 0 ? $created : time() );
		}

		$this->apply_package_label( $order, $payload, $external_id );

		$order->add_order_note(
			sprintf(
				'Pepita rendelés importálva. Pepita rendelésazonosító: %s. Fizetési mód: %s. Szállítási mód: %s. Fizetési státusz: %s.',
				$external_id,
				$payment['title'],
				$shipping['title'],
				$paid ? 'fizetve' : 'nincs fizetve'
			)
		);

		if ( '' !== (string) ( $payload['customer_message'] ?? '' ) ) {
			$order->add_order_note( 'Vásárlói megjegyzés: ' . (string) $payload['customer_message'] );
		}

		if ( '' !== (string) ( $payload['courier_message'] ?? '' ) ) {
			$order->add_order_note( 'Futár megjegyzés: ' . (string) $payload['courier_message'] );
		}

		$order->set_status( $status, 'Pepita import' );

		$email_filters = $this->suppress_emails();

		try {
			$order_id = $order->save();
		} finally {
			$this->restore_emails( $email_filters );
		}

		if ( ! $order_id ) {
			return new \WP_Error( 'wps_order_save_failed', 'A rendelés mentése sikertelen.' );
		}

		Logger::info(
			'order_import',
			'Pepita rendelés importálva.',
			array(
				'external_id' => $external_id,
				'order_id'    => (int) $order_id,
				'items'       => count( $validated['products'] ),
				'total'       => (float) $order->get_total(),
				'paid'        => $paid,
			),
			'order',
			(int) $order_id
		);

		return (int) $order_id;
	}

	/**
	 * @param array<string, mixed> $customer
	 */
	private function apply_customer( \WC_Order $order, array $customer ): void {
		$first = (string) ( $customer['first_name'] ?? '' );
		$last  = (string) ( $customer['last_name'] ?? '' );

		if ( '' === $first && '' === $last && '' !== (string) ( $customer['billing_name'] ?? '' ) ) {
			$parts = preg_split( '/\s+/', trim( (string) $customer['billing_name'] ) ) ?: array();
			$last  = (string) array_shift( $parts );
			$first = implode( ' ', $parts );
		}

		$order->set_billing_first_name( $first );
		$order->set_billing_last_name( $last );
		$order->set_billing_email( (string) ( $customer['email'] ?? '' ) );
		$order->set_billing_phone( (string) ( $customer['phone'] ?? '' ) );

		$billing_name = (string) ( $customer['billing_name'] ?? '' );

		if ( '' !== $billing_name ) {
			$order->set_billing_company( $billing_name );
		}

		$order->set_billing_country( strtoupper( (string) ( $customer['billing_country'] ?? '' ) ) );
		$order->set_billing_city( (string) ( $customer['billing_city'] ?? '' ) );
		$order->set_billing_postcode( (string) ( $customer['billing_postal_code'] ?? '' ) );
		$order->set_billing_address_1( $this->street( $customer, 'billing' ) );
		$order->set_billing_address_2( (string) ( $customer['billing_house_number'] ?? '' ) );

		$order->set_shipping_first_name( $first );
		$order->set_shipping_last_name( $last );
		$order->set_shipping_country( strtoupper( (string) ( $customer['shipping_country'] ?? $customer['billing_country'] ?? '' ) ) );
		$order->set_shipping_city( (string) ( $customer['shipping_city'] ?? $customer['billing_city'] ?? '' ) );
		$order->set_shipping_postcode( (string) ( $customer['shipping_postal_code'] ?? $customer['billing_postal_code'] ?? '' ) );
		$order->set_shipping_address_1( $this->street( $customer, 'shipping' ) );
		$order->set_shipping_address_2( (string) ( $customer['shipping_house_number'] ?? '' ) );

		if ( '' !== (string) ( $customer['tax_number'] ?? '' ) ) {
			$order->update_meta_data( '_billing_vat_number', (string) $customer['tax_number'] );
		}
	}

	/**
	 * @param array<string, mixed> $customer
	 */
	private function street( array $customer, string $prefix ): string {
		$full = trim( (string) ( $customer[ $prefix . '_street' ] ?? '' ) );

		if ( '' !== $full ) {
			return $full;
		}

		$street = trim( (string) ( $customer[ $prefix . '_street_address' ] ?? '' ) );
		$number = trim( (string) ( $customer[ $prefix . '_house_number' ] ?? '' ) );

		return trim( $street . ( '' !== $number ? ' ' . $number : '' ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function apply_package_label( \WC_Order $order, array $payload, string $external_id ): void {
		$label = (string) ( $payload['package_label'] ?? '' );

		if ( '' === $label || ! (bool) Settings::get( 'order_api_save_label', true ) ) {
			return;
		}

		$binary = base64_decode( preg_replace( '/\s+/', '', $label ) ?? '', true );

		if ( false === $binary || '' === $binary || strlen( $binary ) > 8 * MB_IN_BYTES ) {
			Logger::warning(
				'order_import',
				'Érvénytelen vagy túl nagy csomagcímke érkezett, a fájl nem került mentésre.',
				array( 'external_id' => $external_id )
			);

			return;
		}

		$dir = Storage::label_dir();
		$file = $dir . '/pepita-' . preg_replace( '/[^a-zA-Z0-9_-]/', '', $external_id ) . '-' . wp_generate_password( 8, false, false ) . '.pdf';

		if ( file_put_contents( $file, $binary ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$order->update_meta_data( self::META_PACKAGE_LABEL, $file );
		}
	}

	private function parse_date( string $date ): int {
		if ( '' === $date ) {
			return 0;
		}

		$timestamp = strtotime( $date );

		return false !== $timestamp ? $timestamp : 0;
	}

	private function safe_status( string $status ): string {
		$allowed = array_keys( wc_get_order_statuses() );
		$allowed = array_map( static fn( string $key ): string => str_replace( 'wc-', '', $key ), $allowed );

		return in_array( $status, $allowed, true ) ? $status : 'on-hold';
	}

	/**
	 * Prevent customer e-mails during the import; gateways are never executed.
	 *
	 * @return string[]
	 */
	private function suppress_emails(): array {
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
			add_filter( $hook, '__return_false', 999 );
		}

		return $hooks;
	}

	/**
	 * @param string[] $hooks
	 */
	private function restore_emails( array $hooks ): void {
		foreach ( $hooks as $hook ) {
			remove_filter( $hook, '__return_false', 999 );
		}
	}

	private function external_id( array $payload ): string {
		if ( ! isset( $payload['id'] ) ) {
			return '';
		}

		$id = is_scalar( $payload['id'] ) ? trim( (string) $payload['id'] ) : '';

		if ( '' === $id || strlen( $id ) > 191 ) {
			return '';
		}

		return $id;
	}

	/**
	 * @param mixed $value
	 */
	private function to_amount( $value ): float {
		if ( is_string( $value ) ) {
			$value = trim( str_replace( array( ' ', ',' ), array( '', '.' ), $value ) );
		}

		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}

		return (float) $value;
	}

	private function find_product_by_export_id( string $export_id ): int {
		if ( '' === $export_id ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_wps_export_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $export_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return is_array( $ids ) && isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	private function find_order_by_external_id( string $external_id ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => self::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'status'     => 'any',
			)
		);

		return is_array( $orders ) && isset( $orders[0] ) ? (int) $orders[0] : 0;
	}

	/**
	 * @return array{status: string, http: int, message: string, order_id: int}
	 */
	private function result( string $status, int $http, string $message, int $order_id = 0 ): array {
		return array(
			'status'   => $status,
			'http'     => $http,
			'message'  => $message,
			'order_id' => $order_id,
		);
	}
}
