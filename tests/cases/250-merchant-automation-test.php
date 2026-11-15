<?php
/**
 * M11 Merchant Automation & Multi-Store Readiness Test Cases.
 *
 * Covers:
 * - Merchant Configuration Profiles (Secret-free export and schema-validated import).
 * - Multi-store Category Rules portability by category slug.
 * - Bounded Operational Reporting and summary metrics.
 * - Memory-bounded, fail-closed Operational CSV export (fixed error codes, hashed identifiers, formula neutralization).
 * - Authorization and CSRF protection for profile and report actions.
 * - Fail-closed upload validation.
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
 * 1. Configuration Profile Export: Secret Stripping & Allowlist Schema
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile export: strictly excludes secrets and sensitive keys',
	static function (): void {
		// Populate settings including sensitive tokens and security posture settings.
		Settings::update(
			array(
				'feed_token'                 => 'SECRET_FEED_TOKEN_ABC123',
				'order_api_key'              => 'SECRET_API_KEY_XYZ789',
				'feed_alert_webhook_url'     => 'https://user:password@hooks.example.test/webhook',
				'feed_alert_email'           => 'merchant_personal@example.test',
				'order_api_allowed_ips'      => '192.168.1.1, 10.0.0.1',
				'order_api_trusted_proxies'  => '172.16.0.1',
				'storage_key'                => 'SECRET_STORAGE_KEY_999',
				'feed_public'                => false,
				'order_api_require_https'    => true,
				'delete_data_on_uninstall'   => false,
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

		// 3. Security posture keys MUST NOT be exported.
		WPS_Assert::false( isset( $settings['feed_public'] ), 'feed_public must not be in export allowlist.' );
		WPS_Assert::false( isset( $settings['order_api_require_https'] ), 'order_api_require_https must not be in export allowlist.' );
		WPS_Assert::false( isset( $settings['delete_data_on_uninstall'] ), 'delete_data_on_uninstall must not be in export allowlist.' );

		// 4. Portable configuration settings MUST be preserved.
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

		// Loose types (float, string, boolean) must be strictly rejected.
		$float_version = $valid_profile;
		$float_version['schema_version'] = 1.5;
		WPS_Assert::false( Merchant_Profiles::validate_profile( $float_version )['valid'], 'Float schema_version must be rejected.' );

		$string_version = $valid_profile;
		$string_version['schema_version'] = '1';
		WPS_Assert::false( Merchant_Profiles::validate_profile( $string_version )['valid'], 'String schema_version must be rejected.' );

		$bool_version = $valid_profile;
		$bool_version['schema_version'] = true;
		WPS_Assert::false( Merchant_Profiles::validate_profile( $bool_version )['valid'], 'Boolean schema_version must be rejected.' );

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
 * 3. Configuration Profile Import: Safe Merge & Secret/Posture Preservation
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile import: merges non-secret settings and preserves existing store secrets',
	static function (): void {
		// Existing store secrets and security postures.
		Settings::update(
			array(
				'feed_token'               => 'PRESERVED_FEED_TOKEN_999',
				'order_api_key'            => 'PRESERVED_ORDER_KEY_888',
				'feed_public'              => false,
				'order_api_require_https'  => true,
				'shipping_delay'           => 1,
				'price_adjustment_type'    => 'none',
				'price_adjustment_value'   => 0.0,
			)
		);

		// External profile attempting to overwrite secrets, weaken security postures, and update configuration.
		$external_data = array(
			'schema_version' => 1,
			'generator'      => 'wp-pepita-sync',
			'profile_name'   => 'Imported High Margin Strategy',
			'settings'       => array(
				'feed_token'               => 'ATTACKER_OVERWRITE_TOKEN',
				'order_api_key'            => 'ATTACKER_OVERWRITE_KEY',
				'feed_public'              => true,  // Must NOT be imported
				'order_api_require_https'  => false, // Must NOT be imported
				'shipping_delay'           => 5,
				'price_adjustment_type'    => 'fixed',
				'price_adjustment_value'   => 500.0,
				'inventory_buffer'         => 3,
			),
			'category_rules' => array(),
		);

		$res = Merchant_Profiles::import_profile( $external_data );
		WPS_Assert::true( $res['success'], 'Import must succeed.' );

		// Verification: store secrets MUST remain untouched.
		WPS_Assert::same( 'PRESERVED_FEED_TOKEN_999', Settings::get( 'feed_token' ), 'Store feed_token must be preserved.' );
		WPS_Assert::same( 'PRESERVED_ORDER_KEY_888', Settings::get( 'order_api_key' ), 'Store order_api_key must be preserved.' );

		// Verification: security posture toggles MUST remain untouched.
		WPS_Assert::false( Settings::get( 'feed_public' ), 'Store feed_public must NOT be weakened.' );
		WPS_Assert::true( Settings::get( 'order_api_require_https' ), 'Store order_api_require_https must NOT be weakened.' );

		// Verification: portable non-secret settings MUST be applied.
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
		// Create a test category using the test harness term registry.
		$term = WPS_Test_State::add_term( 2501, 'Elektronika', 0, Category_Rules::TAXONOMY );
		$term_id = 2501;

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
		WPS_Assert::false( $summary['gmv_truncated'], 'Should not be truncated under 1000 orders.' );
	}
);

/* -------------------------------------------------------------------------
 * 6. Operational CSV Export: PII-Free & Formula Neutralization
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 operational reports: fail-closed CSV export never contains raw free-text errors or untrusted identifiers',
	static function (): void {
		$sensitive_email = 'customer.private.order@personal-domain.test';
		$sensitive_phone = '+36 30 123 4567';
		$raw_error       = 'Conflict on delivery email: ' . $sensitive_email . ' and phone ' . $sensitive_phone . ' mismatched with billing.';

		Order_Repository::claim( 'PEP-REP-CSV-1', 'pepita.hu', '{}', 'hash_csv1' );
		Order_Repository::fail( 'PEP-REP-CSV-1', $raw_error, 1 );

		// Seed a potential CSV formula injection attempt in an external ID or error.
		Order_Repository::claim( '=cmd|\' /C calc\'!A0', 'pepita.hu', '{}', 'hash_csv2' );
		Order_Repository::fail( '=cmd|\' /C calc\'!A0', '+SUM(1,2)', 1 );

		$csv = Operational_Reports::export_report_csv( 30 );

		// 1. CSV Structure.
		WPS_Assert::true( str_contains( $csv, 'Pepita ID,WC Order ID,Origin,Status' ) || str_contains( $csv, '"Pepita ID"' ), 'CSV header must be present.' );

		// 2. Raw free-text errors are never exported: no email, phone, name or address in any form.
		WPS_Assert::false( str_contains( $csv, $sensitive_email ), 'Raw customer email must NEVER appear in operational CSV.' );
		WPS_Assert::false( str_contains( $csv, $sensitive_phone ), 'Raw customer phone must NEVER appear in operational CSV.' );
		WPS_Assert::false( str_contains( $csv, 'personal-domain' ), 'Not even a masked email domain may appear.' );
		WPS_Assert::false( str_contains( $csv, 'Conflict on delivery' ), 'Raw error text must NEVER appear in operational CSV.' );

		// 3. Only fixed codes with predefined explanations are exported.
		WPS_Assert::true( str_contains( $csv, 'Error Code' ), 'CSV must contain the Error Code column.' );
		WPS_Assert::true( str_contains( $csv, 'unclassified_error' ) || str_contains( $csv, 'validation_error' ), 'Error must be exported as a fixed classification code.' );

		// 4. Untrusted identifiers are hashed instead of exported raw (also neutralizes formula injection).
		WPS_Assert::false( str_contains( $csv, 'cmd|' ), 'Unsafe external_id must not be exported raw.' );
		WPS_Assert::true( str_contains( $csv, 'hash:' ), 'Unsafe external_id must be exported as a salted hash.' );
		WPS_Assert::false( str_contains( $csv, '+SUM' ), 'Raw formula error text must not be exported.' );

		// 5. Bypass corpus from the AppSec review: none of these may ever surface in the export.
		$bypasses = array(
			'delivery to Nagy Éva',
			'Kovács János, Budapest',
			'kovács jános',
			'Иван Петров',
			'山田太郎',
			'Jean-Luc Picard',
			'Szeged Kárász u 5',
			'Andrássy útja 60',
			'221B Baker Street',
			'Hauptstraße 5, 10115 Berlin',
			'x@localhost',
			'jane%40example.test',
			'Bearer abcdef0123456789',
			'https://shop.test/?token=SECRETTOKEN123',
			"Duplicate entry 'Kovács János' for key",
			'kovacs_janos_kossuth_lajos_utca_12',
		);
		foreach ( $bypasses as $i => $text ) {
			Order_Repository::claim( 'PEP-REP-BYP-' . $i, 'pepita.hu', '{}', 'hash_byp' . $i );
			Order_Repository::fail( 'PEP-REP-BYP-' . $i, $text, 1 );
		}
		$csv_updated = Operational_Reports::export_report_csv( 30 );
		foreach ( $bypasses as $text ) {
			foreach ( preg_split( '/[\s,]+/u', $text ) ?: array() as $fragment ) {
				if ( mb_strlen( $fragment ) >= 4 ) {
					WPS_Assert::false( str_contains( $csv_updated, $fragment ), 'Personal fragment must not be exported: ' . $fragment );
				}
			}
		}

		// 6. Invalid UTF-8 must fail closed to a fixed code, never to raw text.
		WPS_Assert::same( 'unclassified_error', Operational_Reports::classify_error( "Nagy \xC3\x28 Éva" ), 'Malformed UTF-8 must classify as unclassified_error.' );
		WPS_Assert::same( '', Operational_Reports::classify_error( '   ' ), 'Empty input classifies as empty.' );

		// 7. Summary top_errors also carry only codes and predefined explanations.
		$summary = Operational_Reports::get_summary( 30 );
		foreach ( $summary['top_errors'] as $err ) {
			WPS_Assert::false( str_contains( (string) $err['error'], 'Nagy' ), 'Summary explanation must be predefined text.' );
			WPS_Assert::true( '' !== (string) $err['code'], 'Summary error must carry a code.' );
		}
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

/* -------------------------------------------------------------------------
 * 8. Profile Upload: Fail-Closed Security Validation
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile upload: fail-closed validation on errors, extension, and upload status',
	static function (): void {
		$admin = new Admin();

		// Helper to invoke handle_import_profile and capture redirect notice.
		$invoke_import = static function () use ( $admin ): string {
			$_POST['_wpnonce']    = wp_create_nonce( 'wps_pepita_import_profile' );
			$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
			try {
				$admin->handle_import_profile();
			} catch ( \RuntimeException $e ) {
				if ( preg_match( '/wps_notice=([a-z_]+)/', $e->getMessage(), $m ) ) {
					return $m[1];
				}
				return $e->getMessage();
			} finally {
				unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
			}
			return '';
		};

		// 1. Missing upload.
		unset( $_FILES['profile_file'] );
		WPS_Assert::same( 'profile_empty', $invoke_import() );

		// 2. Upload error code.
		$_FILES['profile_file'] = array(
			'name'     => 'profile.json',
			'type'     => 'application/json',
			'tmp_name' => '/tmp/somefile.json',
			'error'    => UPLOAD_ERR_INI_SIZE,
			'size'     => 500,
		);
		WPS_Assert::same( 'profile_upload_error', $invoke_import() );

		// 3. Oversized file (> 1MB).
		$_FILES['profile_file']['error'] = UPLOAD_ERR_OK;
		$_FILES['profile_file']['size']  = 1048577;
		WPS_Assert::same( 'profile_too_large', $invoke_import() );

		// 4. Invalid extension (non-JSON).
		$_FILES['profile_file']['size'] = 500;
		$_FILES['profile_file']['name'] = 'malicious.php';
		WPS_Assert::same( 'profile_invalid_type', $invoke_import() );

		// 5. Failed is_uploaded_file check.
		$_FILES['profile_file']['name'] = 'profile.json';
		$filter_disallow = static fn() => false;
		add_filter( 'wps_pepita_is_uploaded_file', $filter_disallow );
		WPS_Assert::same( 'profile_upload_invalid', $invoke_import() );
		remove_filter( 'wps_pepita_is_uploaded_file', $filter_disallow );

		unset( $_FILES['profile_file'] );
	}
);

/* -------------------------------------------------------------------------
 * 9. Profile Import Preview: Non-Destructive Diff Generation
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile import preview: non-destructive diff generation without persistent modification',
	static function (): void {
		Settings::update(
			array(
				'shipping_delay'        => 2,
				'price_adjustment_type' => 'none',
				'price_adjustment_value'=> 0.0,
			)
		);

		$term = WPS_Test_State::add_term( 2502, 'Kert', 0, Category_Rules::TAXONOMY );
		update_term_meta( 2502, Category_Rules::META_INCLUDE, 'include' );
		update_term_meta( 2502, Category_Rules::META_PRICE_VALUE, '5.0' );

		$proposed_profile = array(
			'schema_version' => 1,
			'generator'      => 'wp-pepita-sync',
			'profile_name'   => 'Staging Test Strategy',
			'exported_at'    => '2026-11-15T00:00:00Z',
			'settings'       => array(
				'shipping_delay'        => 7, // Proposed change
				'price_adjustment_type' => 'fixed', // Proposed change
				'price_adjustment_value'=> 1000.0, // Proposed change
			),
			'category_rules' => array(
				'kert' => array(
					'name'  => 'Kert',
					'rules' => array(
						'include'     => 'exclude', // Proposed change
						'price_value' => '10.0', // Proposed change
					),
				),
				'non-existent-category' => array(
					'name'  => 'Nem létező',
					'rules' => array(
						'include' => 'include',
					),
				),
			),
		);

		// Compute preview.
		$preview = Merchant_Profiles::preview_import( $proposed_profile );

		WPS_Assert::true( $preview['valid'], 'Preview should be valid.' );
		WPS_Assert::same( 3, count( $preview['settings_diff'] ), 'Three settings differences expected.' );
		WPS_Assert::same( 2, $preview['settings_diff']['shipping_delay']['current'] );
		WPS_Assert::same( 7, $preview['settings_diff']['shipping_delay']['proposed'] );

		WPS_Assert::true( isset( $preview['category_diff']['kert'] ), 'Kert category diff must exist.' );
		WPS_Assert::same( 'include', $preview['category_diff']['kert']['rule_diffs']['include']['current'] );
		WPS_Assert::same( 'exclude', $preview['category_diff']['kert']['rule_diffs']['include']['proposed'] );

		WPS_Assert::false( $preview['category_diff']['non-existent-category']['matched'], 'Unmatched category must be flagged.' );

		// CRITICAL: Verify NO persistent settings or term meta were modified by preview.
		WPS_Assert::same( 2, Settings::get( 'shipping_delay' ), 'Store settings must not be modified by preview.' );
		WPS_Assert::same( 'none', Settings::get( 'price_adjustment_type' ), 'Store settings must not be modified by preview.' );
		WPS_Assert::same( 'include', get_term_meta( 2502, Category_Rules::META_INCLUDE, true ), 'Term meta must not be modified by preview.' );
	}
);

/* -------------------------------------------------------------------------
 * 10. Profile Import Atomicity: Compensating Rollback
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile import atomicity: compensating rollback on partial category failure restores settings and term meta',
	static function (): void {
		// Set baseline store state.
		Settings::update(
			array(
				'shipping_delay'        => 3,
				'price_adjustment_type' => 'percent',
				'price_adjustment_value'=> 5.0,
			)
		);

		$term1 = WPS_Test_State::add_term( 2503, 'Bútor', 0, Category_Rules::TAXONOMY, 'butor' );
		update_term_meta( 2503, Category_Rules::META_INCLUDE, 'include' );
		update_term_meta( 2503, Category_Rules::META_PRICE_VALUE, '20.0' );

		$term2 = WPS_Test_State::add_term( 2504, 'Világítás', 0, Category_Rules::TAXONOMY, 'vilagitas' );
		update_term_meta( 2504, Category_Rules::META_INCLUDE, 'exclude' );
		update_term_meta( 2504, Category_Rules::META_PRICE_VALUE, '10.0' );

		// Profile proposing modifications for both categories.
		$import_payload = array(
			'schema_version' => 1,
			'generator'      => 'wp-pepita-sync',
			'profile_name'   => 'Failing Profile',
			'settings'       => array(
				'shipping_delay'        => 10,
				'price_adjustment_type' => 'fixed',
				'price_adjustment_value'=> 2500.0,
			),
			'category_rules' => array(
				'butor' => array(
					'name'  => 'Bútor',
					'rules' => array(
						'include'     => 'exclude',
						'price_value' => '99.0',
					),
				),
				'vilagitas' => array(
					'name'  => 'Világítás',
					'rules' => array(
						'include'     => 'include',
						'price_value' => '50.0',
					),
				),
			),
		);

		// Execute import with failure injected on second category (vilagitas).
		// First category (butor) gets applied first, then failure happens on vilagitas.
		$res = Merchant_Profiles::import_profile(
			$import_payload,
			array(
				'fail_on_slug' => 'vilagitas',
			)
		);

		// Verification: import reported failure and executed rollback.
		WPS_Assert::false( $res['success'], 'Import must fail when exception occurs.' );
		WPS_Assert::true( $res['rolled_back'], 'Rollback must be triggered.' );
		WPS_Assert::true( $res['rollback_clean'] ?? false, 'Rollback must be clean.' );

		// Verification: Settings MUST be completely restored to baseline.
		WPS_Assert::same( 3, Settings::get( 'shipping_delay' ), 'Settings must be rolled back to pre-import value.' );
		WPS_Assert::same( 'percent', Settings::get( 'price_adjustment_type' ), 'Settings must be rolled back to pre-import value.' );
		WPS_Assert::same( 5.0, Settings::get( 'price_adjustment_value' ), 'Settings must be rolled back to pre-import value.' );

		// Verification: Term metadata for butor MUST be completely restored to baseline pre-import state.
		WPS_Assert::same( 'include', get_term_meta( 2503, Category_Rules::META_INCLUDE, true ), 'Term meta for first category must be rolled back.' );
		WPS_Assert::same( '20.0', get_term_meta( 2503, Category_Rules::META_PRICE_VALUE, true ), 'Term meta for first category must be rolled back.' );

		// Verification: Term metadata for vilagitas remains untouched.
		WPS_Assert::same( 'exclude', get_term_meta( 2504, Category_Rules::META_INCLUDE, true ), 'Term meta for second category untouched.' );
		WPS_Assert::same( '10.0', get_term_meta( 2504, Category_Rules::META_PRICE_VALUE, true ), 'Term meta for second category untouched.' );

		// Verification: Journal option was cleaned up after rollback.
		WPS_Assert::false( get_option( Merchant_Profiles::JOURNAL_OPTION ), 'Recovery journal must be deleted after rollback.' );
	}
);

/* -------------------------------------------------------------------------
 * 11. Profile Checksum: Integrity Verification and Tampering Detection
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile checksum: verifies integrity and flags tampered profiles',
	static function (): void {
		$profile = Merchant_Profiles::export_profile( array( 'name' => 'Checksum Verification Profile' ) );

		WPS_Assert::true( ! empty( $profile['checksum'] ), 'Exported profile must have checksum.' );

		// 1. Untampered profile passes integrity check.
		WPS_Assert::true( Merchant_Profiles::verify_checksum( $profile ), 'Untampered profile must pass checksum validation.' );

		// 2. Tampered profile (altered setting value).
		$tampered = $profile;
		$tampered['settings']['shipping_delay'] = 999;
		WPS_Assert::false( Merchant_Profiles::verify_checksum( $tampered ), 'Tampered profile must fail checksum validation.' );

		// 3. Validation with required checksum.
		$res_valid = Merchant_Profiles::validate_profile( $profile, array( 'require_checksum' => true ) );
		WPS_Assert::true( $res_valid['valid'], 'Valid profile with checksum must pass validation.' );

		$res_tampered = Merchant_Profiles::validate_profile( $tampered, array( 'require_checksum' => true ) );
		WPS_Assert::false( $res_tampered['valid'], 'Tampered profile with require_checksum must fail validation.' );
	}
);

/* -------------------------------------------------------------------------
 * 12. Profile Two-Step Upload Preview & Confirmation Flow
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 profile two-step preview & confirmation flow',
	static function (): void {
		$admin = new Admin();
		$user_id = get_current_user_id();

		// Baseline setting.
		Settings::update( array( 'shipping_delay' => 1 ) );

		$profile_data = array(
			'schema_version' => 1,
			'generator'      => 'wp-pepita-sync',
			'profile_name'   => 'Two Step Test',
			'settings'       => array( 'shipping_delay' => 6 ),
			'category_rules' => array(),
		);

		$tmp_file = tempnam( sys_get_temp_dir(), 'pep_test_' );
		file_put_contents( $tmp_file, wp_json_encode( $profile_data ) );

		$_FILES['profile_file'] = array(
			'name'     => 'profile.json',
			'type'     => 'application/json',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp_file ),
		);

		// Filter to allow test temp file as uploaded.
		$allow_tmp = static fn( $is_up, $f ) => true;
		add_filter( 'wps_pepita_is_uploaded_file', $allow_tmp, 10, 2 );

		// Step 1: Upload initiates preview.
		$_POST['_wpnonce']    = wp_create_nonce( 'wps_pepita_import_profile' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$preview_redirect = '';
		try {
			$admin->handle_import_profile();
		} catch ( \RuntimeException $e ) {
			$preview_redirect = $e->getMessage();
		}

		WPS_Assert::true( str_contains( $preview_redirect, 'wps_preview=1' ), 'Upload must redirect to preview.' );

		// Verify settings remain UNMODIFIED after upload.
		WPS_Assert::same( 1, Settings::get( 'shipping_delay' ), 'Settings must NOT be updated upon initial upload.' );

		// Verify transient is created.
		$stage_key = 'wps_pepita_staged_profile_' . $user_id;
		$staged    = get_transient( $stage_key );
		WPS_Assert::true( is_array( $staged ) && ! empty( $staged['token'] ), 'Staged transient must exist.' );

		// Step 2: Confirmation applies the changes.
		$_POST['_wpnonce']       = wp_create_nonce( 'wps_pepita_confirm_import_profile' );
		$_REQUEST['_wpnonce']    = $_POST['_wpnonce'];
		$_POST['confirm_token']  = $staged['token'];

		$confirm_redirect = '';
		try {
			$admin->handle_confirm_import_profile();
		} catch ( \RuntimeException $e ) {
			$confirm_redirect = $e->getMessage();
		}

		WPS_Assert::true( str_contains( $confirm_redirect, 'wps_notice=profile_import_success' ), 'Confirmation must report success.' );

		// Verify settings ARE updated after explicit confirmation.
		WPS_Assert::same( 6, Settings::get( 'shipping_delay' ), 'Settings must be updated upon explicit confirmation.' );

		// Verify staging transient was cleaned up.
		WPS_Assert::true( false === get_transient( $stage_key ) || null === get_transient( $stage_key ), 'Staged transient must be deleted.' );

		// Cleanup.
		unlink( $tmp_file );
		unset( $_FILES['profile_file'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['confirm_token'] );
		remove_filter( 'wps_pepita_is_uploaded_file', $allow_tmp );
	}
);

/* -------------------------------------------------------------------------
 * 13. Crash Recovery: Persistent Journal Restores State
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 crash recovery: recover_interrupted_import restores store state from persistent journal',
	static function (): void {
		// Set store state to an uncommitted, dirty state (as if PHP crashed mid-write).
		Settings::update( array( 'shipping_delay' => 99 ) );
		$term = WPS_Test_State::add_term( 2505, 'Kert', 0, Category_Rules::TAXONOMY );
		update_term_meta( 2505, Category_Rules::META_PRICE_VALUE, '999.0' );

		// Journal contains the pre-crash baseline state.
		$journal = array(
			'timestamp'          => time() - 60,
			'original_settings'  => array( 'shipping_delay' => 2 ),
			'original_term_meta' => array(
				2505 => array(
					Category_Rules::META_PRICE_VALUE => array(
						'exists' => true,
						'value'  => '15.0',
					),
				),
			),
		);
		update_option( Merchant_Profiles::JOURNAL_OPTION, $journal, false );

		// Run recovery.
		$recovery = Merchant_Profiles::recover_interrupted_import();

		WPS_Assert::true( $recovery['recovered'], 'Recovery must report success when journal is found.' );
		WPS_Assert::true( $recovery['rollback_clean'], 'Recovery rollback must be clean.' );
		WPS_Assert::same( 2, Settings::get( 'shipping_delay' ), 'Settings must be restored from journal.' );
		WPS_Assert::same( '15.0', get_term_meta( 2505, Category_Rules::META_PRICE_VALUE, true ), 'Term meta must be restored from journal.' );
		WPS_Assert::false( get_option( Merchant_Profiles::JOURNAL_OPTION ), 'Journal must be deleted after recovery.' );

		// Subsequent call does nothing.
		$noop = Merchant_Profiles::recover_interrupted_import();
		WPS_Assert::false( $noop['recovered'], 'Subsequent recovery without journal must report false.' );

		// Active unresolved journal blocks subsequent import from proceeding or overwriting journal.
		update_option( Merchant_Profiles::JOURNAL_OPTION, $journal, false );
		WPS_Test_State::$fail_term_meta = true;

		$blocked_import = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'shipping_delay' => 5 ),
			)
		);
		WPS_Assert::false( $blocked_import['success'], 'Import must be blocked when an unresolved recovery journal exists.' );
		WPS_Assert::false( $blocked_import['rollback_clean'] ?? true, 'Rollback clean must be false on recovery failure.' );
		WPS_Assert::true( false !== get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'Recovery journal must NOT be deleted or overwritten when recovery fails.' );

		WPS_Test_State::$fail_term_meta = false;
		delete_option( Merchant_Profiles::JOURNAL_OPTION );
	}
);

/* -------------------------------------------------------------------------
 * 14. Checksum Validation Enforcement
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 checksum validation: fatal rejection on invalid, missing or malformed checksum',
	static function (): void {
		$profile = Merchant_Profiles::export_profile( array( 'name' => 'Checksum Enforcement Profile' ) );

		// 1. Checksum mismatch must be a FATAL validation error, not just a warning.
		$tampered = $profile;
		$tampered['checksum'] = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
		$res_tampered = Merchant_Profiles::validate_profile( $tampered );
		WPS_Assert::false( $res_tampered['valid'], 'Tampered checksum must be a fatal validation error.' );
		WPS_Assert::true( ! empty( $res_tampered['errors'] ), 'Errors must be populated on checksum mismatch.' );

		// 2. Malformed or empty checksum string must fail validation.
		$malformed = $profile;
		$malformed['checksum'] = '   ';
		$res_malformed = Merchant_Profiles::validate_profile( $malformed );
		WPS_Assert::false( $res_malformed['valid'], 'Whitespace checksum must fail validation.' );

		$non_string = $profile;
		$non_string['checksum'] = 12345;
		$res_non_string = Merchant_Profiles::validate_profile( $non_string );
		WPS_Assert::false( $res_non_string['valid'], 'Non-string checksum must fail validation.' );

		$null_checksum = $profile;
		$null_checksum['checksum'] = null;
		$res_null = Merchant_Profiles::validate_profile( $null_checksum );
		WPS_Assert::false( $res_null['valid'], 'Null checksum must fail validation.' );

		// 3. Missing checksum when require_checksum is true.
		$missing = $profile;
		unset( $missing['checksum'] );
		$res_missing = Merchant_Profiles::validate_profile( $missing, array( 'require_checksum' => true ) );
		WPS_Assert::false( $res_missing['valid'], 'Missing checksum with require_checksum must fail.' );
	}
);

/* -------------------------------------------------------------------------
 * 15. Operational Reports: CSV Export Truncation Disclosure
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 operational report: CSV export truncation disclosure when bounded limit is reached',
	static function (): void {
		global $wpdb;

		// Seed orders up to MAX_EXPORT_ROWS limit.
		$table = Order_Repository::table();
		for ( $i = 1; $i <= Operational_Reports::MAX_EXPORT_ROWS; ++$i ) {
			$wpdb->insert(
				$table,
				array(
					'id'          => 3000 + $i,
					'external_id' => 'PEP-BOUNDED-' . $i,
					'origin'      => 'hu',
					'status'      => 'completed',
					'wc_order_id' => 8000 + $i,
					'attempts'    => 1,
					'last_error'  => null,
					'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
					'updated_at'  => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				)
			);
		}

		$csv = Operational_Reports::export_report_csv( 30 );
		WPS_Assert::true(
			str_contains( $csv, '# MEGJEGYZÉS: RÉSZLEGES EXPORT (A legutóbbi 1 000 rekord alapján korlátozva).' ),
			'CSV must disclose truncation when 1,000 row bound is reached.'
		);

		$summary = Operational_Reports::get_summary( 30 );
		WPS_Assert::true( $summary['is_partial_data'], 'Summary must flag is_partial_data when limit reached.' );
	}
);

/* -------------------------------------------------------------------------
 * 16. Concurrency Locking & Rollback Missing Setting Integrity
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 concurrency and integrity: exclusive import lock and missing setting rollback detection',
	static function (): void {
		// 1. Exclusive Import Lock prevents concurrent imports.
		$token = Merchant_Profiles::acquire_import_lock();
		WPS_Assert::true( ! empty( $token ), 'Acquiring import lock must return token.' );
		WPS_Assert::true( Merchant_Profiles::is_import_locked(), 'is_import_locked must return true while locked.' );

		// Concurrent import attempt while lock is active.
		$concurrent = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'shipping_delay' => 3 ),
			)
		);
		WPS_Assert::false( $concurrent['success'], 'Concurrent import must fail while locked.' );
		WPS_Assert::true( str_contains( $concurrent['message'], 'folyamatban' ), 'Message must indicate import is in progress.' );

		// Active lock prevents recover_interrupted_import from modifying active journal.
		$active_journal = array(
			'timestamp'         => time(),
			'original_settings' => array( 'shipping_delay' => 1 ),
		);
		update_option( Merchant_Profiles::JOURNAL_OPTION, $active_journal, false );

		$rec_while_locked = Merchant_Profiles::recover_interrupted_import();
		WPS_Assert::false( $rec_while_locked['recovered'], 'Recovery must not run while import is actively locked.' );
		WPS_Assert::true( false !== get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'Active journal must remain untouched while locked.' );

		// Release lock.
		Merchant_Profiles::release_import_lock( $token );
		WPS_Assert::false( Merchant_Profiles::is_import_locked(), 'is_import_locked must return false after release.' );

		// 2. Interrupted import recovery when called via import_profile().
		$stale_journal = array(
			'timestamp'         => time() - 3600,
			'original_settings' => array( 'shipping_delay' => 1 ),
		);
		update_option( Merchant_Profiles::JOURNAL_OPTION, $stale_journal, false );

		$recovered_import = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'shipping_delay' => 4 ),
			)
		);
		WPS_Assert::true( $recovered_import['success'], 'import_profile must successfully recover previous stale journal and apply import.' );
		WPS_Assert::same( 4, Settings::get( 'shipping_delay' ), 'Imported setting must be active.' );

		// 3. Recovery restores portable settings only: non-portable / unknown keys in a journal are ignored.
		delete_option( Merchant_Profiles::JOURNAL_OPTION );
		$journal_with_missing_key = array(
			'timestamp'         => time(),
			'original_settings' => array( 'non_existent_key_xyz' => 'some_val' ),
		);
		update_option( Merchant_Profiles::JOURNAL_OPTION, $journal_with_missing_key, false );

		$rec_missing = Merchant_Profiles::recover_interrupted_import();
		WPS_Assert::true( $rec_missing['recovered'], 'Recovery ran.' );
		WPS_Assert::true( $rec_missing['rollback_clean'], 'A non-portable journal key is ignored, not restored.' );
		WPS_Assert::false( get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'The processed journal is removed.' );

		delete_option( Merchant_Profiles::JOURNAL_OPTION );

		// 6. delete_option_if_token prefix and exact token validation.
		update_option( 'wps_pepita_test_token_opt', array( 'token' => 'exact_secret_token_123' ), false );
		WPS_Assert::false( Merchant_Profiles::delete_option_if_token( 'wps_pepitax_invalid_prefix', 'exact_secret_token_123' ), 'delete_option_if_token must reject unapproved prefix.' );
		WPS_Assert::false( Merchant_Profiles::delete_option_if_token( 'wps_pepita_test_token_opt', 'secret_token' ), 'delete_option_if_token must reject partial token match.' );
		WPS_Assert::true( Merchant_Profiles::delete_option_if_token( 'wps_pepita_test_token_opt', 'exact_secret_token_123' ), 'delete_option_if_token must accept exact token and delete option.' );
		WPS_Assert::false( get_option( 'wps_pepita_test_token_opt', false ), 'Option must be deleted.' );

	}
);

/* -------------------------------------------------------------------------
 * 17. Review remediation: fencing, journal integrity, portability
 * ---------------------------------------------------------------------- */

