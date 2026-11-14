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

	private const FENCED_OUT = 'wps_fenced_out';

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

		$canonical_payload = self::canonicalize( $payload );
		$payload_json      = wp_json_encode( $canonical_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$payload_json      = is_string( $payload_json ) ? $payload_json : '';
		$payload_hash      = hash( 'sha256', $payload_json );
		$payload['__hash'] = $payload_hash;

		$claim = Order_Repository::claim( $external_id, (string) ( $payload['origin'] ?? '' ), $payload_json, $payload_hash );

		if ( 'completed' === $claim['state'] ) {
			$attempt     = (int) ( $claim['row']['attempts'] ?? 0 );
			$order_id    = (int) ( $claim['row']['wc_order_id'] ?? 0 );
			$stored_hash = (string) ( $claim['row']['payload_hash'] ?? '' );

			if ( 0 === $order_id ) {
				$candidate_id = $this->find_order_by_external_id( $external_id );
				if ( $candidate_id > 0 ) {
					$candidate_order = wc_get_order( $candidate_id );
					if ( $candidate_order instanceof \WC_Order && ! $this->is_order_incomplete( $candidate_order ) ) {
						$order_id = $candidate_id;
						Order_Repository::associate_legacy_completed_order( $external_id, $order_id );
					}
				}
			}

			if ( 0 === $order_id ) {
				Logger::error(
					'order_import',
					'A Pepita rendelés lezártként szerepel a nyilvántartásban, de a WooCommerce rendelés nem található.',
					array( 'external_id' => $external_id ),
					'claim',
					0
				);

				return $this->result( 'error', 500, 'A rendelés nem található a WooCommerce rendszerében.' );
			}

			// A completed claim must be backed by an existing, finalized order. Never acknowledge a
			// replay (HTTP 200) for an order that is missing or still carries the in-progress
			// marker: Pepita would stop retrying while the merchant has no valid order. Surface a
			// failure instead and require operator repair (wp wps-pepita repair-claim <id>).
			$problem = $this->completed_order_problem( $order_id );

			if ( '' !== $problem ) {
				Logger::error(
					'order_import',
					'A Pepita rendelés lezártként szerepel a nyilvántartásban, de a WooCommerce rendelés ' . $problem . '.',
					array(
						'external_id' => $external_id,
						'order_id'    => $order_id,
						'repair'      => 'wp wps-pepita repair-claim ' . $external_id,
					),
					'claim',
					$order_id
				);

				return $this->result( 'error', 500, 'A rendelés lezártként szerepel, de a WooCommerce rendelés ' . $problem . '. Üzemeltetői javítás szükséges.' );
			}

			return $this->handle_existing_order( $external_id, $order_id, $stored_hash, $payload_hash, $payload, false, $attempt );
		}

		if ( 'busy' === $claim['state'] ) {
			return $this->result( 'retry', 409, 'A rendelés feldolgozása folyamatban van. Kérjük, próbálja újra később.' );
		}

		if ( 'claimed' !== $claim['state'] || ! isset( $claim['row']['attempts'] ) || (int) $claim['row']['attempts'] <= 0 ) {
			return $this->result( 'error', 500, 'A rendelés rögzítése átmenetileg nem lehetséges.' );
		}

		$attempt = (int) $claim['row']['attempts'];

		// Durable fallback: an order may already exist if a previous attempt
		// crashed after the WooCommerce order was initiated.
		$existing = $this->find_order_by_external_id( $external_id );

		if ( $existing > 0 ) {
			$existing_order = wc_get_order( $existing );

			if ( $existing_order instanceof \WC_Order ) {
				if ( $this->is_order_incomplete( $existing_order ) ) {
					// 1. Atomically fence the cleanup attempt in the claim table before initiating order deletion.
					$fenced = Order_Repository::fence_incomplete_order_cleanup( $external_id, $existing, $attempt );
					if ( ! $fenced ) {
						Logger::warning(
							'order_recovery',
							'A félbehagyott rendelés törlése megszakítva: a feldolgozási bérlet lejárt vagy átadásra került.',
							array(
								'external_id' => $external_id,
								'orphan_id'   => $existing,
								'attempt'     => $attempt,
							)
						);

						return $this->result( 'retry', 409, 'A rendelés feldolgozási zárolása lejárt, a feldolgozást egy újabb kísérlet vette át.' );
					}

					// 2. Reload the order state fresh from the database before deleting.
					$fresh_order = wc_get_order( $existing );
					if ( ! ( $fresh_order instanceof \WC_Order ) ) {
						// Shell vanished or was deleted outside this worker.
						// Clear association in claim and fall through to create a fresh order.
						Order_Repository::associate( $external_id, 0, $attempt );
						$existing = 0;
					} elseif ( ! $this->is_order_incomplete( $fresh_order ) ) {
						Logger::info(
							'order_recovery',
							'A félbehagyottnak hitt rendelés időközben befejeződött vagy tételeket kapott; megőrizzük és meglévőként kezeljük.',
							array(
								'external_id' => $external_id,
								'order_id'    => $existing,
							)
						);

						$stored_hash = (string) $fresh_order->get_meta( self::META_PAYLOAD_HASH );
						if ( '' === $stored_hash ) {
							$stored_hash = (string) ( $claim['row']['payload_hash'] ?? '' );
						}

						Order_Repository::associate( $external_id, $existing, $attempt );

						return $this->handle_existing_order( $external_id, $existing, $stored_hash, $payload_hash, $payload, true, $attempt );
					} else {
						// 3. Pre-delete check: confirm is_attempt_current() one more time before calling delete().
						if ( ! Order_Repository::is_attempt_current( $external_id, $attempt ) ) {
							Logger::warning(
								'order_recovery',
								'A félbehagyott rendelés törlése megszakítva a törlés előtti ellenőrzésnél: a bérlet lejárt.',
								array(
									'external_id' => $external_id,
									'orphan_id'   => $existing,
									'attempt'     => $attempt,
								)
							);

							return $this->result( 'retry', 409, 'A rendelés feldolgozási zárolása lejárt, a feldolgozást egy újabb kísérlet vette át.' );
						}

						// 4. Safely delete or quarantine.
						$cleaned = $this->safe_cleanup_incomplete_order( $fresh_order, $external_id, $attempt );
						if ( ! $cleaned ) {
							return $this->result( 'retry', 409, 'A félbehagyott rendelés tisztítása és karanténba helyezése meghiúsult. Újrapróbálkozás szükséges.' );
						}
						$existing = 0;
					}
				} else {
					$stored_hash = (string) $existing_order->get_meta( self::META_PAYLOAD_HASH );
					if ( '' === $stored_hash ) {
						$stored_hash = (string) ( $claim['row']['payload_hash'] ?? '' );
					}

					return $this->handle_existing_order( $external_id, $existing, $stored_hash, $payload_hash, $payload, true, $attempt );
				}
			}
		}

		try {
			$validation = $this->validate( $payload );

			if ( isset( $validation['error'] ) ) {
				Order_Repository::fail( $external_id, $validation['error'], $attempt );

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

			$order_id = $this->create_order( $payload, $validation, $external_id, $attempt );
			if ( is_wp_error( $order_id ) ) {
				if ( 'wps_fenced_out' === $order_id->get_error_code() ) {
					return $this->result( 'retry', 409, 'A rendelés feldolgozási zárolása lejárt, a feldolgozást egy újabb kísérlet vette át.' );
				}

				Order_Repository::fail( $external_id, $order_id->get_error_message(), $attempt );

				return $this->result( 'error', 500, 'A WooCommerce rendelés létrehozása sikertelen.' );
			}

			$completed = Order_Repository::complete( $external_id, (int) $order_id, $payload_hash, $attempt );
			if ( ! $completed ) {
				Logger::warning(
					'order_import',
					'A rendelés feldolgozási bérlete lejárt a befejezés előtt; a rendelést egy újabb kísérlet veszi át.',
					array(
						'external_id' => $external_id,
						'order_id'    => $order_id,
						'attempt'     => $attempt,
					),
					'order',
					(int) $order_id
				);

				return $this->result( 'retry', 409, 'A rendelés feldolgozási zárolása lejárt, a feldolgozást egy újabb kísérlet vette át.' );
			}

			return $this->result( 'created', 200, '', (int) $order_id );
		} catch ( \Throwable $throwable ) {
			Order_Repository::fail( $external_id, $throwable->getMessage(), $attempt );

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
		$lines    = array();

		foreach ( $payload['products'] as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel formátuma érvénytelen.', $index + 1 ) );
			}

			$quantity = Money::parse_quantity( $item['quantity'] ?? null );

			if ( null === $quantity ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel mennyisége érvénytelen.', $index + 1 ) );
			}

			$price = Money::parse( $item['price'] ?? null );

			if ( null === $price ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel ára érvénytelen vagy nem egyértelmű. Használjon pontot tizedesjelként, ezres elválasztó nélkül.', $index + 1 ) );
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

			$vat = Money::parse_vat( $item['vat'] ?? null );

			if ( null === $vat ) {
				return array( 'error' => sprintf( 'A(z) %d. terméktétel áfája érvénytelen.', $index + 1 ) );
			}

			$lines[] = array(
				'quantity' => $quantity,
				'price'    => $price,
				'vat'      => $vat,
			);
		}

		$shipping = Money::parse_optional( $payload['total_shipping_price'] ?? null );

		if ( null === $shipping ) {
			return array( 'error' => 'A szállítási díj érvénytelen vagy nem egyértelmű. Használjon pontot tizedesjelként, ezres elválasztó nélkül.' );
		}

		$voucher = Money::parse_optional( $payload['voucher'] ?? null );

		if ( null === $voucher ) {
			return array( 'error' => 'Az utalvány összege érvénytelen vagy nem egyértelmű. Használjon pontot tizedesjelként, ezres elválasztó nélkül.' );
		}

		$items_total = 0.0;

		foreach ( $lines as $line ) {
			// Work in cents-equivalent precision: binary float sums such as
			// 0.1 * 3 otherwise leak 0.30000000000000004 into order totals.
			$items_total = round( $items_total + round( $line['price'] * $line['quantity'], 2 ), 2 );

			if ( $items_total > Money::MAX_AMOUNT ) {
				return array( 'error' => 'A rendelés tételeinek összege meghaladja a feldolgozható felső határt.' );
			}
		}

		$total = round( $items_total + $shipping - $voucher, 2 );

		if ( $total < 0 ) {
			return array( 'error' => 'A rendelés számított végösszege negatív.' );
		}

		if ( $total > Money::MAX_AMOUNT ) {
			return array( 'error' => 'A rendelés számított végösszege meghaladja a feldolgozható felső határt.' );
		}

		$payment_mode = sanitize_key( (string) ( $payload['payment_mode'] ?? '' ) );

		if ( '' !== $payment_mode && ! Mapper::is_known_payment( $payment_mode ) ) {
			return array( 'error' => sprintf( 'Ismeretlen fizetési mód: %s', $payment_mode ) );
		}

		$delivery_mode = sanitize_key( (string) ( $payload['delivery_mod'] ?? '' ) );

		if ( '' !== $delivery_mode && ! Mapper::is_known_shipping( $delivery_mode ) ) {
			return array( 'error' => sprintf( 'Ismeretlen szállítási mód: %s', $delivery_mode ) );
		}

		$payment_status = sanitize_key( (string) ( $payload['payment_status'] ?? '' ) );

		if ( '' === $payment_status ) {
			$payment_status = 'unpaid';
		}

		if ( ! in_array( $payment_status, array( 'paid', 'unpaid' ), true ) ) {
			return array( 'error' => sprintf( 'Ismeretlen fizetési státusz: %s', $payment_status ) );
		}

		$customer = is_array( $payload['customer'] ?? null ) ? $payload['customer'] : array();
		$resolved = $this->resolve_products( $payload['products'], $customer );

		if ( isset( $resolved['error'] ) ) {
			return array( 'error' => (string) $resolved['error'] );
		}

		return array(
			'items_total'    => $items_total,
			'shipping'       => $shipping,
			'voucher'        => $voucher,
			'total'          => $total,
			'currency'       => '' !== $currency ? $currency : Settings::currency(),
			'payment_mode'   => $payment_mode,
			'delivery_mode'  => $delivery_mode,
			'payment_status' => $payment_status,
			'products'       => $resolved,
		);
	}

	/**
	 * Resolve every payload line to a WooCommerce product before creating anything.
	 *
	 * @param array<int, mixed>    $items
	 * @param array<string, mixed> $customer
	 * @return array<int, array<string, mixed>>|array{error: string}
	 */
	private function resolve_products( array $items, array $customer = array() ): array {
		$resolved    = array();
		$tax_enabled = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();

		foreach ( $items as $index => $item ) {
			$sku        = trim( (string) ( $item['sku'] ?? '' ) );
			$export_id  = trim( (string) ( $item['id'] ?? '' ) );
			$by_sku     = 0;
			$by_export  = 0;

			if ( '' !== $sku ) {
				$by_sku = (int) wc_get_product_id_by_sku( $sku );
			}

			if ( '' !== $export_id ) {
				$by_export = $this->find_product_by_export_id( $export_id );
			}

			// Both identifiers are present but point at different products:
			// refuse rather than silently pick one.
			if ( $by_sku > 0 && $by_export > 0 && $by_sku !== $by_export ) {
				return array(
					'error' => sprintf(
						'A(z) %d. tétel SKU-ja és Pepita azonosítója eltérő termékre mutat (SKU: %s, Pepita azonosító: %s). A rendelés nem került létrehozásra.',
						$index + 1,
						$sku,
						$export_id
					),
				);
			}

			$product_id = $by_sku > 0 ? $by_sku : $by_export;

			// If the SKU won but the product records a different export id,
			// the Pepita line refers to another product.
			if ( $product_id > 0 && $by_sku > 0 && '' !== $export_id ) {
				$mapped = (string) get_post_meta( $product_id, '_wps_export_id', true );

				if ( '' !== $mapped && $mapped !== $export_id ) {
					return array(
						'error' => sprintf(
							'A(z) %d. tétel Pepita azonosítója (%s) nem egyezik a SKU-hoz tartozó termék export azonosítójával (%s). A rendelés nem került létrehozásra.',
							$index + 1,
							$export_id,
							$mapped
						),
					);
				}
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

			$is_tax_exempt = (string) ( $customer['is_vat_exempt'] ?? '' ) === 'yes';

			if ( $tax_enabled && ! $is_tax_exempt ) {
				$tax_status = method_exists( $product, 'get_tax_status' ) ? $product->get_tax_status() : 'taxable';
				$raw_vat    = $item['vat'] ?? null;
				$vat_parsed = Money::parse_vat( $raw_vat );

				if ( 'none' === $tax_status ) {
					if ( null !== $vat_parsed && $vat_parsed > 0.0 ) {
						return array(
							'error' => sprintf(
								'A(z) %d. tétel a WooCommerce-ben adómentes, de a Pepita szerint %s%% áfát tartalmaz.',
								$index + 1,
								$vat_parsed
							),
						);
					}
				} else {
					$tax_class = method_exists( $product, 'get_tax_class' ) ? $product->get_tax_class() : '';
					$country   = (string) ( $customer['shipping_country'] ?? $customer['billing_country'] ?? 'HU' );
					$state     = (string) ( $customer['shipping_state'] ?? $customer['billing_state'] ?? '' );
					$postcode  = (string) ( $customer['shipping_postal_code'] ?? $customer['billing_postal_code'] ?? '' );
					$city      = (string) ( $customer['shipping_city'] ?? $customer['billing_city'] ?? '' );

					$rates = array();
					if ( class_exists( '\WC_Tax' ) ) {
						if ( method_exists( '\WC_Tax', 'find_rates' ) ) {
							$rates = \WC_Tax::find_rates(
								array(
									'country'   => $country ?: 'HU',
									'state'     => $state,
									'postcode'  => $postcode,
									'city'      => $city,
									'tax_class' => $tax_class,
								)
							);
						}

						if ( empty( $rates ) && method_exists( '\WC_Tax', 'get_base_tax_rates' ) ) {
							$rates = \WC_Tax::get_base_tax_rates( $tax_class );
						}

						if ( empty( $rates ) && method_exists( '\WC_Tax', 'get_rates' ) ) {
							$rates = \WC_Tax::get_rates( $tax_class );
						}
					}

					$configured_percent = 0.0;
					foreach ( $rates as $rate ) {
						$configured_percent += (float) ( $rate['rate'] ?? 0.0 );
					}

					if ( null !== $vat_parsed ) {
						if ( empty( $rates ) && $vat_parsed > 0.0 ) {
							return array(
								'error' => sprintf(
									'A(z) %d. tételhez nem található konfigurált adókulcs a WooCommerce-ben.',
									$index + 1
								),
							);
						}

						if ( ! empty( $rates ) && abs( $configured_percent - $vat_parsed ) > 0.01 ) {
							return array(
								'error' => sprintf(
									'A(z) %d. tétel áfakulcsa (%s%%) nem egyezik a WooCommerce-ben beállított adókulccsal (%s%%).',
									$index + 1,
									$vat_parsed,
									$configured_percent
								),
							);
						}
					}
				}
			}

			$resolved[] = array(
				'product'    => $product,
				'quantity'   => (int) Money::parse_quantity( $item['quantity'] ?? null ),
				'price'      => (float) Money::parse( $item['price'] ?? null ),
				'vat'        => (float) ( Money::parse_vat( $item['vat'] ?? null ) ?? 0.0 ),
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
	private function create_order( array $payload, array $validated, string $external_id, int $attempt = 0 ) {
		$customer = is_array( $payload['customer'] ?? null ) ? $payload['customer'] : array();

		$email_filters = $this->suppress_emails();
		$save_fence    = null;
		$finalized_id  = 0;
		$final_saving  = false;

		try {
			$order = new \WC_Order();
			// Never let woocommerce_default_order_status make a live shell look like a finished order.
			$order->set_status( 'pending' );
			$order->set_created_via( 'wps-pepita' );
			$order->set_currency( (string) $validated['currency'] );
			$order->update_meta_data( self::META_ORDER_ID, $external_id );
			$order->update_meta_data( self::META_PAYLOAD_HASH, (string) ( $payload['__hash'] ?? '' ) );
			$order->update_meta_data( '_wps_pepita_in_progress', '1' );

			try {
				$order_id = $order->save();
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'wps_order_failed', 'A kezdő rendelés mentése meghiúsult: ' . $e->getMessage() );
			}

			if ( ! $order_id ) {
				return new \WP_Error( 'wps_order_failed', 'A WooCommerce nem adott vissza rendelés azonosítót.' );
			}

			$associated = Order_Repository::associate( $external_id, (int) $order_id, $attempt );
			if ( ! $associated ) {
				return $this->discard_order( $order, 'wps_fenced_out', 'A rendelés zárolása lejárt, a feldolgozást más folyamat vette át.' );
			}

			// Save fence: WooCommerce fires this action inside save(), immediately before the data
			// store write. A worker whose lease was taken over while it was stalled must not write
			// to an order a newer attempt has already discarded (orphan rows, order hooks for a
			// non-existent order). WooCommerce 11 catches exceptions thrown here (it logs them, adds
			// an order note and still runs status_transition()), so the fence also discards the
			// pending status transition and raises $fenced, which the caller checks right after
			// every save. The remaining window is the single DB write.
			$fenced     = false;
			$save_fence = static function ( $saving ) use ( $order, $external_id, $attempt, &$fenced ): void {
				if ( $saving !== $order || ! empty( $fenced ) ) {
					if ( $saving === $order ) {
						throw new \RuntimeException( self::FENCED_OUT );
					}

					return;
				}

				if ( ! Order_Repository::is_attempt_current( $external_id, $attempt ) ) {
					$fenced = true;
					self::discard_status_transition( $saving );

					throw new \RuntimeException( self::FENCED_OUT );
				}
			};
			add_action( 'woocommerce_before_order_object_save', $save_fence, PHP_INT_MAX );

			$prices_include_tax = function_exists( 'wc_prices_include_tax' ) ? wc_prices_include_tax() : false;
			if ( method_exists( $order, 'set_prices_include_tax' ) ) {
				$order->set_prices_include_tax( $prices_include_tax );
			}

			$order->set_created_via( 'wps-pepita' );
			$order->set_currency( (string) $validated['currency'] );

			$this->apply_customer( $order, $customer );

			$email = (string) ( $customer['email'] ?? '' );
			$user  = '' !== $email ? get_user_by( 'email', $email ) : false;

			// Never attach a marketplace order to an account that can manage
			// the store: a leaked API key must not be able to plant order
			// data in an administrator's or shop manager's account.
			if ( $user instanceof \WP_User && ! $this->is_privileged_user( $user ) ) {
				$order->set_customer_id( (int) $user->ID );
			}

			$tax_enabled   = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
			$is_tax_exempt = (string) ( $customer['is_vat_exempt'] ?? '' ) === 'yes';

			$rate_cart_taxes     = array();
			$rate_shipping_taxes = array();
			$rate_objects        = array();
			$total_cart_tax      = 0.0;
			$total_shipping_tax  = 0.0;

			$country  = (string) ( $customer['shipping_country'] ?? $customer['billing_country'] ?? 'HU' );
			$state    = (string) ( $customer['shipping_state'] ?? $customer['billing_state'] ?? '' );
			$postcode = (string) ( $customer['shipping_postal_code'] ?? $customer['billing_postal_code'] ?? '' );
			$city     = (string) ( $customer['shipping_city'] ?? $customer['billing_city'] ?? '' );

			foreach ( $validated['products'] as $line ) {
				$product     = $line['product'];
				$quantity    = (int) $line['quantity'];
				$gross_price = (float) $line['price'];
				$gross_total = round( $gross_price * $quantity, 2 );

				$rates              = array();
				$configured_percent = 0.0;
				$tax_status         = method_exists( $product, 'get_tax_status' ) ? $product->get_tax_status() : 'taxable';

				if ( $tax_enabled && ! $is_tax_exempt && 'none' !== $tax_status ) {
					$tax_class = method_exists( $product, 'get_tax_class' ) ? $product->get_tax_class() : '';

					if ( class_exists( '\WC_Tax' ) ) {
						if ( method_exists( '\WC_Tax', 'find_rates' ) ) {
							$rates = \WC_Tax::find_rates(
								array(
									'country'   => $country ?: 'HU',
									'state'     => $state,
									'postcode'  => $postcode,
									'city'      => $city,
									'tax_class' => $tax_class,
								)
							);
						}

						if ( empty( $rates ) && method_exists( '\WC_Tax', 'get_base_tax_rates' ) ) {
							$rates = \WC_Tax::get_base_tax_rates( $tax_class );
						}

						if ( empty( $rates ) && method_exists( '\WC_Tax', 'get_rates' ) ) {
							$rates = \WC_Tax::get_rates( $tax_class );
						}
					}

					foreach ( $rates as $r_id => $r_data ) {
						$configured_percent += (float) ( $r_data['rate'] ?? 0.0 );
						$rate_objects[ $r_id ] = $r_data;
					}
				}

				if ( $configured_percent > 0.0 && ! empty( $rates ) ) {
					$tax_factor = $configured_percent / 100.0;
					$net_total  = round( $gross_total / ( 1.0 + $tax_factor ), 2 );
					$line_tax   = round( $gross_total - $net_total, 2 );

					$line_taxes = array();
					if ( 1 === count( $rates ) ) {
						$single_rate_id = (string) array_key_first( $rates );
						$line_taxes[ $single_rate_id ] = $line_tax;
						$rate_cart_taxes[ $single_rate_id ] = round( ( $rate_cart_taxes[ $single_rate_id ] ?? 0.0 ) + $line_tax, 2 );
					} elseif ( class_exists( '\WC_Tax' ) && method_exists( '\WC_Tax', 'calc_inclusive_tax' ) ) {
						$calc_taxes = \WC_Tax::calc_inclusive_tax( $gross_total, $rates );
						$line_tax   = round( array_sum( $calc_taxes ), 2 );
						$net_total  = round( $gross_total - $line_tax, 2 );
						foreach ( $calc_taxes as $c_id => $c_amount ) {
							$c_amount = round( (float) $c_amount, 2 );
							$line_taxes[ (string) $c_id ] = $c_amount;
							$rate_cart_taxes[ (string) $c_id ] = round( ( $rate_cart_taxes[ (string) $c_id ] ?? 0.0 ) + $c_amount, 2 );
						}
					} else {
						foreach ( $rates as $r_id => $r_data ) {
							$r_tax = round( $line_tax * ( (float) $r_data['rate'] / $configured_percent ), 2 );
							$line_taxes[ (string) $r_id ] = $r_tax;
							$rate_cart_taxes[ (string) $r_id ] = round( ( $rate_cart_taxes[ (string) $r_id ] ?? 0.0 ) + $r_tax, 2 );
						}
					}

					$total_cart_tax = round( $total_cart_tax + $line_tax, 2 );
				} else {
					$net_total  = $gross_total;
					$line_tax   = 0.0;
					$line_taxes = array();
				}

				$item_id = $order->add_product(
					$product,
					$quantity,
					array(
						'subtotal'     => $net_total,
						'total'        => $net_total,
						'subtotal_tax' => $line_tax,
						'total_tax'    => $line_tax,
						'name'         => $product->get_name(),
					)
				);

				if ( ! $item_id ) {
					return $this->discard_order( $order, 'wps_order_item_failed', 'A rendeléstétel hozzáadása sikertelen.' );
				}

				$item = $order->get_item( $item_id );

				if ( $item instanceof \WC_Order_Item_Product ) {
					$item->set_subtotal( $net_total );
					$item->set_total( $net_total );
					$item->set_subtotal_tax( $line_tax );
					$item->set_total_tax( $line_tax );

					if ( ! empty( $line_taxes ) ) {
						$item->set_taxes(
							array(
								'subtotal' => $line_taxes,
								'total'    => $line_taxes,
							)
						);
					} else {
						$item->set_taxes(
							array(
								'subtotal' => array(),
								'total'    => array(),
							)
						);
					}

					$item->add_meta_data( '_wps_pepita_product_id', (string) $line['pepita_id'], true );
					$item->add_meta_data( '_wps_pepita_sku', (string) $line['sku'], true );
					$item->add_meta_data( '_wps_pepita_vat', (string) $line['vat'], true );
					$item->add_meta_data( '_wps_pepita_gross_price', (string) $gross_price, true );
					$item->save();
				}
			}

			$shipping       = Mapper::shipping( (string) $validated['delivery_mode'] );
			$gross_shipping = (float) $validated['shipping'];

			if ( $gross_shipping > 0 ) {
				$shipping_item = new \WC_Order_Item_Shipping();
				$shipping_item->set_method_title( $shipping['title'] );
				$shipping_item->set_method_id( $shipping['id'] );

				$shipping_rates   = array();
				$shipping_percent = 0.0;

				if ( $tax_enabled && ! $is_tax_exempt && class_exists( '\WC_Tax' ) ) {
					if ( method_exists( '\WC_Tax', 'find_shipping_rates' ) ) {
						$shipping_rates = \WC_Tax::find_shipping_rates(
							array(
								'country'   => $country ?: 'HU',
								'state'     => $state,
								'postcode'  => $postcode,
								'city'      => $city,
							)
						);
					}

					foreach ( $shipping_rates as $sr_id => $sr_data ) {
						$shipping_percent += (float) ( $sr_data['rate'] ?? 0.0 );
						$rate_objects[ $sr_id ] = $sr_data;
					}
				}

				if ( $shipping_percent > 0.0 && ! empty( $shipping_rates ) ) {
					$s_factor     = $shipping_percent / 100.0;
					$net_shipping = round( $gross_shipping / ( 1.0 + $s_factor ), 2 );
					$shipping_tax = round( $gross_shipping - $net_shipping, 2 );

					$shipping_taxes = array();
					if ( 1 === count( $shipping_rates ) ) {
						$s_rate_id = (string) array_key_first( $shipping_rates );
						$shipping_taxes[ $s_rate_id ] = $shipping_tax;
						$rate_shipping_taxes[ $s_rate_id ] = round( ( $rate_shipping_taxes[ $s_rate_id ] ?? 0.0 ) + $shipping_tax, 2 );
					} elseif ( class_exists( '\WC_Tax' ) && method_exists( '\WC_Tax', 'calc_inclusive_tax' ) ) {
						$calc_s_taxes = \WC_Tax::calc_inclusive_tax( $gross_shipping, $shipping_rates );
						$shipping_tax = round( array_sum( $calc_s_taxes ), 2 );
						$net_shipping = round( $gross_shipping - $shipping_tax, 2 );
						foreach ( $calc_s_taxes as $cs_id => $cs_amount ) {
							$cs_amount = round( (float) $cs_amount, 2 );
							$shipping_taxes[ (string) $cs_id ] = $cs_amount;
							$rate_shipping_taxes[ (string) $cs_id ] = round( ( $rate_shipping_taxes[ (string) $cs_id ] ?? 0.0 ) + $cs_amount, 2 );
						}
					} else {
						foreach ( $shipping_rates as $sr_id => $sr_data ) {
							$sr_tax = round( $shipping_tax * ( (float) $sr_data['rate'] / $shipping_percent ), 2 );
							$shipping_taxes[ (string) $sr_id ] = $sr_tax;
							$rate_shipping_taxes[ (string) $sr_id ] = round( ( $rate_shipping_taxes[ (string) $sr_id ] ?? 0.0 ) + $sr_tax, 2 );
						}
					}

					$total_shipping_tax = $shipping_tax;
					$shipping_item->set_total( $net_shipping );
					$shipping_item->set_taxes( array( 'total' => $shipping_taxes ) );
				} else {
					$shipping_item->set_total( $gross_shipping );
					$shipping_item->set_taxes( array( 'total' => array() ) );
				}

				$order->add_item( $shipping_item );
			}

			if ( (float) $validated['voucher'] > 0 ) {
				$fee = new \WC_Order_Item_Fee();
				$fee->set_name( 'Pepita utalvány' );
				$fee->set_amount( -(float) $validated['voucher'] );
				$fee->set_total( -(float) $validated['voucher'] );
				$fee->set_tax_status( 'none' );
				$fee->set_taxes( array( 'total' => array() ) );
				$order->add_item( $fee );
			}

			// Add WC_Order_Item_Tax rows for all applied rates.
			$all_rate_ids = array_unique( array_merge( array_keys( $rate_cart_taxes ), array_keys( $rate_shipping_taxes ) ) );
			foreach ( $all_rate_ids as $r_id ) {
				$c_tax = $rate_cart_taxes[ $r_id ] ?? 0.0;
				$s_tax = $rate_shipping_taxes[ $r_id ] ?? 0.0;

				if ( $c_tax > 0 || $s_tax > 0 ) {
					$tax_item = new \WC_Order_Item_Tax();
					$tax_item->set_rate( is_numeric( $r_id ) ? (int) $r_id : $r_id );
					$tax_item->set_tax_total( $c_tax );
					$tax_item->set_shipping_tax_total( $s_tax );
					$tax_item->set_compound( false );

					$label = $rate_objects[ $r_id ]['label'] ?? '';
					if ( '' === $label && class_exists( '\WC_Tax' ) && method_exists( '\WC_Tax', 'get_rate_label' ) ) {
						$label = \WC_Tax::get_rate_label( $r_id );
					}
					$tax_item->set_label( '' !== $label ? $label : 'ÁFA' );

					if ( class_exists( '\WC_Tax' ) && method_exists( '\WC_Tax', 'get_rate_code' ) ) {
						$tax_item->set_rate_code( \WC_Tax::get_rate_code( $r_id ) );
					}

					$order->add_item( $tax_item );
				}
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
			// The in-progress marker and the imported-at stamp are set ONLY by the final,
			// lease-verified save below. calculate_totals() persists the order earlier; if
			// the marker were cleared here, a competing recovery worker would treat a
			// half-built order as complete and acknowledge it (RR20).
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

			// Synchronize calculated totals without recalculating taxes:
			// Pepita gross total is authoritative, and taxes have already been
			// correctly partitioned into net and tax rows.
			$order->calculate_totals( false );

			if ( $fenced ) {
				return $this->fenced_out( $order );
			}

			$save_error = $this->last_save_error( $order );
			if ( '' !== $save_error ) {
				return new \WP_Error( 'wps_order_save_failed', $save_error );
			}
			$order->set_cart_tax( $total_cart_tax );
			$order->set_shipping_tax( $total_shipping_tax );
			$order->set_total( (float) $validated['total'] );

			$paid   = 'paid' === (string) $validated['payment_status'];
			$status = $paid
				? (string) Settings::get( 'order_api_status_paid', 'processing' )
				: (string) Settings::get( 'order_api_status_unpaid', 'on-hold' );

			$status = $this->safe_status( $status );

			if ( $paid ) {
				$order->set_date_paid( $created > 0 ? $created : time() );
			}

			$this->apply_package_label( $order, $payload, $external_id );

			$order->set_status( $status, 'Pepita import' );

			// Re-verify lease before finalizing completion to prevent orphan rows if worker stalled
			if ( ! Order_Repository::is_attempt_current( $external_id, $attempt ) ) {
				return $this->discard_order( $order, 'wps_fenced_out', 'A rendelés zárolása lejárt a véglegesítés előtt, a feldolgozást más folyamat vette át.' );
			}

			// Remove in-progress marker and record completion timestamp
			$order->delete_meta_data( '_wps_pepita_in_progress' );
			$order->update_meta_data( self::META_IMPORTED_AT, current_time( 'mysql', true ) );

			$final_saving = true;
			$order_id     = $order->save();

			if ( $fenced ) {
				return $this->fenced_out( $order );
			}

			// WooCommerce >= 11.2.2 catches exceptions inside save() (logs them, still runs the
			// status transition) and returns the id. A swallowed failure must never be reported as
			// an imported order: leave the unfinalized marker order for the retry to rebuild.
			$save_error = $this->last_save_error( $order );
			if ( '' !== $save_error ) {
				return new \WP_Error( 'wps_order_save_failed', $save_error );
			}

			// From here on the order is finalized and persisted: nothing below may discard it.
			$finalized_id = (int) $order_id;

			if ( $order_id ) {
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
			}

			if ( ! $order_id ) {
				return $this->discard_order( $order, 'wps_order_save_failed', 'A rendelés mentése sikertelen.' );
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
		} catch ( \Throwable $throwable ) {
			// Fenced out inside save(): a newer attempt owns the claim and discards/rebuilds the
			// order. Touch nothing here (the order may already be gone).
			if ( $throwable instanceof \RuntimeException && self::FENCED_OUT === $throwable->getMessage() ) {
				return new \WP_Error( 'wps_fenced_out', 'A rendelés zárolása lejárt a mentés előtt, a feldolgozást más folyamat vette át.' );
			}

			// A finalized, persisted order is never discarded (it may already be acknowledged by a
			// takeover worker): log the late failure (e.g. in a note/log hook) and report success.
			if ( $finalized_id > 0 ) {
				Logger::error(
					'order_import',
					'Késői hiba a véglegesített rendelés mentése után; a rendelés megmaradt.',
					array(
						'external_id' => $external_id,
						'order_id'    => $finalized_id,
						'exception'   => $throwable->getMessage(),
					),
					'order',
					$finalized_id
				);

				return $finalized_id;
			}

			// Once the final save started the order may already be persisted and finalized (a hook
			// after the data-store write threw): never delete it here. The claim is failed by the
			// caller; the retry adopts a finalized order or rebuilds one that still has the marker.
			if ( $final_saving ) {
				Logger::error(
					'order_import',
					'Hiba a rendelés végső mentése közben vagy után; a rendelést nem töröljük, az újrapróbálkozás rendezi.',
					array(
						'external_id' => $external_id,
						'exception'   => $throwable->getMessage(),
					),
					'order',
					(int) ( isset( $order ) && $order instanceof \WC_Order ? $order->get_id() : 0 )
				);

				return new \WP_Error( 'wps_order_save_failed', 'A rendelés végső mentése sikertelen.' );
			}

			// Never leave a half-built pending order behind: the retry would
			// create a second one.
			if ( isset( $order ) && $order instanceof \WC_Order ) {
				$this->discard_order( $order );
			}

			throw $throwable;
		} finally {
			if ( null !== $save_fence ) {
				remove_action( 'woocommerce_before_order_object_save', $save_fence, PHP_INT_MAX );
			}

			$this->restore_emails( $email_filters );
		}
	}

	/**
	 * A status transition may already have reduced stock for an order that is about to be discarded
	 * (e.g. after a swallowed save failure); give it back so the rebuilt order does not reduce twice.
	 * wc_maybe_increase_stock_levels() only acts when WooCommerce flagged the stock as reduced.
	 */
	private function restore_stock_before_delete( \WC_Order $order ): void {
		if ( $order->get_id() > 0 && function_exists( 'wc_maybe_increase_stock_levels' ) ) {
			try {
				wc_maybe_increase_stock_levels( $order->get_id() );
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}
		}
	}

	/**
	 * Error of the last WooCommerce save() ('' when it persisted the order or the API is absent).
	 */
	private function last_save_error( \WC_Order $order ): string {
		if ( ! method_exists( $order, 'get_last_save_error' ) ) {
			return '';
		}

		$error = $order->get_last_save_error();

		return $error instanceof \Throwable ? 'A rendelés mentése sikertelen: ' . $error->getMessage() : '';
	}

	/**
	 * Abandon the order of a worker that lost its lease: a newer attempt owns the claim and
	 * discards/rebuilds the order. The order itself is never deleted or notified here; only the
	 * fence's own error note that WooCommerce attaches to it is removed.
	 */
	private function fenced_out( \WC_Order $order ): \WP_Error {
		$order_id = (int) $order->get_id();

		if ( $order_id > 0 && function_exists( 'wp_delete_comment' ) ) {
			global $wpdb;

			// WooCommerce 11 records the swallowed fence exception as an order note on the (already
			// discarded) order. Remove exactly those error notes: they are selected by their id and
			// message, directly, because WooCommerce excludes order notes from comment queries, its
			// note lookup is store-dependent and wc_get_order() may still answer from its cache.
			$note_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type = 'order_note' AND comment_content LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$order_id,
					'%' . $wpdb->esc_like( self::FENCED_OUT ) . '%'
				)
			);

			foreach ( (array) $note_ids as $note_id ) {
				wp_delete_comment( (int) $note_id, true );
			}
		}

		return new \WP_Error( 'wps_fenced_out', 'A rendelés zárolása lejárt a mentés előtt, a feldolgozást más folyamat vette át.' );
	}

	/**
	 * Drop the pending status transition so that a fenced-out save fires no status hooks.
	 */
	private static function discard_status_transition( \WC_Order $order ): void {
		if ( ! property_exists( \WC_Order::class, 'status_transition' ) ) {
			// WooCommerce renamed the property: fail loudly rather than silently firing status hooks.
			Logger::error( 'order_recovery', 'A WooCommerce státuszváltás-tulajdonság nem található; a zárolt mentés státuszhorgai nem tilthatók le.', array( 'order_id' => (int) $order->get_id() ) );

			return;
		}

		try {
			\Closure::bind(
				static function ( $target ): void {
					$target->status_transition = false;
				},
				null,
				\WC_Order::class
			)( $order );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}

	/**
	 * Delete a partially built order and return the error for the caller.
	 */
	private function discard_order( \WC_Order $order, string $code = '', string $message = '' ): \WP_Error {
		if ( $order->get_id() > 0 ) {
			$this->restore_stock_before_delete( $order );
			$order->delete( true );
		}

		return new \WP_Error( '' !== $code ? $code : 'wps_order_discarded', $message );
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

		// Only accept a real PDF: the extension alone is not a format check.
		if ( ! str_starts_with( $binary, '%PDF-' ) ) {
			Logger::warning(
				'order_import',
				'A csomagcímke nem PDF formátumú, a fájl nem került mentésre.',
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
				'limit'         => 1,
				'return'        => 'ids',
				// A persistent object cache must never serve a stale negative
				// result here: that would create a duplicate order.
				'cache_results' => false,
				'meta_key'      => self::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'    => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'status'        => 'any',
			)
		);

		if ( is_array( $orders ) && isset( $orders[0] ) ) {
			// The meta_value column collation may be case-insensitive, so verify
			// the identifier exactly before treating the order as the same one.
			$order = wc_get_order( (int) $orders[0] );

			if ( $order instanceof \WC_Order ) {
				$stored = (string) $order->get_meta( self::META_ORDER_ID );

				if ( '' === $stored || hash_equals( $stored, $external_id ) ) {
					return (int) $orders[0];
				}
			}
		}

		// Fallback: check if the claim row already associates an order ID
		// (e.g. if the process died before metadata was indexed or if object cache missed).
		$claim = Order_Repository::get( $external_id );
		$claimed_order_id = (int) ( $claim['wc_order_id'] ?? 0 );
		if ( $claimed_order_id > 0 ) {
			$order = wc_get_order( $claimed_order_id );

			if ( $order instanceof \WC_Order ) {
				$stored = (string) $order->get_meta( self::META_ORDER_ID );

				if ( '' === $stored || hash_equals( $stored, $external_id ) ) {
					return $claimed_order_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Why a completed claim's order cannot be acknowledged ('' when it can).
	 */
	private function completed_order_problem( int $order_id ): string {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return 'hiányzik';
		}

		// Same predicate as the recovery path (is_order_incomplete): a marker order in an
		// operator-final status is adopted (and finalized, see handle_existing_order), anything else
		// with the marker is unfinished.
		if ( $this->is_order_incomplete( $order ) ) {
			return 'nincs véglegesítve';
		}

		return '';
	}

	/**
	 * Check whether an existing order was left unfinished by an interrupted import.
	 *
	 * The `_wps_pepita_in_progress` marker is set when the order shell is created and is removed
	 * ONLY by the final, lease-verified save in create_order(). Therefore marker === '1' means the
	 * import never finished, regardless of line items (an order that already has items but still
	 * carries the marker has an unfinalized total/status and must never be acknowledged or adopted).
	 * Orders an operator already moved to a final status are never treated as incomplete.
	 */
	private function is_order_incomplete( \WC_Order $order ): bool {
		if ( '1' !== (string) $order->get_meta( '_wps_pepita_in_progress' ) ) {
			return false;
		}

		// Marker present = never finalized. The statuses the importer itself assigns to a finished
		// order (configured paid / unpaid status) prove nothing: "own status + marker" is exactly what
		// an interrupted or in-flight final save leaves behind, so it is unfinished. Only a status the
		// importer never assigns (completed, processing, cancelled, refunded, when not its own) is
		// evidence that an operator handled the order by hand.
		$status = str_replace( 'wc-', '', $order->get_status() );
		$own    = array(
			$this->safe_status( (string) Settings::get( 'order_api_status_paid', 'processing' ) ),
			$this->safe_status( (string) Settings::get( 'order_api_status_unpaid', 'on-hold' ) ),
		);

		if ( in_array( $status, $own, true ) ) {
			return true;
		}

		return ! in_array( $status, array( 'completed', 'processing', 'cancelled', 'refunded' ), true );
	}

	/**
	 * Safely delete an incomplete order shell, quarantining it if deletion fails.
	 *
	 * @param \WC_Order $order
	 * @param string    $external_id
	 * @param int       $attempt
	 * @return bool True if deleted, false if quarantined on failure.
	 */
	private function safe_cleanup_incomplete_order( \WC_Order $order, string $external_id, int $attempt ): bool {
		$order_id = $order->get_id();

		try {
			$this->restore_stock_before_delete( $order );
			$deleted = $order->delete( true );
			if ( $deleted ) {
				Logger::warning(
					'order_recovery',
					'Félbehagyott Pepita rendelés észlelve korábbi összeomlás után; a csonka rendelést töröltük és újra létrehozzuk.',
					array(
						'external_id' => $external_id,
						'orphan_id'   => $order_id,
					)
				);

				return true;
			}
		} catch ( \Throwable $e ) {
			Logger::error(
				'order_recovery',
				'A félbehagyott csonka rendelés törlése sikertelen volt (kivétel történt), karanténba helyezve: ' . $e->getMessage(),
				array(
					'external_id' => $external_id,
					'orphan_id'   => $order_id,
					'error'       => $e->getMessage(),
				)
			);
		}

		// Quarantine the orphaned order
		try {
			$order->update_meta_data( '_wps_pepita_quarantined_from', sprintf( 'external_id=%s;attempt=%d', $external_id, $attempt ) );
			$order->delete_meta_data( self::META_ORDER_ID );
			$order->delete_meta_data( '_wps_pepita_in_progress' );
			$order->set_status( 'failed', sprintf( 'Pepita csonka rendelés karanténba helyezve (kísérlet: %d, Pepita ID: %s) sikertelen törlés miatt.', $attempt, $external_id ) );
			$order->save();

			return true;
		} catch ( \Throwable $q_err ) {
			Logger::error(
				'order_recovery',
				'A csonka rendelés karanténba helyezése sem sikerült: ' . $q_err->getMessage(),
				array(
					'external_id' => $external_id,
					'orphan_id'   => $order_id,
					'error'       => $q_err->getMessage(),
				)
			);
		}

		return false;
	}

	/**
	 * Calculate total payable amount directly from an incoming payload.
	 *
	 * @param array<string, mixed> $payload
	 * @return float|null
	 */
	private function calculate_payload_total( array $payload ): ?float {
		if ( ! isset( $payload['products'] ) || ! is_array( $payload['products'] ) ) {
			return null;
		}

		$total = 0.0;
		foreach ( $payload['products'] as $item ) {
			$price = Money::parse( $item['price'] ?? null );
			$qty   = Money::parse_quantity( $item['quantity'] ?? null );
			if ( null === $price || null === $qty ) {
				return null;
			}
			$total += round( $price * $qty, 2 );
		}

		$shipping = Money::parse( $payload['total_shipping_price'] ?? 0 );
		if ( null !== $shipping && $shipping > 0 ) {
			$total += $shipping;
		}

		$voucher = Money::parse( $payload['voucher'] ?? 0 );
		if ( null !== $voucher && $voucher > 0 ) {
			$total -= $voucher;
		}

		return round( $total, 2 );
	}

	/**
	 * Whether an account can administer the store. Such accounts are never
	 * linked as the customer of an imported marketplace order.
	 */
	private function is_privileged_user( \WP_User $user ): bool {
		if ( function_exists( 'user_can' ) ) {
			return user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'edit_users' );
		}

		return (bool) array_intersect( (array) $user->roles, array( 'administrator', 'shop_manager' ) );
	}

	/**
	 * Canonicalize payload recursively for deterministic hash identity.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function canonicalize( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$is_assoc = array_keys( $data ) !== range( 0, count( $data ) - 1 );
		if ( $is_assoc ) {
			ksort( $data );
		}

		foreach ( $data as $key => $value ) {
			$data[ $key ] = self::canonicalize( $value );
		}

		return $data;
	}

	/**
	 * Compute deterministic SHA-256 hash for an order payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return string
	 */
	public static function hash_payload( array $payload ): string {
		$canonical = self::canonicalize( $payload );
		$json      = wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	/**
	 * Handle duplicate order notifications idempotently or refuse conflicting payloads.
	 *
	 * @param string               $external_id
	 * @param int                  $order_id
	 * @param string               $stored_hash
	 * @param string               $payload_hash
	 * @param array<string, mixed> $payload
	 * @param bool                 $reclaimed Whether this came from the crash-recovery fallback.
	 * @return array<string, mixed>
	 */
	private function handle_existing_order( string $external_id, int $order_id, string $stored_hash, string $payload_hash, array $payload, bool $reclaimed = false, int $attempt = 0 ): array {
		$existing_order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		// Adopting a marker order that an operator already moved to a status the importer never
		// assigns: finalize it consistently (clear the marker, stamp imported_at) instead of
		// acknowledging an order that would keep failing the completed-claim gate on every replay.
		if ( $existing_order instanceof \WC_Order && '1' === (string) $existing_order->get_meta( '_wps_pepita_in_progress' ) ) {
			try {
				$existing_order->delete_meta_data( '_wps_pepita_in_progress' );

				if ( '' === (string) $existing_order->get_meta( self::META_IMPORTED_AT ) ) {
					$existing_order->update_meta_data( self::META_IMPORTED_AT, current_time( 'mysql', true ) );
				}

				$existing_order->add_order_note( 'A félbehagyott Pepita import jelölője eltávolítva: a rendelés kézzel végleges állapotba került. Ellenőrizze a készletet és a végösszeget.' );
				$existing_order->save();

				Logger::warning(
					'order_recovery',
					'Félbehagyott (jelölt) rendelés átvéve és véglegesítve, mert kézzel végleges állapotba került.',
					array(
						'external_id' => $external_id,
						'order_id'    => $order_id,
					),
					'order',
					$order_id
				);
			} catch ( \Throwable $adopt_error ) {
				Logger::error(
					'order_recovery',
					'A jelölt rendelés véglegesítése sikertelen: ' . $adopt_error->getMessage(),
					array(
						'external_id' => $external_id,
						'order_id'    => $order_id,
					),
					'order',
					$order_id
				);

				return $this->result( 'retry', 409, 'A rendelés véglegesítése átmenetileg sikertelen. Kérjük, próbálja újra később.' );
			}
		}

		// A retry that adopts an importer-created order whose final save was cut off after the data
		// write may have missed the status transition (stock reduction). The flag makes it idempotent.
		if ( $reclaimed && $existing_order instanceof \WC_Order && function_exists( 'wc_maybe_reduce_stock_levels' )
			&& in_array( str_replace( 'wc-', '', $existing_order->get_status() ), array( 'processing', 'on-hold', 'completed' ), true ) ) {
			try {
				wc_maybe_reduce_stock_levels( $order_id );
			} catch ( \Throwable $stock_error ) {
				Logger::warning( 'order_recovery', 'A készletcsökkentés ellenőrzése sikertelen: ' . $stock_error->getMessage(), array( 'order_id' => $order_id ), 'order', $order_id );
			}
		}

		if ( '' === $stored_hash && $existing_order instanceof \WC_Order ) {
			$stored_hash = (string) $existing_order->get_meta( self::META_PAYLOAD_HASH );
		}

		// Replay handling for historical orders without a stored payload hash (e.g. pre-1.1.0).
		// We verify structural and financial invariants to refuse altered data fail-closed.
		if ( '' === $stored_hash && $existing_order instanceof \WC_Order ) {
			$conflict = false;
			$reasons  = array();

			// 1. Validate payload structure first: must be syntactically and semantically valid.
			$validation = $this->validate( $payload );
			if ( isset( $validation['error'] ) ) {
				$conflict  = true;
				$reasons[] = 'érvénytelen payload struktúra: ' . $validation['error'];
			} else {
				// 2. Strict currency match
				$order_currency   = (string) $existing_order->get_currency();
				$payload_currency = (string) ( $validation['currency'] ?? '' );
				if ( '' === $order_currency || '' === $payload_currency || 0 !== strcasecmp( $order_currency, $payload_currency ) ) {
					$conflict  = true;
					$reasons[] = sprintf( 'eltérő pénznem (tárolt: %s, új: %s)', $order_currency, $payload_currency );
				}

				// 3. Strict grand total match (zero tolerance for integer currencies like HUF, 0.001 for decimals)
				$order_total   = (float) $existing_order->get_total();
				$payload_total = (float) ( $validation['total'] ?? 0 );
				if ( abs( $order_total - $payload_total ) > 0.001 ) {
					$conflict  = true;
					$reasons[] = sprintf( 'eltérő végösszeg (tárolt: %s, új: %s)', $order_total, $payload_total );
				}

				// 4. Strict customer email match (if order has billing email)
				$order_email   = strtolower( trim( (string) $existing_order->get_billing_email() ) );
				$payload_email = strtolower( trim( (string) ( $validation['customer']['email'] ?? '' ) ) );
				if ( '' !== $order_email && '' !== $payload_email && $order_email !== $payload_email ) {
					$conflict  = true;
					$reasons[] = sprintf( 'eltérő vásárlói email (tárolt: %s, új: %s)', Redactor::mask_email( $order_email ), Redactor::mask_email( $payload_email ) );
				}

				// 5. Strict line items count & contents match
				$existing_items     = $existing_order->get_items( 'line_item' );
				$order_item_count   = count( $existing_items );
				$payload_products   = isset( $validation['products'] ) && is_array( $validation['products'] ) ? $validation['products'] : array();
				$payload_item_count = count( $payload_products );

				if ( 0 === $payload_item_count || $order_item_count !== $payload_item_count ) {
					$conflict  = true;
					$reasons[] = sprintf( 'eltérő tételszám (tárolt: %d, új: %d)', $order_item_count, $payload_item_count );
				} else {
					$order_skus = array();
					foreach ( $existing_items as $item ) {
						if ( $item instanceof \WC_Order_Item_Product ) {
							$product = $item->get_product();
							$sku     = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
							$order_skus[] = array(
								'sku' => $sku,
								'qty' => (int) $item->get_quantity(),
							);
						}
					}

					foreach ( $payload_products as $p ) {
						$p_sku   = (string) ( $p['sku'] ?? '' );
						$p_qty   = (int) ( $p['quantity'] ?? 0 );
						$matched = false;
						foreach ( $order_skus as $idx => $os ) {
							if ( '' !== $p_sku && 0 === strcasecmp( $os['sku'], $p_sku ) && $os['qty'] === $p_qty ) {
								$matched = true;
								unset( $order_skus[ $idx ] );
								break;
							}
						}

						if ( ! $matched ) {
							$conflict  = true;
							$reasons[] = sprintf( 'tétel nem egyezik (SKU: %s, db: %d)', $p_sku, $p_qty );
							break;
						}
					}
				}
			}

			if ( $conflict ) {
				$existing_order->add_order_note(
					sprintf(
						'Figyelem: Ismételt Pepita rendelésértesítés érkezett korábbi (hash nélküli) rendeléshez eltérő tartalommal (%s). A meglévő rendelést a rendszer nem módosította a rendelés integritásának védelmében (NEW-2). Új hash: %s, státusz: %s, fizetési státusz: %s.',
						implode( ', ', $reasons ),
						substr( $payload_hash, 0, 12 ),
						(string) ( $payload['status'] ?? 'n/a' ),
						(string) ( $payload['payment_status'] ?? 'n/a' )
					)
				);

				Logger::warning(
					'order_duplicate',
					'Ismételt Pepita rendelésértesítés korábbi rendeléshez eltérő tartalommal: a rendelést nem módosítottuk.',
					array(
						'external_id'           => $external_id,
						'order_id'              => $order_id,
						'reasons'               => $reasons,
						'new_hash'              => substr( $payload_hash, 0, 16 ),
						'reconciliation_event'  => 'changed_payload_refused',
						'pepita_status'         => (string) ( $payload['status'] ?? '' ),
						'pepita_payment_status' => (string) ( $payload['payment_status'] ?? '' ),
						'redacted_new_payload'  => Redactor::redact( $payload ),
					),
					'order',
					$order_id
				);

				if ( $reclaimed ) {
					Order_Repository::fail( $external_id, 'Conflicting replay rejected: ' . implode( ', ', $reasons ), $attempt );
				}

				return $this->result( 'conflict', 409, 'A rendelés már korábban importálásra került, de az új értesítés tartalma eltér. Kérjük, ellenőrizze a rendelést a WooCommerce-ben.' );
			}

			// Invariants match: securely backfill the hash for future verification
			$existing_order->update_meta_data( self::META_PAYLOAD_HASH, $payload_hash );
			if ( method_exists( $existing_order, 'save_meta_data' ) ) {
				$existing_order->save_meta_data();
			} else {
				$existing_order->save();
			}
			Order_Repository::backfill_hash( $external_id, $payload_hash );
			$stored_hash = $payload_hash;

			$existing_order->add_order_note(
				sprintf(
					'Meglévő korábbi Pepita rendelés megerősítve és hash rögzítve (hash: %s).',
					substr( $payload_hash, 0, 12 )
				)
			);
		}

		if ( '' !== $stored_hash && ! hash_equals( $stored_hash, $payload_hash ) ) {
			if ( $existing_order instanceof \WC_Order ) {
				$existing_order->add_order_note(
					sprintf(
						'Figyelem: Ismételt Pepita rendelésértesítés érkezett eltérő tartalommal (új hash: %s). A meglévő rendelést a rendszer nem módosította a rendelés integritásának védelmében (NEW-2). Új Pepita státusz: %s, fizetési státusz: %s.',
						substr( $payload_hash, 0, 12 ),
						(string) ( $payload['status'] ?? 'n/a' ),
						(string) ( $payload['payment_status'] ?? 'n/a' )
					)
				);
			}

			Logger::warning(
				'order_duplicate',
				'Ismételt Pepita rendelésértesítés eltérő tartalommal: a meglévő rendelést nem módosítottuk.',
				array(
					'external_id'           => $external_id,
					'order_id'              => $order_id,
					'stored_hash'           => substr( $stored_hash, 0, 16 ),
					'new_hash'              => substr( $payload_hash, 0, 16 ),
					'reconciliation_event'  => 'changed_payload_refused',
					'pepita_status'         => (string) ( $payload['status'] ?? '' ),
					'pepita_payment_status' => (string) ( $payload['payment_status'] ?? '' ),
					'redacted_new_payload'  => Redactor::redact( $payload ),
				),
				'order',
				$order_id
			);

			if ( $reclaimed ) {
				Order_Repository::fail( $external_id, 'Conflicting replay rejected: hash mismatch', $attempt );
			}

			return $this->result( 'conflict', 409, 'A rendelés már korábban importálásra került, de az új értesítés tartalma eltér. Kérjük, ellenőrizze a rendelést a WooCommerce-ben.' );
		}

		// Replay is valid: complete claim if reclaimed, and sync claim hash
		if ( $order_id > 0 && $reclaimed ) {
			$completed = Order_Repository::complete( $external_id, $order_id, $payload_hash, $attempt );
			if ( ! $completed ) {
				return $this->result( 'retry', 409, 'A rendelés feldolgozási zárolása lejárt, a feldolgozást egy újabb kísérlet vette át.' );
			}
		}

		return $this->result(
			'duplicate',
			200,
			'A rendelés (Pepita azonosító: ' . $external_id . ') már korábban importálásra került WooCommerce rendelésként (azonosító: #' . $order_id . '). Újabb rendelés nem jött létre.',
			$order_id
		);
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
