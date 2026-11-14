<?php
/**
 * Product quality inspection and issue registry for Pepita marketplace readiness.
 *
 * Categorizes catalog issues into blocking (actionable) quality issues that prevent
 * successful Pepita import and non-blocking informational notices.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Image_Converter;
use WPS\Pepita\Catalog\Price_Calculator;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Product_Issues {

	public const ISSUE_IMAGE_DIM     = 'image_dim';
	public const ISSUE_IMAGE_INVALID = 'invalid_image';
	public const ISSUE_MISSING_IMAGE = 'missing_image';
	public const ISSUE_WEBP          = 'webp';
	public const ISSUE_NAME          = 'missing_name';
	public const ISSUE_DESC          = 'missing_description';
	public const ISSUE_PRICE         = 'invalid_price';
	public const ISSUE_VAT           = 'invalid_vat';
	public const ISSUE_CATEGORY      = 'missing_category';
	public const ISSUE_GTIN          = 'missing_gtin';
	public const ISSUE_SKU           = 'missing_sku';
	public const ISSUE_GENERIC       = 'validation_error';
	private const MAX_QUERY_PAGE     = 1000;

	/** @var array<string, int> Internal diagnostic penalties, not Pepita scoring rules. */
	private const QUALITY_PENALTIES = array(
		'missing_product'    => 100,
		'image_dim'           => 25,
		'invalid_image'       => 30,
		'missing_image'       => 30,
		'webp'                => 25,
		'missing_name'        => 15,
		'missing_description' => 10,
		'invalid_price'       => 20,
		'invalid_vat'         => 15,
		'missing_category'    => 20,
		'missing_gtin'        => 15,
		'missing_sku'         => 3,
	);

	/** @var array<string, string> Actionable guidance derived only from known validator findings. */
	private const QUALITY_RECOMMENDATIONS = array(
		'image_dim'           => 'Cserélje a képet legalább 1200×630 pixeles, jó minőségű JPEG vagy PNG fájlra; ne nagyítsa fel a kis képet.',
		'invalid_image'       => 'Ellenőrizze a helyi képfájlt, MIME-típust és dekódolhatóságot, majd adjon meg érvényes JPEG vagy PNG képet.',
		'missing_image'       => 'Állítson be ellenőrizhető kiemelt képet vagy megfelelő galériaképet.',
		'webp'                => 'Használjon ellenőrzött JPEG/PNG eredetit, vagy javítsa a WebP forrásfájlt és próbálja újra a konverziót.',
		'missing_name'        => 'Adjon meg terméknevet a WooCommerce termékszerkesztőben.',
		'missing_description' => 'Adjon meg pontos, termékspecifikus leírást.',
		'invalid_price'       => 'Ellenőrizze, hogy a termék feedre számított bruttó ára pozitív és érvényes.',
		'invalid_vat'         => 'Ellenőrizze a WooCommerce adóosztályt és az adóbeállításokat.',
		'missing_category'    => 'Rendelje a terméket egy engedélyezett, Pepita-exporthoz leképezett kategóriához.',
		'missing_gtin'        => 'Adja meg a termék valódi GTIN/EAN/ISBN kódját; a rendszer nem generál helyette kódot.',
		'missing_sku'         => 'Opcionális: adja meg a kereskedő tényleges belső cikkszámát. Ez önmagában nem blokkolja az exportot.',
	);

	/** @var array<int, array<int, array{category: string, code: string, description: string, blocking: bool}>> */
	private static array $issues_cache = array();

	/**
	 * Inspect one product and return all detected quality issues and warnings.
	 *
	 * @param int $product_id
	 * @return array<int, array{category: string, code: string, description: string, blocking: bool}>
	 */
	public static function inspect( int $product_id ): array {
		if ( isset( self::$issues_cache[ $product_id ] ) ) {
			return self::$issues_cache[ $product_id ];
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return self::$issues_cache[ $product_id ] = array(
				array(
					'category'    => __( 'Termék', 'wp-pepita-sync' ),
					'code'        => 'missing_product',
					'description' => __( 'A termék nem tölthető be WooCommerce termékként.', 'wp-pepita-sync' ),
					'blocking'    => true,
				),
			);
		}

		$issues = array();

		// 1. Image checks: primary image resolution and dimension requirements (1200x630).
		$featured_id = (int) $product->get_image_id();
		$primary_id  = 0;

		if ( $featured_id > 0 ) {
			$dim_warning = Image_Converter::check_dimensions( $featured_id );
			if ( null === $dim_warning ) {
				$primary_id = $featured_id;
			} else {
				// Primary image is undersized (< 1200x630). Attempt gallery fallback.
				$compliant_gallery = Product_Mapper::find_compliant_gallery_image( $product );
				if ( $compliant_gallery > 0 ) {
					$primary_id = $compliant_gallery;
				} else {
					$primary_id = $featured_id;
					$issues[]   = self::image_issue( $dim_warning );
				}
			}
		} else {
			// No featured image; attempt gallery fallback.
			$compliant_gallery = Product_Mapper::find_compliant_gallery_image( $product );
			if ( $compliant_gallery > 0 ) {
				$primary_id = $compliant_gallery;
			} else {
				$gallery_ids = $product->get_gallery_image_ids();
				$primary_id  = ( is_array( $gallery_ids ) && ! empty( $gallery_ids ) ) ? (int) $gallery_ids[0] : 0;
				if ( $primary_id > 0 ) {
					$dim_warning = Image_Converter::check_dimensions( $primary_id );
					if ( null !== $dim_warning ) {
						$issues[] = self::image_issue( $dim_warning );
					}
				}
			}
		}

		if ( 0 === $primary_id ) {
			$issues[] = array(
				'category'    => __( 'Hiányzó kép', 'wp-pepita-sync' ),
				'code'        => self::ISSUE_MISSING_IMAGE,
				'description' => __( 'A termékhez nincs használható kiemelt kép vagy galériakép.', 'wp-pepita-sync' ),
				'blocking'    => true,
			);
		} else {
			$img_warnings = array();
			$url          = Image_Converter::resolve_attachment_url( $primary_id, $img_warnings );
			if ( '' === $url ) {
				$has_image_issue = false;
				foreach ( $issues as $existing_issue ) {
					if ( in_array( $existing_issue['code'], array( self::ISSUE_IMAGE_DIM, self::ISSUE_IMAGE_INVALID, self::ISSUE_WEBP ), true ) ) {
						$has_image_issue = true;
						break;
					}
				}
				if ( ! $has_image_issue ) {
					$warning = $img_warnings[0] ?? array(
						'code'    => 'webp_conversion_failed',
						'message' => sprintf( 'A(z) #%d azonosítójú kép nem alakítható át ellenőrzött, kompatibilis képpé.', $primary_id ),
					);
					$issues[] = 'webp_conversion_failed' === $warning['code']
						? array(
							'category'    => __( 'WebP hiba', 'wp-pepita-sync' ),
							'code'        => self::ISSUE_WEBP,
							'description' => $warning['message'],
							'blocking'    => true,
						)
						: self::image_issue( $warning );
				}
			}
		}

		// 2. Required content checks: Name and description.
		$name = Product_Mapper::name( $product );
		if ( '' === $name ) {
			$issues[] = array(
				'category'    => __( 'Kötelező adat', 'wp-pepita-sync' ),
				'code'        => self::ISSUE_NAME,
				'description' => __( 'Hiányzik a termék neve.', 'wp-pepita-sync' ),
				'blocking'    => true,
			);
		}

		$desc = Product_Mapper::description( $product );
		if ( '' === $desc ) {
			$issues[] = array(
				'category'    => __( 'Kötelező adat', 'wp-pepita-sync' ),
				'code'        => self::ISSUE_DESC,
				'description' => __( 'Hiányzik a termékleírás.', 'wp-pepita-sync' ),
				'blocking'    => true,
			);
		}

		// 3. Pricing and VAT checks.
		$price = Price_Calculator::gross( $product );
		if ( $price <= 0.0 ) {
			$issues[] = array(
				'category'    => __( 'Árhiba', 'wp-pepita-sync' ),
				'code'        => self::ISSUE_PRICE,
				'description' => __( 'A termék bruttó ára 0 vagy érvénytelen. A Pepita nem fogad el 0 árat.', 'wp-pepita-sync' ),
				'blocking'    => true,
			);
		}

		$vat = Price_Calculator::vat_percent( $product );
		if ( $vat < 0.0 || $vat > 100.0 ) {
			$issues[] = array(
				'category'    => __( 'Árhiba', 'wp-pepita-sync' ),
				'code'        => self::ISSUE_VAT,
				'description' => __( 'Az áfa mértéke érvénytelen (0 és 100% között kell lennie).', 'wp-pepita-sync' ),
				'blocking'    => true,
			);
		}

		// 4. Category hierarchy resolution.
		$term_ids   = Product_Selection::category_ids( $product_id );
		$categories = Product_Mapper::categories( $product_id, $term_ids );
		if ( array() === $categories || empty( $categories[0] ) ) {
			$issues[] = array(
				'category'    => __( 'Kategória', 'wp-pepita-sync' ),
				'code'        => self::ISSUE_CATEGORY,
				'description' => __( 'A termék nincs érvényes kategóriához rendelve vagy a kategória nincs engedélyezve a feedben.', 'wp-pepita-sync' ),
				'blocking'    => true,
			);
		} else {
			// Check mandatory GTIN requirement for resolved category.
			$primary_leaf = end( $categories[0] );
			$cat_id       = (int) ( $primary_leaf['id'] ?? 0 );
			if ( $cat_id > 0 && Category_Rules::gtin_required( array( $cat_id ) ) ) {
				$gtin = Product_Mapper::gtin( $product_id );
				if ( '' === $gtin ) {
					$issues[] = array(
						'category'    => __( 'GTIN kód', 'wp-pepita-sync' ),
						'code'        => self::ISSUE_GTIN,
						'description' => __( 'Ebben a kategóriában a Pepita kötelezővé teszi a GTIN/EAN/ISBN kódot, de a termékhez nincs érvényes kód megadva.', 'wp-pepita-sync' ),
						'blocking'    => true,
					);
				}
			}
		}

		// 5. Non-blocking informational SKU check (Never blocks export on its own).
		$sku = (string) $product->get_sku();
		if ( '' === trim( $sku ) ) {
			$issues[] = array(
				'category'    => __( 'Cikkszám (SKU)', 'wp-pepita-sync' ),
				'code'        => self::ISSUE_SKU,
				'description' => __( 'A termékhez nincs WooCommerce cikkszám (SKU) megadva. A feedben a ProductNumber üresen marad vagy felülírásból jön.', 'wp-pepita-sync' ),
				'blocking'    => false,
			);
		}

		return self::$issues_cache[ $product_id ] = $issues;
	}

	/** @param array{code: string, message: string} $warning */
	private static function image_issue( array $warning ): array {
		$is_dimension = 'insufficient_image_dimensions' === ( $warning['code'] ?? '' );
		return array(
			'category'    => $is_dimension ? __( 'Képméret', 'wp-pepita-sync' ) : __( 'Képfájl', 'wp-pepita-sync' ),
			'code'        => $is_dimension ? self::ISSUE_IMAGE_DIM : self::ISSUE_IMAGE_INVALID,
			'description' => (string) ( $warning['message'] ?? __( 'A kép nem ellenőrizhető.', 'wp-pepita-sync' ) ),
			'blocking'    => true,
		);
	}

	/**
	 * Check whether a product has any blocking quality issues.
	 *
	 * @param int $product_id
	 * @return bool
	 */
	public static function has_blocking_issues( int $product_id ): bool {
		$issues = self::inspect( $product_id );

		foreach ( $issues as $issue ) {
			if ( ! empty( $issue['blocking'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return an internal diagnostic score and safe, non-mutating recommendations.
	 * The score is not a Pepita ranking and never changes validation or eligibility.
	 *
	 * @param int $product_id
	 * @return array{score: int, official: bool, blocking_count: int, warning_count: int, recommendations: array<int, array{code: string, blocking: bool, text: string}>}
	 */
	public static function quality_summary( int $product_id ): array {
		return self::summarize_issues( self::inspect( $product_id ) );
	}

	/**
	 * Deterministic summary helper kept separate so scoring does not depend on product mutations.
	 *
	 * @param array<int, array{code: string, blocking: bool}> $issues
	 * @return array{score: int, official: bool, blocking_count: int, warning_count: int, recommendations: array<int, array{code: string, blocking: bool, text: string}>}
	 */
	public static function summarize_issues( array $issues ): array {
		$score            = 100;
		$blocking_count   = 0;
		$warning_count    = 0;
		$recommendations  = array();
		$seen             = array();

		foreach ( $issues as $issue ) {
			$code     = sanitize_key( (string) ( $issue['code'] ?? '' ) );
			$blocking = ! empty( $issue['blocking'] );
			if ( '' === $code || isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;

			if ( $blocking ) {
				++$blocking_count;
			} else {
				++$warning_count;
			}

			$score -= self::QUALITY_PENALTIES[ $code ] ?? ( $blocking ? 10 : 2 );
			if ( isset( self::QUALITY_RECOMMENDATIONS[ $code ] ) ) {
				$recommendations[] = array(
					'code'     => $code,
					'blocking' => $blocking,
					'text'     => self::QUALITY_RECOMMENDATIONS[ $code ],
				);
			}
		}

		return array(
			'score'           => max( 0, min( 100, $score ) ),
			'official'        => false,
			'blocking_count'  => $blocking_count,
			'warning_count'   => $warning_count,
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Determine the current feed eligibility state and badge styling for a product.
	 *
	 * @param int                                                                             $product_id
	 * @param array<int, array{category: string, code: string, description: string, blocking: bool}> $issues
	 * @return array{key: string, label: string, class: string}
	 */
	public static function feed_status( int $product_id, array $issues = array() ): array {
		$resolution = Product_Selection::resolve( $product_id );

		if ( $resolution['included'] ) {
			return array(
				'key'   => 'included',
				'label' => __( 'Szerepel a feedben', 'wp-pepita-sync' ),
				'class' => 'wps-badge--ok',
			);
		}

		if ( Product_Selection::SOURCE_QUALITY_EXCLUDED === $resolution['source'] ) {
			return array(
				'key'   => 'quality_excluded',
				'label' => __( 'Kizárva a minőség miatt', 'wp-pepita-sync' ),
				'class' => 'wps-badge--bad',
			);
		}

		if ( Product_Selection::SOURCE_PRODUCT_EXCLUDED === $resolution['source'] ) {
			return array(
				'key'   => 'product_excluded',
				'label' => __( 'Manuálisan kizárva', 'wp-pepita-sync' ),
				'class' => 'wps-badge--muted',
			);
		}

		if ( in_array( $resolution['source'], array( 'beta_unapproved_category', Product_Selection::SOURCE_CATEGORY ), true ) ) {
			return array(
				'key'   => 'category_excluded',
				'label' => __( 'Kategória miatt kizárva', 'wp-pepita-sync' ),
				'class' => 'wps-badge--warn',
			);
		}

		return array(
			'key'   => 'other',
			'label' => __( 'Egyéb hiba miatt kizárva', 'wp-pepita-sync' ),
			'class' => 'wps-badge--bad',
		);
	}

	/**
	 * Query products with issues supporting filtering, search, and pagination.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{available: bool, items: array<int, array<string, mixed>>, total: int, scanned: int, total_products: int, page: int, per_page: int, pages: int}
	 */
	public static function query( array $filters ): array {
		$search      = trim( (string) ( $filters['search'] ?? '' ) );
		$issue_type  = (string) ( $filters['issue_type'] ?? 'all' );
		$feed_filter = (string) ( $filters['feed_status'] ?? 'all' );
		$per_page    = max( 1, min( 50, (int) ( $filters['per_page'] ?? 25 ) ) );
		$page        = max( 1, min( self::MAX_QUERY_PAGE, (int) ( $filters['page'] ?? 1 ) ) );

		$query_args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'status'   => Settings::allowed_product_statuses(),
			'orderby'  => 'ID',
			'order'    => 'DESC',
			'return'   => 'ids',
		);

		if ( '' !== $search ) {
			$sku_id = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $search ) : 0;
			$sku_product = $sku_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $sku_id ) : null;

			// The issue center lists catalog products. A variation SKU therefore
			// resolves to its parent row, whose issues and edit screen are actionable.
			if ( $sku_product instanceof \WC_Product_Variation ) {
				$sku_id = (int) $sku_product->get_parent_id();
			}

			if ( ctype_digit( $search ) ) {
				// Numeric searches are treated as exact product IDs; WooCommerce's `s`
				// search covers titles and SKUs but does not promise post-ID matching.
				$query_args['include'] = array( (int) $search );
			} elseif ( $sku_id > 0 ) {
				// Guarantee exact SKU lookup independently of datastore text-search behavior.
				$query_args['include'] = array( $sku_id );
			} else {
				$query_args['s'] = $search;
			}
		}

		$product_page  = function_exists( 'wc_get_products' ) ? wc_get_products( $query_args ) : array();
		$query_available = is_object( $product_page ) && isset( $product_page->products ) && is_array( $product_page->products ) && isset( $product_page->total );
		$all_ids       = $query_available
			? array_map( 'intval', $product_page->products )
			: array();
		$total_products = $query_available
			? max( 0, (int) $product_page->total )
			: 0;

		$matched_items = array();

		foreach ( $all_ids as $pid ) {
			$issues = self::inspect( $pid );

			if ( array() === $issues ) {
				continue;
			}

			// Filter by problem issue category / code.
			if ( 'all' !== $issue_type ) {
				$has_type = false;
				foreach ( $issues as $iss ) {
					if ( $iss['code'] === $issue_type ) {
						$has_type = true;
						break;
					}
				}
				if ( ! $has_type ) {
					continue;
				}
			}

			$status = self::feed_status( $pid, $issues );
			$quality = self::summarize_issues( $issues );

			// Filter by feed eligibility.
			if ( 'all' !== $feed_filter && $status['key'] !== $feed_filter ) {
				continue;
			}

			$product = wc_get_product( $pid );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			// Resolve category path for display.
			$term_ids   = Product_Selection::category_ids( $pid );
			$categories = Product_Mapper::categories( $pid, $term_ids );
			$cat_path   = '—';
			if ( ! empty( $categories[0] ) && is_array( $categories[0] ) ) {
				$names    = array_column( $categories[0], 'name' );
				$cat_path = implode( ' > ', $names );
			}

			// Thumbnail.
			$thumb_id  = (int) $product->get_image_id();
			$thumb_url = $thumb_id > 0 ? (string) wp_get_attachment_image_url( $thumb_id, array( 40, 40 ) ) : '';

			$matched_items[] = array(
				'product_id'         => $pid,
				'name'               => $product->get_name(),
				'sku'                => '' !== $product->get_sku() ? $product->get_sku() : '—',
				'thumbnail_url'      => $thumb_url,
				'primary_category'   => $cat_path,
				'issues'             => $issues,
				'quality_summary'    => $quality,
				'problem_categories' => array_values( array_unique( array_column( $issues, 'category' ) ) ),
				'descriptions'       => array_column( $issues, 'description' ),
				'feed_status'        => $status,
				'edit_url'           => admin_url( 'post.php?post=' . $pid . '&action=edit' ),
			);
		}

		$pages = max( 1, (int) ceil( $total_products / $per_page ) );

		return array(
			'available'      => $query_available,
			'items'          => $matched_items,
			'total'          => count( $matched_items ),
			'scanned'        => count( $all_ids ),
			'total_products' => $total_products,
			'page'           => $page,
			'per_page'       => $per_page,
			'pages'          => $pages,
		);
	}

	/**
	 * Reset internal issue cache.
	 */
	public static function clear_cache(): void {
		self::$issues_cache = array();
	}
}