$suite->add(
	'm11 lock fencing: renew requires ownership and a lost lock aborts without writes or rollback',
	static function (): void {
		delete_option( Merchant_Profiles::JOURNAL_OPTION );
		delete_option( Merchant_Profiles::LOCK_OPTION );

		$token = Merchant_Profiles::acquire_import_lock();
		WPS_Assert::true( ! empty( $token ), 'Lock acquired.' );
		WPS_Assert::true( Merchant_Profiles::renew_import_lock( $token ), 'Owner can renew its lease.' );
		WPS_Assert::false( Merchant_Profiles::renew_import_lock( 'not-the-owner' ), 'A non-owner must not renew the lease.' );
		WPS_Assert::false( Merchant_Profiles::renew_import_lock( null ), 'Empty token must not renew.' );
		Merchant_Profiles::release_import_lock( $token );
		WPS_Assert::false( Merchant_Profiles::renew_import_lock( $token ), 'A released lock cannot be renewed.' );

		Settings::update( array( 'shipping_delay' => 2, 'order_api_key' => 'CURRENT_KEY_ABC' ) );

		$res = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'shipping_delay' => 6 ),
			),
			array( 'simulate_lock_loss' => true )
		);
		WPS_Assert::false( $res['success'], 'Import must not report success after losing its lock.' );
		WPS_Assert::false( $res['rolled_back'], 'A former lock owner must not roll back.' );

		$journal = get_option( Merchant_Profiles::JOURNAL_OPTION, false );
		WPS_Assert::true( is_array( $journal ), 'Recovery journal must be retained for the current owner.' );
		WPS_Assert::true( array_key_exists( 'shipping_delay', $journal['original_settings'] ), 'Journal covers the changed key.' );
		WPS_Assert::false( array_key_exists( 'order_api_key', $journal['original_settings'] ), 'Journal must never contain secrets.' );
		WPS_Assert::false( array_key_exists( 'feed_token', $journal['original_settings'] ), 'Journal must never contain secrets.' );
		WPS_Assert::same( 'CURRENT_KEY_ABC', Settings::get( 'order_api_key' ), 'Secrets are never touched by import.' );

		// The next import recovers (restores shipping_delay=2) and then applies its own change.
		$next = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'shipping_delay' => 4 ),
			)
		);
		WPS_Assert::true( $next['success'], 'Next import recovers the retained journal and succeeds.' );
		WPS_Assert::same( 4, Settings::get( 'shipping_delay' ), 'Next import applied.' );
		WPS_Assert::false( get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'Journal removed after durable finalization.' );
	}
);

