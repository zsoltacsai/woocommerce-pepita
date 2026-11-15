<?php
/**
 * Order API default-OFF safety (RC2 P0).
 *
 * Covers: fresh install, missing settings, REST 503 (unauthenticated and authenticated),
 * explicit opt-in, legacy-upgrade fail-closed migration, settings save, beta mode and
 * configuration profile import. HTTP is exercised through the real Api_Controller.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Admin\Merchant_Profiles;
use WPS\Pepita\Installer;
use WPS\Pepita\Orders\Api_Controller;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/**
 * @return array<string, mixed>
 */
$wps_rc2_payload = static function (): array {
	return array(
		'origin'               => 'pepita.hu',
		'id'                   => 'PEP-RC2-1',
		'payment_mode'         => 'transfer',
		'payment_status'       => 'paid',
		'total_shipping_price' => 0,
		'delivery_mod'         => 'gls',
		'customer'             => array( 'email' => 'rc2@example.test' ),
		'products'             => array(
			array(
				'sku'      => 'SKU-RC2',
				'currency' => 'HUF',
				'quantity' => 1,
				'price'    => 1000,
				'vat'      => 27,
			),
		),
	);
};

/**
 * POST to the real controller over TLS.
 *
 * @param array<string, mixed> $params Query params (apikey).
 */
$wps_rc2_post = static function ( array $params = array() ) use ( $wps_rc2_payload ) {
	WPS_Test_State::$ssl = true;
	$request             = new WP_REST_Request( $params, (string) wp_json_encode( $wps_rc2_payload() ), array() );

	return ( new Api_Controller() )->handle( $request );
};

/**
 * Simulate a legacy (pre-RC2) install by writing the persisted flag directly, bypassing the guarded API.
 */
$wps_rc2_force_on = static function (): void {
	$stored                      = get_option( Settings::OPTION, array() );
	$stored['order_api_enabled'] = true;
	update_option( Settings::OPTION, $stored, false );
	Settings::reset_cache();
};

$suite->add(
	'order api default off: defaults, missing settings and fresh activation disable order intake',
	static function () use ( $wps_rc2_post ): void {
		WPS_Assert::false( (bool) Settings::defaults()['order_api_enabled'], 'Default must be OFF.' );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Missing settings must read as OFF.' );

		Installer::activate();

		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Fresh activation must leave the API OFF.' );
		WPS_Assert::false( (bool) ( get_option( Settings::OPTION )['order_api_enabled'] ?? true ), 'Persisted flag must be false after activation.' );
		WPS_Assert::true( strlen( (string) Settings::secret( 'order_api_key' ) ) >= 32, 'A key is still generated for later opt-in.' );
		WPS_Assert::same( '1', (string) get_option( Installer::ORDER_API_MIGRATION_OPTION, '' ), 'Migration marker is set on a fresh install.' );
		WPS_Assert::false( get_option( Installer::ORDER_API_LEGACY_DISABLED_OPTION, false ), 'A fresh install is not a legacy downgrade.' );

		// REST: unauthenticated and authenticated requests are both refused with 503.
		$unauth = $wps_rc2_post();
		WPS_Assert::same( 503, $unauth->get_status(), 'Unauthenticated request must receive 503 while OFF.' );
		WPS_Assert::same( true, $unauth->get_data()['isError'] );

		$key  = (string) Settings::secret( 'order_api_key' );
		$auth = $wps_rc2_post( array( 'apikey' => $key ) );
		WPS_Assert::same( 503, $auth->get_status(), 'An authenticated request must also receive 503 while OFF.' );
		WPS_Assert::same( 0, count( WPS_Test_State::$orders ), 'No order may be created while OFF.' );
	}
);

