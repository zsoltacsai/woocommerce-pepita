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

		// Strip all secrets and sensitive identifiers.
		$sanitized_settings = array();
		foreach ( $all_settings as $key => $value ) {
			if ( in_array( $key, self::SECRET_KEYS, true ) ) {
				continue;
			}
			$sanitized_settings[ $key ] = $value;
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

		if ( ! isset( $data['schema_version'] ) ) {
			$errors[] = 'Hiányzik a sémaverzió (schema_version).';
		} elseif ( (int) $data['schema_version'] !== self::SCHEMA_VERSION ) {
			$errors[] = sprintf( 'Nem támogatott sémaverzió: %s (elvárt: %d).', (string) $data['schema_version'], self::SCHEMA_VERSION );
		}

		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			$errors[] = 'A profil nem tartalmaz érvényes beállításokat (settings).';
		} else {
			// Ensure no secrets are injected into export.
			foreach ( self::SECRET_KEYS as $secret_key ) {
				if ( isset( $data['settings'][ $secret_key ] ) && '' !== $data['settings'][ $secret_key ] ) {
					$warnings[] = sprintf( 'A profil érzékeny kulcsot (%s) tartalmaz, amely biztonsági okokból figyelmen kívül lesz hagyva.', $secret_key );
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

		// Security: remove any secret keys from import payload to keep store secrets intact.
		foreach ( self::SECRET_KEYS as $secret_key ) {
			unset( $import_settings[ $secret_key ] );
		}

		// Merge import settings with current settings (keeping existing secrets).
		$merged = array_merge( $current_settings, $import_settings );

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