$suite->add(
	'm11 journal integrity: completed journals are never undone and legacy secrets are never restored',
	static function (): void {
		delete_option( Merchant_Profiles::LOCK_OPTION );

		Settings::update( array( 'shipping_delay' => 7 ) );
		update_option(
			Merchant_Profiles::JOURNAL_OPTION,
			array(
				'timestamp'         => time() - 10,
				'completed'         => true,
				'original_settings' => array( 'shipping_delay' => 1 ),
			),
			false
		);
		$rec = Merchant_Profiles::recover_interrupted_import();
		WPS_Assert::false( $rec['recovered'], 'A completed journal must not be treated as an interrupted import.' );
		WPS_Assert::same( 7, Settings::get( 'shipping_delay' ), 'A successful import must not be undone by a retained completed journal.' );
		WPS_Assert::false( get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'Completed journal is cleaned up.' );

		// Legacy full-snapshot journal containing secrets: secrets must not be restored.
		Settings::update( array( 'order_api_key' => 'ROTATED_NEW_KEY' ) );
		update_option(
			Merchant_Profiles::JOURNAL_OPTION,
			array(
				'timestamp'         => time() - 10,
				'original_settings' => array(
					'shipping_delay' => 2,
					'order_api_key'  => 'OLD_LEAKED_KEY',
					'feed_token'     => 'OLD_FEED_TOKEN',
				),
			),
			false
		);
		$rec2 = Merchant_Profiles::recover_interrupted_import();
		WPS_Assert::true( $rec2['recovered'], 'Interrupted journal is recovered.' );
		WPS_Assert::same( 2, Settings::get( 'shipping_delay' ), 'Non-secret setting restored.' );
		WPS_Assert::same( 'ROTATED_NEW_KEY', Settings::get( 'order_api_key' ), 'A rotated secret must not be rolled back to an old value.' );
	}
);

