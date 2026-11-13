<?php
/**
 * Catalog difference reporter and feed safety validator.
 *
 * Compares the current full-feed catalog snapshot with the previous
 * successful snapshot to track added, removed, price-changed, variation-changed
 * and availability-changed products, issuing safety warnings when unexpected
 * catalog drops occur.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Feed;

use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

defined( 'ABSPATH' ) || exit;

final class Catalog_Diff {

	public const OPTION_DIFF_FULL = 'wps_pepita_feed_diff_full';

	/**
	 * Compare two catalog index files line by line without loading the full
	 * catalog into memory.
	 *
	 * @param ?string              $current_path  Path to the current/new catalog.jsonl.
	 * @param ?string              $previous_path Path to the previous catalog.previous.jsonl.
	 * @param array<string, mixed> $options       Optional thresholds and limits.
	 * @return array<string, mixed>
	 */
	public static function compare( ?string $current_path = null, ?string $previous_path = null, array $options = array() ): array {
		$current_path  = $current_path ?? Storage::catalog_index_path();
		$previous_path = $previous_path ?? Storage::previous_catalog_index_path();

		$drop_threshold          = (int) ( $options['drop_threshold'] ?? Settings::get( 'feed_safety_drop_threshold', 20 ) );
		$warn_variation_changes = (bool) ( $options['warn_variation_changes'] ?? Settings::get( 'feed_safety_variation_change_warning', true ) );
		$sample_limit            = max( 5, (int) ( $options['sample_limit'] ?? 50 ) );

		$has_current  = is_file( $current_path ) && filesize( $current_path ) > 0;
		$has_previous = is_file( $previous_path ) && filesize( $previous_path ) > 0;

		if ( ! $has_current ) {
			return array(
				'status'                     => 'no_current',
				'is_initial'                 => false,
				'has_current'                => false,
				'has_previous'               => $has_previous,
				'current_count'              => 0,
				'previous_count'             => 0,
				'added_count'                => 0,
				'removed_count'              => 0,
				'unavailable_count'          => 0,
				'price_changed_count'        => 0,
				'variation_changed_count'    => 0,
				'tax_currency_changed_count' => 0,
				'drop_percent'               => 0.0,
				'warnings'                   => array(),
				'samples'                    => array(
					'added'                => array(),
					'removed'              => array(),
					'unavailable'          => array(),
					'price_changed'        => array(),
					'variation_changed'    => array(),
					'tax_currency_changed' => array(),
				),
				'message'                    => __( 'Nincs publikált teljes feed katalógus index.', 'wp-pepita-sync' ),
				'compared_at'                => time(),
			);
		}

		if ( ! $has_previous ) {
			$current_count     = 0;
			$unavailable_count = 0;

			foreach ( Catalog_Index::read( $current_path ) as $row ) {
				if ( 0 === (int) ( $row['retired_at'] ?? 0 ) ) {
					++$current_count;
				} else {
					++$unavailable_count;
				}
			}

			return array(
				'status'                     => 'initial',
				'is_initial'                 => true,
				'has_current'                => true,
				'has_previous'               => false,
				'current_count'              => $current_count,
				'previous_count'             => 0,
				'added_count'                => $current_count,
				'removed_count'              => 0,
				'unavailable_count'          => $unavailable_count,
				'price_changed_count'        => 0,
				'variation_changed_count'    => 0,
				'tax_currency_changed_count' => 0,
				'drop_percent'               => 0.0,
				'warnings'                   => array(),
				'samples'                    => array(
					'added'                => array(),
					'removed'              => array(),
					'unavailable'          => array(),
					'price_changed'        => array(),
					'variation_changed'    => array(),
					'tax_currency_changed' => array(),
				),
				'message'                    => __( 'Első generálás — nincs korábbi összehasonlítási alap.', 'wp-pepita-sync' ),
				'compared_at'                => time(),
			);
		}

		// Stream the previous snapshot into an indexed compact map.
		$prev_map          = array();
		$prev_active_count = 0;

		foreach ( Catalog_Index::read( $previous_path ) as $row ) {
			$pid        = (int) $row['pid'];
			$retired_at = (int) ( $row['retired_at'] ?? 0 );

			if ( 0 === $retired_at ) {
				++$prev_active_count;
			}

			$prev_map[ $pid ] = array(
				'pid'        => $pid,
				'eid'        => (string) ( $row['eid'] ?? '' ),
				'name'       => (string) ( $row['name'] ?? '' ),
				'price'      => (float) ( $row['price'] ?? 0.0 ),
				'sale'       => isset( $row['sale'] ) && null !== $row['sale'] ? (float) $row['sale'] : null,
				'vat'        => (float) ( $row['vat'] ?? 0.0 ),
				'currency'   => (string) ( $row['currency'] ?? '' ),
				'vars'       => (array) ( $row['vars'] ?? array() ),
				'retired_at' => $retired_at,
			);
		}

		// Stream the current snapshot, comparing against the previous map.
		$curr_active_count          = 0;
		$curr_unavailable_count     = 0;
		$added_count                = 0;
		$price_changed_count        = 0;
		$variation_changed_count    = 0;
		$tax_currency_changed_count = 0;

		$samples = array(
			'added'                => array(),
			'removed'              => array(),
			'unavailable'          => array(),
			'price_changed'        => array(),
			'variation_changed'    => array(),
			'tax_currency_changed' => array(),
		);

		foreach ( Catalog_Index::read( $current_path ) as $row ) {
			$pid        = (int) $row['pid'];
			$retired_at = (int) ( $row['retired_at'] ?? 0 );

			if ( 0 === $retired_at ) {
				++$curr_active_count;
			} else {
				++$curr_unavailable_count;
			}

			if ( ! isset( $prev_map[ $pid ] ) ) {
				if ( 0 === $retired_at ) {
					++$added_count;

					if ( count( $samples['added'] ) < $sample_limit ) {
						$samples['added'][] = array(
							'pid'   => $pid,
							'eid'   => (string) ( $row['eid'] ?? '' ),
							'name'  => (string) ( $row['name'] ?? '' ),
							'price' => (float) ( $row['price'] ?? 0.0 ),
						);
					}
				}
			} else {
				$prev = $prev_map[ $pid ];
				unset( $prev_map[ $pid ] ); // Handled; remaining in $prev_map will be items removed from current.

				// 1. Availability / Retired transition.
				if ( 0 === $prev['retired_at'] && $retired_at > 0 ) {
					if ( count( $samples['unavailable'] ) < $sample_limit ) {
						$samples['unavailable'][] = array(
							'pid'        => $pid,
							'eid'        => (string) ( $row['eid'] ?? '' ),
							'name'       => (string) ( $row['name'] ?? '' ),
							'retired_at' => $retired_at,
						);
					}
				}

				// 2. Price changes.
				$curr_price = (float) ( $row['price'] ?? 0.0 );
				$curr_sale  = isset( $row['sale'] ) && null !== $row['sale'] ? (float) $row['sale'] : null;

				if ( abs( $prev['price'] - $curr_price ) > 0.001 || $prev['sale'] !== $curr_sale ) {
					++$price_changed_count;

					if ( count( $samples['price_changed'] ) < $sample_limit ) {
						$samples['price_changed'][] = array(
							'pid'       => $pid,
							'eid'       => (string) ( $row['eid'] ?? '' ),
							'name'      => (string) ( $row['name'] ?? '' ),
							'old_price' => $prev['price'],
							'new_price' => $curr_price,
							'old_sale'  => $prev['sale'],
							'new_sale'  => $curr_sale,
						);
					}
				}

				// 3. Variation identity changes.
				$curr_vars = (array) ( $row['vars'] ?? array() );

				if ( $prev['vars'] !== $curr_vars ) {
					++$variation_changed_count;

					if ( count( $samples['variation_changed'] ) < $sample_limit ) {
						$samples['variation_changed'][] = array(
							'pid'      => $pid,
							'eid'      => (string) ( $row['eid'] ?? '' ),
							'name'     => (string) ( $row['name'] ?? '' ),
							'old_vars' => $prev['vars'],
							'new_vars' => $curr_vars,
						);
					}
				}

				// 4. Tax / Currency changes.
				$curr_vat = (float) ( $row['vat'] ?? 0.0 );
				$curr_cur = (string) ( $row['currency'] ?? '' );

				if ( abs( $prev['vat'] - $curr_vat ) > 0.001 || $prev['currency'] !== $curr_cur ) {
					++$tax_currency_changed_count;

					if ( count( $samples['tax_currency_changed'] ) < $sample_limit ) {
						$samples['tax_currency_changed'][] = array(
							'pid'          => $pid,
							'eid'          => (string) ( $row['eid'] ?? '' ),
							'name'         => (string) ( $row['name'] ?? '' ),
							'old_vat'      => $prev['vat'],
							'new_vat'      => $curr_vat,
							'old_currency' => $prev['currency'],
							'new_currency' => $curr_cur,
						);
					}
				}
			}
		}

		// Items remaining in $prev_map were active in previous, but are completely gone from current.
		$removed_count = 0;

		foreach ( $prev_map as $prev_item ) {
			if ( 0 === (int) $prev_item['retired_at'] ) {
				++$removed_count;

				if ( count( $samples['removed'] ) < $sample_limit ) {
					$samples['removed'][] = array(
						'pid'   => (int) $prev_item['pid'],
						'eid'   => (string) $prev_item['eid'],
						'name'  => (string) $prev_item['name'],
						'price' => (float) $prev_item['price'],
					);
				}
			}
		}

		// Calculate drop percentage based on active previous catalog.
		$drop_percent = 0.0;

		if ( $prev_active_count > 0 ) {
			$drop_percent = round( ( $removed_count / $prev_active_count ) * 100, 1 );
		}

		$warnings = array();
		$status   = 'ok';

		// Safety warning 1: previously populated feed became empty.
		if ( $prev_active_count > 0 && 0 === $curr_active_count ) {
			$status     = 'fail';
			$warnings[] = sprintf(
				/* translators: %d: previous product count */
				__( 'Kritikus figyelmeztetés: a feed teljesen kiürült (0 termék a korábbi %d termékhez képest).', 'wp-pepita-sync' ),
				$prev_active_count
			);
		} elseif ( $prev_active_count > 0 && $drop_percent >= $drop_threshold ) {
			$status     = 'warn';
			$warnings[] = sprintf(
				/* translators: 1: removed product count, 2: drop percentage, 3: threshold percentage */
				__( 'Jelentős termékcsökkenés észlelve: %1$d termék (%2$.1f%%) eltűnt a feedből (figyelmeztetési küszöb: %3$d%%).', 'wp-pepita-sync' ),
				$removed_count,
				$drop_percent,
				$drop_threshold
			);
		}

		// Safety warning 2: unexpected variation changes.
		if ( $warn_variation_changes && $variation_changed_count > 0 ) {
			if ( 'ok' === $status ) {
				$status = 'warn';
			}

			$warnings[] = sprintf(
				/* translators: %d: variation changed count */
				__( 'Variáció-módosulás: %d termék variációs struktúrája megváltozott az előző feedhez képest.', 'wp-pepita-sync' ),
				$variation_changed_count
			);
		}

		return array(
			'status'                     => $status,
			'is_initial'                 => false,
			'has_current'                => true,
			'has_previous'               => true,
			'current_count'              => $curr_active_count,
			'previous_count'             => $prev_active_count,
			'added_count'                => $added_count,
			'removed_count'              => $removed_count,
			'unavailable_count'          => $curr_unavailable_count,
			'price_changed_count'        => $price_changed_count,
			'variation_changed_count'    => $variation_changed_count,
			'tax_currency_changed_count' => $tax_currency_changed_count,
			'drop_percent'               => $drop_percent,
			'warnings'                   => $warnings,
			'samples'                    => $samples,
			'message'                    => '',
			'compared_at'                => time(),
		);
	}

	/**
	 * Get the cached difference report from the last successful full feed,
	 * generating and saving it if missing.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_cached_diff(): array {
		$cached = get_option( self::OPTION_DIFF_FULL, null );

		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			return $cached;
		}

		return self::generate_and_save();
	}

	/**
	 * Generate and persist the difference report.
	 *
	 * @return array<string, mixed>
	 */
	public static function generate_and_save(): array {
		$diff = self::compare();
		update_option( self::OPTION_DIFF_FULL, $diff, false );

		return $diff;
	}

	/**
	 * Invalidate cached difference report.
	 */
	public static function clear_cache(): void {
		delete_option( self::OPTION_DIFF_FULL );
	}
}