$suite->add(
	'order api default off: legacy persisted true without verified opt-in is switched off on upgrade, credentials kept',
	static function () use ( $wps_rc2_post, $wps_rc2_force_on ): void {
		Installer::activate();
		$key = (string) Settings::secret( 'order_api_key' );

		// Simulate an install created by an earlier build: enabled by default, no migration marker, no opt-in record.
		$wps_rc2_force_on();
		delete_option( Installer::ORDER_API_MIGRATION_OPTION );
		delete_option( Installer::ORDER_API_OPTIN_OPTION );
		delete_option( Installer::ORDER_API_LEGACY_DISABLED_OPTION );
		WPS_Assert::true( (bool) Settings::get( 'order_api_enabled' ), 'Precondition: legacy state is enabled.' );

		Installer::maybe_upgrade();

		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Unverified legacy enablement must be switched off.' );
		WPS_Assert::same( $key, (string) Settings::secret( 'order_api_key' ), 'Existing credentials must not be regenerated.' );
		WPS_Assert::true( (int) get_option( Installer::ORDER_API_LEGACY_DISABLED_OPTION, 0 ) > 0, 'The downgrade must be recorded for the admin notice.' );
		WPS_Assert::same( '1', (string) get_option( Installer::ORDER_API_MIGRATION_OPTION, '' ) );
		WPS_Assert::same( 503, $wps_rc2_post( array( 'apikey' => $key ) )->get_status(), 'Endpoint must refuse after the migration.' );

		// A later plugin update must not silently re-enable it.
		Installer::maybe_upgrade();
		Settings::update( array( 'default_included' => true ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Updates and unrelated saves must not re-enable intake.' );
	}
);

$suite->add(
	'order api default off: a verified explicit opt-in record survives the upgrade',
	static function (): void {
		Installer::activate();
		WPS_Assert::true( Settings::enable_order_api( 1, 'test' ), 'Explicit enablement must work outside beta.' );
		delete_option( Installer::ORDER_API_MIGRATION_OPTION );

		Installer::maybe_upgrade();

		WPS_Assert::true( (bool) Settings::get( 'order_api_enabled' ), 'A deliberate, recorded opt-in must be preserved.' );
		WPS_Assert::false( get_option( Installer::ORDER_API_LEGACY_DISABLED_OPTION, false ), 'No downgrade is recorded for a verified opt-in.' );
	}
);

$suite->add(
	'order api default off: only an explicit admin save enables intake and records the opt-in',
	static function () use ( $wps_rc2_post ): void {
		Installer::activate();
		WPS_Test_State::$current_user_caps['manage_woocommerce'] = true;

		$save = static function ( array $wps ): void {
			$_POST['_wpnonce']    = wp_create_nonce( 'wps_pepita_save_settings' );
			$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
			$_POST['wps']         = $wps;
			try {
				( new Admin() )->handle_save_settings();
			} catch ( \RuntimeException $e ) {
				if ( ! str_starts_with( $e->getMessage(), 'redirect:' ) ) {
					throw $e;
				}
			}
		};

		// A save that does not tick the checkbox keeps it OFF and writes no opt-in.
		$save( array( 'shipping_delay' => '3' ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'A save without the checkbox must keep intake OFF.' );
		WPS_Assert::false( get_option( Installer::ORDER_API_OPTIN_OPTION, false ), 'No opt-in is recorded without the checkbox.' );

		// Explicit opt-in.
		$save( array( 'order_api_enabled' => '1' ) );
		WPS_Assert::true( (bool) Settings::get( 'order_api_enabled' ), 'Ticking the checkbox enables intake.' );
		$optin = get_option( Installer::ORDER_API_OPTIN_OPTION, false );
		WPS_Assert::true( is_array( $optin ) && isset( $optin['time'] ), 'The explicit opt-in is recorded.' );
		WPS_Assert::true( 503 !== $wps_rc2_post( array( 'apikey' => 'wrong-key' ) )->get_status(), 'When ON the endpoint proceeds to authentication (not 503).' );

		// Unticking turns it off again.
		$save( array( 'shipping_delay' => '3' ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Unticking disables intake.' );
	}
);

$suite->add(
	'order api default off: beta feed-only never enables intake, and leaving beta does not re-enable it',
	static function (): void {
		Installer::activate();

		Settings::update( array( 'beta_feed_only' => true ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Beta mode keeps intake OFF.' );

		// Even an explicit attempt to enable while beta is active is refused and not persisted.
		WPS_Assert::false( Settings::enable_order_api( 1, 'test' ), 'enable_order_api must refuse under beta.' );
		Settings::update( array( 'order_api_enabled' => true ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ) );
		WPS_Assert::false( (bool) ( get_option( Settings::OPTION )['order_api_enabled'] ?? true ), 'Beta must not persist an enabled flag.' );

		// Leaving beta: intake stays OFF (no implicit activation).
		Settings::update( array( 'beta_feed_only' => false ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Disabling beta must not activate order intake.' );
	}
);

$suite->add(
	'order api default off: configuration profile import can never enable intake',
	static function (): void {
		Installer::activate();

		WPS_Assert::false( in_array( 'order_api_enabled', Merchant_Profiles::PORTABLE_SETTINGS_ALLOWLIST, true ), 'order_api_enabled must not be portable.' );

		$res = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array(
					'order_api_enabled' => true,
					'shipping_delay'    => 4,
				),
			)
		);
		WPS_Assert::true( $res['success'], 'The profile import must succeed (the switch is simply ignored): ' . $res['message'] );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Imported profile must not enable intake.' );

		// Exported profiles never carry the flag either.
		$profile = Merchant_Profiles::export_profile();
		WPS_Assert::false( array_key_exists( 'order_api_enabled', $profile['settings'] ), 'Export must not contain order_api_enabled.' );
	}
);

$suite->add(
	'order api default off: no generic path can switch intake on (direct update, merge, restore)',
	static function (): void {
		Installer::activate();
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ) );

		Settings::update( array( 'order_api_enabled' => true ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Settings::update must not perform the OFF -> ON transition.' );
		Settings::update( array( 'order_api_enabled' => '1', 'default_included' => true ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Truthy strings must not enable intake either.' );
		WPS_Assert::false( get_option( Installer::ORDER_API_OPTIN_OPTION, false ), 'No opt-in record without the audited path.' );

		// Already enabled stays enabled through unrelated updates.
		WPS_Assert::true( Settings::enable_order_api( 7, 'Test Source!' ), 'Audited enablement works.' );
		Settings::update( array( 'shipping_delay' => 5 ) );
		WPS_Assert::true( (bool) Settings::get( 'order_api_enabled' ), 'Unrelated updates keep an enabled flag.' );
		$optin = get_option( Installer::ORDER_API_OPTIN_OPTION, false );
		WPS_Assert::same( 7, (int) $optin['user'], 'The acting user is recorded.' );
		WPS_Assert::same( 'testsource', (string) $optin['source'], 'The audit source is sanitized.' );
		Settings::update( array( 'order_api_enabled' => false ) );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Switching OFF stays possible through update.' );
	}
);

$suite->add(
	'order api default off: a legacy journal snapshot can never switch intake back on',
	static function (): void {
		Installer::activate();
		delete_option( Merchant_Profiles::LOCK_OPTION );
		delete_option( Merchant_Profiles::JOURNAL_OPTION );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ) );

		update_option(
			Merchant_Profiles::JOURNAL_OPTION,
			array(
				'timestamp'         => time() - 10,
				'original_settings' => array(
					'shipping_delay'    => 2,
					'order_api_enabled' => true,
					'feed_public'       => true,
					'beta_feed_only'    => false,
					'order_api_key'     => 'OLD_KEY_FROM_SNAPSHOT',
				),
			),
			false
		);

		$res = Merchant_Profiles::import_profile(
			array(
				'schema_version' => 1,
				'generator'      => 'wp-pepita-sync',
				'settings'       => array( 'shipping_delay' => 3 ),
			)
		);
		WPS_Assert::true( $res['success'], 'The import recovers the legacy journal and proceeds: ' . $res['message'] );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'A journal restore must not enable intake.' );
		WPS_Assert::false( (bool) Settings::get( 'feed_public' ), 'A journal restore must not change security posture.' );
		WPS_Assert::true( 'OLD_KEY_FROM_SNAPSHOT' !== Settings::secret( 'order_api_key' ), 'A journal restore must not restore secrets.' );
	}
);

$suite->add(
	'order api default off: constant-style beta persists OFF so leaving beta never resumes intake; malformed opt-in does not count',
	static function () use ( $wps_rc2_force_on ): void {
		Installer::activate();

		// An already enabled, migrated store enters beta without any settings save (the constant case).
		WPS_Assert::true( Settings::enable_order_api( 1, 'test' ) );
		$stored                   = get_option( Settings::OPTION, array() );
		$stored['beta_feed_only'] = true;
		update_option( Settings::OPTION, $stored, false );
		Settings::reset_cache();
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Beta view is OFF.' );
		WPS_Assert::true( ! empty( get_option( Settings::OPTION )['order_api_enabled'] ), 'Precondition: the persisted flag is still true.' );

		Installer::maybe_upgrade();
		WPS_Assert::false( ! empty( get_option( Settings::OPTION )['order_api_enabled'] ), 'Entering beta must persist the flag as OFF.' );

		// Leaving beta (constant removed): intake stays OFF until a new explicit opt-in.
		$stored                   = get_option( Settings::OPTION, array() );
		$stored['beta_feed_only'] = false;
		update_option( Settings::OPTION, $stored, false );
		Settings::reset_cache();
		Installer::maybe_upgrade();
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'Leaving beta must not resume intake.' );

		// A malformed / empty opt-in record is not a verified opt-in.
		$wps_rc2_force_on();
		update_option( Installer::ORDER_API_OPTIN_OPTION, array(), false );
		delete_option( Installer::ORDER_API_MIGRATION_OPTION );
		Installer::maybe_upgrade();
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'A malformed opt-in record must not preserve a legacy enabled state.' );
	}
);

$suite->add(
	'order api default off: the admin save is the audited path and rejects missing capability',
	static function (): void {
		Installer::activate();

		$_POST['_wpnonce']    = wp_create_nonce( 'wps_pepita_save_settings' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		$_POST['wps']         = array( 'order_api_enabled' => '1' );

		WPS_Test_State::$current_user_caps['manage_woocommerce'] = false;
		$rejected = false;
		try {
			( new Admin() )->handle_save_settings();
		} catch ( \RuntimeException $e ) {
			$rejected = str_contains( $e->getMessage(), 'Nincs jogosultsága' );
		}
		WPS_Test_State::$current_user_caps['manage_woocommerce'] = true;

		WPS_Assert::true( $rejected, 'A user without manage_woocommerce must be rejected.' );
		WPS_Assert::false( (bool) Settings::get( 'order_api_enabled' ), 'A rejected save must not enable intake.' );
	}
);