$suite->add(
	'm11 portability: numeric category-ID settings are not exported or imported',
	static function (): void {
		delete_option( Merchant_Profiles::LOCK_OPTION );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );

		WPS_Assert::false( in_array( 'gtin_required_categories', Merchant_Profiles::PORTABLE_SETTINGS_ALLOWLIST, true ), 'Store-specific term IDs must not be portable.' );

		Settings::update( array( 'gtin_required_categories' => array( 10 ) ) );
		$profile = Merchant_Profiles::export_profile();
		WPS_Assert::false( array_key_exists( 'gtin_required_categories', $profile['settings'] ), 'Export must not contain numeric term IDs.' );

		Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'gtin_required_categories' => array( 99 ), 'shipping_delay' => 3 ),
			)
		);
		WPS_Assert::same( array( 10 ), Settings::gtin_required_categories(), 'Import must not overwrite destination term IDs.' );
	}
);

$suite->add(
	'm11 lock/journal ownership: exact-state reclaim, owner-bound journal and no foreign journal deletion',
	static function (): void {
		delete_option( Merchant_Profiles::LOCK_OPTION );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );

		// A lease renewed after a reclaimer observed it must not be reclaimed (exact token+expiry match).
		$token = Merchant_Profiles::acquire_import_lock();
		$seen  = get_option( Merchant_Profiles::LOCK_OPTION );
		WPS_Assert::true( is_array( $seen ), 'Lock row exists.' );
		$observed_expiry = (int) $seen['expires'] - 1000; // reclaimer observed an older, expired state.
		WPS_Assert::false( Merchant_Profiles::delete_lock_if_stale( Merchant_Profiles::LOCK_OPTION, $token, $observed_expiry ), 'A renewed lease must not be deleted by a stale observation.' );
		WPS_Assert::true( Merchant_Profiles::is_import_locked(), 'Lock must survive the stale reclaim attempt.' );
		WPS_Assert::true( Merchant_Profiles::delete_lock_if_stale( Merchant_Profiles::LOCK_OPTION, $token, (int) $seen['expires'] ), 'Exact observed state may be reclaimed.' );
		WPS_Assert::false( Merchant_Profiles::is_import_locked(), 'Lock removed after exact match.' );

		// Journal replaced by another owner before finalization: the stale owner stops and leaves it untouched.
		Settings::update( array( 'shipping_delay' => 2 ) );
		$res = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'shipping_delay' => 5 ),
			),
			array( 'simulate_journal_replaced' => true )
		);
		WPS_Assert::false( $res['success'], 'Import must not succeed when its journal was replaced.' );
		WPS_Assert::false( $res['rolled_back'], 'A non-owner must not roll back.' );
		$foreign = get_option( Merchant_Profiles::JOURNAL_OPTION, false );
		WPS_Assert::true( is_array( $foreign ) && 'other-owner' === ( $foreign['token'] ?? '' ), 'Foreign journal must be left untouched.' );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );
		delete_option( Merchant_Profiles::LOCK_OPTION );

		// A journal created by an import carries the lock token (owner-bound).
		$res2 = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'shipping_delay' => 6 ),
			),
			array( 'simulate_lock_loss' => true )
		);
		$j = get_option( Merchant_Profiles::JOURNAL_OPTION, false );
		WPS_Assert::true( is_array( $j ) && '' !== (string) ( $j['token'] ?? '' ), 'Journal must be bound to the owner token.' );
		WPS_Assert::false( $res2['success'], 'Lock-lost import fails.' );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );
		delete_option( Merchant_Profiles::LOCK_OPTION );

		// Origin/identifier patterns reject trailing newlines.
		WPS_Assert::same( 'other', Operational_Reports::safe_origin( "hu\n" ), 'Origin with trailing newline must not pass.' );
		WPS_Assert::true( str_starts_with( Operational_Reports::safe_identifier( "ID1\n" ), 'hash:' ), 'Identifier with trailing newline must be hashed.' );
	}
);

