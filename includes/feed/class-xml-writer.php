<?php
/**
 * Streaming-safe XML fragments for the Pepita feed format.
 *
 * Each product is rendered into its own XMLWriter memory buffer and appended
 * to the feed file, which keeps memory bounded regardless of catalog size.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Feed;

use WPS\Pepita\Catalog\Price_Calculator;

defined( 'ABSPATH' ) || exit;

final class Xml_Writer {

	public const NAMESPACE_URI = 'https://pepita.hu/feed/1.0';

	public function header(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<Catalog xmlns="' . self::NAMESPACE_URI . '">' . "\n";
	}

	public function footer(): string {
		return '</Catalog>' . "\n";
	}

	/**
	 * Full product node.
	 *
	 * @param array<string, mixed> $data
	 */
	public function product( array $data ): string {
		$x = $this->writer();
		$x->startElement( 'Product' );

		$this->text( $x, 'Id', (string) $data['export_id'] );
		$this->text( $x, 'LastMod', (string) (int) $data['last_mod'] );

		if ( '' !== (string) $data['gtin'] ) {
			$this->text( $x, 'StructuredId', (string) $data['gtin'] );
		}

		if ( '' !== (string) $data['mpn'] ) {
			$this->text( $x, 'ProductNumber', (string) $data['mpn'] );
		}

		if ( ! empty( $data['email_delivery'] ) ) {
			$this->text( $x, 'EmailDelivery', 'true' );
		}

		$x->startElement( 'Descriptions' );
		$this->text( $x, 'Name', (string) $data['name'] );
		$this->text( $x, 'Description', (string) $data['description'] );

		if ( '' !== (string) $data['brand'] ) {
			$this->text( $x, 'Brand', (string) $data['brand'] );
		}

		if ( '' !== (string) $data['manufacturer'] ) {
			$this->text( $x, 'Manufacturer', (string) $data['manufacturer'] );
		}

		if ( '' !== (string) ( $data['author'] ?? '' ) ) {
			$this->text( $x, 'Author', (string) $data['author'] );
		}

		if ( '' !== (string) ( $data['publisher'] ?? '' ) ) {
			$this->text( $x, 'Publisher', (string) $data['publisher'] );
		}

		$x->endElement();

		$this->prices( $x, $data, true );

		if ( is_array( $data['warranty'] ) ) {
			$x->startElement( 'Warranty' );
			$this->text( $x, 'Type', (string) $data['warranty']['type'] );
			$this->text( $x, 'Duration', (string) $data['warranty']['duration'] );
			$x->endElement();
		}

		$this->categories( $x, $data['categories'] );
		$this->photos( $x, $data['photos'] );

		if ( '' !== (string) $data['video_links'] ) {
			$this->text( $x, 'VideoLinks', (string) $data['video_links'] );
		}

		if ( '' !== (string) $data['product_url'] ) {
			$this->text( $x, 'ProductUrl', (string) $data['product_url'] );
		}

		$this->availability( $x, $data, false );

		if ( array() !== ( $data['attributes'] ?? array() ) ) {
			$x->startElement( 'Attributes' );

			foreach ( $data['attributes'] as $attribute ) {
				$x->startElement( 'Attribute' );
				$this->text( $x, 'AttributeName', (string) $attribute['name'] );
				$this->text( $x, 'AttributeValue', (string) $attribute['value'] );
				$x->endElement();
			}

			$x->endElement();
		}

		if ( array() !== ( $data['dimensions'] ?? array() ) ) {
			$x->startElement( 'VolumeDimensions' );

			foreach ( array( 'width', 'height', 'length', 'weight' ) as $key ) {
				if ( isset( $data['dimensions'][ $key ] ) ) {
					$this->text( $x, ucfirst( $key ), Price_Calculator::format( (float) $data['dimensions'][ $key ], 4 ) );
				}
			}

			$x->endElement();
		}

		if ( is_array( $data['variations'] ) ) {
			$this->variations( $x, $data['variations'], (string) $data['currency'], true );
		}

		$x->endElement();

		return $x->outputMemory();
	}

	/**
	 * Stock / price node. Contains only the documented elements.
	 *
	 * @param array<string, mixed> $data
	 */
	public function stock_product( array $data ): string {
		$x = $this->writer();
		$x->startElement( 'Product' );

		$this->text( $x, 'Id', (string) $data['export_id'] );
		$this->availability( $x, $data, false );
		$this->prices( $x, $data, false );

		if ( is_array( $data['variations'] ) ) {
			$this->variations( $x, $data['variations'], (string) $data['currency'], false );
		}

		$x->endElement();

		return $x->outputMemory();
	}

	private function writer(): \XMLWriter {
		$x = new \XMLWriter();
		$x->openMemory();
		$x->setIndent( true );
		$x->setIndentString( '  ' );

		return $x;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function prices( \XMLWriter $x, array $data, bool $full ): void {
		$x->startElement( 'Prices' );
		$this->text( $x, 'Currency', (string) $data['currency'] );
		$this->text( $x, 'Price', Price_Calculator::format( (float) $data['price'] ) );

		if ( $full ) {
			$this->text( $x, 'VatPercent', Price_Calculator::format( (float) $data['vat_percent'], 2 ) );
		}

		if ( null !== $data['sale_price'] ) {
			$this->text( $x, 'DiscountedPrice', Price_Calculator::format( (float) $data['sale_price'] ) );
		}

		if ( $full && null !== $data['purchase_net'] ) {
			$this->text( $x, 'PurchaseNetPrice', Price_Calculator::format( (float) $data['purchase_net'] ) );
		}

		if ( $full && null !== $data['shipping_price'] ) {
			$this->text( $x, 'ShippingPrice', Price_Calculator::format( (float) $data['shipping_price'] ) );
		}

		$x->endElement();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function availability( \XMLWriter $x, array $data, bool $variation ): void {
		$x->startElement( 'Availability' );
		$this->text( $x, 'Available', ! empty( $data['available'] ) ? 'true' : 'false' );

		if ( null !== ( $data['quantity'] ?? null ) ) {
			$this->text( $x, 'Quantity', (string) max( 0, (int) $data['quantity'] ) );
		}

		if ( '' !== (string) ( $data['restock_date'] ?? '' ) ) {
			$this->text( $x, 'RestockDate', (string) $data['restock_date'] );
		}

		if ( null !== ( $data['shipping_delay'] ?? null ) ) {
			$this->text( $x, 'ShippingDelay', (string) max( 0, (int) $data['shipping_delay'] ) );
		}

		$x->endElement();
	}

	/**
	 * @param array<int, array<int, array{id: int, name: string}>> $categories
	 */
	private function categories( \XMLWriter $x, array $categories ): void {
		foreach ( $categories as $path ) {
			$x->startElement( 'Categories' );

			foreach ( $path as $category ) {
				$x->startElement( 'Category' );

				if ( ! empty( $category['id'] ) ) {
					$this->text( $x, 'Id', (string) $category['id'] );
				}

				$this->text( $x, 'Name', (string) $category['name'] );
				$x->endElement();
			}

			$x->endElement();
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $photos
	 */
	private function photos( \XMLWriter $x, array $photos ): void {
		if ( array() === $photos ) {
			return;
		}

		$x->startElement( 'Photos' );

		foreach ( $photos as $photo ) {
			$x->startElement( 'Photo' );
			$this->text( $x, 'Url', (string) $photo['url'] );
			$this->text( $x, 'IsPrimary', ! empty( $photo['primary'] ) ? 'true' : 'false' );

			if ( '' !== (string) ( $photo['title'] ?? '' ) ) {
				$this->text( $x, 'Title', (string) $photo['title'] );
			}

			$values = $photo['variations'] ?? array();

			if ( is_array( $values ) && array() !== $values ) {
				$names = array( 'PrimaryVariation', 'SecondaryVariation', 'TertiaryVariation' );

				foreach ( array_values( $values ) as $index => $value ) {
					if ( isset( $names[ $index ] ) && '' !== (string) $value ) {
						$this->text( $x, $names[ $index ], (string) $value );
					}
				}
			}

			$x->endElement();
		}

		$x->endElement();
	}

	/**
	 * @param array<string, mixed> $variations
	 */
	private function variations( \XMLWriter $x, array $variations, string $currency, bool $full = true ): void {
		$axes  = $variations['axes'] ?? array();
		$items = $variations['items'] ?? array();

		if ( ! is_array( $axes ) || array() === $axes ) {
			return;
		}

		$x->startElement( 'Variations' );

		$axis_names = array( 'PrimaryAttribute', 'SecondaryAttribute', 'TertiaryAttribute' );

		foreach ( array_values( $axes ) as $index => $axis ) {
			if ( isset( $axis_names[ $index ] ) ) {
				$this->text( $x, $axis_names[ $index ], (string) $axis['name'] );
			}
		}

		$value_names = array( 'PrimaryVariation', 'SecondaryVariation', 'TertiaryVariation' );

		foreach ( $items as $item ) {
			$x->startElement( 'Variation' );

			foreach ( array_values( $item['values'] ) as $index => $value ) {
				if ( isset( $value_names[ $index ] ) ) {
					$this->text( $x, $value_names[ $index ], (string) $value );
				}
			}

			$x->startElement( 'Prices' );
			$this->text( $x, 'Currency', $currency );
			$this->text( $x, 'Price', Price_Calculator::format( (float) $item['price'] ) );

			if ( $full && isset( $item['vat_percent'] ) ) {
				$this->text( $x, 'VatPercent', Price_Calculator::format( (float) $item['vat_percent'], 2 ) );
			}

			if ( null !== ( $item['sale_price'] ?? null ) ) {
				$this->text( $x, 'DiscountedPrice', Price_Calculator::format( (float) $item['sale_price'] ) );
			}

			if ( $full && null !== ( $item['purchase_net'] ?? null ) ) {
				$this->text( $x, 'PurchaseNetPrice', Price_Calculator::format( (float) $item['purchase_net'] ) );
			}

			if ( $full && null !== ( $item['shipping_price'] ?? null ) ) {
				$this->text( $x, 'ShippingPrice', Price_Calculator::format( (float) $item['shipping_price'] ) );
			}

			$x->endElement();

			$this->availability( $x, $item, true );
			$x->endElement();
		}

		$x->endElement();
	}

	private function text( \XMLWriter $x, string $name, string $value ): void {
		$x->startElement( $name );
		$x->text( self::clean( $value ) );
		$x->endElement();
	}

	public static function clean( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$value = wp_check_invalid_utf8( $value, true );
		} elseif ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}

		// XML 1.0 forbids most C0 controls; a literal 0x00 makes the whole
		// document unparseable for the marketplace.
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value ) ?? '';

		// Noncharacters are not valid XML characters even though they are
		// valid UTF-8. Strip the Unicode noncharacter blocks (U+FDD0–U+FDEF
		// and the last two code points of every plane).
		$value = preg_replace(
			'/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}]/u',
			'',
			$value
		) ?? '';

		return $value;
	}
}
