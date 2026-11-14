<?php
/**
 * In-memory state shared by the WordPress/WooCommerce test stubs.
 *
 * @package WPS\Pepita\Tests
 */

defined( 'ABSPATH' ) || exit;

final class WPS_Test_State {

	/** @var array<string, mixed> */
	public static array $options = array();

	/** @var array<int, array<string, array<int, mixed>>> post_id => meta_key => values */
	public static array $post_meta = array();

	/** @var array<int, array<string, mixed>> term_id => meta_key => value */
	public static array $term_meta = array();

	/** @var array<int, WP_Term> */
	public static array $terms = array();

	/** @var array<int, int[]> term_id => ancestors (nearest parent first) */
	public static array $term_ancestors = array();

	/** @var array<int, array<string, int[]>> post_id => taxonomy => term ids */
	public static array $post_terms = array();

	/** @var array<string, mixed> */
	public static array $transients = array();

	/** @var array<string, array<int, array{callback: callable, priority: int, args: int}>> */
	public static array $hooks = array();

	/** @var array<string, int> hook => timestamp */
	public static array $cron = array();

	/** @var array<int, WC_Product> */
	public static array $products = array();

	/** @var array<string, int> */
	public static array $skus = array();

	/** @var array<int, WC_Order> */
	public static array $orders = array();

	/** @var int */
	public static int $order_sequence = 0;

	/** @var array<string, bool> */
	public static array $current_user_caps = array();

	/** @var array<int, string> attachment_id => url */
	public static array $attachments = array();

	/** @var array<int, string> attachment_id => file_path */
	public static array $attached_files = array();

	/** @var array<int, string> attachment_id => mime_type */
	public static array $mime_types = array();

	/** @var array<int, array<string, mixed>> attachment_id => metadata */
	public static array $attachment_metadata = array();
	/** @var array<int, string> */
	public static array $original_image_urls = array();
	/** @var array<int, string> */
	public static array $original_image_files = array();

	/** @var array<string, array<int, array<string, mixed>>> tax class => rates */
	public static array $tax_rates = array();

	/** @var bool */
	public static bool $tax_enabled = false;

	/** @var bool */
	public static bool $ssl = false;

	/** @var bool */
	public static bool $is_admin = false;

	/** @var string */
	public static string $currency = 'HUF';

	/** @var array<int, string> */
	public static array $permalinks = array();

	/** @var array<string, WP_User> email => user */
	public static array $users_by_email = array();

	/** @var string[] */
	public static array $taxonomies = array( 'product_cat', 'product_tag', 'product_brand', 'pa_color', 'pa_size' );

	/** @var array<string, string> attribute name/taxonomy => label */
	public static array $attribute_labels = array();

	/** @var string */
	public static string $home_url = 'https://example.test';

	/** @var string */
	public static string $site_url = 'https://example.test';

	/** @var array<string, array<string, mixed>> */
	public static array $rest_routes = array();

	/** @var bool|null value of the new order e-mail gate observed during WC_Order::save() */
	public static ?bool $last_save_email_enabled = null;

	/** @var array<int, array{to: string, subject: string, message: string}> */
	public static array $mails = array();

	/** @var array<int, array{url: string, args: array}> */
	public static array $remote_posts = array();

	/** @var bool force WC_Order::save() to fail, for orphan-cleanup tests */
	public static bool $fail_order_save = false;

	public static function reset(): void {
		self::$options           = array();
		self::$post_meta         = array();
		self::$term_meta         = array();
		self::$terms             = array();
		self::$term_ancestors    = array();
		self::$post_terms        = array();
		self::$transients        = array();
		self::$hooks             = array();
		self::$cron              = array();
		self::$products          = array();
		self::$skus              = array();
		self::$orders            = array();
		self::$order_sequence    = 0;
		self::$current_user_caps = array(
			'manage_woocommerce'  => true,
			'edit_product'        => true,
			'manage_product_terms' => true,
			'activate_plugins'    => true,
		);
		self::$attachments    = array();
		self::$attached_files = array();
		self::$mime_types     = array();
		self::$attachment_metadata = array();
		self::$original_image_urls = array();
		self::$original_image_files = array();
		self::$tax_rates      = array();
		self::$tax_enabled    = false;
		self::$ssl            = false;
		self::$is_admin       = false;
		self::$currency       = 'HUF';
		self::$permalinks     = array();
		self::$users_by_email = array();
		self::$taxonomies     = array( 'product_cat', 'product_tag', 'product_brand', 'pa_color', 'pa_size' );
		self::$mails          = array();
		self::$remote_posts   = array();
		self::$attribute_labels = array();
		self::$home_url       = 'https://example.test';
		self::$site_url       = 'https://example.test';
		self::$rest_routes    = array();
		self::$last_save_email_enabled = null;
		self::$fail_order_save = false;
	}

