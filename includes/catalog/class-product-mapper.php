<?php
/**
 * Maps WooCommerce products (simple and variable) to Pepita feed data.
 *
 * The mapper is read-only with one exception: the stable export identifier is
 * persisted by ensure_export_id() when a product is first exported.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Product_Mapper {

	public const META_EXPORT_ID          = '_wps_export_id';
	public const META_INCLUDE            = '_wps_include';
	public const META_TITLE_OVERRIDE     = '_wps_title_override';
	public const META_DESCRIPTION_OVERRIDE = '_wps_description_override';
	public const META_GTIN               = '_wps_gtin';
	public const META_BRAND              = '_wps_brand';
	public const META_MANUFACTURER       = '_wps_manufacturer';
	public const META_AUTHOR             = '_wps_author';
	public const META_PUBLISHER          = '_wps_publisher';
	public const META_MPN                = '_wps_mpn';
	public const META_EMAIL_DELIVERY     = '_wps_email_delivery';
	public const META_PRICE_TYPE         = '_wps_price_adjustment_type';
	public const META_PRICE_VALUE        = '_wps_price_adjustment_value';
	public const META_SHIPPING_DELAY     = '_wps_shipping_delay';
	public const META_SHIPPING_PRICE     = '_wps_shipping_price';
	public const META_WARRANTY_TYPE      = '_wps_warranty_type';
	public const META_WARRANTY_DURATION  = '_wps_warranty_duration';
	public const META_VIDEO_LINKS        = '_wps_video_links';
	public const META_RESTOCK_DATE       = '_wps_restock_date';
	public const META_LAST_STATUS        = '_wps_last_export_status';
	public const META_LAST_AT            = '_wps_last_export_at';
	public const META_LAST_MESSAGE       = '_wps_last_export_message';

	private const MAX_VARIATION_AXES = 3;

	/**
	 * Stable identifier: existing meta wins, otherwise derived from the product ID.
	 * A SKU change can never alter this value.
	 */
	public static function export_id( int $product_id ): string {
		$stored = (string) get_post_meta( $product_id, self::META_EXPORT_ID, true );

		if ( '' !== $stored ) {
			return $stored;
		}

		return 'wps-' . $product_id;
	}

	public static function ensure_export_id( int $product_id ): string {
		$id = self::export_id( $product_id );

		if ( '' === (string) get_post_meta( $product_id, self::META_EXPORT_ID, true ) ) {
			update_post_meta( $product_id, self::META_EXPORT_ID, $id );
		}

		return $id;
	}

	/**
	 * Build the complete feed representation of a product.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public static function map( int $product_id, array $options = array() ): array {
		$data = array(
			'product_id'  => $product_id,
			'export_id'   => self::export_id( $product_id ),
			'type'        => 'simple',
			'name'        => '',
			'description' => '',
			'brand'       => '',
			'manufacturer'=> '',
			'author'      => '',
			'publisher'   => '',
			'mpn'         => '',
			'gtin'        => '',
			'email_delivery' => false,
			'last_mod'    => 0,
			'currency'    => Settings::currency(),
			'price'       => 0.0,
			'sale_price'  => null,
			'vat_percent' => 0.0,
			'purchase_net'=> null,
			'shipping_price' => null,
			'shipping_delay' => null,
			'warranty'    => null,
			'categories'  => array(),
			'photos'      => array(),
			'video_links' => '',
			'product_url' => '',
			'available'   => false,
			'quantity'    => null,
			'restock_date'=> '',
			'attributes'  => array(),
			'dimensions'  => array(),
			'variations'  => null,
			'errors'      => array(),
			'warnings'    => array(),
		);

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			$data['errors'][] = self::error( 'missing_product', 'A termék nem található vagy nem WooCommerce termék.' );

			return $data;
		}

		$status = $product->get_status();

		if ( ! in_array( $status, Settings::allowed_product_statuses(), true ) ) {
			$data['errors'][] = self::error(
				'product_status',
				sprintf( 'A termék állapota (%s) nem engedélyezett a feedben.', $status )
			);
		}

		$type = $product->get_type();

		if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
			$data['errors'][] = self::error(
				'unsupported_product_type',
				sprintf( 'A(z) "%s" terméktípus nem támogatott a Pepita feedben.', $type )
			);
		}

		$data['type']     = 'variable' === $type ? 'variable' : 'simple';
		$data['last_mod'] = self::last_modified( $product );

		$term_ids            = Product_Selection::category_ids( $product_id );
		$data['categories']  = self::categories( $term_ids );
		$data['name']        = self::name( $product );
		$data['description'] = self::description( $product );
		$data['brand']          = self::brand( $product_id );
		$data['manufacturer']   = self::manufacturer( $product_id );
		$data['author']         = self::author( $product_id );
		$data['publisher']      = self::publisher( $product_id );
		$data['mpn']            = Description_Sanitizer::line( get_post_meta( $product_id, self::META_MPN, true ) );
		$data['gtin']           = self::gtin( $product_id );
		$data['email_delivery'] = self::email_delivery( $product );
		$data['product_url'] = self::product_url( $product );
		$data['video_links'] = self::video_links( $product_id );
		$data['attributes']  = self::attributes( $product );
		$data['dimensions']  = self::dimensions( $product );

		$adjustment = Price_Calculator::resolve_adjustment( $product_id, $term_ids );

		$data['shipping_delay'] = self::shipping_delay( $product_id, $term_ids );
		$data['shipping_price'] = self::shipping_price( $product_id );
		$data['warranty']       = self::warranty( $product_id );

		if ( 'variable' === $data['type'] ) {
			self::map_variable( $data, $product, $adjustment, $term_ids );
		} else {
			self::map_simple( $data, $product, $adjustment );
		}

		// GTIN is mandatory for configured categories but is never fabricated.
		if ( Category_Rules::gtin_required( $term_ids ) && '' === $data['gtin'] ) {
			$data['errors'][] = self::error(
				'gtin_required',
				'Ebben a kategóriában a Pepita kötelezővé teszi a GTIN/EAN/ISBN kódot, de a termékhez nincs érvényes kód megadva.'
			);
		}

		$data['photos'] = self::photos( $product, $data['variations'] );

		if ( '' === $data['name'] ) {
			$data['errors'][] = self::error( 'missing_name', 'Hiányzik a termék neve.' );
		}

		if ( '' === $data['description'] ) {
			$data['errors'][] = self::error( 'missing_description', 'Hiányzik a termékleírás.' );
		}

		if ( array() === $data['categories'] ) {
			$data['errors'][] = self::error( 'missing_category', 'A termék nincs WooCommerce kategóriához rendelve.' );
		}

		if ( array() === $data['photos'] ) {
			$data['errors'][] = self::error( 'missing_image', 'A termékhez nincs használható kiemelt kép vagy galériakép.' );
		}

		if ( $data['price'] <= 0 ) {
			$data['errors'][] = self::error( 'invalid_price', 'A termék bruttó ára 0 vagy érvénytelen. A Pepita nem fogad el 0 árat.' );
		}

		if ( $data['vat_percent'] < 0 || $data['vat_percent'] > 100 ) {
			$data['errors'][] = self::error( 'invalid_vat', 'Az áfa mértéke érvénytelen (0 és 100% között kell lennie).' );
		}

		if ( '' !== $data['product_url'] && ! self::is_http_url( $data['product_url'] ) ) {
			$data['errors'][] = self::error( 'invalid_product_url', 'A termék URL-je érvénytelen.' );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function map_simple( array &$data, \WC_Product $product, array $adjustment ): void {
		$gross = Price_Calculator::gross( $product );
		$price = Price_Calculator::adjust( $gross, $adjustment );
		$sale  = Price_Calculator::gross_sale( $product );

		$data['price'] = $price;
		$data['sale_price'] = null !== $sale ? Price_Calculator::adjust( $sale, $adjustment ) : null;
		$data['vat_percent'] = Price_Calculator::vat_percent( $product );

		if ( null !== $data['sale_price'] && $data['sale_price'] >= $data['price'] ) {
			$data['warnings'][] = self::warning( 'sale_price_ignored', 'Az akciós ár nem alacsonyabb a rendes árnál, ezért kihagytuk a feedből.' );
			$data['sale_price'] = null;
		}

		if ( (bool) Settings::get( 'include_purchase_net', false ) && $data['price'] > 0 ) {
			$data['purchase_net'] = Price_Calculator::net( $data['price'], $data['vat_percent'] );
		}

		$data['available'] = $product->is_in_stock();
		$data['quantity']  = self::quantity( $product );
		$data['restock_date'] = self::restock_date( $data['product_id'] );

		$data['photos'] = self::photos( $product, null );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param int[]                $term_ids
	 */
	private static function map_variable( array &$data, \WC_Product $product, array $adjustment, array $term_ids ): void {
		$axes = self::variation_axes( $product );

		if ( array() === $axes ) {
			$data['errors'][] = self::error(
				'no_variation_attributes',
				'A variációs termékhez nincs beállítva variációs attribútum.'
			);
			$data['available'] = $product->is_in_stock();

			return;
		}

		if ( count( $axes ) > self::MAX_VARIATION_AXES ) {
			$data['errors'][] = self::error(
				'too_many_variation_attributes',
				sprintf(
					'A Pepita legfeljebb %d variációs attribútumot támogat, ennél a terméknél %d van beállítva.',
					self::MAX_VARIATION_AXES,
					count( $axes )
				)
			);
			$data['available'] = $product->is_in_stock();

			return;
		}

		$parent_gross = Price_Calculator::gross( $product );
		$parent_price = Price_Calculator::adjust( $parent_gross, $adjustment );

		$variations = array();
		$seen       = array();
		$sum_qty    = 0;
		$manages    = false;
		$any_stock  = false;
		$last_mod   = $data['last_mod'];

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			if ( 'publish' !== $variation->get_status() ) {
				continue;
			}

			$values = self::variation_values( $variation, $axes );

			if ( null === $values ) {
				$data['errors'][] = self::error(
					'invalid_variation_structure',
					sprintf( 'A(z) #%d variáció attribútumértékei hiányosak vagy nem egyeztethetők a termék attribútumaival.', $variation_id )
				);
				continue;
			}

			$key = implode( "\x1F", $values );

			if ( isset( $seen[ $key ] ) ) {
				$data['errors'][] = self::error(
					'duplicate_variation',
					sprintf( 'Két variáció ugyanazokkal az attribútumértékekkel szerepel: %s.', implode( ' / ', $values ) )
				);
				continue;
			}

			$seen[ $key ] = true;

			$raw_price = $variation->get_price();
			$gross     = '' === $raw_price || null === $raw_price
				? $parent_gross
				: Price_Calculator::gross( $variation );

			$price = Price_Calculator::adjust( $gross, $adjustment );

			if ( $price <= 0 ) {
				$price = $parent_price;
			}

			$sale = Price_Calculator::gross_sale( $variation );
			$sale = null !== $sale ? Price_Calculator::adjust( $sale, $adjustment ) : null;

			if ( null !== $sale && $sale >= $price ) {
				$sale = null;
			}

			$quantity = self::quantity( $variation );

			if ( null !== $quantity ) {
				$sum_qty += $quantity;
				$manages  = true;
			}

			$available = $variation->is_in_stock();

			if ( $available ) {
				$any_stock = true;
			}

			$image_url = '';
			$image_id  = (int) $variation->get_image_id();

			if ( $image_id > 0 ) {
				$image_url = self::attachment_url( $image_id );
			}

			$var_vat = Price_Calculator::vat_percent( $variation );

			$variations[] = array(
				'values'         => $values,
				'key'            => $key,
				'price'          => $price,
				'sale_price'     => $sale,
				'vat_percent'    => $var_vat,
				'purchase_net'   => ( (bool) Settings::get( 'include_purchase_net', false ) && $price > 0 )
					? Price_Calculator::net( $price, $var_vat )
					: null,
				'available'      => $available,
				'quantity'       => $quantity,
				'shipping_price' => self::shipping_price( $variation_id ) ?? $data['shipping_price'],
				'shipping_delay' => self::shipping_delay( $variation_id, $term_ids ) ?? $data['shipping_delay'],
				'restock_date'   => self::restock_date( $variation_id ) ?: $data['restock_date'],
				'image'          => $image_url,
				'variation_id'   => $variation_id,
			);

			$modified = $variation->get_date_modified();

			if ( $modified instanceof \DateTimeInterface ) {
				$last_mod = max( $last_mod, $modified->getTimestamp() );
			}
		}

		if ( array() === $variations ) {
			$data['errors'][] = self::error(
				'no_published_variations',
				'A variációs termékhez nincs exportálható (publikált) variáció.'
			);
		}

		$data['last_mod']   = $last_mod;
		$data['price']      = $parent_price > 0 ? $parent_price : self::min_variation_price( $variations );
		$data['sale_price'] = null;
		$data['vat_percent']= Price_Calculator::vat_percent( $product );
		$data['available']  = $any_stock;
		$data['quantity']   = $manages ? $sum_qty : null;
		$data['restock_date'] = self::restock_date( $data['product_id'] );

		if ( (bool) Settings::get( 'include_purchase_net', false ) && $data['price'] > 0 ) {
			$data['purchase_net'] = Price_Calculator::net( $data['price'], $data['vat_percent'] );
		}

		$data['variations'] = array(
			'axes'  => $axes,
			'items' => $variations,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $variations
	 */
	private static function min_variation_price( array $variations ): float {
		$min = 0.0;

		foreach ( $variations as $variation ) {
			$price = (float) $variation['price'];

			if ( $price > 0 && ( 0.0 === $min || $price < $min ) ) {
				$min = $price;
			}
		}

		return $min;
	}

	/**
	 * Variation axes, ordered by the WooCommerce attribute position.
	 *
	 * @return array<int, array{name: string, key: string, taxonomy: string}>
	 */
	public static function variation_axes( \WC_Product $product ): array {
		$attributes = $product->get_attributes();
		$axes       = array();

		foreach ( $attributes as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}

			$key      = $attribute->is_taxonomy()
				? (string) $attribute->get_name()
				: sanitize_title( (string) $attribute->get_name() );
			$taxonomy = $attribute->is_taxonomy() ? $key : '';
			$name     = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $key, $product ) : $key;

			if ( '' === $name ) {
				$name = $key;
			}

			$axes[] = array(
				'name'     => Description_Sanitizer::line( $name ),
				'key'      => $key,
				'taxonomy' => $taxonomy,
				'position' => (int) $attribute->get_position(),
			);
		}

		usort(
			$axes,
			static fn( array $a, array $b ): int => $a['position'] <=> $b['position']
		);

		return $axes;
	}

	/**
	 * @param array<int, array{name: string, key: string, taxonomy: string}> $axes
	 * @return string[]|null Values aligned with the axes, or null when invalid.
	 */
	private static function variation_values( \WC_Product_Variation $variation, array $axes ): ?array {
		$raw = $variation->get_variation_attributes();
		$out = array();

		foreach ( $axes as $axis ) {
			$lookup = 'attribute_' . $axis['key'];
			$value  = isset( $raw[ $lookup ] ) ? (string) $raw[ $lookup ] : '';

			if ( '' === $value ) {
				return null;
			}

			if ( '' !== $axis['taxonomy'] && taxonomy_exists( $axis['taxonomy'] ) ) {
				$term = get_term_by( 'slug', $value, $axis['taxonomy'] );

				if ( $term && ! is_wp_error( $term ) ) {
					$value = $term->name;
				}
			}

			$value = Description_Sanitizer::line( $value );

			if ( '' === $value ) {
				return null;
			}

			$out[] = $value;
		}

		return $out;
	}

	private static function quantity( \WC_Product $product ): ?int {
		if ( ! $product->managing_stock() ) {
			return null;
		}

		$quantity = $product->get_stock_quantity();

		return null === $quantity ? null : max( 0, (int) $quantity );
	}

	private static function name( \WC_Product $product ): string {
		$override = Description_Sanitizer::line( get_post_meta( $product->get_id(), self::META_TITLE_OVERRIDE, true ) );

		return '' !== $override ? $override : Description_Sanitizer::line( $product->get_name() );
	}

	private static function description( \WC_Product $product ): string {
		$override = Description_Sanitizer::clean( get_post_meta( $product->get_id(), self::META_DESCRIPTION_OVERRIDE, true ) );

		if ( '' !== $override ) {
			return $override;
		}

		$source = (string) Settings::get( 'description_source', 'long' );
		$long   = Description_Sanitizer::clean( $product->get_description() );
		$short  = Description_Sanitizer::clean( $product->get_short_description() );

		$description = match ( $source ) {
			'short'      => $short,
			'long_short' => trim( $long . ( '' !== $short ? "\n\n" . $short : '' ) ),
			default      => '' !== $long ? $long : $short,
		};

		return $description;
	}

	private static function brand( int $product_id ): string {
		$source = (string) Settings::get( 'brand_source', 'meta' );

		if ( 'none' === $source ) {
			return '';
		}

		if ( 'taxonomy' === $source ) {
			$taxonomy = (string) Settings::get( 'brand_taxonomy', 'product_brand' );

			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return '';
			}

			$terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );

			return is_array( $terms ) && array() !== $terms ? Description_Sanitizer::line( $terms[0] ) : '';
		}

		$keys = array_unique(
			array_filter(
				array(
					(string) Settings::get( 'brand_meta_key', self::META_BRAND ),
					self::META_BRAND,
					'brand',
					'_brand',
				)
			)
		);

		foreach ( $keys as $key ) {
			$value = Description_Sanitizer::line( get_post_meta( $product_id, $key, true ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private static function manufacturer( int $product_id ): string {
		$value = Description_Sanitizer::line( get_post_meta( $product_id, self::META_MANUFACTURER, true ) );

		return '' !== $value ? $value : Description_Sanitizer::line( Settings::get( 'manufacturer_default', '' ) );
	}

	private static function author( int $product_id ): string {
		return Description_Sanitizer::line( (string) get_post_meta( $product_id, self::META_AUTHOR, true ) );
	}

	private static function publisher( int $product_id ): string {
		return Description_Sanitizer::line( (string) get_post_meta( $product_id, self::META_PUBLISHER, true ) );
	}

	private static function email_delivery( \WC_Product $product ): bool {
		$meta = get_post_meta( $product->get_id(), self::META_EMAIL_DELIVERY, true );

		if ( '' !== $meta && null !== $meta ) {
			return in_array( $meta, array( 'yes', '1', 1, 'true', true ), true );
		}

		return false;
	}

	private static function gtin( int $product_id ): string {
		foreach ( Settings::gtin_meta_keys() as $key ) {
			$raw = get_post_meta( $product_id, $key, true );

			if ( '' === $raw || null === $raw ) {
				continue;
			}

			$gtin = Description_Sanitizer::gtin( $raw );

			if ( '' !== $gtin ) {
				return $gtin;
			}
		}

		return '';
	}

	private static function product_url( \WC_Product $product ): string {
		$url = get_permalink( $product->get_id() );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		return str_replace( ' ', '%20', $url );
	}

	private static function video_links( int $product_id ): string {
		$raw = (string) get_post_meta( $product_id, self::META_VIDEO_LINKS, true );

		if ( '' === $raw ) {
			return '';
		}

		$links = array();

		foreach ( preg_split( '/[\s,]+/', $raw ) ?: array() as $link ) {
			$link = trim( $link );

			if ( '' !== $link && self::is_http_url( $link ) ) {
				$links[] = $link;
			}
		}

		return implode( ',', array_values( array_unique( $links ) ) );
	}

	private static function shipping_delay( int $product_id, array $term_ids ): ?int {
		$raw = get_post_meta( $product_id, self::META_SHIPPING_DELAY, true );

		if ( '' !== $raw && null !== $raw ) {
			return max( 0, (int) $raw );
		}

		$category = Category_Rules::shipping_delay( $term_ids );

		if ( null !== $category ) {
			return $category;
		}

		$default = Settings::get( 'shipping_delay', '' );

		return '' === $default ? null : max( 0, (int) $default );
	}

	private static function shipping_price( int $product_id ): ?float {
		$raw = get_post_meta( $product_id, self::META_SHIPPING_PRICE, true );

		if ( '' === $raw || null === $raw ) {
			$raw = Settings::get( 'shipping_price', '' );
		}

		if ( '' === $raw || null === $raw ) {
			return null;
		}

		$value = (float) str_replace( ',', '.', (string) $raw );

		return $value > 0 ? $value : null;
	}

	/**
	 * @return array{type: string, duration: string}|null
	 */
	private static function warranty( int $product_id ): ?array {
		$type     = (string) get_post_meta( $product_id, self::META_WARRANTY_TYPE, true );
		$duration = (string) get_post_meta( $product_id, self::META_WARRANTY_DURATION, true );

		if ( ! in_array( $type, array( 'None', 'Day', 'Week', 'Month', 'Year' ), true ) ) {
			$type     = (string) Settings::get( 'warranty_type', 'None' );
			$duration = (string) Settings::get( 'warranty_duration', '' );
		}

		if ( 'None' === $type ) {
			return null;
		}

		$duration = preg_replace( '/\D/', '', $duration ) ?? '';

		if ( '' === $duration ) {
			return null;
		}

		return array(
			'type'     => $type,
			'duration' => $duration,
		);
	}

	private static function restock_date( int $product_id ): string {
		$raw = (string) get_post_meta( $product_id, self::META_RESTOCK_DATE, true );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return '';
		}

		$timestamp = strtotime( $raw . ' 00:00:00' );

		return false !== $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}

	private static function last_modified( \WC_Product $product ): int {
		$modified = $product->get_date_modified();

		if ( $modified instanceof \DateTimeInterface ) {
			return $modified->getTimestamp();
		}

		return time();
	}

	/**
	 * Build the category path list (root -> leaf).
	 *
	 * @param int[] $term_ids
	 * @return array<int, array<int, array{id: int, name: string}>>
	 */
	private static function categories( array $term_ids ): array {
		$max_paths = max( 1, (int) Settings::get( 'max_category_paths', 5 ) );
		$paths     = array();

		sort( $term_ids );

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, Category_Rules::TAXONOMY );

			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$ancestors = get_ancestors( $term_id, Category_Rules::TAXONOMY, 'taxonomy' );
			$ancestors = array_reverse( array_map( 'intval', $ancestors ) );

			$path = array();

			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, Category_Rules::TAXONOMY );

				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$path[] = array(
						'id'   => (int) $ancestor->term_id,
						'name' => Description_Sanitizer::line( $ancestor->name ),
					);
				}
			}

			$path[] = array(
				'id'   => (int) $term->term_id,
				'name' => Description_Sanitizer::line( $term->name ),
			);

			$paths[ $term_id ] = $path;

			if ( count( $paths ) >= $max_paths ) {
				break;
			}
		}

		return array_values( $paths );
	}

	/**
	 * Product attributes (excluding variation axes).
	 *
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function attributes( \WC_Product $product ): array {
		$out = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			if ( $attribute->get_variation() ) {
				continue;
			}

			$name = $attribute->is_taxonomy()
				? (string) wc_attribute_label( $attribute->get_name(), $product )
				: (string) $attribute->get_name();

			if ( $attribute->is_taxonomy() ) {
				$terms = wp_get_post_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
				$value = is_array( $terms ) ? implode( ', ', array_map( array( Description_Sanitizer::class, 'line' ), $terms ) ) : '';
			} else {
				$value = implode( ', ', array_map( array( Description_Sanitizer::class, 'line' ), $attribute->get_options() ) );
			}

			$name  = Description_Sanitizer::line( $name );
			$value = trim( $value );

			if ( '' !== $name && '' !== $value ) {
				$out[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}

		return $out;
	}

	/**
	 * Dimensions converted to the units required by Pepita (cm, kg).
	 *
	 * @return array<string, float>
	 */
	private static function dimensions( \WC_Product $product ): array {
		$unit         = function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_dimension_unit', 'cm' ) : 'cm';
		$weight_unit  = function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_weight_unit', 'kg' ) : 'kg';

		$length = self::to_cm( (float) $product->get_length(), $unit );
		$width  = self::to_cm( (float) $product->get_width(), $unit );
		$height = self::to_cm( (float) $product->get_height(), $unit );
		$weight = self::to_kg( (float) $product->get_weight(), $weight_unit );

		$out = array();

		if ( $width > 0 ) {
			$out['width'] = $width;
		}

		if ( $height > 0 ) {
			$out['height'] = $height;
		}

		if ( $length > 0 ) {
			$out['length'] = $length;
		}

		if ( $weight > 0 ) {
			$out['weight'] = $weight;
		}

		return $out;
	}

	private static function to_cm( float $value, string $unit ): float {
		return match ( $unit ) {
			'mm'  => $value / 10,
			'm'   => $value * 100,
			'in'  => $value * 2.54,
			'yd'  => $value * 91.44,
			default => $value,
		};
	}

	private static function to_kg( float $value, string $unit ): float {
		return match ( $unit ) {
			'g'   => $value / 1000,
			'lbs' => $value * 0.45359237,
			'oz'  => $value * 0.028349523125,
			default => $value,
		};
	}

	/**
	 * Featured, gallery and variation images.
	 *
	 * @param array<string, mixed>|null $variations
	 * @return array<int, array<string, mixed>>
	 */
	private static function photos( \WC_Product $product, ?array $variations ): array {
		$photos = array();
		$seen   = array();

		$featured_id = (int) $product->get_image_id();

		if ( $featured_id > 0 ) {
			$url = self::attachment_url( $featured_id );

			if ( '' !== $url ) {
				$photos[] = array(
					'url'        => $url,
					'primary'    => true,
					'title'      => '',
					'variations' => array(),
				);
				$seen[ $url ] = true;
			}
		}

		$gallery_ids = $product->get_gallery_image_ids();
		$max_gallery = max( 0, (int) Settings::get( 'max_gallery_images', 10 ) );

		foreach ( array_slice( $gallery_ids, 0, $max_gallery ) as $image_id ) {
			$url = self::attachment_url( (int) $image_id );

			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}

			$photos[] = array(
				'url'        => $url,
				'primary'    => array() === $photos,
				'title'      => '',
				'variations' => array(),
			);
			$seen[ $url ] = true;
		}

		if ( null !== $variations ) {
			foreach ( $variations['items'] as $item ) {
				$url = (string) ( $item['image'] ?? '' );

				if ( '' === $url || isset( $seen[ $url ] ) ) {
					continue;
				}

				$photos[] = array(
					'url'        => $url,
					'primary'    => false,
					'title'      => '',
					'variations' => $item['values'],
				);
				$seen[ $url ] = true;
			}
		}

		return $photos;
	}

	public static function attachment_url( int $attachment_id ): string {
		$url = function_exists( 'wp_get_attachment_image_url' )
			? wp_get_attachment_image_url( $attachment_id, 'full' )
			: '';

		if ( ! is_string( $url ) || '' === $url ) {
			$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment_id ) : '';
		}

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		return str_replace( ' ', '%20', $url );
	}

	public static function is_http_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		return isset( $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * @return array{code: string, message: string}
	 */
	private static function error( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * @return array{code: string, message: string}
	 */
	private static function warning( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}
}
