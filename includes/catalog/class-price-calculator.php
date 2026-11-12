<?php
/**
 * Gross price, VAT and marketplace price adjustment handling.
 *
 * WooCommerce prices are never modified: every adjustment is applied only to
 * the value written into the feed.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Price_Calculator {

	/** @var array<string, float> */
	private static array $vat_cache = array();

	/**
	 * Return the gross (tax inclusive) price for a product.
	 */
	public static function gross( \WC_Product $product, ?float $base = null ): float {
		$raw = null === $base ? (float) $product->get_price() : $base;

		if ( $raw <= 0 ) {
			return 0.0;
		}

		if ( function_exists( 'wc_get_price_including_tax' ) ) {
			$gross = (float) wc_get_price_including_tax(
				$product,
				array(
					'price' => $raw,
					'qty'   => 1,
				)
			);

			if ( $gross > 0 ) {
				return $gross;
			}
		}

		return $raw;
	}

	/**
	 * Gross sale price, or null when the product has no active sale price.
	 */
	public static function gross_sale( \WC_Product $product ): ?float {
		$sale = $product->get_sale_price();

		if ( '' === $sale || null === $sale || (float) $sale <= 0 ) {
			return null;
		}

		$gross = self::gross( $product, (float) $sale );

		return $gross > 0 ? $gross : null;
	}

	/**
	 * VAT percentage for the product's tax class.
	 *
	 * Uses WooCommerce tax rates when taxes are enabled. Stores without tax
	 * (or with zero-rated classes) correctly produce 0.
	 */
	public static function vat_percent( \WC_Product $product ): float {
		if ( 'fixed' === Settings::get( 'vat_mode', 'auto' ) ) {
			return (float) Settings::get( 'vat_fixed', 0.0 );
		}

		if ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) {
			return 0.0;
		}

		$class = (string) $product->get_tax_class();
		$key   = '' === $class ? '__standard__' : $class;

		if ( array_key_exists( $key, self::$vat_cache ) ) {
			return self::$vat_cache[ $key ];
		}

		$percent = 0.0;

		if ( class_exists( '\WC_Tax' ) ) {
			$rates = \WC_Tax::get_rates( $class );

			if ( is_array( $rates ) && array() !== $rates ) {
				$first   = reset( $rates );
				$percent = is_array( $first ) && isset( $first['rate'] ) ? (float) $first['rate'] : 0.0;
			}
		}

		self::$vat_cache[ $key ] = max( 0.0, $percent );

		return self::$vat_cache[ $key ];
	}

	/**
	 * Resolve the price adjustment for a product: product > category > global.
	 *
	 * @param int[] $term_ids
	 * @return array{type: string, value: float}
	 */
	public static function resolve_adjustment( int $product_id, array $term_ids = array() ): array {
		$product_type = (string) get_post_meta( $product_id, '_wps_price_adjustment_type', true );

		if ( in_array( $product_type, array( 'none', 'fixed', 'percent' ), true ) ) {
			return array(
				'type'  => $product_type,
				'value' => (float) get_post_meta( $product_id, '_wps_price_adjustment_value', true ),
			);
		}

		$category = Category_Rules::price_adjustment( $term_ids );

		if ( null !== $category ) {
			return $category;
		}

		return array(
			'type'  => (string) Settings::get( 'price_adjustment_type', 'none' ),
			'value' => (float) Settings::get( 'price_adjustment_value', 0.0 ),
		);
	}

	/**
	 * Apply an adjustment and the configured rounding rule.
	 *
	 * @param array{type: string, value: float} $adjustment
	 */
	public static function adjust( float $price, array $adjustment ): float {
		if ( $price <= 0 ) {
			return 0.0;
		}

		$type  = (string) ( $adjustment['type'] ?? 'none' );
		$value = (float) ( $adjustment['value'] ?? 0.0 );

		if ( 'percent' === $type && 0.0 !== $value ) {
			$price *= ( 1 + ( $value / 100 ) );
		} elseif ( 'fixed' === $type && 0.0 !== $value ) {
			$price += $value;
		}

		$price = self::round( $price );

		return $price > 0 ? $price : 0.0;
	}

	public static function round( float $price ): float {
		$mode = (string) Settings::get( 'rounding', 'nearest_1' );

		return match ( $mode ) {
			'none'       => round( $price, 2 ),
			'nearest_5'  => round( $price / 5 ) * 5,
			'nearest_10' => round( $price / 10 ) * 10,
			'nearest_100'=> round( $price / 100 ) * 100,
			'up_1'       => ceil( $price ),
			default      => round( $price ),
		};
	}

	public static function net( float $gross, float $vat_percent ): float {
		if ( $gross <= 0 ) {
			return 0.0;
		}

		if ( $vat_percent <= 0 ) {
			return round( $gross, 2 );
		}

		return round( $gross / ( 1 + ( $vat_percent / 100 ) ), 2 );
	}

	/**
	 * Format a number for XML: dot decimal separator, no thousands separator.
	 */
	public static function format( float $value, int $max_decimals = 2 ): string {
		$value = round( $value, $max_decimals );

		if ( abs( $value - round( $value ) ) < 0.00001 ) {
			return (string) (int) round( $value );
		}

		return rtrim( rtrim( number_format( $value, $max_decimals, '.', '' ), '0' ), '.' );
	}
}
