<?php
/**
 * Merchant Configuration Profiles.
 *
 * Facilitates multi-store deployments and backup/restore by exporting and
 * importing versioned configuration profiles.
 *
 * Strict security invariants:
 * - NEVER exports API keys, secret tokens, storage keys, webhook URLs, or merchant emails.
 * - Validates schema version and structure fail-closed.
 * - Enforces strict allowlist of portable settings (PORTABLE_SETTINGS_ALLOWLIST).
 * - Matches category rules by category slug to support multi-store ID mapping.
 * - Provides non-destructive preview before persistent changes.
 * - Guarantees atomicity via compensating rollback on any partial failure.
 * - Verifies payload integrity via SHA-256 checksum (distinguished from authentication).
 *
 * @package WPS\Pepita\Admin
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Logger;
use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Merchant_Profiles {

	public const SCHEMA_VERSION = 1;

	/**
	 * Settings that are explicitly permitted in configuration exports and imports.
	 *
	 * Strict security invariant:
	 * Only portable, operational merchant configuration keys are allowlisted.
	 * Secrets (feed_token, order_api_key, storage_key, alert webhooks/emails, IP restrictions)
	 * and security posture toggles (feed_public, order_api_require_https, order_api_enabled,
	 * order_api_keep_payloads, delete_data_on_uninstall, feed_emergency_disabled, beta_feed_only)
	 * are strictly excluded from portability.
	 *
	 * @var string[]
	 */
	public const PORTABLE_SETTINGS_ALLOWLIST = array(
		// Catalog selection.
		'default_included',
		'include_child_categories',
		'allowed_product_statuses',
		'exclude_quality_issues',
		'show_internal_quality_score',

		// Pricing and tax.
		'currency',
		'vat_mode',
		'vat_fixed',
		'price_tax_mode',
		'price_adjustment_type',
		'price_adjustment_value',
		'rounding',
		'include_purchase_net',

		// Shipping and warranty.
		'shipping_delay',
		'shipping_price',
		'warranty_type',
		'warranty_duration',

		// Content mapping.
		'description_source',
		'brand_source',
		'brand_meta_key',
		'brand_taxonomy',
		'manufacturer_default',
		'attribute_name_map',
		'gtin_meta_keys',
		'gtin_required_categories',
		'primary_category_strategy',
		'max_category_paths',
		'max_gallery_images',

		// Feed operational schedule and thresholds.
		'batch_size',
		'batches_per_run',
		'max_runtime',
		'full_feed_interval',
		'stock_feed_interval',
		'stock_requires_full',
		'removal_policy',
		'removal_grace_days',
		'feed_cache_max_age',
		'feed_full_slug',
		'feed_stock_slug',
		'feed_safety_drop_threshold',
		'feed_safety_block_publication',
		'feed_safety_variation_change_warning',
		'feed_safety_require_approval',
		'feed_safety_max_price_drop_percent',
		'feed_safety_max_price_spikes',
		'feed_safety_max_quality_exclusions',
		'offpeak_schedule_enabled',
		'offpeak_start_hour',
		'offpeak_end_hour',

		// Marketplace Inventory & Pricing Strategy (M9-H).
		'inventory_strategy_enabled',
		'inventory_buffer',
		'inventory_max_qty',
		'pricing_strategy_enabled',
		'marketplace_commission_percent',
		'marketplace_commission_fixed',
		'marketplace_packaging_cost',
		'marketplace_shipping_cost',
		'marketplace_min_margin_percent',

		// Safe Order mappings & status settings.
		'order_api_status_paid',
		'order_api_status_unpaid',
		'order_api_save_label',
		'order_api_label_retention_days',
		'payment_map',
		'shipping_map',

		// Logging & metadata.
		'log_retention_days',
		'log_level',
		'record_export_meta',
	);

	/**
	 * Keys that are strictly forbidden in configuration exports.
	 *
	 * @var string[]
	 */
	public const SECRET_KEYS = array(
		'feed_token',
		'order_api_key',
		'storage_key',
		'feed_alert_webhook_url',
		'feed_alert_email',
		'order_api_allowed_ips',
		'order_api_trusted_proxies',
	);

	/**
	 * Export the current store configuration as a portable, secret-free profile array.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public static function export_profile( array $options = array() ): array {
		$all_settings = Settings::all();

		// Export only allowlisted portable settings.
		$sanitized_settings = array();
		foreach ( self::PORTABLE_SETTINGS_ALLOWLIST as $key ) {
			if ( array_key_exists( $key, $all_settings ) ) {
				$sanitized_settings[ $key ] = $all_settings[ $key ];
			}
		}

		// Export category rules by term slug for multi-store portability.
		$category_rules = array();
		if ( taxonomy_exists( Category_Rules::TAXONOMY ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => Category_Rules::TAXONOMY,
					'hide_empty' => false,
				)
			);

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}

					$rules = array();
					$meta_map = array(
						'include'          => Category_Rules::META_INCLUDE,
						'include_children' => Category_Rules::META_INCLUDE_CHILDREN,
						'gtin_required'    => Category_Rules::META_GTIN_REQUIRED,
						'price_type'       => Category_Rules::META_PRICE_TYPE,
						'price_value'      => Category_Rules::META_PRICE_VALUE,
						'shipping_delay'   => Category_Rules::META_SHIPPING_DELAY,
					);

					foreach ( $meta_map as $rule_key => $meta_key ) {
						$val = get_term_meta( $term->term_id, $meta_key, true );
						if ( '' !== $val && false !== $val ) {
							$rules[ $rule_key ] = $val;
						}
					}

					if ( ! empty( $rules ) ) {
						$category_rules[ $term->slug ] = array(
							'name'  => $term->name,
							'rules' => $rules,
						);
					}
				}
			}
		}

		$profile_body = array(
			'schema_version' => self::SCHEMA_VERSION,
			'generator'      => 'wp-pepita-sync',
			'plugin_version' => defined( 'WPS_PEPITA_VERSION' ) ? WPS_PEPITA_VERSION : '1.0.0',
			'profile_name'   => sanitize_text_field( (string) ( $options['name'] ?? 'Pepita Sync Profil' ) ),
			'exported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'settings'       => $sanitized_settings,
			'category_rules' => $category_rules,
		);

		$canonical_json = wp_json_encode( $profile_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$profile_body['checksum'] = hash( 'sha256', is_string( $canonical_json ) ? $canonical_json : '' );

		return $profile_body;
	}

	/**
	 * Verify profile payload checksum for data integrity.
	 *
	 * Note: Checksum provides integrity validation against accidental corruption
	 * or file truncation; it does NOT provide cryptographic authentication/provenance.
	 *
	 * @param array<string, mixed> $data
	 * @return bool|null True if matching, false if mismatched, null if checksum omitted.
	 */
	public static function verify_checksum( array $data ): ?bool {
		if ( ! isset( $data['checksum'] ) || ! is_string( $data['checksum'] ) || '' === trim( $data['checksum'] ) ) {
			return null;
		}

		$expected = trim( $data['checksum'] );
		$copy     = $data;
		unset( $copy['checksum'] );

		$canonical_json = wp_json_encode( $copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $canonical_json ) ) {
			return false;
		}

		$calculated = hash( 'sha256', $canonical_json );

		return hash_equals( $expected, $calculated );
	}

	/**
	 * Validate an imported profile structure.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $options
	 * @return array{valid: bool, errors: string[], warnings: string[]}
	 */
	public static function validate_profile( array $data, array $options = array() ): array {
		$errors   = array();
		$warnings = array();

		// Strict type and value check for schema_version (must be integer 1, no loose casting).
		if ( ! isset( $data['schema_version'] ) || ! is_int( $data['schema_version'] ) || self::SCHEMA_VERSION !== $data['schema_version'] ) {
			$errors[] = sprintf(
				'Nem támogatott sémaverzió: %s (elvárt: egész szám %d).',
				isset( $data['schema_version'] ) ? (string) $data['schema_version'] : 'hiányzó',
				self::SCHEMA_VERSION
			);
		}

		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			$errors[] = 'A profil nem tartalmaz érvényes beállításokat (settings).';
		} else {
			// Ensure no secrets or unallowlisted settings weaken store security posture.
			foreach ( $data['settings'] as $key => $val ) {
				if ( in_array( $key, self::SECRET_KEYS, true ) ) {
					$warnings[] = sprintf( 'A profil érzékeny kulcsot (%s) tartalmaz, amely biztonsági okokból figyelmen kívül lesz hagyva.', $key );
				} elseif ( ! in_array( $key, self::PORTABLE_SETTINGS_ALLOWLIST, true ) ) {
					$warnings[] = sprintf( 'A profil biztonsági vagy nem hordozható beállítást (%s) tartalmaz, amely figyelmen kívül lesz hagyva.', $key );
				}
			}
		}

		if ( isset( $data['category_rules'] ) && ! is_array( $data['category_rules'] ) ) {
			$errors[] = 'A kategóriaszabályok formátuma érvénytelen.';
		}

		// Integrity check (checksum).
		if ( isset( $data['checksum'] ) ) {
			$checksum_valid = self::verify_checksum( $data );
			if ( false === $checksum_valid ) {
				$msg = 'A profil integritás-ellenőrző összege (checksum) nem egyezik. A profilfájl sérült vagy módosult az exportálás óta.';
				if ( ! empty( $options['require_checksum'] ) ) {
					$errors[] = $msg;
				} else {
					$warnings[] = $msg;
				}
			}
		} elseif ( ! empty( $options['require_checksum'] ) ) {
			$errors[] = 'Hiányzik az integritás-ellenőrző összeg (checksum) a profilból.';
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Generate a side-by-side diff preview between current store settings/rules
	 * and the proposed profile data.
	 *
	 * Does NOT modify persistent state.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public static function preview_import( array $data, array $options = array() ): array {
		$validation = self::validate_profile( $data, $options );
		if ( ! $validation['valid'] ) {
			return array(
				'valid'          => false,
				'errors'         => $validation['errors'],
				'warnings'       => $validation['warnings'],
				'settings_diff'  => array(),
				'category_diff'  => array(),
				'checksum_valid' => false,
			);
		}

		$current_settings = Settings::all();
		$import_settings  = (array) ( $data['settings'] ?? array() );

		// 1. Settings diff (only allowlisted portable keys).
		$settings_diff = array();
		foreach ( self::PORTABLE_SETTINGS_ALLOWLIST as $key ) {
			if ( array_key_exists( $key, $import_settings ) ) {
				$cur_val = $current_settings[ $key ] ?? null;
				$new_val = $import_settings[ $key ];

				// Compare values.
				if ( $cur_val !== $new_val ) {
					$settings_diff[ $key ] = array(
						'key'      => $key,
						'current'  => $cur_val,
						'proposed' => $new_val,
					);
				}
			}
		}

		// 2. Category rules diff by slug.
		$category_diff = array();
		$import_cats   = (bool) ( $options['import_category_rules'] ?? true );

		if ( $import_cats && isset( $data['category_rules'] ) && is_array( $data['category_rules'] ) ) {
			$meta_map = array(
				'include'          => Category_Rules::META_INCLUDE,
				'include_children' => Category_Rules::META_INCLUDE_CHILDREN,
				'gtin_required'    => Category_Rules::META_GTIN_REQUIRED,
				'price_type'       => Category_Rules::META_PRICE_TYPE,
				'price_value'      => Category_Rules::META_PRICE_VALUE,
				'shipping_delay'   => Category_Rules::META_SHIPPING_DELAY,
			);

			foreach ( $data['category_rules'] as $slug => $cat_data ) {
				$slug_str = (string) $slug;
				$term     = get_term_by( 'slug', $slug_str, Category_Rules::TAXONOMY );

				if ( ! $term instanceof \WP_Term ) {
					$category_diff[ $slug_str ] = array(
						'slug'        => $slug_str,
						'name'        => (string) ( $cat_data['name'] ?? $slug_str ),
						'matched'     => false,
						'rule_diffs'  => array(),
						'status_note' => 'A kategória nem létezik ebben az áruházban (figyelmen kívül lesz hagyva).',
					);
					continue;
				}

				$proposed_rules = isset( $cat_data['rules'] ) && is_array( $cat_data['rules'] ) ? $cat_data['rules'] : array();
				$rule_diffs     = array();

				foreach ( $meta_map as $rule_key => $meta_key ) {
					if ( array_key_exists( $rule_key, $proposed_rules ) ) {
						$cur_meta = get_term_meta( $term->term_id, $meta_key, true );
						$cur_meta = ( false === $cur_meta || null === $cur_meta ) ? '' : (string) $cur_meta;
						$prop_val = (string) $proposed_rules[ $rule_key ];

						if ( $cur_meta !== $prop_val ) {
							$rule_diffs[ $rule_key ] = array(
								'rule'     => $rule_key,
								'current'  => $cur_meta,
								'proposed' => $prop_val,
							);
						}
					}
				}

				if ( ! empty( $rule_diffs ) ) {
					$category_diff[ $slug_str ] = array(
						'slug'        => $slug_str,
						'name'        => $term->name,
						'term_id'     => $term->term_id,
						'matched'     => true,
						'rule_diffs'  => $rule_diffs,
						'status_note' => 'Módosul',
					);
				}
			}
		}

		return array(
			'valid'          => true,
			'errors'         => array(),
			'warnings'       => $validation['warnings'],
			'profile_name'   => (string) ( $data['profile_name'] ?? 'Pepita Sync Profil' ),
			'exported_at'    => (string) ( $data['exported_at'] ?? '' ),
			'schema_version' => (int) ( $data['schema_version'] ?? self::SCHEMA_VERSION ),
			'checksum'       => (string) ( $data['checksum'] ?? '' ),
			'checksum_valid' => self::verify_checksum( $data ),
			'settings_diff'  => $settings_diff,
			'category_diff'  => $category_diff,
		);
	}

	/**
	 * Import a validated profile into the current store with compensating rollback atomicity.
	 *
	 * Guarantees that any failure during settings update or category metadata updates
	 * results in a complete rollback of all affected settings and term metadata.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $options
	 * @return array{success: bool, rolled_back: bool, message: string, imported_settings: int, imported_categories: int}
	 */
	public static function import_profile( array $data, array $options = array() ): array {
		$validation = self::validate_profile( $data, $options );
		if ( ! $validation['valid'] ) {
			return array(
				'success'             => false,
				'rolled_back'         => false,
				'message'             => implode( '; ', $validation['errors'] ),
				'imported_settings'   => 0,
				'imported_categories' => 0,
			);
		}

		$current_settings = Settings::all();
		$import_settings  = (array) ( $data['settings'] ?? array() );

		// Security: allow only strictly permit-listed portable settings.
		$filtered_import = array();
		foreach ( self::PORTABLE_SETTINGS_ALLOWLIST as $allowed_key ) {
			if ( array_key_exists( $allowed_key, $import_settings ) ) {
				$filtered_import[ $allowed_key ] = $import_settings[ $allowed_key ];
			}
		}

		// Merge filtered import settings with current settings.
		$merged = array_merge( $current_settings, $filtered_import );

		// Re-apply existing secrets unconditionally to protect store security.
		foreach ( self::SECRET_KEYS as $secret_key ) {
			$merged[ $secret_key ] = $current_settings[ $secret_key ] ?? '';
		}

		// Sanitize all settings through standard validator.
		$sanitized = Settings::sanitize( $merged );

		// Snapshot term metadata for rollback if category rules are being imported.
		$meta_map = array(
			'include'          => Category_Rules::META_INCLUDE,
			'include_children' => Category_Rules::META_INCLUDE_CHILDREN,
			'gtin_required'    => Category_Rules::META_GTIN_REQUIRED,
			'price_type'       => Category_Rules::META_PRICE_TYPE,
			'price_value'      => Category_Rules::META_PRICE_VALUE,
			'shipping_delay'   => Category_Rules::META_SHIPPING_DELAY,
		);

		$import_cats = (bool) ( $options['import_category_rules'] ?? true );
		$original_term_meta = array();

		if ( $import_cats && isset( $data['category_rules'] ) && is_array( $data['category_rules'] ) ) {
			foreach ( $data['category_rules'] as $slug => $cat_data ) {
				$term = get_term_by( 'slug', (string) $slug, Category_Rules::TAXONOMY );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$term_all_meta = get_term_meta( $term->term_id );
				foreach ( $meta_map as $rule_key => $meta_key ) {
					$has_meta = is_array( $term_all_meta ) && array_key_exists( $meta_key, $term_all_meta );
					$original_term_meta[ $term->term_id ][ $meta_key ] = array(
						'exists' => $has_meta,
						'value'  => $has_meta ? get_term_meta( $term->term_id, $meta_key, true ) : null,
					);
				}
			}
		}

		// Transactional execution with compensating rollback.
		$settings_applied = false;
		$imported_categories = 0;

		try {
			// Step 1: Apply sanitized settings.
			Settings::update( $sanitized );
			$settings_applied = true;

			// Check for simulated failure injection (used in test cases).
			if ( ! empty( $options['simulate_failure_at_category'] ) ) {
				throw new \RuntimeException( 'Szimulált hiba a kategóriaszabályok alkalmazása során.' );
			}

			// Step 2: Apply category rules by slug.
			if ( $import_cats && isset( $data['category_rules'] ) && is_array( $data['category_rules'] ) ) {
				foreach ( $data['category_rules'] as $slug => $cat_data ) {
					$term = get_term_by( 'slug', (string) $slug, Category_Rules::TAXONOMY );
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}

					if ( ! empty( $options['fail_on_slug'] ) && (string) $slug === (string) $options['fail_on_slug'] ) {
						throw new \RuntimeException( sprintf( 'Szimulált hiba a(z) %s kategóriánál.', $slug ) );
					}

					$rules = isset( $cat_data['rules'] ) && is_array( $cat_data['rules'] ) ? $cat_data['rules'] : array();
					foreach ( $meta_map as $rule_key => $meta_key ) {
						if ( isset( $rules[ $rule_key ] ) ) {
							$updated = update_term_meta( $term->term_id, $meta_key, sanitize_text_field( (string) $rules[ $rule_key ] ) );
							if ( false === $updated ) {
								$current_meta = get_term_meta( $term->term_id, $meta_key, true );
								if ( (string) $current_meta !== (string) $rules[ $rule_key ] ) {
									throw new \RuntimeException( sprintf( 'Nem sikerült frissíteni a(z) %d kategória %s szabályát.', $term->term_id, $rule_key ) );
								}
							}
						}
					}

					++$imported_categories;
				}
			}

			Logger::info(
				'profile_import',
				'Konfigurációs profil sikeresen importálva.',
				array(
					'profile_name'        => (string) ( $data['profile_name'] ?? 'Névtelen' ),
					'settings_count'      => count( $filtered_import ),
					'category_rule_count' => $imported_categories,
				)
			);

			return array(
				'success'             => true,
				'rolled_back'         => false,
				'message'             => 'A konfigurációs profil sikeresen importálva.',
				'imported_settings'   => count( $filtered_import ),
				'imported_categories' => $imported_categories,
			);
		} catch ( \Throwable $e ) {
			// COMPENSATING ROLLBACK: Restore settings and term metadata to exact pre-import state.
			if ( $settings_applied ) {
				Settings::update( $current_settings );
			}

			foreach ( $original_term_meta as $term_id => $metas ) {
				foreach ( $metas as $meta_key => $state ) {
					if ( ! empty( $state['exists'] ) ) {
						update_term_meta( $term_id, $meta_key, $state['value'] );
					} else {
						delete_term_meta( $term_id, $meta_key );
					}
				}
			}

			Logger::error(
				'profile_import_rollback',
				'A profil importálása megszakadt, a kompenzációs visszagörgetés (rollback) visszaállította a korábbi állapotot.',
				array(
					'error'             => $e->getMessage(),
					'restored_settings' => count( $filtered_import ),
					'restored_terms'    => count( $original_term_meta ),
				)
			);

			return array(
				'success'             => false,
				'rolled_back'         => true,
				'message'             => 'Hiba történt az importálás során. Minden módosítás visszagörgetve: ' . $e->getMessage(),
				'imported_settings'   => 0,
				'imported_categories' => 0,
			);
		}
	}
}
