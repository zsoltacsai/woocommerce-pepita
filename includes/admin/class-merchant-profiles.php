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
 * - Sanitizes all incoming settings through Settings::sanitize().
 * - Matches category rules by category slug to support multi-store ID mapping.
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
	 * Validate an imported profile structure.
	 *
	 * @param array<string, mixed> $data
	 * @return array{valid: bool, errors: string[], warnings: string[]}
	 */
	public static function validate_profile( array $data ): array {
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

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Import a validated profile into the current store.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $options
	 * @return array{success: bool, message: string, imported_settings: int, imported_categories: int}
	 */
	public static function import_profile( array $data, array $options = array() ): array {
		$validation = self::validate_profile( $data );
		if ( ! $validation['valid'] ) {
			return array(
				'success'             => false,
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

		// Re-apply existing secrets unconditionally.
		foreach ( self::SECRET_KEYS as $secret_key ) {
			$merged[ $secret_key ] = $current_settings[ $secret_key ] ?? '';
		}

		// Sanitize all settings through standard validator.
		$sanitized = Settings::sanitize( $merged );
		Settings::update( $sanitized );

		$imported_categories = 0;
		$import_cats = (bool) ( $options['import_category_rules'] ?? true );

		if ( $import_cats && isset( $data['category_rules'] ) && is_array( $data['category_rules'] ) ) {
			foreach ( $data['category_rules'] as $slug => $cat_data ) {
				$term = get_term_by( 'slug', (string) $slug, Category_Rules::TAXONOMY );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$rules = isset( $cat_data['rules'] ) && is_array( $cat_data['rules'] ) ? $cat_data['rules'] : array();
				$meta_map = array(
					'include'          => Category_Rules::META_INCLUDE,
					'include_children' => Category_Rules::META_INCLUDE_CHILDREN,
					'gtin_required'    => Category_Rules::META_GTIN_REQUIRED,
					'price_type'       => Category_Rules::META_PRICE_TYPE,
					'price_value'      => Category_Rules::META_PRICE_VALUE,
					'shipping_delay'   => Category_Rules::META_SHIPPING_DELAY,
				);

				foreach ( $meta_map as $rule_key => $meta_key ) {
					if ( isset( $rules[ $rule_key ] ) ) {
						update_term_meta( $term->term_id, $meta_key, sanitize_text_field( (string) $rules[ $rule_key ] ) );
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
				'settings_count'      => count( $import_settings ),
				'category_rule_count' => $imported_categories,
			)
		);

		return array(
			'success'             => true,
			'message'             => 'A konfigurációs profil sikeresen importálva.',
			'imported_settings'   => count( $import_settings ),
			'imported_categories' => $imported_categories,
		);
	}
}
