<?php
/**
 * Catalog index (JSON Lines) describing the last successfully published full
 * feed. Used for stable variation identity checks, the zero-stock removal
 * policy and the full/stock consistency signature.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Feed;

defined( 'ABSPATH' ) || exit;

final class Catalog_Index {

	/**
	 * Stream the index without loading it into memory.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	public static function read( ?string $path = null ): \Generator {
		$path = $path ?? \WPS\Pepita\Storage::catalog_index_path();

		if ( ! is_file( $path ) ) {
			return;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return;
		}

		try {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$line = trim( $line );

				if ( '' === $line ) {
					continue;
				}

				$row = json_decode( $line, true );

				if ( is_array( $row ) && isset( $row['pid'] ) ) {
					yield $row;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * @param resource             $handle
	 * @param array<string, mixed> $row
	 */
	public static function append( $handle, array $row ): void {
		$encoded = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( is_string( $encoded ) ) {
			fwrite( $handle, $encoded . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
	}

	/**
	 * @return array<int, string> product_id => type
	 */
	public static function load_types( ?string $path = null ): array {
		$types = array();

		foreach ( self::read( $path ) as $row ) {
			$types[ (int) $row['pid'] ] = (string) ( $row['type'] ?? 'simple' );
		}

		return $types;
	}

	/**
	 * Signature key for one exported product (identifier + variation values).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function signature_key( array $data ): string {
		$key = (string) $data['export_id'];

		if ( is_array( $data['variations'] ?? null ) ) {
			$parts = array();

			foreach ( $data['variations']['items'] as $item ) {
				$parts[] = (string) ( $item['key'] ?? implode( "\x1F", array_map( 'strval', $item['values'] ?? array() ) ) );
			}

			sort( $parts, SORT_STRING );
			$key .= '|' . implode( ',', $parts );
		}

		return $key;
	}

	/**
	 * Deterministic, resumable signature chain.
	 */
	public static function add_signature( string $state, string $key ): string {
		return hash( 'sha256', $state . "\n" . $key );
	}

	public static function start_signature(): string {
		return hash( 'sha256', 'wps-pepita-catalog' );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function row_from_data( array $data, int $retired_at = 0 ): array {
		$vars = array();

		if ( is_array( $data['variations'] ?? null ) ) {
			foreach ( $data['variations']['items'] as $item ) {
				$vars[] = (string) ( $item['key'] ?? implode( "\x1F", array_map( 'strval', $item['values'] ?? array() ) ) );
			}
		}

		return array(
			'pid'        => (int) $data['product_id'],
			'eid'        => (string) $data['export_id'],
			'type'       => (string) $data['type'],
			'name'       => mb_substr( (string) $data['name'], 0, 300 ),
			'price'      => (float) $data['price'],
			'sale'       => null !== $data['sale_price'] ? (float) $data['sale_price'] : null,
			'currency'   => (string) $data['currency'],
			'vat'        => (float) $data['vat_percent'],
			'vars'       => $vars,
			'retired_at' => $retired_at,
		);
	}
}
