<?php
/**
 * Marketplace pricing calculator, cost-plus margins, and fee modeling.
 *
 * WooCommerce source prices are never modified. All adjustments and margin
 * warnings are calculated only for feed representation and administrative review.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Marketplace_Pricing {

	public const META_COMMISSION_PERCENT = '_wps_commission_percent';
	public const META_COMMISSION_FIXED   = '_wps_commission_fixed';
	public const META_PACKAGING_COST     = '_wps_packaging_cost';
	public const META_SHIPPING_COST      = '_wps_shipping_cost';
	public const META_COST_PRICE         = '_wps_cost_price';
	public const META_MIN_MARGIN         = '_wps_min_margin';

	public const TERM_META_COMMISSION_PERCENT = 'wps_commission_percent';
	public const TERM_META_COMMISSION_FIXED   = 'wps_commission_fixed';
	public const TERM_META_PACKAGING_COST     = 'wps_packaging_cost';
	public const TERM_META_SHIPPING_COST      = 'wps_shipping_cost';
	public const TERM_META_MIN_MARGIN         = 'wps_min_margin';

	/**
	 * Check whether the marketplace pricing strategy is globally enabled.
	 */
	public static function is_enabled(): bool {
		return (bool) Settings::get( 'pricing_strategy_enabled', false );
	}

	/**
	 * Resolve cost and fee inputs for a product / variation.
	 *
	 * Precedence:
	 * 1. Product-level override (for a variation, variation meta; if not set, inherits from parent product).
	 * 2. Category-level override (deepest category).
	 * 3. Global fallback settings.
	 *
	 * @param int      $product_id
	 * @param int[]    $term_ids
	 * @param int|null $parent_id
	 * @return array{
	 *     commission_percent: float,
	 *     commission_fixed: float,
	 *     packaging_cost: float,
	 *     shipping_cost: float,
	 *     cost_price: ?float,
	 *     min_margin: ?float,
	 *     source: string
	 * }
	 */
	public static function resolve_cost_inputs( int $product_id, array $term_ids = array(), ?int $parent_id = null ): array {
		// 1. Direct product-level metadata.
		$prod_comm_pct = get_post_meta( $product_id, self::META_COMMISSION_PERCENT, true );
		$prod_comm_fix = get_post_meta( $product_id, self::META_COMMISSION_FIXED, true );
		$prod_pkg      = get_post_meta( $product_id, self::META_PACKAGING_COST, true );
		$prod_ship     = get_post_meta( $product_id, self::META_SHIPPING_COST, true );
		$prod_cost     = self::read_cost_meta( $product_id );
		$prod_min_mgn  = get_post_meta( $product_id, self::META_MIN_MARGIN, true );

		$has_prod_rule = '' !== $prod_comm_pct
			|| '' !== $prod_comm_fix
			|| '' !== $prod_pkg
			|| '' !== $prod_ship
			|| null !== $prod_cost
			|| '' !== $prod_min_mgn;

		if ( $has_prod_rule ) {
			return array(
				'commission_percent' => '' !== $prod_comm_pct ? (float) $prod_comm_pct : (float) Settings::get( 'marketplace_commission_percent', 0.0 ),
				'commission_fixed'   => '' !== $prod_comm_fix ? (float) $prod_comm_fix : (float) Settings::get( 'marketplace_commission_fixed', 0.0 ),
				'packaging_cost'     => '' !== $prod_pkg ? (float) $prod_pkg : (float) Settings::get( 'marketplace_packaging_cost', 0.0 ),
				'shipping_cost'      => '' !== $prod_ship ? (float) $prod_ship : (float) Settings::get( 'marketplace_shipping_cost', 0.0 ),
				'cost_price'         => $prod_cost,
				'min_margin'         => '' !== $prod_min_mgn ? (float) $prod_min_mgn : self::default_min_margin(),
				'source'             => 'product',
			);
		}

		// If variation has no direct overrides, check parent product overrides.
		if ( null !== $parent_id && $parent_id > 0 && $parent_id !== $product_id ) {
			$parent_comm_pct = get_post_meta( $parent_id, self::META_COMMISSION_PERCENT, true );
			$parent_comm_fix = get_post_meta( $parent_id, self::META_COMMISSION_FIXED, true );
			$parent_pkg      = get_post_meta( $parent_id, self::META_PACKAGING_COST, true );
			$parent_ship     = get_post_meta( $parent_id, self::META_SHIPPING_COST, true );
			$parent_cost     = self::read_cost_meta( $parent_id );
			$parent_min_mgn  = get_post_meta( $parent_id, self::META_MIN_MARGIN, true );

			$has_parent_rule = '' !== $parent_comm_pct
				|| '' !== $parent_comm_fix
				|| '' !== $parent_pkg
				|| '' !== $parent_ship
				|| null !== $parent_cost
				|| '' !== $parent_min_mgn;

			if ( $has_parent_rule ) {
				return array(
					'commission_percent' => '' !== $parent_comm_pct ? (float) $parent_comm_pct : (float) Settings::get( 'marketplace_commission_percent', 0.0 ),
					'commission_fixed'   => '' !== $parent_comm_fix ? (float) $parent_comm_fix : (float) Settings::get( 'marketplace_commission_fixed', 0.0 ),
					'packaging_cost'     => '' !== $parent_pkg ? (float) $parent_pkg : (float) Settings::get( 'marketplace_packaging_cost', 0.0 ),
					'shipping_cost'      => '' !== $parent_ship ? (float) $parent_ship : (float) Settings::get( 'marketplace_shipping_cost', 0.0 ),
					'cost_price'         => $parent_cost,
					'min_margin'         => '' !== $parent_min_mgn ? (float) $parent_min_mgn : self::default_min_margin(),
					'source'             => 'variation_parent',
				);
			}
		}

		// 2. Category-level overrides.
		$cat_rule = self::resolve_category_cost_rule( $term_ids );
		if ( null !== $cat_rule ) {
			// Cost of goods (acquisition cost) is product-specific, not category-level.
			$cat_rule['cost_price'] = $prod_cost;
			return $cat_rule;
		}

		// 3. Global fallback settings.
		return array(
			'commission_percent' => (float) Settings::get( 'marketplace_commission_percent', 0.0 ),
			'commission_fixed'   => (float) Settings::get( 'marketplace_commission_fixed', 0.0 ),
			'packaging_cost'     => (float) Settings::get( 'marketplace_packaging_cost', 0.0 ),
			'shipping_cost'      => (float) Settings::get( 'marketplace_shipping_cost', 0.0 ),
			'cost_price'         => $prod_cost,
			'min_margin'         => self::default_min_margin(),
			'source'             => 'global',
		);
	}

	/**
	 * Read cost of goods meta across standard WooCommerce COGS plugins or internal meta.
	 */
	public static function read_cost_meta( int $product_id ): ?float {
		$keys = array(
			self::META_COST_PRICE,
			'_cogs_cost',
			'_wc_cog_cost',
			'_cost_price',
			'cost_price',
		);

		foreach ( $keys as $key ) {
			$raw = get_post_meta( $product_id, $key, true );
			if ( '' !== $raw && null !== $raw && is_numeric( $raw ) ) {
				$val = (float) $raw;
				if ( $val >= 0.0 ) {
					return $val;
				}
			}
		}

		return null;
	}

	private static function default_min_margin(): ?float {
		$val = Settings::get( 'marketplace_min_margin_percent', null );
		if ( null !== $val && '' !== (string) $val && is_numeric( $val ) && (float) $val > 0.0 ) {
			return (float) $val;
		}

		return null;
	}

	/**
	 * Resolve category-level cost rules by deepest category.
	 *
	 * @param int[] $term_ids
	 * @return array<string, mixed>|null
	 */
	private static function resolve_category_cost_rule( array $term_ids ): ?array {
		if ( empty( $term_ids ) ) {
			return null;
		}

		$candidates = array();

		foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}

			$comm_pct = get_term_meta( $term_id, self::TERM_META_COMMISSION_PERCENT, true );
			$comm_fix = get_term_meta( $term_id, self::TERM_META_COMMISSION_FIXED, true );
			$pkg      = get_term_meta( $term_id, self::TERM_META_PACKAGING_COST, true );
			$ship     = get_term_meta( $term_id, self::TERM_META_SHIPPING_COST, true );
			$min_mgn  = get_term_meta( $term_id, self::TERM_META_MIN_MARGIN, true );

			$has_rule = '' !== $comm_pct
				|| '' !== $comm_fix
				|| '' !== $pkg
				|| '' !== $ship
				|| '' !== $min_mgn;

			if ( ! $has_rule ) {
				continue;
			}

			$depth = function_exists( 'get_ancestors' )
				? count( get_ancestors( $term_id, Category_Rules::TAXONOMY, 'taxonomy' ) )
				: 0;

			$candidates[] = array(
				'term_id'            => $term_id,
				'depth'              => $depth,
				'commission_percent' => '' !== $comm_pct ? (float) $comm_pct : (float) Settings::get( 'marketplace_commission_percent', 0.0 ),
				'commission_fixed'   => '' !== $comm_fix ? (float) $comm_fix : (float) Settings::get( 'marketplace_commission_fixed', 0.0 ),
				'packaging_cost'     => '' !== $pkg ? (float) $pkg : (float) Settings::get( 'marketplace_packaging_cost', 0.0 ),
				'shipping_cost'      => '' !== $ship ? (float) $ship : (float) Settings::get( 'marketplace_shipping_cost', 0.0 ),
				'min_margin'         => '' !== $min_mgn ? (float) $min_mgn : self::default_min_margin(),
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
			'commission_percent' => $winner['commission_percent'],
			'commission_fixed'   => $winner['commission_fixed'],
			'packaging_cost'     => $winner['packaging_cost'],
			'shipping_cost'      => $winner['shipping_cost'],
			'cost_price'         => null,
			'min_margin'         => $winner['min_margin'],
			'source'             => 'category',
		);
	}

	/**
	 * Perform detailed financial calculations for a product price.
	 *
	 * Formulas:
	 * - gross_price: final exported selling price (incl. VAT)
	 * - net_price: gross_price / (1.0 + (vat_percent / 100.0))
	 * - vat_amount: gross_price - net_price
	 * - commission_fee: (gross_price * (commission_percent / 100.0)) + commission_fixed
	 * - operational_fees: commission_fee + packaging_cost + shipping_cost
	 * - net_revenue_after_fees: net_price - operational_fees
	 * - estimated_profit: net_revenue_after_fees - cost_price (only if cost_price is known)
	 * - estimated_margin: (estimated_profit / net_price) * 100 (only if cost_price is known and net_price > 0)
	 *
	 * @param \WC_Product          $product
	 * @param float                $gross_price
	 * @param array<string, mixed> $cost_inputs
	 * @return array<string, mixed>
	 */
	public static function calculate( \WC_Product $product, float $gross_price, array $cost_inputs ): array {
		$gross_price = max( 0.0, round( $gross_price, 2 ) );
		$vat_percent = (float) Price_Calculator::vat_percent( $product );
		$net_price   = (float) Price_Calculator::net( $gross_price, $vat_percent );
		$vat_amount  = max( 0.0, round( $gross_price - $net_price, 2 ) );

		$comm_pct = max( 0.0, (float) ( $cost_inputs['commission_percent'] ?? 0.0 ) );
		$comm_fix = max( 0.0, (float) ( $cost_inputs['commission_fixed'] ?? 0.0 ) );
		$pkg_cost = max( 0.0, (float) ( $cost_inputs['packaging_cost'] ?? 0.0 ) );
		$shp_cost = max( 0.0, (float) ( $cost_inputs['shipping_cost'] ?? 0.0 ) );

		// Marketplace commission basis is gross selling price
		$commission_basis = $gross_price;
		$commission_fee   = round( ( $commission_basis * ( $comm_pct / 100.0 ) ) + $comm_fix, 2 );
		$operational_fees = round( $commission_fee + $pkg_cost + $shp_cost, 2 );

		$net_after_fees = round( $net_price - $operational_fees, 2 );

		$cost_price = isset( $cost_inputs['cost_price'] ) && null !== $cost_inputs['cost_price']
			? round( (float) $cost_inputs['cost_price'], 2 )
			: null;

		$min_margin = isset( $cost_inputs['min_margin'] ) && null !== $cost_inputs['min_margin']
			? (float) $cost_inputs['min_margin']
			: null;

		$has_cost_data    = null !== $cost_price && $cost_price >= 0.0;
		$estimated_profit = null;
		$estimated_margin = null;
		$margin_status    = 'insufficient_data';

		if ( $has_cost_data ) {
			$estimated_profit = round( $net_after_fees - $cost_price, 2 );

			if ( $net_price > 0.0 ) {
				$estimated_margin = round( ( $estimated_profit / $net_price ) * 100.0, 2 );
			} else {
				$estimated_margin = 0.0;
			}

			if ( null !== $min_margin && $min_margin > 0.0 && $estimated_margin < $min_margin ) {
				$margin_status = 'below_threshold';
			} else {
				$margin_status = 'ok';
			}
		}

		return array(
			'gross_price'            => $gross_price,
			'vat_percent'            => $vat_percent,
			'vat_amount'             => $vat_amount,
			'net_price'              => $net_price,
			'commission_basis'       => $commission_basis,
			'commission_percent'     => $comm_pct,
			'commission_fixed'       => $comm_fix,
			'commission_fee'         => $commission_fee,
			'packaging_cost'         => $pkg_cost,
			'shipping_cost'          => $shp_cost,
			'operational_fees'       => $operational_fees,
			'net_revenue_after_fees' => $net_after_fees,
			'cost_price'             => $cost_price,
			'has_cost_data'          => $has_cost_data,
			'estimated_profit'       => $estimated_profit,
			'estimated_margin'       => $estimated_margin,
			'min_margin'             => $min_margin,
			'margin_status'          => $margin_status,
			'source'                 => (string) ( $cost_inputs['source'] ?? 'global' ),
		);
	}
}
