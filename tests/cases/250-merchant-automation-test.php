<?php
/**
 * M11 Merchant Automation & Multi-Store Readiness Test Cases.
 *
 * Covers:
 * - Merchant Configuration Profiles (Secret-free export and schema-validated import).
 * - Multi-store Category Rules portability by category slug.
 * - Bounded Operational Reporting and summary metrics.
 * - Memory-bounded, PII-free Operational CSV export with email masking.
 * - Authorization and CSRF protection for profile and report actions.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Admin\Merchant_Profiles;
use WPS\Pepita\Admin\Operational_Reports;
use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/* -------------------------------------------------------------------------
 * 1. Configuration Profile Export: Secret Stripping & Schema
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile export: strictly excludes secrets and sensitive keys',
	static function (): void {
		// Populate settings including sensitive tokens.
		Settings::update(
			array(
				'feed_token'                 => 'SECRET_FEED_TOKEN_ABC123',
				'order_api_key'              => 'SECRET_API_KEY_XYZ789',
				'feed_alert_webhook_url'     => 'https://user:password@hooks.example.test/webhook',
				'feed_alert_email'           => 'merchant_personal@example.test',
				'order_api_allowed_ips'      => '192.168.1.1, 10.0.0.1',
				'order_api_trusted_proxies'  => '172.16.0.1',
				'storage_key'                => 'SECRET_STORAGE_KEY_999',
				'shipping_delay'             => 4,
				'price_adjustment_type'      => 'percent',
				'price_adjustment_value'     => 7.5,
				'attribute_name_map'         => "pa_color=Szín\npa_size=Méret",
			)
		);

		$profile = Merchant_Profiles::export_profile( array( 'name' => 'Test Profile 1' ) );

		// 1. Schema versioning and metadata.
		WPS_Assert::same( 1, $profile['schema_version'], 'Schema version must be 1.' );
		WPS_Assert::same( 'wp-pepita-sync', $profile['generator'] );
		WPS_Assert::same( 'Test Profile 1', $profile['profile_name'] );
		WPS_Assert::true( ! empty( $profile['exported_at'] ) );
		WPS_Assert::true( ! empty( $profile['checksum'] ) );

		// 2. Secret keys MUST be strictly stripped.
		$settings = $profile['settings'];
		WPS_Assert::false( isset( $settings['feed_token'] ), 'feed_token must be stripped from export.' );
		WPS_Assert::false( isset( $settings['order_api_key'] ), 'order_api_key must be stripped from export.' );
		WPS_Assert::false( isset( $settings['storage_key'] ), 'storage_key must be stripped from export.' );
		WPS_Assert::false( isset( $settings['feed_alert_webhook_url'] ), 'feed_alert_webhook_url must be stripped from export.' );
		WPS_Assert::false( isset( $settings['feed_alert_email'] ), 'feed_alert_email must be stripped from export.' );
		WPS_Assert::false( isset( $settings['order_api_allowed_ips'] ), 'order_api_allowed_ips must be stripped from export.' );
		WPS_Assert::false( isset( $settings['order_api_trusted_proxies'] ), 'order_api_trusted_proxies must be stripped from export.' );

		// 3. Portable configuration settings MUST be preserved.
		WPS_Assert::same( 4, $settings['shipping_delay'] );
		WPS_Assert::same( 'percent', $settings['price_adjustment_type'] );
		WPS_Assert::same( 7.5, $settings['price_adjustment_value'] );
		WPS_Assert::same( "pa_color=Szín\npa_size=Méret", $settings['attribute_name_map'] );
	}
);

/* -------------------------------------------------------------------------
 * 2. Configuration Profile Validation: Fail-Closed Schema Verification
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile validation: validates schema and rejects malformed payloads',
	static function (): void {
		// Valid profile.
		$valid_profile = Merchant_Profiles::export_profile();
		$res = Merchant_Profiles::validate_profile( $valid_profile );
		WPS_Assert::true( $res['valid'], 'Valid profile must pass validation.' );
		WPS_Assert::true( empty( $res['errors'] ), 'No errors on valid profile.' );

		// Missing schema_version.
		$missing_version = $valid_profile;
		unset( $missing_version['schema_version'] );
		$res_missing = Merchant_Profiles::validate_profile( $missing_version );
		WPS_Assert::false( $res_missing['valid'], 'Missing schema_version must fail validation.' );

		// Unsupported future schema_version.
		$unsupported_version = $valid_profile;
		$unsupported_version['schema_version'] = 99;
		$res_unsupported = Merchant_Profiles::validate_profile( $unsupported_version );
		WPS_Assert::false( $res_unsupported['valid'], 'Unsupported schema_version must fail validation.' );

		// Malformed non-array settings.
		$bad_settings = $valid_profile;
		$bad_settings['settings'] = 'not an array';
		$res_bad_settings = Merchant_Profiles::validate_profile( $bad_settings );
		WPS_Assert::false( $res_bad_settings['valid'], 'Non-array settings must fail validation.' );

		// Injected secret keys produce warnings.
		$injected = $valid_profile;
		$injected['settings']['feed_token'] = 'evil_token';
		$res_injected = Merchant_Profiles::validate_profile( $injected );
		WPS_Assert::true( ! empty( $res_injected['warnings'] ), 'Injected secrets must generate warning.' );
	}
);

/* -------------------------------------------------------------------------
 * 3. Configuration Profile Import: Safe Merge & Secret Preservation
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile import: merges non-secret settings and preserves existing store secrets',
	static function (): void {
		// Existing store secrets.
		Settings::update(
			array(
				'feed_token'             => 'PRESERVED_FEED_TOKEN_999',
				'order_api_key'          => 'PRESERVED_ORDER_KEY_888',
				'shipping_delay'         => 1,
				'price_adjustment_type'  => 'none',
				'price_adjustment_value' => 0.0,
			)
		);

		// External profile attempting to overwrite secrets and update configuration.
		$external_data = array(
			'schema_version' => 1,
			'generator'      => 'wp-pepita-sync',
			'profile_name'   => 'Imported High Margin Strategy',
			'settings'       => array(
				'feed_token'             => 'ATTACKER_OVERWRITE_TOKEN',
				'order_api_key'          => 'ATTACKER_OVERWRITE_KEY',
				'shipping_delay'         => 5,
				'price_adjustment_type'  => 'fixed',
				'price_adjustment_value' => 500.0,
				'inventory_buffer'       => 3,
			),
			'category_rules' => array(),
		);

		$res = Merchant_Profiles::import_profile( $external_data );
		WPS_Assert::true( $res['success'], 'Import must succeed.' );

		// Verification: store secrets MUST remain untouched.
		WPS_Assert::same( 'PRESERVED_FEED_TOKEN_999', Settings::get( 'feed_token' ), 'Store feed_token must be preserved.' );
		WPS_Assert::same( 'PRESERVED_ORDER_KEY_888', Settings::get( 'order_api_key' ), 'Store order_api_key must be preserved.' );

		// Verification: non-secret settings MUST be applied.
		WPS_Assert::same( 5, Settings::get( 'shipping_delay' ) );
		WPS_Assert::same( 'fixed', Settings::get( 'price_adjustment_type' ) );
		WPS_Assert::same( 500.0, Settings::get( 'price_adjustment_value' ) );
		WPS_Assert::same( 3, Settings::get( 'inventory_buffer' ) );
	}
);

/* -------------------------------------------------------------------------
 * 4. Multi-Store Category Rules Portability by Slug
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 category rules portability: exports and imports rules by category slug',
	static function (): void {
		// Create a test category.
		$term = wp_insert_term( 'Elektronika', Category_Rules::TAXONOMY, array( 'slug' => 'elektronika' ) );
		$term_id = is_array( $term ) ? (int) $term['term_id'] : 0;
		WPS_Assert::true( $term_id > 0 );

		update_term_meta( $term_id, Category_Rules::META_INCLUDE, 'include' );
		update_term_meta( $term_id, Category_Rules::META_PRICE_TYPE, 'percent' );
		update_term_meta( $term_id, Category_Rules::META_PRICE_VALUE, '12.5' );

		// Export profile.
		$profile = Merchant_Profiles::export_profile();
		WPS_Assert::true( isset( $profile['category_rules']['elektronika'] ), 'Category rules must be keyed by slug.' );
		WPS_Assert::same( 'include', $profile['category_rules']['elektronika']['rules']['include'] );
		WPS_Assert::same( '12.5', $profile['category_rules']['elektronika']['rules']['price_value'] );

		// Reset category meta in destination store.
		update_term_meta( $term_id, Category_Rules::META_INCLUDE, 'exclude' );
		update_term_meta( $term_id, Category_Rules::META_PRICE_VALUE, '0' );

		// Import profile.
		$res = Merchant_Profiles::import_profile( $profile, array( 'import_category_rules' => true ) );
		WPS_Assert::true( $res['success'] );
		WPS_Assert::true( $res['imported_categories'] >= 1 );

		// Assert rules were restored by matching slug.
		WPS_Assert::same( 'include', get_term_meta( $term_id, Category_Rules::META_INCLUDE, true ) );
		WPS_Assert::same( '12.5', get_term_meta( $term_id, Category_Rules::META_PRICE_VALUE, true ) );
	}
);

/* -------------------------------------------------------------------------
 * 5. Bounded Operational Reporting: Metrics Aggregation
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 operational reports: summary metrics calculation',
	static function (): void {
		// Seed orders in repository.
		Order_Repository::claim( 'PEP-REP-001', 'pepita.hu', '{}', 'hash_rep1' );
		Order_Repository::complete( 'PEP-REP-001', 101, 'hash_rep1', 1 );

		Order_Repository::claim( 'PEP-REP-002', 'pepita.hu', '{}', 'hash_rep2' );
		Order_Repository::fail( 'PEP-REP-002', 'SKU not found in store', 1 );

		Order_Repository::claim( 'PEP-REP-003', 'pepita.hu', '{}', 'hash_rep3' );
		// Remains in processing state.

		$summary = Operational_Reports::get_summary( 7 );

		WPS_Assert::true( $summary['total_orders'] >= 3, 'Total orders should be at least 3.' );
		WPS_Assert::true( $summary['completed_orders'] >= 1, 'Completed orders should be at least 1.' );
		WPS_Assert::true( $summary['failed_orders'] >= 1, 'Failed orders should be at least 1.' );
		WPS_Assert::true( $summary['processing_orders'] >= 1, 'Processing orders should be at least 1.' );
		WPS_Assert::true( is_array( $summary['feed_telemetry'] ), 'Feed telemetry must be present.' );
	}
);

/* -------------------------------------------------------------------------
 * 6. Operational CSV Export: PII-Free & Email Masking
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 operational reports: CSV export has zero customer PII and masks emails in errors',
	static function (): void {
		$sensitive_email = 'customer.private.order@personal-domain.test';
		$raw_error       = 'Conflict on delivery email: ' . $sensitive_email . ' mismatched with billing.';

		Order_Repository::claim( 'PEP-REP-CSV-1', 'pepita.hu', '{}', 'hash_csv1' );
		Order_Repository::fail( 'PEP-REP-CSV-1', $raw_error, 1 );

		$csv = Operational_Reports::export_report_csv( 30 );

		// 1. CSV Structure.
		WPS_Assert::true( str_contains( $csv, 'Pepita ID,WC Order ID,Origin,Status' ) || str_contains( $csv, '"Pepita ID"' ), 'CSV header must be present.' );
		WPS_Assert::true( str_contains( $csv, 'PEP-REP-CSV-1' ), 'Order external ID must appear.' );

		// 2. Strict PII Non-Disclosure: raw personal email must NOT appear in output.
		WPS_Assert::false( str_contains( $csv, $sensitive_email ), 'Raw customer email must NEVER appear in operational CSV.' );

		// 3. Masked email format must be present.
		WPS_Assert::true( str_contains( $csv, 'c***@personal-domain.test' ), 'Email in error details must be masked.' );
	}
);

/* -------------------------------------------------------------------------
 * 7. Authorization & CSRF Security for Profile & Report Actions
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 authorization: unprivileged user and invalid nonce rejection',
	static function (): void {
		$admin = new Admin();

		// Case A: Unprivileged user (lacks manage_woocommerce capability).
		WPS_Test_State::$current_user_caps['manage_woocommerce'] = false;

		$unauth_died = 0;
		try {
			$admin->handle_export_profile();
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), 'Nincs jogosultsága' ) ) {
				++$unauth_died;
			}
		}

		try {
			$admin->handle_import_profile();
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), 'Nincs jogosultsága' ) ) {
				++$unauth_died;
			}
		}

		try {
			$admin->handle_export_report_csv();
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), 'Nincs jogosultsága' ) ) {
				++$unauth_died;
			}
		}

		WPS_Test_State::$current_user_caps['manage_woocommerce'] = true;
		WPS_Assert::same( 3, $unauth_died, 'All 3 operations must reject unprivileged user.' );

		// Case B: Invalid nonce for authorized user.
		$_POST['_wpnonce']    = 'invalid_nonce';
		$_REQUEST['_wpnonce'] = 'invalid_nonce';
		$_GET['_wpnonce']     = 'invalid_nonce';

		$bad_nonce_died = 0;
		try {
			$admin->handle_export_profile();
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), 'Invalid admin nonce' ) ) {
				++$bad_nonce_died;
			}
		}

		try {
			$admin->handle_import_profile();
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), 'Invalid admin nonce' ) ) {
				++$bad_nonce_died;
			}
		}

		try {
			$admin->handle_export_report_csv();
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), 'Invalid admin nonce' ) ) {
				++$bad_nonce_died;
			}
		}

		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['_wpnonce'] );
		WPS_Assert::same( 3, $bad_nonce_died, 'All 3 operations must reject invalid nonce.' );
	}
);
