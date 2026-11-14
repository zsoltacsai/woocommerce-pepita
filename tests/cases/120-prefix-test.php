<?php
/**
 * Prefix isolation: the plugin must never collide with the Allegro plugin or
 * with WordPress / WooCommerce core symbols.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

/**
 * Every PHP file that ships with the plugin (tests and tooling excluded).
 *
 * @return string[]
 */
$plugin_files = static function (): array {
	$root  = dirname( __DIR__, 2 );
	$paths = array( $root . '/woocommerce-pepita.php', $root . '/uninstall.php' );
	$dirs  = array( 'includes' );

	foreach ( $dirs as $dir ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$paths[] = $file->getPathname();
			}
		}
	}

	sort( $paths );

	return $paths;
};

$suite->add(
	'prefix: plugin sources never mention the Allegro plugin',
	static function () use ( $plugin_files ): void {
		foreach ( $plugin_files() as $file ) {
			$contents = strtolower( (string) file_get_contents( $file ) );

			foreach ( array( 'allegro', 'wpas_', 'wp-allegro' ) as $needle ) {
				WPS_Assert::false(
					str_contains( $contents, $needle ),
					'Forbidden reference "' . $needle . '" in ' . $file
				);
			}
		}
	}
);

$suite->add(
	'prefix: every declared class lives in the WPS\\Pepita namespace',
	static function () use ( $plugin_files ): void {
		foreach ( $plugin_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( ! preg_match_all( '/^namespace\s+([^;]+);/m', $source, $namespaces ) ) {
				continue;
			}

			foreach ( $namespaces[1] as $namespace ) {
				WPS_Assert::true(
					str_starts_with( trim( $namespace ), 'WPS\\Pepita' ),
					'Unexpected namespace "' . $namespace . '" in ' . $file
				);
			}

			if ( preg_match_all( '/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $classes ) ) {
				WPS_Assert::true( '' !== trim( (string) $namespaces[1][0] ?? '' ), 'Class without namespace in ' . $file );
				WPS_Assert::same( count( $classes[1] ), count( array_unique( $classes[1] ) ) );
			}
		}
	}
);

$suite->add(
	'prefix: global constants use the WPS_PEPITA_ prefix',
	static function () use ( $plugin_files ): void {
		$found = 0;

		foreach ( $plugin_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( ! preg_match_all( '/\bdefine\(\s*\'([A-Z0-9_]+)\'/', $source, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $constant ) {
				WPS_Assert::true(
					str_starts_with( $constant, 'WPS_PEPITA_' ),
					'Unprefixed constant "' . $constant . '" in ' . $file
				);
				++$found;
			}
		}

		WPS_Assert::true( $found >= 5, 'Expected the plugin constants to be defined, found ' . $found );
	}
);

$suite->add(
	'prefix: custom hooks are namespaced and core hooks are untouched',
	static function () use ( $plugin_files ): void {
		$allowed_core = array(
			'add_meta_boxes',
			'admin_enqueue_scripts',
			'admin_menu',
			'admin_notices',
			'before_woocommerce_init',
			'bulk_actions-edit-product',
			'created_product_cat',
			'cron_schedules',
			'delete_product_cat',
			'edited_product_cat',
			'handle_bulk_actions-edit-product',
			'init',
			'manage_edit-product_cat_columns',
			'manage_edit-product_columns',
			'manage_edit-shop_order_columns',
			'manage_product_cat_custom_column',
			'manage_product_posts_custom_column',
			'manage_shop_order_posts_custom_column',
			'manage_woocommerce_page_wc-orders_columns',
			'manage_woocommerce_page_wc-orders_custom_column',
			'plugins_loaded',
			'posts_clauses',
			'posts_search',
			'product_cat_add_form_fields',
			'product_cat_edit_form_fields',
			'rest_api_init',
			'restrict_manage_posts',
			'save_post_product',
			'save_post_product_variation',
		);

		foreach ( $plugin_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( ! preg_match_all( '/\b(?:add_action|add_filter)\(\s*\'([^\']+)\'/', $source, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $hook ) {
				$ok = str_starts_with( $hook, 'wps' )
					|| str_starts_with( $hook, 'woocommerce_' )
					|| str_starts_with( $hook, 'admin_post_wps' )
					|| str_starts_with( $hook, 'wp_ajax_wps' )
					|| in_array( $hook, $allowed_core, true );

				WPS_Assert::true( $ok, 'Hook "' . $hook . '" is not namespaced in ' . $file );
			}
		}
	}
);

$suite->add(
	'prefix: options, transients and post meta are namespaced',
	static function () use ( $plugin_files ): void {
		foreach ( $plugin_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( preg_match_all( '/\b(?:get_option|update_option|add_option|delete_option|set_transient|get_transient|delete_transient)\(\s*\'([^\']+)\'/', $source, $matches ) ) {
				foreach ( $matches[1] as $key ) {
					$ok = str_starts_with( $key, 'wps_pepita_' ) || in_array( $key, array( 'woocommerce_dimension_unit', 'woocommerce_weight_unit' ), true );

					WPS_Assert::true( $ok, 'Option/transient "' . $key . '" is not namespaced in ' . $file );
				}
			}

			if ( preg_match_all( '/\b(?:get_post_meta|update_post_meta|add_post_meta|delete_post_meta|get_term_meta|update_term_meta|add_term_meta|delete_term_meta)\(\s*[^,]+,\s*\'([^\']+)\'/', $source, $matches ) ) {
				foreach ( $matches[1] as $key ) {
					WPS_Assert::true(
						str_starts_with( $key, '_wps' ),
						'Meta key "' . $key . '" is not namespaced in ' . $file
					);
				}
			}
		}
	}
);

$suite->add(
	'prefix: custom tables use the plugin prefix and never write to core tables',
	static function () use ( $plugin_files ): void {
		foreach ( $plugin_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( preg_match_all( '/\$wpdb->prefix\s*\.\s*\'([^\']+)\'/', $source, $matches ) ) {
				foreach ( $matches[1] as $table ) {
					WPS_Assert::true(
						str_starts_with( $table, 'wps_pepita_' ),
						'Custom table "' . $table . '" is not namespaced in ' . $file
					);
				}
			}

			foreach ( explode( "\n", $source ) as $line ) {
				if ( ! preg_match( '/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE\s+TABLE)\b/i', $line ) ) {
					continue;
				}

				if ( ! preg_match( '/\$wpdb->(posts|postmeta|options|terms|termmeta|users|usermeta)\b/', $line ) ) {
					continue;
				}

				// Cleanup of the plugin's own rows is scoped by a prefixed pattern or key.
				if ( str_contains( $line, 'wps_pepita' ) || str_contains( $line, '_wps' ) ) {
					continue;
				}

				WPS_Assert::true( false, 'Direct core table write in ' . $file . ': ' . trim( $line ) );
			}

			// The wpdb helper API is allowed for plugin-owned rows only; direct
			// object rows (posts, terms, users) must never be written by the plugin.
			if ( preg_match_all( '/\$wpdb->(?:insert|update|delete|replace)\(\s*\$wpdb->(posts|terms|term_taxonomy|users|usermeta)\b/si', $source, $matches ) ) {
				foreach ( $matches[0] as $statement ) {
					WPS_Assert::true( false, 'Direct core table write in ' . $file . ': ' . substr( $statement, 0, 80 ) );
				}
			}
		}
	}
);

$suite->add(
	'prefix: the text domain is consistent across the plugin',
	static function () use ( $plugin_files ): void {
		foreach ( $plugin_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( ! preg_match_all( '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_n)\(\s*[^,]+,\s*\'([^\']+)\'/', $source, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $domain ) {
				WPS_Assert::same( 'wp-pepita-sync', $domain, 'Unexpected text domain in ' . $file );
			}
		}
	}
);

$suite->add(
	'prefix: settings and export identifiers use the documented namespace',
	static function (): void {
		WPS_Assert::same( 'wps_pepita_settings', Settings::OPTION );

		WPS_Test_State::add_product(
			array(
				'id'    => 42,
				'name'  => 'Prefix',
				'price' => '100',
			)
		);

		WPS_Assert::same( 'wps-42', \WPS\Pepita\Catalog\Product_Mapper::export_id( 42 ) );
		WPS_Assert::same( 'wps-42', \WPS\Pepita\Catalog\Product_Mapper::ensure_export_id( 42 ) );

		$stored = get_post_meta( 42, '_wps_export_id', true );

		WPS_Assert::same( 'wps-42', $stored );
	}
);
