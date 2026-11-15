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
	 * Option key for persistent recovery journal to guarantee crash-safe atomicity.
	 */
	public const JOURNAL_OPTION = 'wps_pepita_import_journal';

	/**
	 * Option key and TTL for exclusive import lock to protect against concurrency.
	 */
	public const LOCK_OPTION = 'wps_pepita_import_lock';
	public const LOCK_TTL    = 120; // 2 minutes, renewed before every write phase.

	/** Exception code signalling that the import lock was lost (fencing). */
	private const LOCK_LOST_CODE = 7001;

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
		// 'gtin_required_categories' is intentionally NOT portable: it stores numeric term IDs that differ per store
		// (portable only once translated through term slugs).
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
		if ( array_key_exists( 'checksum', $data ) ) {
			if ( ! is_string( $data['checksum'] ) || '' === trim( $data['checksum'] ) ) {
				$errors[] = 'A profil integritás-ellenőrző összege (checksum) formátuma érvénytelen.';
			} else {
				$checksum_valid = self::verify_checksum( $data );
				if ( false === $checksum_valid ) {
					$errors[] = 'A profil integritás-ellenőrző összege (checksum) nem egyezik. A profilfájl sérült vagy módosult az exportálás óta.';
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
	 * Atomically delete an option only if its stored serialized payload contains the expected token.
	 *
	 * In WordPress production / integration environments, executes an atomic single SQL DELETE query
	 * on wp_options with an option_value token condition to prevent check-then-delete race conditions.
	 * In stubbed unit-test environments, performs an atomic token match and unset.
	 *
	 * @param string $key
	 * @param string $token
	 * @return bool True if deleted, false if token did not match or option was absent.
	 */
	public static function delete_option_if_token( string $key, string $token ): bool {
		global $wpdb;

		if ( empty( $token ) || ( ! str_starts_with( $key, 'wps_pepita_' ) && ! str_starts_with( $key, '_wps_' ) ) ) {
			return false;
		}

		// Unit test environment with WPS_Test_State stub.
		if ( class_exists( '\WPS_Test_State' ) && isset( \WPS_Test_State::$options ) ) {
			if ( ! isset( \WPS_Test_State::$options[ $key ] ) ) {
				return false;
			}
			$val = \WPS_Test_State::$options[ $key ];
			if ( is_array( $val ) && (string) ( $val['token'] ?? '' ) === $token ) {
				unset( \WPS_Test_State::$options[ $key ] );
				return true;
			}
			if ( is_string( $val ) && $val === $token ) {
				unset( \WPS_Test_State::$options[ $key ] );
				return true;
			}
			return false;
		}

		// WordPress database environment.
		if ( isset( $wpdb->options ) && is_string( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			// Exact serialized field match for 'token': s:5:"token";s:<len>:"<token>"
			$token_pattern = '%s:5:"token";s:' . strlen( $token ) . ':"' . $wpdb->esc_like( $token ) . '"%';
			$deleted       = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND ( LEFT(option_name, 11) = 'wps_pepita_' OR LEFT(option_name, 5) = '_wps_' ) AND ( option_value LIKE %s OR option_value = %s )", // Scoped to wps_pepita_ rows. phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$key,
					$token_pattern,
					$token
				)
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $key, 'options' );
			}
			return (int) $deleted > 0;
		}

		return false;
	}

	/**
	 * Exact serialized-token LIKE pattern for the lock/mutex option value.
	 */
	private static function token_like_pattern( string $token ): string {
		global $wpdb;
		return '%s:5:"token";s:' . strlen( $token ) . ':"' . $wpdb->esc_like( $token ) . '"%';
	}

	/**
	 * Truly atomic "create option only if absent".
	 *
	 * WordPress core add_option() reads first and then upserts, so two racing requests can both
	 * succeed. INSERT IGNORE on the unique option_name key gives exactly one winner.
	 *
	 * @param string               $key
	 * @param array<string, mixed> $value
	 * @return bool True only for the single process that created the row.
	 */
	private static function insert_option_if_absent( string $key, array $value ): bool {
		global $wpdb;

		if ( class_exists( '\WPS_Test_State' ) && isset( \WPS_Test_State::$options ) ) {
			return (bool) add_option( $key, $value, '', false );
		}

		if ( isset( $wpdb->options ) && is_string( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			$rows = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$key,
					maybe_serialize( $value )
				)
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $key, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
			return 1 === (int) $rows;
		}

		return (bool) add_option( $key, $value, '', false );
	}

	/**
	 * Atomically replace an option value only while the stored row still carries the given token.
	 *
	 * @param string               $key
	 * @param string               $token
	 * @param array<string, mixed> $value New value; MUST keep the same 'token'.
	 * @return bool True while the token still owns the row after the update.
	 */
	private static function update_option_if_token( string $key, string $token, array $value ): bool {
		global $wpdb;

		if ( '' === $token || ( ! str_starts_with( $key, 'wps_pepita_' ) && ! str_starts_with( $key, '_wps_' ) ) ) {
			return false;
		}

		if ( class_exists( '\WPS_Test_State' ) && isset( \WPS_Test_State::$options ) ) {
			$cur = \WPS_Test_State::$options[ $key ] ?? null;
			if ( is_array( $cur ) && (string) ( $cur['token'] ?? '' ) === $token ) {
				\WPS_Test_State::$options[ $key ] = $value;
				return true;
			}
			return false;
		}

		if ( isset( $wpdb->options ) && is_string( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			$pattern = self::token_like_pattern( $token );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND LEFT(option_name, 11) = 'wps_pepita_' AND option_value LIKE %s", // Scoped to wps_pepita_ rows. phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					maybe_serialize( $value ),
					$key,
					$pattern
				)
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $key, 'options' );
			}
			// Row count is 0 when the value is unchanged, so verify ownership explicitly.
			$owned = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$key,
					$pattern
				)
			);
			return (int) $owned > 0;
		}

		return false;
	}

	/**
	 * Delete a lock/mutex row only if it still holds the EXACT observed token AND expiry.
	 *
	 * A lease renewed after the reclaimer read it changes the expiry, so the delete no longer
	 * matches and the live owner keeps its lock.
	 *
	 * @return bool True if the exact stale row was deleted.
	 */
	public static function delete_lock_if_stale( string $key, string $token, int $expires ): bool {
		global $wpdb;

		if ( '' === $token || ( ! str_starts_with( $key, 'wps_pepita_' ) && ! str_starts_with( $key, '_wps_' ) ) ) {
			return false;
		}

		if ( class_exists( '\WPS_Test_State' ) && isset( \WPS_Test_State::$options ) ) {
			$cur = \WPS_Test_State::$options[ $key ] ?? null;
			if ( is_array( $cur ) && (string) ( $cur['token'] ?? '' ) === $token && (int) ( $cur['expires'] ?? 0 ) === $expires ) {
				unset( \WPS_Test_State::$options[ $key ] );
				return true;
			}
			return false;
		}

		if ( isset( $wpdb->options ) && is_string( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			$exact   = '%s:5:"token";s:' . strlen( $token ) . ':"' . $wpdb->esc_like( $token ) . '";s:7:"expires";i:' . $expires . ';%';
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND LEFT(option_name, 11) = 'wps_pepita_' AND option_value LIKE %s", // Scoped to wps_pepita_ rows. phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$key,
					$exact
				)
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $key, 'options' );
			}
			return (int) $deleted > 0;
		}

		return false;
	}

	/**
	 * Renew the lease of the import lock, only while this token still owns it (fencing).
	 *
	 * @param string|null $token Lock token returned by acquire_import_lock().
	 * @return bool False when the lock was lost (expired and reclaimed); the caller must stop writing.
	 */
	public static function renew_import_lock( ?string $token ): bool {
		if ( empty( $token ) ) {
			return false;
		}

		return self::update_option_if_token(
			self::LOCK_OPTION,
			$token,
			array(
				'token'   => $token,
				'expires' => time() + self::LOCK_TTL,
				'pid'     => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
			)
		);
	}

	/**
	 * Acquire a short-lived mutex token for lock reclamation.
	 *
	 * Uses atomic add_option() and atomic conditional token-verified deletion.
	 */
	private static function acquire_reclaim_mutex( string $key, int $ttl = 30 ): ?string {
		$now   = time();
		$token = wp_generate_password( 32, false, false );
		$val   = array(
			'token'   => $token,
			'expires' => $now + $ttl,
		);

		if ( self::insert_option_if_absent( $key, $val ) ) {
			return $token;
		}

		$existing = get_option( $key, false );
		if ( ! is_array( $existing ) || (int) ( $existing['expires'] ?? 0 ) > $now ) {
			return null;
		}

		$stale_token = (string) ( $existing['token'] ?? '' );
		if ( '' === $stale_token ) {
			return null;
		}

		// Atomically delete the expired mutex ONLY if it still holds the exact observed token and expiry.
		if ( self::delete_lock_if_stale( $key, $stale_token, (int) ( $existing['expires'] ?? 0 ) ) ) {
			if ( self::insert_option_if_absent( $key, $val ) ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Release a short-lived mutex token if currently held.
	 */
	private static function release_reclaim_mutex( string $key, ?string $token ): void {
		if ( empty( $token ) ) {
			return;
		}
		self::delete_option_if_token( $key, $token );
	}

	/**
	 * Acquire an exclusive import lock to protect against concurrent imports.
	 *
	 * Uses an atomic INSERT IGNORE (see insert_option_if_absent()) to ensure single-process execution.
	 *
	 * @return string|null Lock token on success, null on contention.
	 */
	public static function acquire_import_lock(): ?string {
		$token = wp_generate_password( 32, false, false );
		$now   = time();
		$val   = array(
			'token'   => $token,
			'expires' => $now + self::LOCK_TTL,
			'pid'     => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
		);

		if ( self::insert_option_if_absent( self::LOCK_OPTION, $val ) ) {
			return $token;
		}

		$current = get_option( self::LOCK_OPTION, false );
		if ( ! is_array( $current ) || (int) ( $current['expires'] ?? 0 ) > $now ) {
			return null;
		}

		// Reclaiming an expired lock uses an atomic mutex to prevent competing
		// processes from interleaving and deleting each other's fresh locks.
		$mutex_key   = self::LOCK_OPTION . '_reclaim';
		$mutex_token = self::acquire_reclaim_mutex( $mutex_key, 30 );
		if ( null === $mutex_token ) {
			return null;
		}

		try {
			$current = get_option( self::LOCK_OPTION, false );
			if ( is_array( $current ) && (int) ( $current['expires'] ?? 0 ) > $now ) {
				return null;
			}

			$stale_lock_token = is_array( $current ) ? (string) ( $current['token'] ?? '' ) : '';
			if ( '' === $stale_lock_token ) {
				// Malformed lock row without an owner token: nothing to compare against, never guess.
				return null;
			}

			// Delete only the exact stale lease that was observed; a lease renewed in the meantime stays.
			if ( ! self::delete_lock_if_stale( self::LOCK_OPTION, $stale_lock_token, (int) ( $current['expires'] ?? 0 ) ) ) {
				return null;
			}

			if ( self::insert_option_if_absent( self::LOCK_OPTION, $val ) ) {
				return $token;
			}

			return null;
		} finally {
			self::release_reclaim_mutex( $mutex_key, $mutex_token );
		}
	}

	/**
	 * Whether the persisted recovery journal still belongs to the given lock owner.
	 */
	private static function journal_is_owned( ?string $token ): bool {
		$journal = get_option( self::JOURNAL_OPTION, false );
		return ! empty( $token ) && is_array( $journal ) && (string) ( $journal['token'] ?? '' ) === $token;
	}

	/**
	 * Result returned by a former lock owner: it stops, writes nothing and leaves the journal to the current owner.
	 *
	 * @return array<string, mixed>
	 */
	private static function lock_lost_result(): array {
		Logger::error( 'profile_import_lock_lost', 'Az import zárja elveszett; a helyreállítási napló megmarad a következő biztonságos helyreállításhoz.', array() );
		return array(
			'success'             => false,
			'rolled_back'         => false,
			'rollback_clean'      => false,
			'rollback_errors'     => array( 'Import lock lost; recovery journal retained.' ),
			'message'             => 'Az importálás zárja lejárt, ezért az importálás leállt. A következő importálás vagy helyreállítás visszaállítja a korábbi állapotot.',
			'imported_settings'   => 0,
			'imported_categories' => 0,
		);
	}

	/**
	 * Fencing check: throw if this process no longer owns the (renewed) import lock.
	 *
	 * @throws \RuntimeException With LOCK_LOST_CODE when ownership was lost.
	 */
	private static function assert_lock_owned( ?string $token ): void {
		if ( ! self::renew_import_lock( $token ) ) {
			throw new \RuntimeException( 'Import lock lost.', self::LOCK_LOST_CODE );
		}
	}

	/**
	 * Release the exclusive import lock.
	 *
	 * @param string|null $token
	 */
	public static function release_import_lock( ?string $token ): void {
		if ( empty( $token ) ) {
			return;
		}

		self::delete_option_if_token( self::LOCK_OPTION, $token );
	}

	/**
	 * Check whether an import process currently holds an active, unexpired lock.
	 *
	 * @param string|null $except_token Token permitted to bypass lock check (e.g. self-held lock).
	 * @return bool
	 */
	public static function is_import_locked( ?string $except_token = null ): bool {
		$current = get_option( self::LOCK_OPTION, false );
		if ( ! is_array( $current ) ) {
			return false;
		}

		if ( null !== $except_token && ( $current['token'] ?? '' ) === $except_token ) {
			return false;
		}

		return ( (int) ( $current['expires'] ?? 0 ) ) > time();
	}

	/**
	 * Recover and rollback any partially applied profile import interrupted by server crash or timeout.
	 *
	 * Reads persistent recovery journal from wp_options and restores original store state.
	 *
	 * @param string|null $current_token Optional lock token held by caller to permit self-recovery.
	 * @return array{recovered: bool, rollback_clean: bool, rollback_errors: string[]}
	 */
	public static function recover_interrupted_import( ?string $current_token = null ): array {
		$own_lock   = false;
		$lock_token = $current_token;

		if ( null === $lock_token ) {
			// External recovery call (e.g. from Admin init): must acquire the exclusive import lock first
			// so that no concurrent import can start or run while recovery is inspecting and restoring state.
			$lock_token = self::acquire_import_lock();
			if ( null === $lock_token ) {
				// Lock is held by an active import; do not interfere with it.
				return array(
					'recovered'       => false,
					'rollback_clean'  => true,
					'rollback_errors' => array(),
				);
			}
			$own_lock = true;
		}

		try {
			$journal = get_option( self::JOURNAL_OPTION );
			if ( empty( $journal ) || ! is_array( $journal ) ) {
				return array(
					'recovered'       => false,
					'rollback_clean'  => true,
					'rollback_errors' => array(),
				);
			}

			// A journal marked completed belongs to an import that already succeeded; it must never be undone.
			if ( ! empty( $journal['completed'] ) ) {
				delete_option( self::JOURNAL_OPTION );
				return array(
					'recovered'       => false,
					'rollback_clean'  => true,
					'rollback_errors' => array(),
				);
			}

			$rollback_clean  = true;
			$rollback_errors = array();

			// Never restore secrets from a journal (legacy journals stored full snapshots).
			if ( ! empty( $journal['original_settings'] ) && is_array( $journal['original_settings'] ) ) {
				$journal['original_settings'] = array_diff_key( $journal['original_settings'], array_flip( self::SECRET_KEYS ) );
			}

			if ( ! empty( $journal['original_settings'] ) && is_array( $journal['original_settings'] ) ) {
				try {
					Settings::update( $journal['original_settings'] );
				} catch ( \Throwable $settings_err ) {
					$rollback_clean   = false;
					$rollback_errors[] = 'Settings restore threw exception in recovery: ' . $settings_err->getMessage();
				}

				Settings::reset_cache();
				$db_settings = get_option( Settings::OPTION, array() );
				if ( ! is_array( $db_settings ) ) {
					$rollback_clean   = false;
					$rollback_errors[] = 'Settings option read from DB failed during recovery.';
				} else {
					foreach ( $journal['original_settings'] as $k => $v ) {
						if ( ! array_key_exists( $k, $db_settings ) || $db_settings[ $k ] !== $v ) {
							$rollback_clean   = false;
							$rollback_errors[] = sprintf( 'Setting %s DB restore mismatch in recovery.', $k );
						}
					}
				}
			}

		if ( ! empty( $journal['original_term_meta'] ) && is_array( $journal['original_term_meta'] ) ) {
			foreach ( $journal['original_term_meta'] as $term_id => $metas ) {
				if ( ! is_array( $metas ) ) {
					continue;
				}
				foreach ( $metas as $meta_key => $state ) {
					if ( ! empty( $state['exists'] ) ) {
						$res = update_term_meta( (int) $term_id, $meta_key, $state['value'] );
						if ( is_wp_error( $res ) ) {
							$rollback_clean   = false;
							$rollback_errors[] = sprintf( 'Term %d meta %s restore failed: %s', (int) $term_id, $meta_key, $res->get_error_message() );
						} else {
							$current_val = get_term_meta( (int) $term_id, $meta_key, true );
							if ( (string) $current_val !== (string) $state['value'] ) {
								$rollback_clean   = false;
								$rollback_errors[] = sprintf( 'Term %d meta %s restore verification failed: expected %s, got %s', (int) $term_id, $meta_key, (string) $state['value'], (string) $current_val );
							}
						}
					} else {
						$res = delete_term_meta( (int) $term_id, $meta_key );
						if ( is_wp_error( $res ) ) {
							$rollback_clean   = false;
							$rollback_errors[] = sprintf( 'Term %d meta %s delete failed: %s', (int) $term_id, $meta_key, $res->get_error_message() );
						} else {
							$check_all = get_term_meta( (int) $term_id );
							if ( is_array( $check_all ) && array_key_exists( $meta_key, $check_all ) ) {
								$rollback_clean   = false;
								$rollback_errors[] = sprintf( 'Term %d meta %s delete failed: meta still exists.', (int) $term_id, $meta_key );
							}
						}
					}
				}
			}
		}

		if ( $rollback_clean ) {
			delete_option( self::JOURNAL_OPTION );
		}

		Logger::warning(
			'profile_import_recovered',
			'Félbemaradt profilimport észlelve és visszaállítva a helyreállítási naplóból (recovery journal).',
			array(
				'journal_timestamp' => $journal['timestamp'] ?? 0,
				'rollback_clean'    => $rollback_clean,
				'rollback_errors'   => $rollback_errors,
			)
		);

		return array(
			'recovered'       => true,
			'rollback_clean'  => $rollback_clean,
			'rollback_errors' => $rollback_errors,
		);
		} finally {
			if ( $own_lock ) {
				self::release_import_lock( $lock_token );
			}
		}
	}

	/**
	 * Import a validated profile into the current store with compensating rollback atomicity.
	 *
	 * Guarantees that any failure during settings update or category metadata updates
	 * results in a complete rollback of all affected settings and term metadata.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $options
	 * @return array{success: bool, rolled_back: bool, rollback_clean?: bool, rollback_errors?: string[], message: string, imported_settings: int, imported_categories: int}
	 */
	public static function import_profile( array $data, array $options = array() ): array {
		$lock_token = self::acquire_import_lock();
		if ( null === $lock_token ) {
			return array(
				'success'             => false,
				'rolled_back'         => false,
				'rollback_clean'      => true,
				'rollback_errors'     => array( 'Konkurrens importálás blokkolva.' ),
				'message'             => 'Egy másik profilimportálás jelenleg is folyamatban van. Kérjük, várjon a befejezésére.',
				'imported_settings'   => 0,
				'imported_categories' => 0,
			);
		}

		try {
			$recovery = self::recover_interrupted_import( $lock_token );
			if ( $recovery['recovered'] && ! $recovery['rollback_clean'] ) {
				return array(
					'success'             => false,
					'rolled_back'         => false,
					'rollback_clean'      => false,
					'rollback_errors'     => $recovery['rollback_errors'],
					'message'             => 'Félbemaradt profilimport helyreállítása meghiúsult. Az új importálás blokkolva van a meglévő állapot védelmében.',
					'imported_settings'   => 0,
					'imported_categories' => 0,
				);
			}

			if ( false !== get_option( self::JOURNAL_OPTION, false ) ) {
				return array(
					'success'             => false,
					'rolled_back'         => false,
					'rollback_clean'      => false,
					'rollback_errors'     => array( 'Félbemaradt import naplója még létezik a rendszerben.' ),
					'message'             => 'Egy korábbi félbemaradt profilimport naplója még létezik. Az új importálás blokkolva van a meglévő helyreállítási állapot védelmében.',
					'imported_settings'   => 0,
					'imported_categories' => 0,
				);
			}

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

		// Journal and rollback cover ONLY the keys this import changes (never secrets, never unrelated keys).
		$restore_settings = array_intersect_key( $current_settings, $filtered_import );

		// Persist recovery journal to ensure crash-safe atomicity.
		$journal = array(
			'token'              => $lock_token,
			'timestamp'          => time(),
			'original_settings'  => $restore_settings,
			'original_term_meta' => $original_term_meta,
		);

		// Ownership is verified BEFORE the journal is created, and creation is atomic and never overwrites
		// another owner's journal.
		if ( ! self::renew_import_lock( $lock_token ) ) {
			return self::lock_lost_result();
		}
		if ( ! self::insert_option_if_absent( self::JOURNAL_OPTION, $journal ) ) {
			return array(
				'success'             => false,
				'rolled_back'         => false,
				'rollback_clean'      => true,
				'rollback_errors'     => array( 'Nem sikerült elmenteni a helyreállítási naplót (recovery journal).' ),
				'message'             => 'Nem sikerült létrehozni a perzisztens helyreállítási naplót (recovery journal). Az importálás megszakítva a biztonság érdekében.',
				'imported_settings'   => 0,
				'imported_categories' => 0,
			);
		}

		try {
			// Step 1: Apply sanitized settings (only the imported keys).
			self::assert_lock_owned( $lock_token );
			Settings::update( array_intersect_key( $sanitized, $filtered_import ) );
			$settings_applied = true;

			// Verify that settings are actually persisted in the DB before proceeding.
			Settings::reset_cache();
			$db_persisted = get_option( Settings::OPTION, false );
			if ( ! is_array( $db_persisted ) ) {
				throw new \RuntimeException( 'A beállítások perzisztálása az adatbázisban meghiúsult.' );
			}
			foreach ( $filtered_import as $k => $v ) {
				if ( ! array_key_exists( $k, $db_persisted ) || $db_persisted[ $k ] !== $sanitized[ $k ] ) {
					throw new \RuntimeException( sprintf( 'A(z) %s beállítás nem perzisztálódott megfelelően az adatbázisban.', $k ) );
				}
			}

			// Test hook: simulate losing the lock (expiry + reclaim by another process) mid-import.
			if ( ! empty( $options['simulate_journal_replaced'] ) ) {
				update_option( self::JOURNAL_OPTION, array( 'token' => 'other-owner', 'timestamp' => time(), 'original_settings' => array() ), false );
			}
			if ( ! empty( $options['simulate_lock_loss'] ) ) {
				delete_option( self::LOCK_OPTION );
			}

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

					self::assert_lock_owned( $lock_token );

					if ( ! empty( $options['fail_on_slug'] ) && (string) $slug === (string) $options['fail_on_slug'] ) {
						throw new \RuntimeException( sprintf( 'Szimulált hiba a(z) %s kategóriánál.', $slug ) );
					}

					$rules = isset( $cat_data['rules'] ) && is_array( $cat_data['rules'] ) ? $cat_data['rules'] : array();
					foreach ( $meta_map as $rule_key => $meta_key ) {
						if ( isset( $rules[ $rule_key ] ) ) {
							$updated = update_term_meta( $term->term_id, $meta_key, sanitize_text_field( (string) $rules[ $rule_key ] ) );
							if ( is_wp_error( $updated ) ) {
								throw new \RuntimeException( sprintf( 'Nem sikerült frissíteni a(z) %d kategória %s szabályát: %s', $term->term_id, $rule_key, $updated->get_error_message() ) );
							} elseif ( false === $updated ) {
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

			// Durable finalization: the success must not be reported while a retained journal could
			// make a later recovery undo this import.
			self::assert_lock_owned( $lock_token );
			if ( ! self::journal_is_owned( $lock_token ) ) {
				throw new \RuntimeException( 'Journal ownership lost.', self::LOCK_LOST_CODE );
			}
			self::delete_option_if_token( self::JOURNAL_OPTION, $lock_token );
			if ( false !== get_option( self::JOURNAL_OPTION, false ) ) {
				// Deletion failed: mark OUR journal completed (token-conditional) so a later recovery never undoes it.
				self::update_option_if_token( self::JOURNAL_OPTION, $lock_token, array_merge( $journal, array( 'completed' => true ) ) );
				$check = get_option( self::JOURNAL_OPTION, false );
				if ( ! is_array( $check ) || empty( $check['completed'] ) ) {
					throw new \RuntimeException( 'A helyreállítási napló véglegesítése sikertelen.' );
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
			// Fencing: a former lock owner must not roll back or write anything; the current owner's
			// recovery restores state from the retained journal.
			if ( self::LOCK_LOST_CODE === $e->getCode() || ! self::renew_import_lock( $lock_token ) || ! self::journal_is_owned( $lock_token ) ) {
				return self::lock_lost_result();
			}

			// COMPENSATING ROLLBACK: restore original settings and term metadata for the keys this import changed.
			$rollback_clean  = true;
			$rollback_errors = array();

			// Restore only keys still holding the value THIS import wrote (or untouched ones); a value changed
			// meanwhile by someone else is never overwritten with a stale snapshot.
			Settings::reset_cache();
			$db_now       = get_option( Settings::OPTION, array() );
			$db_now       = is_array( $db_now ) ? $db_now : array();
			$to_restore   = array();
			foreach ( $restore_settings as $k => $v ) {
				if ( ! array_key_exists( $k, $db_now ) || $db_now[ $k ] === $v || ( isset( $sanitized[ $k ] ) && $db_now[ $k ] === $sanitized[ $k ] ) ) {
					$to_restore[ $k ] = $v;
				}
			}
			$restore_settings = $to_restore;

			try {
				Settings::update( $restore_settings );
			} catch ( \Throwable $settings_err ) {
				$rollback_clean   = false;
				$rollback_errors[] = 'Settings rollback restore failed: ' . $settings_err->getMessage();
			}

			Settings::reset_cache();
			$db_settings = get_option( Settings::OPTION, array() );
			if ( ! is_array( $db_settings ) ) {
				$rollback_clean   = false;
				$rollback_errors[] = 'Settings option read from DB failed during rollback.';
			} else {
				foreach ( $restore_settings as $k => $v ) {
					if ( ! array_key_exists( $k, $db_settings ) || $db_settings[ $k ] !== $v ) {
						$rollback_clean   = false;
						$rollback_errors[] = sprintf( 'Setting %s DB restore mismatch during rollback.', $k );
					}
				}
			}

			foreach ( $original_term_meta as $term_id => $metas ) {
				foreach ( $metas as $meta_key => $state ) {
					if ( ! empty( $state['exists'] ) ) {
						$res = update_term_meta( $term_id, $meta_key, $state['value'] );
						if ( is_wp_error( $res ) ) {
							$rollback_clean   = false;
							$rollback_errors[] = sprintf( 'Term %d meta %s restore failed: %s', $term_id, $meta_key, $res->get_error_message() );
						} else {
							$current_val = get_term_meta( $term_id, $meta_key, true );
							if ( (string) $current_val !== (string) $state['value'] ) {
								$rollback_clean   = false;
								$rollback_errors[] = sprintf( 'Term %d meta %s restore verification failed: expected %s, got %s', $term_id, $meta_key, (string) $state['value'], (string) $current_val );
							}
						}
					} else {
						$res = delete_term_meta( $term_id, $meta_key );
						if ( is_wp_error( $res ) ) {
							$rollback_clean   = false;
							$rollback_errors[] = sprintf( 'Term %d meta %s delete failed: %s', $term_id, $meta_key, $res->get_error_message() );
						} else {
							$check_all = get_term_meta( $term_id );
							if ( is_array( $check_all ) && array_key_exists( $meta_key, $check_all ) ) {
								$rollback_clean   = false;
								$rollback_errors[] = sprintf( 'Term %d meta %s delete failed: meta still exists.', $term_id, $meta_key );
							}
						}
					}
				}
			}

			if ( $rollback_clean ) {
				self::delete_option_if_token( self::JOURNAL_OPTION, $lock_token );
			}

			Logger::error(
				'profile_import_rollback',
				'A profil importálása megszakadt, a kompenzációs visszagörgetés (rollback) visszaállította a korábbi állapotot.',
				array(
					'error'             => $e->getMessage(),
					'restored_settings' => count( $filtered_import ),
					'restored_terms'    => count( $original_term_meta ),
					'rollback_clean'    => $rollback_clean,
					'rollback_errors'   => $rollback_errors,
				)
			);

			return array(
				'success'             => false,
				'rolled_back'         => true,
				'rollback_clean'      => $rollback_clean,
				'rollback_errors'     => $rollback_errors,
				'message'             => 'Hiba történt az importálás során. Minden módosítás visszagörgetve: ' . $e->getMessage(),
				'imported_settings'   => 0,
				'imported_categories' => 0,
			);
		}
		} finally {
			self::release_import_lock( $lock_token );
		}
	}
}
