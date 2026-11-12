<?php
/**
 * Feed validation before publishing.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Catalog;

defined( 'ABSPATH' ) || exit;

final class Validator {

	/**
	 * Validate a single mapped product.
	 *
	 * @param array<string, mixed> $data
	 * @return array{valid: bool, errors: array<int, array{code: string, message: string}>, warnings: array<int, array{code: string, message: string}>}
	 */
	public static function validate( array $data ): array {
		$errors   = isset( $data['errors'] ) && is_array( $data['errors'] ) ? $data['errors'] : array();
		$warnings = isset( $data['warnings'] ) && is_array( $data['warnings'] ) ? $data['warnings'] : array();

		$export_id = (string) ( $data['export_id'] ?? '' );

		if ( '' === $export_id ) {
			$errors[] = self::error( 'missing_identifier', 'Hiányzik a termék egyedi Pepita azonosítója.' );
		} elseif ( strlen( $export_id ) > 191 ) {
			$errors[] = self::error( 'identifier_too_long', 'A termék Pepita azonosítója túl hosszú (max. 191 karakter).' );
		}

		$name = trim( (string) ( $data['name'] ?? '' ) );

		if ( '' === $name ) {
			$errors[] = self::error( 'missing_name', 'Hiányzik a termék neve.' );
		} elseif ( mb_strlen( $name ) > 500 ) {
			$warnings[] = self::warning( 'long_name', 'A termék neve hosszabb 500 karakternél; a Pepita csonkíthatja.' );
		}

		if ( '' === trim( (string) ( $data['description'] ?? '' ) ) ) {
			$errors[] = self::error( 'missing_description', 'Hiányzik a termékleírás.' );
		}

		$price = (float) ( $data['price'] ?? 0 );

		if ( ! is_finite( $price ) || $price <= 0 ) {
			$errors[] = self::error( 'invalid_price', 'A termék ára 0 vagy érvénytelen.' );
		}

		$vat = (float) ( $data['vat_percent'] ?? 0 );

		if ( ! is_finite( $vat ) || $vat < 0 || $vat > 100 ) {
			$errors[] = self::error( 'invalid_vat', 'Az áfa mértéke érvénytelen (0–100%).' );
		}

		$currency = (string) ( $data['currency'] ?? '' );

		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$errors[] = self::error( 'invalid_currency', 'A pénznem ISO 4217 kódja érvénytelen.' );
		}

		if ( array() === ( $data['categories'] ?? array() ) ) {
			$errors[] = self::error( 'missing_category', 'A termék nincs kategóriához rendelve.' );
		}

		$photos = $data['photos'] ?? array();

		if ( ! is_array( $photos ) || array() === $photos ) {
			$errors[] = self::error( 'missing_image', 'A termékhez nincs exportálható kép.' );
		} else {
			$primary = 0;

			foreach ( $photos as $photo ) {
				$url = (string) ( $photo['url'] ?? '' );

				if ( ! self::is_valid_image_url( $url ) ) {
					$errors[] = self::error(
						'invalid_image_url',
						sprintf( 'Érvénytelen kép URL: %s', '' !== $url ? $url : '(üres)' )
					);
					continue;
				}

				if ( ! empty( $photo['primary'] ) ) {
					++$primary;
				}
			}

			if ( 0 === $primary && array() !== $photos ) {
				$warnings[] = self::warning( 'no_primary_image', 'Nincs elsődleges kép megjelölve; az első képet tekinti annak a Pepita.' );
			}
		}

		if ( ! empty( $data['video_links'] ) ) {
			foreach ( explode( ',', (string) $data['video_links'] ) as $video ) {
				if ( ! Product_Mapper::is_http_url( trim( $video ) ) ) {
					$warnings[] = self::warning( 'invalid_video_url', sprintf( 'Érvénytelen videó URL kihagyva: %s', $video ) );
				}
			}
		}

		$gtin = (string) ( $data['gtin'] ?? '' );

		if ( '' !== $gtin && ! preg_match( '/^\d{8}$|^\d{12,14}$/', $gtin ) ) {
			$errors[] = self::error( 'invalid_gtin', 'A megadott GTIN/EAN/ISBN kód formátuma érvénytelen.' );
		}

		if ( 'variable' === ( $data['type'] ?? '' ) ) {
			$errors = array_merge( $errors, self::validate_variations( $data ) );
		}

		return array(
			'valid'    => array() === $errors,
			'errors'   => self::dedupe( $errors ),
			'warnings' => self::dedupe( $warnings ),
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<int, array{code: string, message: string}>
	 */
	private static function validate_variations( array $data ): array {
		$errors     = array();
		$variations = $data['variations'] ?? null;

		if ( ! is_array( $variations ) ) {
			return array( self::error( 'missing_variations', 'A variációs termékhez nem készült variációs adat.' ) );
		}

		$axes = $variations['axes'] ?? array();

		if ( ! is_array( $axes ) || array() === $axes ) {
			return array( self::error( 'invalid_variation_structure', 'A variációs termékhez nincs variációs attribútum.' ) );
		}

		if ( count( $axes ) > 3 ) {
			$errors[] = self::error( 'too_many_variation_attributes', 'A Pepita legfeljebb 3 variációs attribútumot támogat.' );
		}

		$items = $variations['items'] ?? array();

		if ( ! is_array( $items ) || array() === $items ) {
			$errors[] = self::error( 'no_published_variations', 'Nincs exportálható variáció.' );

			return $errors;
		}

		$seen = array();

		foreach ( $items as $item ) {
			$values = $item['values'] ?? array();

			if ( ! is_array( $values ) || count( $values ) !== count( $axes ) ) {
				$errors[] = self::error( 'invalid_variation_structure', 'Egy variáció attribútumértékeinek száma nem egyezik az attribútumok számával.' );
				continue;
			}

			foreach ( $values as $value ) {
				if ( '' === trim( (string) $value ) ) {
					$errors[] = self::error( 'invalid_variation_structure', 'Egy variációnál üres attribútumérték szerepel.' );
					break;
				}
			}

			$key = implode( "\x1F", array_map( 'strval', $values ) );

			if ( isset( $seen[ $key ] ) ) {
				$errors[] = self::error( 'duplicate_variation', 'Két variáció ugyanazokkal az értékekkel szerepel.' );
			}

			$seen[ $key ] = true;

			if ( (float) ( $item['price'] ?? 0 ) <= 0 ) {
				$errors[] = self::error( 'invalid_variation_price', 'Egy variáció ára 0 vagy érvénytelen.' );
			}
		}

		return $errors;
	}

	/**
	 * Validate a generated XML file: well formed, correct namespace, product count.
	 *
	 * @return array{valid: bool, products: int, errors: string[]}
	 */
	public static function validate_xml_file( string $path, string $expected_namespace = 'https://pepita.hu/feed/1.0' ): array {
		$errors = array();

		if ( ! is_file( $path ) || filesize( $path ) <= 0 ) {
			return array(
				'valid'    => false,
				'products' => 0,
				'errors'   => array( 'A feed fájl nem jött létre vagy üres.' ),
			);
		}

		$reader = new \XMLReader();

		if ( ! @$reader->open( $path, null, LIBXML_NONET | LIBXML_COMPACT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'valid'    => false,
				'products' => 0,
				'errors'   => array( 'A feed fájl nem nyitható meg XML olvasóval.' ),
			);
		}

		$products  = 0;
		$root_seen = false;
		$ids       = array();
		$duplicates = 0;

		$previous_use_errors = libxml_use_internal_errors( true );
		libxml_clear_errors();

		try {
			while ( $reader->read() ) {
				if ( \XMLReader::ELEMENT !== $reader->nodeType ) {
					continue;
				}

				if ( ! $root_seen ) {
					$root_seen = true;

					if ( 'Catalog' !== $reader->localName ) {
						$errors[] = 'A gyökér elem nem <Catalog>.';
					}

					$namespace = (string) $reader->namespaceURI;

					if ( '' !== $namespace && $expected_namespace !== $namespace ) {
						$errors[] = sprintf( 'Hibás XML névtér: %s', $namespace );
					}

					continue;
				}

				if ( 'Product' === $reader->localName ) {
					++$products;
					$node = $reader->readOuterXml();
					$id   = self::extract_id( $node );

					if ( '' !== $id ) {
						if ( isset( $ids[ $id ] ) ) {
							++$duplicates;
						}

						$ids[ $id ] = true;
					}
				}
			}
		} finally {
			$reader->close();
			$xml_errors = libxml_get_errors();
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_use_errors );
		}

		foreach ( $xml_errors as $xml_error ) {
			$errors[] = trim( $xml_error->message ) . ' (sor: ' . $xml_error->line . ')';
		}

		if ( ! $root_seen ) {
			$errors[] = 'A feed fájl nem tartalmaz gyökér elemet.';
		}

		if ( $duplicates > 0 ) {
			$errors[] = sprintf( '%d ismétlődő termékazonosító található a feedben.', $duplicates );
		}

		return array(
			'valid'    => array() === $errors,
			'products' => $products,
			'errors'   => $errors,
		);
	}

	private static function extract_id( string $node ): string {
		if ( preg_match( '#<Id>(.*?)</Id>#s', $node, $matches ) ) {
			return html_entity_decode( trim( $matches[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
		}

		return '';
	}

	public static function is_valid_image_url( string $url ): bool {
		if ( ! Product_Mapper::is_http_url( $url ) ) {
			return false;
		}

		if ( preg_match( '/\s/', $url ) ) {
			return false;
		}

		return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif|bmp|svg)(\?.*)?$/i', $url )
			|| false !== strpos( $url, '/wp-content/uploads/' );
	}

	/**
	 * @param array<int, array{code: string, message: string}> $items
	 * @return array<int, array{code: string, message: string}>
	 */
	private static function dedupe( array $items ): array {
		$seen = array();
		$out  = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['code'] ) ) {
				continue;
			}

			$key = $item['code'] . '|' . ( $item['message'] ?? '' );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[]        = array(
				'code'    => (string) $item['code'],
				'message' => (string) ( $item['message'] ?? '' ),
			);
		}

		return $out;
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