$suite->add(
	'm11 recovery fencing: a recovery without lock ownership restores nothing and deletes nothing',
	static function (): void {
		delete_option( Merchant_Profiles::LOCK_OPTION );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );

		Settings::update( array( 'shipping_delay' => 9 ) );
		update_option(
			Merchant_Profiles::JOURNAL_OPTION,
			array(
				'token'             => 'owner-b',
				'timestamp'         => time() - 10,
				'original_settings' => array( 'shipping_delay' => 1 ),
			),
			false
		);

		// A recovery holding a token that does not own the lock (lease taken over) must abort untouched.
		$lost = Merchant_Profiles::recover_interrupted_import( 'stale-owner-a' );
		WPS_Assert::false( $lost['recovered'], 'A recovery without ownership must not report recovery.' );
		WPS_Assert::false( $lost['rollback_clean'], 'Lost recovery lease must be reported as not clean.' );
		WPS_Assert::same( 9, Settings::get( 'shipping_delay' ), 'Stale recovery must not restore its snapshot.' );
		$kept = get_option( Merchant_Profiles::JOURNAL_OPTION, false );
		WPS_Assert::true( is_array( $kept ) && 'owner-b' === ( $kept['token'] ?? '' ), 'Stale recovery must not delete the current journal.' );

		// A proper recovery under its own lock restores and removes exactly the observed journal.
		$ok = Merchant_Profiles::recover_interrupted_import();
		WPS_Assert::true( $ok['recovered'] && $ok['rollback_clean'], 'Owned recovery succeeds.' );
		WPS_Assert::same( 1, Settings::get( 'shipping_delay' ), 'Owned recovery restores the journal snapshot.' );
		WPS_Assert::false( get_option( Merchant_Profiles::JOURNAL_OPTION, false ), 'Observed journal removed.' );

		// A blocked import (lost recovery) must refuse to proceed.
		update_option(
			Merchant_Profiles::JOURNAL_OPTION,
			array( 'token' => 'owner-c', 'timestamp' => time() - 10, 'original_settings' => array( 'shipping_delay' => 3 ) ),
			false
		);
		$rec = Merchant_Profiles::recover_interrupted_import( 'stale-owner-a' );
		WPS_Assert::false( $rec['rollback_clean'], 'Unowned recovery is not clean.' );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );
		delete_option( Merchant_Profiles::LOCK_OPTION );
	}
);
