<?php
/**
 * Marketplace inventory safety rules and buffer calculations.
 *
 * WooCommerce stock quantities are never modified. All rules are applied
 * only to the exported feed quantities.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Marketplace_Inventory {

	public const META_BUFFER    = '_wps_inventory_buffer';
	public const META_MAX_QTY   = '_wps_inventory_max_qty';
	public const META_DISABLED  = '_wps_inventory_disabled';

	public const TERM_META_BUFFER  = 'wps_inventory_buffer';
	public const TERM_META_MAX_QTY = 'wps_inventory_max_qty';

	public const MAX_QUANTITY = 999999;

	/**
	 * Check whether the inventory optimization strategy is globally enabled.
	 */
	public static function is_enabled(): bool {
		return (bool) Settings::get( 'inventory_strategy_enabled', false );
	}

	/**
	 * Resolve effective inventory rules for a product / variation.
	 *
	 * Precedence:
	 * 1. Product-level override (for a variation, variation meta; if not set, inherits from parent product).
	 * 2. Category-level override (deepest category with an explicit buffer or max).
	 * 3. Global fallback settings.
	 *
	 * @param int      $product_id
	 * @param int[]    $term_ids
	 * @param int|null $parent_id
	 * @return array{enabled: bool, buffer: int, max_qty: ?int, source: string}
	 */
	public static function resolve_rules( int $product_id, array $term_ids = array(), ?int $parent_id = null, bool $force_enable = false ): array {
		$globally_enabled = $force_enable || self::is_enabled();

		// 1. Direct product-level override.
		$prod_disabled = get_post_meta( $product_id, self::META_DISABLED, true );
		$prod_buf      = get_post_meta( $product_id, self::META_BUFFER, true );
		$prod_max      = get_post_meta( $product_id, self::META_MAX_QTY, true );

		if ( 'yes' === $prod_disabled ) {
			return array(
				'enabled' => false,
				'buffer'  => 0,
				'max_qty' => null,
				'source'  => 'product',
			);
		}

		if ( '' !== $prod_buf || '' !== $prod_max ) {
			return array(
				'enabled' => $globally_enabled,
				'buffer'  => max( 0, (int) $prod_buf ),
				'max_qty' => ( '' !== $prod_max && (int) $prod_max > 0 ) ? (int) $prod_max : null,
				'source'  => 'product',
			);
		}

		// If variation has no direct override, check parent product override.
		if ( null !== $parent_id && $parent_id > 0 && $parent_id !== $product_id ) {
			$parent_disabled = get_post_meta( $parent_id, self::META_DISABLED, true );
			$parent_buf      = get_post_meta( $parent_id, self::META_BUFFER, true );
			$parent_max      = get_post_meta( $parent_id, self::META_MAX_QTY, true );

			if ( 'yes' === $parent_disabled ) {
				return array(
					'enabled' => false,
					'buffer'  => 0,
					'max_qty' => null,
					'source'  => 'variation_parent',
				);
			}

			if ( '' !== $parent_buf || '' !== $parent_max ) {
				return array(
					'enabled' => $globally_enabled,
					'buffer'  => max( 0, (int) $parent_buf ),
					'max_qty' => ( '' !== $parent_max && (int) $parent_max > 0 ) ? (int) $parent_max : null,
					'source'  => 'variation_parent',
				);
			}
		}

		// 2. Category-level override.
		$cat_rule = self::resolve_category_rule( $term_ids );
		if ( null !== $cat_rule ) {
			$cat_rule['enabled'] = $globally_enabled;
			return $cat_rule;
		}

		// 3. Global fallback settings.
		$global_buf = max( 0, (int) Settings::get( 'inventory_buffer', 0 ) );
		$global_max = Settings::get( 'inventory_max_qty', null );
		$max_qty    = ( null !== $global_max && '' !== (string) $global_max && (int) $global_max > 0 )
			? (int) $global_max
			: null;

		return array(
			'enabled' => $globally_enabled,
			'buffer'  => $global_buf,
			'max_qty' => $max_qty,
			'source'  => 'global',
		);
	}

	/**
	 * Resolve category-level inventory rule by deepest category.
	 *
	 * @param int[] $term_ids
	 * @return array{enabled: bool, buffer: int, max_qty: ?int, source: string}|null
	 */
	private static function resolve_category_rule( array $term_ids ): ?array {
		if ( empty( $term_ids ) ) {
			return null;
		}

		$candidates = array();

		foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}

			$buf = get_term_meta( $term_id, self::TERM_META_BUFFER, true );
			$max = get_term_meta( $term_id, self::TERM_META_MAX_QTY, true );

			if ( '' === $buf && '' === $max ) {
				continue;
			}

			$depth = function_exists( 'get_ancestors' )
				? count( get_ancestors( $term_id, Category_Rules::TAXONOMY, 'taxonomy' ) )
				: 0;

			$candidates[] = array(
				'term_id' => $term_id,
				'depth'   => $depth,
				'buffer'  => '' !== $buf ? max( 0, (int) $buf ) : 0,
				'max_qty' => ( '' !== $max && (int) $max > 0 ) ? (int) $max : null,
			);
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				if ( $a['depth'] === $b['depth'] ) {
					return $a['term_id'] <=> $b['term_id'];
				}

				return $b['depth'] <=> $a['depth'];
			}
		);

		$winner = $candidates[0];

		return array(
			'enabled' => true,
			'buffer'  => $winner['buffer'],
			'max_qty' => $winner['max_qty'],
			'source'  => 'category',
		);
	}

	/**
	 * Calculate exported quantity applying buffer, caps, and safety invariants.
	 *
	 * Formula for managed stock:
	 * min(999999, max(0, source_quantity - reserve), marketplace_maximum)
	 *
	 * Invariants:
	 * - Unmanaged stock (null) remains null; never fabricate numeric stock.
	 * - When disabled, returns unadjusted source quantity clamped to 0..999999.
	 * - Never increase exported stock above effective source stock.
	 * - If product is out of stock in WooCommerce and not backorderable, returns 0.
	 * - Never returns negative quantity.
	 *
	 * Supports either:
	 *   calculate_quantity( \WC_Product $product, array $term_ids = array() )
	 * or:
	 *   calculate_quantity( ?int $source_qty, array $rules, ?\WC_Product $product = null )
	 *
	 * @param \WC_Product|int|null     $product_or_qty
	 * @param array<string, mixed>     $rules_or_terms
	 * @param \WC_Product|null         $product
	 * @return int|null
	 */
	public static function calculate_quantity( $product_or_qty, array $rules_or_terms = array(), ?\WC_Product $product = null, bool $force_enable = false ): ?int {
		if ( $product_or_qty instanceof \WC_Product ) {
			$product    = $product_or_qty;
			$source_qty = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
			$parent_id  = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : null;
			$term_ids   = $rules_or_terms;
			$rules      = self::resolve_rules( (int) $product->get_id(), $term_ids, $parent_id, $force_enable );
		} else {
			$source_qty = null !== $product_or_qty ? (int) $product_or_qty : null;
			$rules      = $rules_or_terms;
			if ( $force_enable && is_array( $rules ) ) {
				$rules['enabled'] = true;
			}
		}

		if ( null === $source_qty ) {
			return null;
		}

		$stock_status = ( $product && method_exists( $product, 'get_stock_status' ) )
			? (string) $product->get_stock_status()
			: ( ( null === $product || $product->is_in_stock() ) ? 'instock' : 'outofstock' );
		$is_in_stock  = ( 'outofstock' !== $stock_status ) && ( null === $product || $product->is_in_stock() );
		$backorders   = $product && method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed();

		if ( ! $is_in_stock && ! $backorders ) {
			return 0;
		}

		$raw = max( 0, $source_qty );

		if ( empty( $rules['enabled'] ) ) {
			return min( self::MAX_QUANTITY, $raw );
		}

		$buffer  = max( 0, (int) ( $rules['buffer'] ?? 0 ) );
		$max_qty = isset( $rules['max_qty'] ) && null !== $rules['max_qty'] && (int) $rules['max_qty'] > 0
			? (int) $rules['max_qty']
			: null;

		// 1. Subtract reserve buffer: max(0, source_quantity - reserve)
		$qty = max( 0, $raw - $buffer );

		// 2. Apply marketplace maximum if configured
		if ( null !== $max_qty ) {
			$qty = min( $qty, $max_qty );
		}

		// 3. Clamp within 0..999999 and ensure it never exceeds effective source stock
		$qty = min( self::MAX_QUANTITY, max( 0, $qty ) );
		$qty = min( $qty, $raw );

		return $qty;
	}
}
