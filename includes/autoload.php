<?php
/**
 * Minimal PSR-4 style autoloader for the plugin namespace.
 *
 * WPS\Pepita\Feed\Xml_Writer  ->  includes/feed/class-xml-writer.php
 * WPS\Pepita\Settings         ->  includes/class-settings.php
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WPS\\Pepita\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$segments = explode( '\\', $relative );
		$name     = array_pop( $segments );
		$dir      = implode( '/', array_map( 'strtolower', $segments ) );

		$file = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$path = WPS_PEPITA_DIR . 'includes/' . ( '' !== $dir ? $dir . '/' : '' ) . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
