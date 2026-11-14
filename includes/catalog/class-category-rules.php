<?php
/**
 * Category inclusion rules and category level defaults.
 *
 * Rule resolution is deterministic:
 *  - The deepest category with an explicit include/exclude decision wins.
 *  - An explicit include is inherited by children only when the category's
 *    "include children" flag is enabled (default: on).
 *  - An explicit exclude is always inherited.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Category_Rules {

	public const TAXONOMY = 'product_cat';

	public const META_INCLUDE          = 'wps_include';
	public const META_INCLUDE_CHILDREN = 'wps_include_children';
	public const META_GTIN_REQUIRED    = 'wps_gtin_required';
	public const META_PRICE_TYPE       = 'wps_price_adjustment_type';
	public const META_PRICE_VALUE      = 'wps_price_adjustment_value';
	public const META_SHIPPING_DELAY   = 'wps_shipping_delay';

	public const DECISION_INCLUDE = 'include';
	public const DECISION_EXCLUDE = 'exclude';
	public const DECISION_NONE    = 'none';

	/** @var array<int, string> */
	private static array $decision_cache = array();

	/** @var array<int, array<string, mixed>> */
	private static array $term_cache = array();

	/**
	 * Resolve the effective decision for a single category.
	 */
	public static function decision( int $term_id ): string {
		if ( isset( self::$decision_cache[ $term_id ] ) ) {
			return self::$decision_cache[ $term_id ];
		}

		$current   = $term_id;
		$is_self   = true;
		$visited   = array();
		$decision  = self::DECISION_NONE;

		while ( $current > 0 && ! isset( $visited[ $current ] ) ) {
			$visited[ $current ] = true;
			$term                = self::term( $current );

			if ( null === $term ) {
				break;
			}

			$explicit = (string) get_term_meta( $current, self::META_INCLUDE, true );

			if ( self::DECISION_INCLUDE === $explicit || self::DECISION_EXCLUDE === $explicit ) {
				if ( ! $is_self && self::DECISION_INCLUDE === $explicit && ! self::children_allowed( $current ) ) {
					$decision = self::DECISION_NONE;
				} else {
					$decision = $explicit;
				}

				break;
			}

			$current = (int) $term->parent;
			$is_self = false;
		}

		self::$decision_cache[ $term_id ] = $decision;

		return $decision;
	}

	/**
	 * Resolve a product from its assigned category ids.
	 *
	 * Exclusion wins when a product is assigned to both an included and an
	 * excluded category: the safer choice is never to publish it.
	 *
	 * @param int[] $term_ids
	 * @return array{decision: string, category_id: int}
	 */
	public static function decision_for_terms( array $term_ids ): array {
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

		if ( array() === $term_ids ) {
			return array(
				'decision'    => self::DECISION_NONE,
				'category_id' => 0,
			);
		}

		$included = 0;
		$excluded = 0;

		foreach ( $term_ids as $term_id ) {
			$decision = self::decision( $term_id );

			if ( self::DECISION_EXCLUDE === $decision && 0 === $excluded ) {
				$excluded = $term_id;
			}

			if ( self::DECISION_INCLUDE === $decision && 0 === $included ) {
				$included = $term_id;
			}
		}

		if ( $excluded > 0 ) {
			return array(
				'decision'    => self::DECISION_EXCLUDE,
				'category_id' => $excluded,
			);
		}

		if ( $included > 0 ) {
			return array(
				'decision'    => self::DECISION_INCLUDE,
				'category_id' => $included,
			);
		}

		return array(
			'decision'    => self::DECISION_NONE,
			'category_id' => 0,
		);
	}

	public static function children_allowed( int $term_id ): bool {
		$meta = get_term_meta( $term_id, self::META_INCLUDE_CHILDREN, true );

		if ( '' === $meta || null === $meta ) {
			return (bool) Settings::get( 'include_child_categories', true );
		}

		return '1' === (string) $meta || 'yes' === (string) $meta || true === $meta;
	}

	/**
	 * Category level price adjustment, deepest configured category wins.
	 *
	 * @param int[] $term_ids
	 * @return array{type: string, value: float}|null
	 */
	public static function price_adjustment( array $term_ids ): ?array {
		$candidates = array();

		foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
			$type = (string) get_term_meta( $term_id, self::META_PRICE_TYPE, true );

			if ( ! in_array( $type, array( 'none', 'fixed', 'percent' ), true ) ) {
				continue;
			}

			$candidates[] = array(
				'term_id' => $term_id,
				'depth'   => count( get_ancestors( $term_id, self::TAXONOMY, 'taxonomy' ) ),
				'type'    => $type,
				'value'   => (float) get_term_meta( $term_id, self::META_PRICE_VALUE, true ),
			);
		}

		if ( array() === $candidates ) {
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

		return array(
			'type'  => $candidates[0]['type'],
			'value' => $candidates[0]['value'],
		);
	}

	/**
	 * @param int[] $term_ids
	 */
	public static function shipping_delay( array $term_ids ): ?int {
		$candidates = array();

		foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
			$raw = get_term_meta( $term_id, self::META_SHIPPING_DELAY, true );

			if ( '' === $raw || null === $raw ) {
				continue;
			}

			$candidates[] = array(
				'term_id' => $term_id,
				'depth'   => count( get_ancestors( $term_id, self::TAXONOMY, 'taxonomy' ) ),
				'value'   => max( 0, (int) $raw ),
			);
		}

		if ( array() === $candidates ) {
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

		return (int) $candidates[0]['value'];
	}

	/**
	 * @param int[] $term_ids
	 */
	public static function gtin_required( array $term_ids ): bool {
		$configured = Settings::gtin_required_categories();

		foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
			if ( in_array( $term_id, $configured, true ) ) {
				return true;
			}

			if ( '1' === (string) get_term_meta( $term_id, self::META_GTIN_REQUIRED, true ) ) {
				return true;
			}

			foreach ( get_ancestors( $term_id, self::TAXONOMY, 'taxonomy' ) as $ancestor ) {
				if ( in_array( (int) $ancestor, $configured, true ) || '1' === (string) get_term_meta( (int) $ancestor, self::META_GTIN_REQUIRED, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if GTIN is required for a single term ID.
	 *
	 * @param int $term_id
	 * @return bool
	 */
	public static function is_gtin_required( int $term_id ): bool {
		return self::gtin_required( array( $term_id ) );
	}

	/**
	 * Persist the category form values.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function save( int $term_id, array $data ): void {
		$include = isset( $data['include'] ) ? (string) $data['include'] : 'inherit';

		if ( in_array( $include, array( self::DECISION_INCLUDE, self::DECISION_EXCLUDE ), true ) ) {
			update_term_meta( $term_id, self::META_INCLUDE, $include );
		} else {
			delete_term_meta( $term_id, self::META_INCLUDE );
		}

		update_term_meta( $term_id, self::META_INCLUDE_CHILDREN, empty( $data['include_children'] ) ? '0' : '1' );
		update_term_meta( $term_id, self::META_GTIN_REQUIRED, empty( $data['gtin_required'] ) ? '0' : '1' );

		$price_type = isset( $data['price_adjustment_type'] ) ? (string) $data['price_adjustment_type'] : '';

		if ( in_array( $price_type, array( 'none', 'fixed', 'percent' ), true ) ) {
			update_term_meta( $term_id, self::META_PRICE_TYPE, $price_type );
			update_term_meta( $term_id, self::META_PRICE_VALUE, (float) ( $data['price_adjustment_value'] ?? 0 ) );
		} else {
			delete_term_meta( $term_id, self::META_PRICE_TYPE );
			delete_term_meta( $term_id, self::META_PRICE_VALUE );
		}

		$delay = isset( $data['shipping_delay'] ) ? trim( (string) $data['shipping_delay'] ) : '';

		if ( '' === $delay ) {
			delete_term_meta( $term_id, self::META_SHIPPING_DELAY );
		} else {
			update_term_meta( $term_id, self::META_SHIPPING_DELAY, max( 0, (int) $delay ) );
		}

		if ( array_key_exists( 'inventory_buffer', $data ) ) {
			$buf = trim( (string) $data['inventory_buffer'] );
			if ( '' === $buf ) {
				delete_term_meta( $term_id, Marketplace_Inventory::TERM_META_BUFFER );
			} else {
				update_term_meta( $term_id, Marketplace_Inventory::TERM_META_BUFFER, max( 0, min( 999999, (int) $buf ) ) );
			}
		}

		if ( array_key_exists( 'inventory_max_qty', $data ) ) {
			$max = trim( (string) $data['inventory_max_qty'] );
			if ( '' === $max || (int) $max <= 0 ) {
				delete_term_meta( $term_id, Marketplace_Inventory::TERM_META_MAX_QTY );
			} else {
				update_term_meta( $term_id, Marketplace_Inventory::TERM_META_MAX_QTY, min( 999999, (int) $max ) );
			}
		}

		if ( array_key_exists( 'commission_percent', $data ) ) {
			$comm = trim( (string) $data['commission_percent'] );
			if ( '' === $comm ) {
				delete_term_meta( $term_id, Marketplace_Pricing::TERM_META_COMMISSION_PERCENT );
			} else {
				update_term_meta( $term_id, Marketplace_Pricing::TERM_META_COMMISSION_PERCENT, max( 0.0, min( 100.0, (float) str_replace( ',', '.', $comm ) ) ) );
			}
		}

		if ( array_key_exists( 'min_margin', $data ) ) {
			$mgn = trim( (string) $data['min_margin'] );
			if ( '' === $mgn ) {
				delete_term_meta( $term_id, Marketplace_Pricing::TERM_META_MIN_MARGIN );
			} else {
				update_term_meta( $term_id, Marketplace_Pricing::TERM_META_MIN_MARGIN, max( 0.0, min( 100.0, (float) str_replace( ',', '.', $mgn ) ) ) );
			}
		}

		self::clear_cache();
	}

	public static function included_category_count(): int {
		$cached = get_transient( 'wps_pepita_included_category_count' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 5000,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_INCLUDE,
						'value' => self::DECISION_INCLUDE,
					),
				),
			)
		);

		$count = is_array( $terms ) ? count( $terms ) : 0;

		set_transient( 'wps_pepita_included_category_count', $count, 10 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Check whether at least one category has an effective include decision.
	 */
	public static function has_included_categories(): bool {
		return self::count_included_categories() > 0;
	}

	/**
	 * Count of categories whose effective decision is include.
	 */
	public static function count_included_categories(): int {
		return count( self::get_included_category_ids() );
	}

	/**
	 * @return int[]
	 */
	public static function get_included_category_ids(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( ! is_array( $terms ) || array() === $terms ) {
			return array();
		}

		$included = array();
		foreach ( $terms as $term_id ) {
			$term_id = (int) $term_id;
			if ( self::DECISION_INCLUDE === self::decision( $term_id ) ) {
				$included[] = $term_id;
			}
		}

		return $included;
	}

	/**
	 * @return int[]
	 */
	public static function get_excluded_category_ids(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( ! is_array( $terms ) || array() === $terms ) {
			return array();
		}

		$excluded = array();
		foreach ( $terms as $term_id ) {
			$term_id = (int) $term_id;
			if ( self::DECISION_EXCLUDE === self::decision( $term_id ) ) {
				$excluded[] = $term_id;
			}
		}

		return $excluded;
	}

	public static function clear_cache(): void {
		self::$decision_cache = array();
		self::$term_cache     = array();
		delete_transient( 'wps_pepita_included_category_count' );
		if ( class_exists( Product_Selection::class, false ) ) {
			Product_Selection::clear_resolution_cache();
		}
	}

	/**
	 * @return \WP_Term|null
	 */
	private static function term( int $term_id ) {
		if ( array_key_exists( $term_id, self::$term_cache ) ) {
			return self::$term_cache[ $term_id ] ?: null;
		}

		$term = get_term( $term_id, self::TAXONOMY );

		self::$term_cache[ $term_id ] = ( $term && ! is_wp_error( $term ) ) ? $term : null;

		return self::$term_cache[ $term_id ];
	}
}
