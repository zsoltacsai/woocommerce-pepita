<?php
/**
 * Deterministic product inclusion rules and admin product querying.
 *
 * Priority (highest first):
 *   1. Explicit product exclusion.
 *   2. Explicit product inclusion.
 *   3. Category rules.
 *   4. Global default.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

use WPS\Pepita\Logger;
use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Product_Selection {

	public const META_INCLUDE = '_wps_include';

	public const MODE_DEFAULT  = 'default';
	public const MODE_INCLUDE  = 'include';
	public const MODE_EXCLUDE  = 'exclude';

	public const SOURCE_PRODUCT_EXCLUDED = 'product_excluded';
	public const SOURCE_PRODUCT_INCLUDED = 'product_included';
	public const SOURCE_CATEGORY         = 'category';
	public const SOURCE_DEFAULT          = 'default';
	public const SOURCE_QUALITY_EXCLUDED = 'quality_excluded';

	/** @var array<int, array{included: bool, source: string, category_id: int}> */
	private static array $resolution_cache = array();

	/**
	 * Resolve inclusion for one product.
	 *
	 * @return array{included: bool, source: string, category_id: int}
	 */
	public static function resolve( int $product_id ): array {
		if ( isset( self::$resolution_cache[ $product_id ] ) ) {
			return self::$resolution_cache[ $product_id ];
		}

		$base = self::resolve_base( $product_id );

		if ( $base['included'] && Settings::exclude_quality_issues() ) {
			if ( class_exists( \WPS\Pepita\Admin\Product_Issues::class ) && \WPS\Pepita\Admin\Product_Issues::has_blocking_issues( $product_id ) ) {
				return self::$resolution_cache[ $product_id ] = array(
					'included'    => false,
					'source'      => self::SOURCE_QUALITY_EXCLUDED,
					'category_id' => $base['category_id'],
				);
			}
		}

		return self::$resolution_cache[ $product_id ] = $base;
	}

	/**
	 * Base deterministic inclusion resolution without quality exclusions.
	 *
	 * @return array{included: bool, source: string, category_id: int}
	 */
	public static function resolve_base( int $product_id ): array {
		$explicit = (string) get_post_meta( $product_id, self::META_INCLUDE, true );

		if ( self::MODE_EXCLUDE === $explicit ) {
			return array(
				'included'    => false,
				'source'      => self::SOURCE_PRODUCT_EXCLUDED,
				'category_id' => 0,
			);
		}

		if ( Settings::is_beta_feed_only() ) {
			$term_ids = self::category_ids( $product_id );

			if ( array() === $term_ids ) {
				return array(
					'included'    => false,
					'source'      => 'beta_unapproved_category',
					'category_id' => 0,
				);
			}

			$category = Category_Rules::decision_for_terms( $term_ids );

			if ( Category_Rules::DECISION_EXCLUDE === $category['decision'] ) {
				return array(
					'included'    => false,
					'source'      => self::SOURCE_CATEGORY,
					'category_id' => $category['category_id'],
				);
			}

			if ( Category_Rules::DECISION_INCLUDE === $category['decision'] ) {
				return array(
					'included'    => true,
					'source'      => ( self::MODE_INCLUDE === $explicit ) ? self::SOURCE_PRODUCT_INCLUDED : self::SOURCE_CATEGORY,
					'category_id' => $category['category_id'],
				);
			}

			return array(
				'included'    => false,
				'source'      => 'beta_unapproved_category',
				'category_id' => 0,
			);
		}

		if ( self::MODE_INCLUDE === $explicit ) {
			return array(
				'included'    => true,
				'source'      => self::SOURCE_PRODUCT_INCLUDED,
				'category_id' => 0,
			);
		}

		$term_ids = self::category_ids( $product_id );
		$category = Category_Rules::decision_for_terms( $term_ids );

		if ( Category_Rules::DECISION_EXCLUDE === $category['decision'] ) {
			return array(
				'included'    => false,
				'source'      => self::SOURCE_CATEGORY,
				'category_id' => $category['category_id'],
			);
		}

		if ( Category_Rules::DECISION_INCLUDE === $category['decision'] ) {
			return array(
				'included'    => true,
				'source'      => self::SOURCE_CATEGORY,
				'category_id' => $category['category_id'],
			);
		}

		return array(
			'included'    => (bool) Settings::get( 'default_included', false ),
			'source'      => self::SOURCE_DEFAULT,
			'category_id' => 0,
		);
	}

	public static function is_included( int $product_id ): bool {
		return self::resolve( $product_id )['included'];
	}

	/**
	 * @return int[]
	 */
	public static function category_ids( int $product_id ): array {
		$terms = wp_get_post_terms( $product_id, Category_Rules::TAXONOMY, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $terms ) ) );
	}

	/**
	 * Bulk set explicit product inclusion.
	 *
	 * @param int[] $product_ids
	 * @return int Number of changed products.
	 */
	public static function bulk_set( array $product_ids, string $mode ): int {
		$mode = in_array( $mode, array( self::MODE_INCLUDE, self::MODE_EXCLUDE, self::MODE_DEFAULT ), true )
			? $mode
			: self::MODE_DEFAULT;

		$changed = 0;

		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $product_id ) {
			if ( $product_id <= 0 || ! current_user_can( 'edit_product', $product_id ) ) {
				continue;
			}

			if ( self::MODE_DEFAULT === $mode ) {
				delete_post_meta( $product_id, self::META_INCLUDE );
			} else {
				update_post_meta( $product_id, self::META_INCLUDE, $mode );
			}

			++$changed;
		}

		self::clear_cache();

		if ( $changed > 0 ) {
			Logger::info(
				'product_selection',
				'Termékbesorolás módosítva.',
				array( 'mode' => $mode, 'count' => $changed )
			);
		}

		return $changed;
	}

	/**
	 * Admin product query.
	 *
	 * @param array<string, mixed> $args
	 * @return array{ids: int[], total: int, truncated: bool, per_page: int, page: int}
	 */
	public static function query( array $args ): array {
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$mode     = (string) ( $args['inclusion'] ?? 'all' );
		$search   = trim( (string) ( $args['search'] ?? '' ) );

		$base = array(
			'status'  => Settings::allowed_product_statuses(),
			'orderby' => 'ID',
			'order'   => 'DESC',
		);

		if ( '' !== $search ) {
			$base['search'] = $search;
		}

		$category = (int) ( $args['category'] ?? 0 );
		if ( $category > 0 ) {
			$base['category'] = array( $category );
		}

		$type = (string) ( $args['type'] ?? '' );
		if ( '' !== $type ) {
			$base['type'] = array( $type );
		}

		$stock = (string) ( $args['stock_status'] ?? '' );
		if ( '' !== $stock ) {
			$base['stock_status'] = $stock;
		}

		if ( 'out_of_stock' === $mode ) {
			$base['stock_status'] = 'outofstock';
			$mode                 = 'all';
		}

		if ( 'all' === $mode || 'out_of_stock' === $mode ) {
			$base['limit']    = $per_page;
			$base['page']     = $page;
			$base['paginate'] = true;
			$base['return']   = 'ids';

			$result = wc_get_products( $base );

			$ids = is_object( $result ) && isset( $result->products ) ? $result->products : array();
			$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

			return array(
				'ids'       => $ids,
				'total'     => is_object( $result ) ? (int) $result->total : count( $ids ),
				'truncated' => false,
				'per_page'  => $per_page,
				'page'      => $page,
			);
		}

		if ( 'invalid' === $mode ) {
			return self::query_by_meta( $base, $per_page, $page, self::invalid_meta_query() );
		}

		if ( 'individual' === $mode ) {
			return self::query_by_meta(
				$base,
				$per_page,
				$page,
				array(
					array(
						'key'   => self::META_INCLUDE,
						'value' => self::MODE_INCLUDE,
					),
				)
			);
		}

		// Modes that need rule resolution are scanned with a hard cap.
		$cap = (int) apply_filters( 'wps_pepita_admin_scan_limit', 5000 );
		$cap = max( 200, $cap );

		$matched = array();
		$scanned = 0;
		$paged   = 1;
		$truncated = false;

		do {
			$batch_args         = $base;
			$batch_args['limit']  = 200;
			$batch_args['page']   = $paged;
			$batch_args['return'] = 'ids';

			$batch = wc_get_products( $batch_args );
			$batch = is_array( $batch ) ? array_map( 'intval', $batch ) : array();

			if ( array() === $batch ) {
				break;
			}

			update_object_term_cache( $batch, 'product' );

			foreach ( $batch as $product_id ) {
				++$scanned;

				$resolution = self::resolve( $product_id );
				$keep       = match ( $mode ) {
					'included' => $resolution['included'],
					'excluded' => ! $resolution['included'],
					'category' => self::SOURCE_CATEGORY === $resolution['source'],
					default    => true,
				};

				if ( $keep ) {
					$matched[] = $product_id;
				}

				if ( $scanned >= $cap ) {
					$truncated = true;
					break 2;
				}
			}

			++$paged;
		} while ( true );

		$total  = count( $matched );
		$offset = ( $page - 1 ) * $per_page;

		return array(
			'ids'       => array_slice( $matched, $offset, $per_page ),
			'total'     => $total,
			'truncated' => $truncated,
			'per_page'  => $per_page,
			'page'      => $page,
		);
	}

	/**
	 * @param array<string, mixed>   $base
	 * @param array<int, array<string, mixed>> $meta_query
	 * @return array{ids: int[], total: int, truncated: bool, per_page: int, page: int}
	 */
	private static function query_by_meta( array $base, int $per_page, int $page, array $meta_query ): array {
		$query_args = array(
			'post_type'              => 'product',
			'post_status'            => $base['status'],
			'fields'                 => 'ids',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'ID',
			'order'                  => $base['order'] ?? 'DESC',
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'wps_pepita_sku_search'  => $base['search'] ?? '',
		);

		if ( isset( $base['category'] ) ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy'         => Category_Rules::TAXONOMY,
					'field'            => 'term_id',
					'terms'            => $base['category'],
					'include_children' => true,
				),
			);
		}

		if ( isset( $base['type'] ) ) {
			$query_args['tax_query'][] = array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $base['type'],
			);
		}

		if ( isset( $base['stock_status'] ) ) {
			$query_args['meta_query'][] = array(
				'key'   => '_stock_status',
				'value' => $base['stock_status'],
			);
		}

		if ( '' !== (string) ( $base['search'] ?? '' ) ) {
			$query_args['s'] = (string) $base['search'];
			add_filter( 'posts_search', array( self::class, 'extend_search_with_sku' ), 10, 2 );
		}

		$query = new \WP_Query( $query_args );

		if ( '' !== (string) ( $base['search'] ?? '' ) ) {
			remove_filter( 'posts_search', array( self::class, 'extend_search_with_sku' ), 10 );
		}

		return array(
			'ids'       => array_map( 'intval', $query->posts ),
			'total'     => (int) $query->found_posts,
			'truncated' => false,
			'per_page'  => $per_page,
			'page'      => $page,
		);
	}

	/**
	 * Extend the admin product search to SKU and product ID.
	 *
	 * @param string   $search
	 * @param \WP_Query $query
	 */
	public static function extend_search_with_sku( $search, $query ): string {
		$term = (string) $query->get( 'wps_pepita_sku_search' );

		if ( '' === $term || '' === (string) $search ) {
			return (string) $search;
		}

		global $wpdb;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$sku_sql = $wpdb->prepare(
			"EXISTS (SELECT 1 FROM {$wpdb->postmeta} wps_pm WHERE wps_pm.post_id = {$wpdb->posts}.ID AND wps_pm.meta_key = '_sku' AND wps_pm.meta_value LIKE %s)",
			$like
		);

		$inner = preg_replace( '/^\s*AND\s*/i', '', (string) $search );

		if ( ctype_digit( $term ) ) {
			$sku_sql .= $wpdb->prepare( " OR {$wpdb->posts}.ID = %d", (int) $term );
		}

		return ' AND ( ' . $inner . ' OR ' . $sku_sql . ' ) ';
	}

	/**
	 * Meta query for products marked invalid by the last feed generation.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function invalid_meta_query(): array {
		return array(
			array(
				'key'   => '_wps_last_export_status',
				'value' => 'invalid',
			),
		);
	}

	public static function clear_cache(): void {
		self::clear_resolution_cache();
		Category_Rules::clear_cache();
		if ( class_exists( \WPS\Pepita\Admin\Product_Issues::class ) ) {
			\WPS\Pepita\Admin\Product_Issues::clear_cache();
		}
	}

	public static function clear_resolution_cache(): void {
		self::$resolution_cache = array();
	}

	/**
	 * Fast approximate counters for the dashboard.
	 *
	 * @return array{selected: int, scanned: int, truncated: bool}
	 */
	public static function count_selected( int $cap = 5000 ): array {
		$selected  = 0;
		$scanned   = 0;
		$paged     = 1;
		$truncated = false;

		do {
			$batch = wc_get_products(
				array(
					'limit'   => 200,
					'page'    => $paged,
					'return'  => 'ids',
					'status'  => Settings::allowed_product_statuses(),
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			$batch = is_array( $batch ) ? array_map( 'intval', $batch ) : array();

			if ( array() === $batch ) {
				break;
			}

			update_object_term_cache( $batch, 'product' );

			foreach ( $batch as $product_id ) {
				++$scanned;

				if ( self::is_included( $product_id ) ) {
					++$selected;
				}

				if ( $scanned >= $cap ) {
					$truncated = true;
					break 2;
				}
			}

			++$paged;
		} while ( true );

		return array(
			'selected'  => $selected,
			'scanned'   => $scanned,
			'truncated' => $truncated,
		);
	}
}
