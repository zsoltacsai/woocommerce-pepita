<?php
/**
 * Plugin settings: defaults, sanitisation and typed accessors.
 *
 * All settings live in a single autoload-disabled option so that the
 * front-end never pays for loading the configuration.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION      = 'wps_pepita_settings';
	public const GROUP       = 'wps_pepita';
	public const SECRET_KEYS = array( 'feed_token', 'order_api_key' );

	/**
	 * Secret constants that may be defined in wp-config.php.
	 */
	private const CONSTANTS = array(
		'feed_token'    => 'WPS_PEPITA_FEED_TOKEN',
		'order_api_key' => 'WPS_PEPITA_ORDER_API_KEY',
	);

	private static ?array $cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Catalog selection.
			'default_included'         => false,
			'include_child_categories' => true,
			'allowed_product_statuses' => array( 'publish' ),
			'beta_feed_only'           => false,

			// Pricing and tax.
			'currency'                 => '',
			'vat_mode'                 => 'auto',
			'vat_fixed'                => 27.0,
			'price_adjustment_type'    => 'none',
			'price_adjustment_value'   => 0.0,
			'rounding'                 => 'nearest_1',
			'include_purchase_net'     => false,

			// Shipping and warranty.
			'shipping_delay'           => 2,
			'shipping_price'           => '',
			'warranty_type'            => 'None',
			'warranty_duration'        => '',

			// Content mapping.
			'description_source'       => 'long',
			'brand_source'             => 'meta',
			'brand_meta_key'           => '_wps_brand',
			'brand_taxonomy'           => 'product_brand',
			'manufacturer_default'     => '',
			'gtin_meta_keys'           => '_wps_gtin,_gtin,_ean,_wps_ean',
			'gtin_required_categories' => array(),
			'max_category_paths'       => 5,
			'max_gallery_images'       => 10,

			// Feed generation.
			'batch_size'               => 100,
			'batches_per_run'          => 10,
			'max_runtime'              => 20,
			'full_feed_interval'       => 24,
			'stock_feed_interval'      => 30,
			'stock_requires_full'      => true,
			'removal_policy'           => 'omit',
			'removal_grace_days'       => 3,
			'allow_empty_feed'         => false,
			'feed_public'              => false,
			'feed_cache_max_age'       => 300,
			'feed_token'               => '',
			'feed_full_slug'           => 'full',
			'feed_stock_slug'          => 'stock',
			'feed_safety_drop_threshold' => 20,
			'feed_safety_block_publication' => false,
			'feed_safety_variation_change_warning' => true,

			// Order API.
			'order_api_enabled'        => true,
			'order_api_key'            => '',
			'order_api_allowed_ips'    => '',
			'order_api_trusted_proxies' => '',
			'order_api_status_paid'    => 'processing',
			'order_api_status_unpaid'  => 'on-hold',
			'order_api_require_https'  => true,
			'order_api_max_body_kb'    => 512,
			'order_api_keep_payloads'  => false,
			'order_api_save_label'     => true,
			'order_api_label_retention_days' => 30,
			'payment_map'              => array(
				'cod'        => 'cod',
				'transfer'   => 'bacs',
				'creditcard' => '',
			),
			'shipping_map'             => array(
				'shipping'         => 'flat_rate',
				'gls'              => 'flat_rate',
				'gls_parcellocker' => 'flat_rate',
				'gls_xxl'          => 'flat_rate',
				'mpl'              => 'flat_rate',
			),

			// Logging and lifecycle.
			'log_retention_days'       => 30,
			'log_level'                => 'info',
			'record_export_meta'       => true,
			'delete_data_on_uninstall' => false,
			'storage_key'              => '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			self::$cache = self::sanitize( array_merge( self::defaults(), $stored ) );
		}

		return self::$cache;
	}

	/**
	 * Whether the plugin is currently running in feed-only beta mode.
	 */
	public static function is_beta_feed_only(): bool {
		if ( defined( 'WPS_PEPITA_BETA_FEED_ONLY' ) ) {
			return (bool) constant( 'WPS_PEPITA_BETA_FEED_ONLY' );
		}

		$all = self::all();
		return ! empty( $all['beta_feed_only'] );
	}

	/**
	 * @param mixed $fallback
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		$value = array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;

		if ( 'beta_feed_only' === $key ) {
			if ( defined( 'WPS_PEPITA_BETA_FEED_ONLY' ) ) {
				return (bool) constant( 'WPS_PEPITA_BETA_FEED_ONLY' );
			}
			return (bool) $value;
		}

		if ( self::is_beta_feed_only() ) {
			if ( 'order_api_enabled' === $key ) {
				return false;
			}
			if ( 'feed_public' === $key ) {
				return false;
			}
			if ( 'default_included' === $key ) {
				return false;
			}
		}

		return $value;
	}

	/**
	 * Merge and persist settings.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function update( array $values ): array {
		$merged      = array_merge( self::all(), $values );
		$sanitized   = self::sanitize( $merged );
		self::$cache = $sanitized;

		update_option( self::OPTION, $sanitized, false );

		return $sanitized;
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Create an option with defaults on first install (never overwrites).
	 */
	public static function install_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::sanitize( self::defaults() ), '', false );
		}
	}

	/**
	 * Ensure both secrets exist. Secrets may also come from wp-config constants.
	 */
	public static function ensure_tokens(): void {
		$values = array();

		foreach ( self::SECRET_KEYS as $key ) {
			if ( '' === (string) self::get( $key, '' ) && ! self::using_constant( $key ) ) {
				$values[ $key ] = self::generate_secret();
			}
		}

		if ( array() !== $values ) {
			self::update( $values );
		}
	}

	public static function generate_secret(): string {
		return wp_generate_password( 48, false, false );
	}

	public static function regenerate_secret( string $key ): string {
		if ( ! in_array( $key, self::SECRET_KEYS, true ) ) {
			return '';
		}

		$value = self::generate_secret();
		self::update( array( $key => $value ) );

		return $value;
	}

	/**
	 * Resolve a secret, preferring a wp-config constant.
	 */
	public static function secret( string $key ): string {
		if ( isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] ) ) {
			return (string) constant( self::CONSTANTS[ $key ] );
		}

		return (string) self::get( $key, '' );
	}

	public static function using_constant( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	public static function currency(): string {
		$configured = strtoupper( trim( (string) self::get( 'currency', '' ) ) );

		if ( '' !== $configured && preg_match( '/^[A-Z]{3}$/', $configured ) ) {
			return $configured;
		}

		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'HUF';
	}

	public static function full_feed_interval(): int {
		return max( 1, (int) self::get( 'full_feed_interval', 24 ) ) * HOUR_IN_SECONDS;
	}

	public static function stock_feed_interval(): int {
		return max( 5, (int) self::get( 'stock_feed_interval', 30 ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Build the stable public feed URL.
	 */
	public static function feed_url( string $type ): string {
		$args = array( 'wps_feed' => $type );

		if ( ! (bool) self::get( 'feed_public', false ) ) {
			$args['token'] = self::secret( 'feed_token' );
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	public static function feed_slug( string $type ): string {
		return 'full' === $type
			? (string) self::get( 'feed_full_slug', 'full' )
			: (string) self::get( 'feed_stock_slug', 'stock' );
	}

	/**
	 * @return array<string, string>
	 */
	public static function payment_map(): array {
		$map = self::get( 'payment_map', array() );

		return is_array( $map ) ? array_map( 'strval', $map ) : array();
	}

	/**
	 * @return array<string, string>
	 */
	public static function shipping_map(): array {
		$map = self::get( 'shipping_map', array() );

		return is_array( $map ) ? array_map( 'strval', $map ) : array();
	}

	/**
	 * @return string[]
	 */
	public static function gtin_meta_keys(): array {
		$keys = array_map( 'trim', explode( ',', (string) self::get( 'gtin_meta_keys', '' ) ) );

		return array_values( array_filter( $keys, static fn( string $key ): bool => '' !== $key ) );
	}

	/**
	 * @return int[]
	 */
	public static function gtin_required_categories(): array {
		$ids = self::get( 'gtin_required_categories', array() );
		$ids = is_array( $ids ) ? $ids : array();

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function allowed_product_statuses(): array {
		$statuses = self::get( 'allowed_product_statuses', array( 'publish' ) );
		$statuses = is_array( $statuses ) ? $statuses : array( 'publish' );
		$valid    = array( 'publish', 'private', 'draft', 'pending' );

		$statuses = array_values( array_intersect( $statuses, $valid ) );

		return array() !== $statuses ? $statuses : array( 'publish' );
	}

	/**
	 * Normalise every value coming from the admin form or from code.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$output   = array();

		$bools = array(
			'default_included',
			'include_child_categories',
			'include_purchase_net',
			'stock_requires_full',
			'allow_empty_feed',
			'feed_public',
			'beta_feed_only',
			'order_api_enabled',
			'order_api_require_https',
			'order_api_keep_payloads',
			'order_api_save_label',
			'record_export_meta',
			'delete_data_on_uninstall',
			'feed_safety_block_publication',
			'feed_safety_variation_change_warning',
		);

		$ints = array(
			'batch_size'            => array( 10, 1000 ),
			'batches_per_run'       => array( 1, 100 ),
			'max_runtime'           => array( 5, 300 ),
			'full_feed_interval'    => array( 1, 168 ),
			'stock_feed_interval'   => array( 5, 1440 ),
			'feed_safety_drop_threshold' => array( 5, 95 ),
			'removal_grace_days'    => array( 0, 60 ),
			'feed_cache_max_age'    => array( 0, 86400 ),
			'order_api_max_body_kb' => array( 16, 8192 ),
			'log_retention_days'    => array( 1, 3650 ),
			'order_api_label_retention_days' => array( 1, 3650 ),
			'max_category_paths'    => array( 1, 20 ),
			'max_gallery_images'    => array( 0, 50 ),
			'shipping_delay'        => array( 0, 60 ),
		);

		$floats = array(
			'vat_fixed'              => array( 0.0, 100.0 ),
			'price_adjustment_value' => array( -1000000000.0, 1000000000.0 ),
		);

		$enums = array(
			'vat_mode'              => array( 'auto', 'fixed' ),
			'price_adjustment_type' => array( 'none', 'fixed', 'percent' ),
			'rounding'              => array( 'none', 'nearest_1', 'nearest_5', 'nearest_10', 'nearest_100', 'up_1' ),
			'warranty_type'         => array( 'None', 'Day', 'Week', 'Month', 'Year' ),
			'description_source'    => array( 'long', 'short', 'long_short' ),
			'brand_source'          => array( 'meta', 'taxonomy', 'none' ),
			'removal_policy'        => array( 'omit', 'zero_stock' ),
			'log_level'             => array( 'debug', 'info', 'warning', 'error' ),
		);

		$texts = array(
			'currency',
			'brand_meta_key',
			'brand_taxonomy',
			'manufacturer_default',
			'gtin_meta_keys',
			'shipping_price',
			'warranty_duration',
			'feed_full_slug',
			'feed_stock_slug',
			'order_api_allowed_ips',
			'order_api_trusted_proxies',
			'order_api_status_paid',
			'order_api_status_unpaid',
			'feed_token',
			'order_api_key',
			'storage_key',
		);

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$output[ $key ] = $default;
				continue;
			}

			$value = $input[ $key ];

			if ( in_array( $key, $bools, true ) ) {
				$output[ $key ] = self::to_bool( $value );
				continue;
			}

			if ( isset( $ints[ $key ] ) ) {
				$output[ $key ] = max( $ints[ $key ][0], min( $ints[ $key ][1], (int) $value ) );
				continue;
			}

			if ( isset( $floats[ $key ] ) ) {
				$output[ $key ] = max( $floats[ $key ][0], min( $floats[ $key ][1], (float) $value ) );
				continue;
			}

			if ( isset( $enums[ $key ] ) ) {
				$output[ $key ] = in_array( $value, $enums[ $key ], true ) ? $value : $default;
				continue;
			}

			if ( in_array( $key, $texts, true ) ) {
				$output[ $key ] = sanitize_text_field( (string) $value );
				continue;
			}

			switch ( $key ) {
				case 'allowed_product_statuses':
					$statuses       = is_array( $value ) ? $value : array( 'publish' );
					$statuses       = array_values( array_intersect( array_map( 'sanitize_key', $statuses ), array( 'publish', 'private', 'draft', 'pending' ) ) );
					$output[ $key ] = array() !== $statuses ? $statuses : array( 'publish' );
					break;

				case 'gtin_required_categories':
					$ids            = is_array( $value ) ? $value : explode( ',', (string) $value );
					$output[ $key ] = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
					break;

				case 'payment_map':
					$output[ $key ] = self::sanitize_map( $value, array( 'cod', 'transfer', 'creditcard' ), $default );
					break;

				case 'shipping_map':
					$output[ $key ] = self::sanitize_map( $value, array( 'shipping', 'gls', 'gls_parcellocker', 'gls_xxl', 'mpl' ), $default );
					break;

				default:
					$output[ $key ] = $default;
			}
		}

		$output['currency'] = strtoupper( $output['currency'] );

		if ( '' !== $output['currency'] && ! preg_match( '/^[A-Z]{3}$/', $output['currency'] ) ) {
			$output['currency'] = '';
		}

		foreach ( array( 'feed_full_slug', 'feed_stock_slug' ) as $slug_key ) {
			$slug                 = sanitize_title( (string) $output[ $slug_key ] );
			$output[ $slug_key ]  = '' !== $slug ? $slug : $defaults[ $slug_key ];
		}

		$output['shipping_price'] = '' === $output['shipping_price']
			? ''
			: (string) max( 0.0, (float) str_replace( ',', '.', $output['shipping_price'] ) );

		$output['warranty_duration'] = preg_replace( '/[^0-9]/', '', (string) $output['warranty_duration'] ) ?? '';

		if ( 'None' === $output['warranty_type'] ) {
			$output['warranty_duration'] = '';
		}

		if ( ! empty( $output['beta_feed_only'] ) || ( defined( 'WPS_PEPITA_BETA_FEED_ONLY' ) && WPS_PEPITA_BETA_FEED_ONLY ) ) {
			$output['beta_feed_only']    = true;
			$output['order_api_enabled'] = false;
			$output['feed_public']       = false;
			$output['default_included']  = false;
		}

		return $output;
	}

	/**
	 * @param mixed                $value
	 * @param string[]             $allowed_keys
	 * @param array<string, mixed> $default
	 * @return array<string, string>
	 */
	private static function sanitize_map( $value, array $allowed_keys, array $default ): array {
		$value = is_array( $value ) ? $value : array();
		$map   = array();

		foreach ( $allowed_keys as $map_key ) {
			$map[ $map_key ] = isset( $value[ $map_key ] ) ? sanitize_text_field( (string) $value[ $map_key ] ) : (string) ( $default[ $map_key ] ?? '' );
		}

		return $map;
	}

	/**
	 * @param mixed $value
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
