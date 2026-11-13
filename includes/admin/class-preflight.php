<?php
/**
 * Preflight catalog audit and validation report for Pepita marketplace readiness.
 *
 * Checks catalog categories, inclusion/exclusion state, single primary category resolution,
 * SKU mappings, GTINs, inventory quantity caps, WebP derivatives, and pricing consistency.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Image_Converter;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Preflight {

	/**
	 * Run the preflight audit on the current catalog.
	 *
	 * @return array<string, mixed>
	 */
	public static function run(): array {
		$category_stats = self::analyze_categories();
		$tax_mode       = Settings::price_tax_mode();
		$cat_strategy   = Settings::primary_category_strategy();
		$beta_mode      = Settings::is_beta_feed_only();

		$summary = array(
			'total_products'           => 0,
			'eligible_products'        => 0,
			'excluded_products'        => 0,
			'ready_products'           => 0,
			'products_with_errors'     => 0,
			'products_with_warnings'   => 0,
			'missing_primary_category' => 0,
			'missing_skus'             => 0,
			'available_gtins'          => 0,
			'capped_quantities'        => 0,
			'webp_conversions'         => 0,
			'resolution_issues'        => 0,
			'tax_mode'                 => $tax_mode,
			'primary_cat_strategy'     => $cat_strategy,
			'beta_feed_only'           => $beta_mode,
			'category_stats'           => $category_stats,
		);

		$items    = array();
		$post_ids = self::query_product_ids();

		$summary['total_products'] = count( $post_ids );

		foreach ( $post_ids as $product_id ) {
			$resolution = Product_Selection::resolve( $product_id );

			if ( ! $resolution['included'] ) {
				$summary['excluded_products']++;
				continue;
			}

			$summary['eligible_products']++;

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

			if ( ! $product instanceof \WC_Product ) {
				$summary['products_with_errors']++;
				$items[] = array(
					'id'               => $product_id,
					'name'             => sprintf( 'Termék #%d', $product_id ),
					'sku'              => '',
					'primary_category' => '',
					'severity'         => 'error',
					'code'             => 'missing_product',
					'message'          => 'A termék nem tölthető be WooCommerce termékként.',
				);
				continue;
			}

			$mapped = Product_Mapper::map( $product_id );

			$sku      = (string) $product->get_sku();
			$name     = (string) ( $mapped['name'] ?? $product->get_name() );
			$gtin     = (string) ( $mapped['gtin'] ?? '' );
			$errors   = $mapped['errors'] ?? array();
			$warnings = $mapped['warnings'] ?? array();

			// Primary category hierarchy check.
			$cat_path = '';
			if ( ! empty( $mapped['categories'][0] ) && is_array( $mapped['categories'][0] ) ) {
				$names = array_column( $mapped['categories'][0], 'name' );
				$cat_path = implode( ' > ', $names );
			}

			if ( '' === $cat_path ) {
				$summary['missing_primary_category']++;
				$items[] = array(
					'id'               => $product_id,
					'name'             => $name,
					'sku'              => $sku,
					'primary_category' => '',
					'severity'         => 'error',
					'code'             => 'missing_primary_category',
					'message'          => 'Nincs kiválasztható elsődleges kategória hierarchia (a termék kategóriája nincs engedélyezve vagy nincs hozzárendelve kategória).',
				);
			}

			// SKU check (Warning only).
			if ( '' === trim( $sku ) ) {
				$summary['missing_skus']++;
				$items[] = array(
					'id'               => $product_id,
					'name'             => $name,
					'sku'              => '',
					'primary_category' => $cat_path,
					'severity'         => 'warning',
					'code'             => 'missing_sku',
					'message'          => 'A termékhez nincs WooCommerce cikkszám (SKU) megadva. A feedben a ProductNumber üresen marad vagy felülírásból jön.',
				);
			}

			// GTIN check.
			if ( '' !== $gtin ) {
				$summary['available_gtins']++;
			}

			// Quantity cap check.
			$has_capped = false;
			foreach ( $warnings as $w ) {
				if ( ( $w['code'] ?? '' ) === 'stock_quantity_capped' ) {
					$has_capped = true;
					break;
				}
			}
			if ( $has_capped ) {
				$summary['capped_quantities']++;
				$items[] = array(
					'id'               => $product_id,
					'name'             => $name,
					'sku'              => $sku,
					'primary_category' => $cat_path,
					'severity'         => 'warning',
					'code'             => 'stock_quantity_capped',
					'message'          => sprintf( 'A készlet meghaladta a 999 999 limitet, ezért a rendszer automatikusan a megengedett maximumra korlátozta.' ),
				);
			}

			// Image checks.
			$feat_id = (int) $product->get_image_id();
			if ( $feat_id > 0 && Image_Converter::is_webp( $feat_id ) ) {
				$summary['webp_conversions']++;
			}

			foreach ( $warnings as $w ) {
				if ( ( $w['code'] ?? '' ) === 'insufficient_image_dimensions' ) {
					$summary['resolution_issues']++;
					$items[] = array(
						'id'               => $product_id,
						'name'             => $name,
						'sku'              => $sku,
						'primary_category' => $cat_path,
						'severity'         => 'warning',
						'code'             => 'insufficient_image_dimensions',
						'message'          => $w['message'] ?? 'A kép felbontása nem éri el a 300x300 képpontot.',
					);
				}
			}

			// Generic errors and warnings from mapping.
			foreach ( $errors as $e ) {
				if ( ( $e['code'] ?? '' ) === 'missing_category' ) {
					continue; // Already handled under missing_primary_category.
				}
				$items[] = array(
					'id'               => $product_id,
					'name'             => $name,
					'sku'              => $sku,
					'primary_category' => $cat_path,
					'severity'         => 'error',
					'code'             => (string) ( $e['code'] ?? 'error' ),
					'message'          => (string) ( $e['message'] ?? '' ),
				);
			}

			if ( array() !== $errors ) {
				$summary['products_with_errors']++;
			} else {
				$summary['ready_products']++;
			}

			if ( array() !== $warnings ) {
				$summary['products_with_warnings']++;
			}
		}

		return array(
			'summary' => $summary,
			'items'   => $items,
		);
	}

	/**
	 * Analyze category hierarchy and decision distribution.
	 *
	 * @return array{total: int, included: int, excluded: int, none: int}
	 */
	public static function analyze_categories(): array {
		$terms = function_exists( 'get_terms' ) ? get_terms(
			array(
				'taxonomy'   => Category_Rules::TAXONOMY,
				'hide_empty' => false,
			)
		) : array();

		$terms = is_array( $terms ) ? $terms : array();

		$stats = array(
			'total'    => count( $terms ),
			'included' => 0,
			'excluded' => 0,
			'none'     => 0,
		);

		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
				continue;
			}
			$decision = Category_Rules::decision( (int) $term->term_id );
			if ( Category_Rules::DECISION_INCLUDE === $decision ) {
				$stats['included']++;
			} elseif ( Category_Rules::DECISION_EXCLUDE === $decision ) {
				$stats['excluded']++;
			} else {
				$stats['none']++;
			}
		}

		return $stats;
	}

	/**
	 * Query all product IDs matching allowed statuses.
	 *
	 * @return int[]
	 */
	public static function query_product_ids(): array {
		if ( function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products(
				array(
					'limit'  => -1,
					'status' => Settings::allowed_product_statuses(),
					'return' => 'ids',
				)
			);
			if ( is_array( $ids ) ) {
				return array_values( array_map( 'intval', $ids ) );
			}
		}

		return array();
	}

	/**
	 * Generate and stream CSV export for preflight audit findings.
	 */
	public static function export_csv(): void {
		$report = self::run();
		$items  = $report['items'] ?? array();

		$filename = sprintf( 'pepita-preflight-%s.csv', gmdate( 'Y-m-d-His' ) );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// UTF-8 BOM for Excel compatibility.
		echo "\xEF\xBB\xBF";

		$out = fopen( 'php://output', 'wb' );

		if ( false === $out ) {
			return;
		}

		fputcsv( $out, array( 'Termék ID', 'Cikkszám (SKU)', 'Terméknév', 'Elsődleges kategória', 'Súlyosság', 'Hibakód', 'Üzenet' ) );

		foreach ( $items as $item ) {
			fputcsv(
				$out,
				array(
					$item['id'] ?? '',
					$item['sku'] ?? '',
					$item['name'] ?? '',
					$item['primary_category'] ?? '',
					$item['severity'] ?? '',
					$item['code'] ?? '',
					$item['message'] ?? '',
				)
			);
		}

		fclose( $out );
	}
}