	public static function add_term( int $id, string $name, int $parent = 0, string $taxonomy = 'product_cat' ): WP_Term {
		$term                = new WP_Term(
			array(
				'term_id'  => $id,
				'name'     => $name,
				'slug'     => strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) ?? (string) $id ),
				'parent'   => $parent,
				'taxonomy' => $taxonomy,
				'count'    => 0,
			)
		);
		self::$terms[ $id ] = $term;

		$ancestors = array();

		if ( $parent > 0 ) {
			$ancestors[] = $parent;

			if ( isset( self::$term_ancestors[ $parent ] ) ) {
				$ancestors = array_merge( $ancestors, self::$term_ancestors[ $parent ] );
			}
		}

		self::$term_ancestors[ $id ] = array_values( array_unique( $ancestors ) );

		return $term;
	}

	public static function assign_term( int $post_id, int $term_id, string $taxonomy = 'product_cat' ): void {
		self::$post_terms[ $post_id ][ $taxonomy ][] = $term_id;
		self::$post_terms[ $post_id ][ $taxonomy ]   = array_values( array_unique( self::$post_terms[ $post_id ][ $taxonomy ] ) );

		if ( isset( self::$terms[ $term_id ] ) ) {
			++self::$terms[ $term_id ]->count;
		}
	}

	public static function add_attachment( int $id, string $url ): void {
		self::$attachments[ $id ] = $url;
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$basename  = strtolower( basename( $path ) );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$fixtures  = array(
			'jpg'  => array( 'image/jpeg', 'pepita-compatible-1200x630.jpg', 1200, 630 ),
			'jpeg' => array( 'image/jpeg', 'pepita-compatible-1200x630.jpg', 1200, 630 ),
			'png'  => array( 'image/png', 'pepita-compatible-1200x630.png', 1200, 630 ),
			'gif'  => array( 'image/gif', 'pepita-unsupported-1200x630.gif', 1200, 630 ),
		);
		if ( isset( $fixtures[ $extension ] ) ) {
			[ $mime, $filename, $width, $height ] = $fixtures[ $extension ];
			if ( 'jpg' === $extension || 'jpeg' === $extension ) {
				if ( str_contains( $basename, 'small' ) || str_contains( $basename, 'undersized' ) ) {
					$filename = 'pepita-small-500x500.jpg';
					$width    = 500;
					$height   = 500;
				}
			}
			self::$mime_types[ $id ] = self::$mime_types[ $id ] ?? $mime;
			self::$attachment_metadata[ $id ] = self::$attachment_metadata[ $id ] ?? array( 'width' => $width, 'height' => $height );
			self::$attached_files[ $id ] = self::$attached_files[ $id ] ?? __DIR__ . '/../fixtures/images/' . $filename;
		}
	}

	public static function add_attribute_label( string $name, string $label ): void {
		self::$attribute_labels[ $name ] = $label;
	}

	public static function add_tax_rate( string $class, float $rate ): void {
		self::$tax_rates[ '' === $class ? '__standard__' : $class ][] = array( 'rate' => $rate );
	}

	public static function add_user( string $email, string $first = '', string $last = '' ): WP_User {
		$user                        = new WP_User( count( self::$users_by_email ) + 1, $email, $first, $last );
		self::$users_by_email[ $email ] = $user;

		return $user;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function add_product( array $data ): WC_Product {
		$product = new WC_Product( $data );

		self::$products[ $product->get_id() ] = $product;

		$sku = $product->get_sku();

		if ( '' !== $sku ) {
			self::$skus[ $sku ] = $product->get_id();
		}

		if ( ! isset( self::$permalinks[ $product->get_id() ] ) ) {
			self::$permalinks[ $product->get_id() ] = self::$home_url . '/product/product-' . $product->get_id() . '/';
		}

		return $product;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function add_variation( int $parent_id, array $data ): WC_Product_Variation {
		$data['parent_id'] = $parent_id;
		$variation         = new WC_Product_Variation( $data );

		self::$products[ $variation->get_id() ] = $variation;

		$sku = $variation->get_sku();

		if ( '' !== $sku ) {
			self::$skus[ $sku ] = $variation->get_id();
		}

		if ( isset( self::$products[ $parent_id ] ) && self::$products[ $parent_id ] instanceof WC_Product ) {
			self::$products[ $parent_id ]->register_child( $variation->get_id() );
		}

		return $variation;
	}

	public static function add_order( WC_Order $order ): WC_Order {
		if ( $order->get_id() <= 0 ) {
			$order->assign_id( ++self::$order_sequence );
		}

		self::$orders[ $order->get_id() ] = $order;

		return $order;
	}
}
