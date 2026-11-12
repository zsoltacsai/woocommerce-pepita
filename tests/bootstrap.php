<?php
/**
 * Test bootstrap: WordPress/WooCommerce stubs + plugin autoloader.
 *
 * @package WPS\Pepita\Tests
 */

define( 'ABSPATH', __DIR__ . '/stubs/' );
define( 'WP_CONTENT_DIR', '/tmp/opencode/wps-tests/wp-content' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );

define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

define( 'WPS_PEPITA_VERSION', '1.0.0' );
define( 'WPS_PEPITA_DB_VERSION', '1.0.0' );
define( 'WPS_PEPITA_FILE', dirname( __DIR__ ) . '/woocommerce-pepita.php' );
define( 'WPS_PEPITA_DIR', dirname( __DIR__ ) . '/' );
define( 'WPS_PEPITA_URL', 'https://example.test/wp-content/plugins/wp-pepita-sync/' );
define( 'WC_VERSION', '10.0.0' );

require_once ABSPATH . 'class-wp-stubs.php';
require_once ABSPATH . 'class-wc-stubs.php';
require_once ABSPATH . 'class-fake-wpdb.php';
require_once __DIR__ . '/helpers/class-wps-assert.php';
require_once __DIR__ . '/helpers/class-wps-suite.php';

require_once WPS_PEPITA_DIR . 'includes/autoload.php';

/**
 * Create a fresh fake database with the plugin tables.
 */
function wps_test_new_wpdb(): Fake_WPDB {
	$wpdb = new Fake_WPDB();

	$wpdb->register_table(
		'wp_wps_pepita_logs',
		array( 'id', 'created_at', 'level', 'event', 'message', 'context', 'object_type', 'object_id' )
	);

	$wpdb->register_table(
		'wp_wps_pepita_orders',
		array( 'id', 'external_id', 'origin', 'status', 'wc_order_id', 'payload', 'payload_hash', 'attempts', 'last_error', 'created_at', 'updated_at' ),
		'external_id'
	);

	return $wpdb;
}

function wps_test_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = glob( $dir . '/*' );

	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( is_dir( $item ) ) {
				wps_test_rrmdir( $item );
				continue;
			}

			@unlink( $item );
		}
	}

	$hidden = glob( $dir . '/.*' );

	if ( is_array( $hidden ) ) {
		foreach ( $hidden as $item ) {
			if ( '.' === basename( $item ) || '..' === basename( $item ) ) {
				continue;
			}

			if ( is_dir( $item ) ) {
				wps_test_rrmdir( $item );
				continue;
			}

			@unlink( $item );
		}
	}

	@rmdir( $dir );
}

/**
 * Reset every piece of global state between tests.
 */
function wps_test_reset_env(): void {
	WPS_Test_State::reset();

	$GLOBALS['wpdb'] = wps_test_new_wpdb();

	\WPS\Pepita\Settings::reset_cache();
	\WPS\Pepita\Storage::reset();
	\WPS\Pepita\Logger::reset_table_cache();
	\WPS\Pepita\Orders\Order_Repository::reset_table_cache();
	\WPS\Pepita\Catalog\Product_Selection::clear_cache();
	\WPS\Pepita\Catalog\Category_Rules::clear_cache();

	$property = new \ReflectionProperty( \WPS\Pepita\Catalog\Price_Calculator::class, 'vat_cache' );
	$property->setValue( null, array() );

	wps_test_rrmdir( WP_CONTENT_DIR );

	if ( ! is_dir( WP_CONTENT_DIR ) ) {
		mkdir( WP_CONTENT_DIR, 0775, true );
	}

	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	$_SERVER  = array(
		'REMOTE_ADDR'     => '127.0.0.1',
		'HTTP_HOST'       => 'example.test',
		'SERVER_NAME'     => 'example.test',
		'REQUEST_METHOD'  => 'GET',
	);
}

wps_test_reset_env();
