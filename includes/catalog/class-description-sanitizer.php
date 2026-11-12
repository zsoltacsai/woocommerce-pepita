<?php
/**
 * Converts WooCommerce HTML descriptions into plain, feed safe text.
 *
 * The original product description is never modified.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

defined( 'ABSPATH' ) || exit;

final class Description_Sanitizer {

	/**
	 * @param mixed $html
	 */
	public static function clean( $html ): string {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( function_exists( 'strip_shortcodes' ) ) {
			$html = strip_shortcodes( $html );
		}

		// Remove embedded media and interactive content entirely.
		$html = preg_replace(
			'#<(script|style|iframe|object|embed|video|audio|svg|form|noscript|map|canvas)\b[^>]*>.*?</\1>#is',
			'',
			$html
		) ?? $html;

		$html = preg_replace(
			'#<(script|style|iframe|object|embed|video|audio|svg|form|noscript|map|canvas)\b[^>]*/?>#i',
			'',
			$html
		) ?? $html;

		$html = preg_replace( '#<!--.*?-->#s', '', $html ) ?? $html;
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html ) ?? $html;
		$html = preg_replace( '#</(p|div|li|ul|ol|h[1-6]|tr|td|th|blockquote|section|article)>#i', "\n", $html ) ?? $html;

		$text = function_exists( 'wp_strip_all_tags' )
			? wp_strip_all_tags( $html )
			: strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalise non-breaking spaces and line endings.
		$text = str_replace( array( "\xC2\xA0", "\r\n", "\r" ), array( ' ', "\n", "\n" ), $text );

		// Remove characters that are illegal in XML 1.0.
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text ) ?? $text;

		$text = preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Single line text (product name, brand, attribute labels).
	 *
	 * @param mixed $value
	 */
	public static function line( $value ): string {
		$value = self::clean( $value );
		$value = preg_replace( "/\s+/u", ' ', $value ) ?? $value;

		return trim( $value );
	}

	/**
	 * Normalise a GTIN/EAN/ISBN value. Returns '' for invalid values so that
	 * no fabricated identifier can ever reach the feed.
	 *
	 * @param mixed $value
	 */
	public static function gtin( $value ): string {
		$digits = preg_replace( '/\D/', '', (string) $value ) ?? '';

		if ( ! in_array( strlen( $digits ), array( 8, 12, 13, 14 ), true ) ) {
			return '';
		}

		if ( '0' === substr( $digits, 0, 1 ) && 13 === strlen( $digits ) ) {
			// Keep leading zeroes (EAN-13 starting with 0 is valid).
			return $digits;
		}

		return $digits;
	}
}
