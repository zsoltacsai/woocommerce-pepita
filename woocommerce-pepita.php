<?php
/**
 * Plugin Name:       WP Pepita Sync
 * Plugin URI:        https://github.com/zsoltacsai/woocommerce-pepita
 * Description:       WooCommerce és Pepita piactér integráció: Pepita XML termékfeed, külön készlet- és árfeed, valamint automatikus Pepita rendelésfogadás. Magyar adminisztrációs felület.
 * Version:           1.0.0
 * Author:            Pepita integráció
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-pepita-sync
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.0
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

define( 'WPS_PEPITA_VERSION', '1.0.0' );
define( 'WPS_PEPITA_DB_VERSION', '1.0.0' );
define( 'WPS_PEPITA_FILE', __FILE__ );
define( 'WPS_PEPITA_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPS_PEPITA_URL', plugin_dir_url( __FILE__ ) );

require_once WPS_PEPITA_DIR . 'includes/autoload.php';

register_activation_hook( WPS_PEPITA_FILE, array( \WPS\Pepita\Installer::class, 'activate' ) );
register_deactivation_hook( WPS_PEPITA_FILE, array( \WPS\Pepita\Installer::class, 'deactivate' ) );

\WPS\Pepita\Plugin::instance();
