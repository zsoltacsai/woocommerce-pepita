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
		$max_price_drop_percent = (int) ( $options['max_price_drop_percent'] ?? Settings::get( 'feed_safety_max_price_drop_percent', 50 ) );
		$max_price_spikes       = (int) ( $options['max_price_spikes'] ?? Settings::get( 'feed_safety_max_price_spikes', 10 ) );
		$max_quality_exclusions = (int) ( $options['max_quality_exclusions'] ?? Settings::get( 'feed_safety_max_quality_exclusions', 50 ) );
		$require_approval       = (bool) ( $options['require_approval'] ?? Settings::get( 'feed_safety_require_approval', false ) );
		$sample_limit           = max( 5, (int) ( $options['sample_limit'] ?? 50 ) );

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
				'price_spike_count'          => 0,
				'stock_changed_count'        => 0,
				'category_changed_count'     => 0,
				'variation_changed_count'    => 0,
				'quality_excluded_count'     => 0,
				'tax_currency_changed_count' => 0,
				'drop_percent'               => 0.0,
				'overall_impact'             => 'none',
				'warnings'                   => array(),
				'samples'                    => array(
					'added'                => array(),
					'removed'              => array(),
					'unavailable'          => array(),
					'price_changed'        => array(),
					'price_spike'          => array(),
					'stock_changed'        => array(),
					'category_changed'     => array(),
					'variation_changed'    => array(),
					'quality_excluded'     => array(),
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
				'price_spike_count'          => 0,
				'stock_changed_count'        => 0,
				'category_changed_count'     => 0,
				'variation_changed_count'    => 0,
				'quality_excluded_count'     => 0,
				'tax_currency_changed_count' => 0,
				'drop_percent'               => 0.0,
				'overall_impact'             => 'safe',
				'warnings'                   => array(),
				'samples'                    => array(
					'added'                => array(),
					'removed'              => array(),
					'unavailable'          => array(),
					'price_changed'        => array(),
					'price_spike'          => array(),
					'stock_changed'        => array(),
					'category_changed'     => array(),
					'variation_changed'    => array(),
					'quality_excluded'     => array(),
					'tax_currency_changed' => array(),
				),
				'message'                    => __( 'Első generálás — nincs korábbi összehasonlítási alap.', 'wp-pepita-sync' ),
				'compared_at'                => time(),
			);
		}

		// Stream the previous snapshot into an indexed compact map.
		// To maintain strictly bounded memory, names and nested variation structures are NOT
		// held in memory; only compact scalar primitives, checksums, and file offsets are kept.
		$prev_map          = array();
		$prev_active_count = 0;
		$prev_stream       = fopen( $previous_path, 'rb' );

		if ( false !== $prev_stream ) {
			while ( true ) {
				$offset = ftell( $prev_stream );
				$line   = fgets( $prev_stream );
				if ( false === $line ) {
					break;
				}

				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				$row = json_decode( $line, true );
				if ( ! is_array( $row ) || ! isset( $row['pid'] ) ) {
					continue;
				}

				$pid        = (int) $row['pid'];
				$retired_at = (int) ( $row['retired_at'] ?? 0 );

				if ( 0 === $retired_at ) {
					++$prev_active_count;
				}

				$vars_crc = ! empty( $row['vars'] ) && is_array( $row['vars'] )
					? crc32( (string) wp_json_encode( $row['vars'] ) )
					: 0;

				$prev_map[ $pid ] = array(
					'ret'   => $retired_at,
					'price' => (float) ( $row['price'] ?? 0.0 ),
					'sale'  => isset( $row['sale'] ) && null !== $row['sale'] ? (float) $row['sale'] : null,
					'vat'   => (float) ( $row['vat'] ?? 0.0 ),
					'curr'  => (string) ( $row['currency'] ?? '' ),
					'qty'   => isset( $row['qty'] ) ? (int) $row['qty'] : null,
					'avail' => isset( $row['avail'] ) ? (bool) $row['avail'] : null,
					'cat'   => (string) ( $row['cat'] ?? '' ),
					'qual'  => (bool) ( $row['qual_ex'] ?? false ),
					'vars'  => $vars_crc,
					'pos'   => $offset,
				);
			}

			fclose( $prev_stream );
		}

		// Stream the current snapshot, comparing against the previous map.
		$curr_active_count          = 0;
		$curr_unavailable_count     = 0;
		$added_count                = 0;
		$price_changed_count        = 0;
		$price_spike_count          = 0;
		$stock_changed_count        = 0;
		$category_changed_count     = 0;
		$variation_changed_count    = 0;
		$quality_excluded_count     = 0;
		$tax_currency_changed_count = 0;

		$samples = array(
			'added'                => array(),
			'removed'              => array(),
			'unavailable'          => array(),
			'price_changed'        => array(),
			'price_spike'          => array(),
			'stock_changed'        => array(),
			'category_changed'     => array(),
			'variation_changed'    => array(),
			'quality_excluded'     => array(),
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
				if ( 0 === $prev['ret'] && $retired_at > 0 ) {
					if ( count( $samples['unavailable'] ) < $sample_limit ) {
						$samples['unavailable'][] = array(
							'pid'        => $pid,
							'eid'        => (string) ( $row['eid'] ?? '' ),
							'name'       => (string) ( $row['name'] ?? '' ),
							'retired_at' => $retired_at,
						);
					}
				}

				// 2. Price changes and price spikes.
				$curr_price = (float) ( $row['price'] ?? 0.0 );
				$curr_sale  = isset( $row['sale'] ) && null !== $row['sale'] ? (float) $row['sale'] : null;

				if ( abs( $prev['price'] - $curr_price ) > 0.001 || $prev['sale'] !== $curr_sale ) {
					++$price_changed_count;

					$drop_pct = 0.0;
					if ( $prev['price'] > 0.0 && $curr_price < $prev['price'] ) {
						$drop_pct = round( ( ( $prev['price'] - $curr_price ) / $prev['price'] ) * 100, 1 );
						if ( $drop_pct >= $max_price_drop_percent ) {
							++$price_spike_count;
							if ( count( $samples['price_spike'] ) < $sample_limit ) {
								$samples['price_spike'][] = array(
									'pid'          => $pid,
									'eid'          => (string) ( $row['eid'] ?? '' ),
									'name'         => (string) ( $row['name'] ?? '' ),
									'old_price'    => $prev['price'],
									'new_price'    => $curr_price,
									'drop_percent' => $drop_pct,
								);
							}
						}
					}

					if ( count( $samples['price_changed'] ) < $sample_limit ) {
						$samples['price_changed'][] = array(
							'pid'          => $pid,
							'eid'          => (string) ( $row['eid'] ?? '' ),
							'name'         => (string) ( $row['name'] ?? '' ),
							'old_price'    => $prev['price'],
							'new_price'    => $curr_price,
							'old_sale'     => $prev['sale'],
							'new_sale'     => $curr_sale,
							'drop_percent' => $drop_pct,
						);
					}
				}

				// 3. Stock changes: availability or quantity.
				$prev_avail = $prev['avail'];
				$curr_avail = isset( $row['avail'] ) ? (bool) $row['avail'] : ( 0 === $retired_at );
				$prev_qty   = $prev['qty'];
				$curr_qty   = isset( $row['qty'] ) ? (int) $row['qty'] : null;

				$has_avail_diff = null !== $prev_avail && ( $prev_avail !== $curr_avail );
				$has_qty_diff   = null !== $prev_qty && null !== $curr_qty && ( $prev_qty !== $curr_qty );

				if ( $has_avail_diff || $has_qty_diff ) {
					++$stock_changed_count;

					if ( count( $samples['stock_changed'] ) < $sample_limit ) {
						$samples['stock_changed'][] = array(
							'pid'       => $pid,
							'eid'       => (string) ( $row['eid'] ?? '' ),
							'name'      => (string) ( $row['name'] ?? '' ),
							'old_avail' => $prev_avail,
							'new_avail' => $curr_avail,
							'old_qty'   => $prev_qty,
							'new_qty'   => $curr_qty,
						);
					}
				}

				// 4. Category changes.
				$prev_cat = (string) ( $prev['cat'] ?? '' );
				$curr_cat = (string) ( $row['cat'] ?? '' );

				if ( $prev_cat !== $curr_cat ) {
					++$category_changed_count;

					if ( count( $samples['category_changed'] ) < $sample_limit ) {
						$samples['category_changed'][] = array(
							'pid'     => $pid,
							'eid'     => (string) ( $row['eid'] ?? '' ),
							'name'    => (string) ( $row['name'] ?? '' ),
							'old_cat' => $prev_cat,
							'new_cat' => $curr_cat,
						);
					}
				}

				// 5. Quality exclusion changes.
				$prev_qual = (bool) ( $prev['qual'] ?? false );
				$curr_qual = (bool) ( $row['qual_ex'] ?? false );

				if ( $curr_qual && ! $prev_qual ) {
					++$quality_excluded_count;

					if ( count( $samples['quality_excluded'] ) < $sample_limit ) {
						$samples['quality_excluded'][] = array(
							'pid'    => $pid,
							'eid'    => (string) ( $row['eid'] ?? '' ),
							'name'   => (string) ( $row['name'] ?? '' ),
							'reason' => 'quality_excluded',
						);
					}
				}

				// 6. Variation identity changes.
				$curr_vars     = (array) ( $row['vars'] ?? array() );
				$curr_vars_crc = ! empty( $curr_vars )
					? crc32( (string) wp_json_encode( $curr_vars ) )
					: 0;

				if ( $prev['vars'] !== $curr_vars_crc ) {
					++$variation_changed_count;

					if ( count( $samples['variation_changed'] ) < $sample_limit ) {
						$samples['variation_changed'][] = array(
							'pid'      => $pid,
							'eid'      => (string) ( $row['eid'] ?? '' ),
							'name'     => (string) ( $row['name'] ?? '' ),
							'new_vars' => $curr_vars,
						);
					}
				}

				// 7. Tax / Currency changes.
				$curr_vat = (float) ( $row['vat'] ?? 0.0 );
				$curr_cur = (string) ( $row['currency'] ?? '' );

				if ( abs( $prev['vat'] - $curr_vat ) > 0.001 || $prev['curr'] !== $curr_cur ) {
					++$tax_currency_changed_count;

					if ( count( $samples['tax_currency_changed'] ) < $sample_limit ) {
						$samples['tax_currency_changed'][] = array(
							'pid'          => $pid,
							'eid'          => (string) ( $row['eid'] ?? '' ),
							'name'         => (string) ( $row['name'] ?? '' ),
							'old_vat'      => $prev['vat'],
							'new_vat'      => $curr_vat,
							'old_currency' => $prev['curr'],
							'new_currency' => $curr_cur,
						);
					}
				}
			}
		}

		// Items remaining in $prev_map were active in previous, but are completely gone from current.
		$removed_count = 0;
		$prev_fh       = ( count( $prev_map ) > 0 && is_file( $previous_path ) ) ? fopen( $previous_path, 'rb' ) : false;

		foreach ( $prev_map as $pid => $prev_item ) {
			if ( 0 === (int) $prev_item['ret'] ) {
				++$removed_count;

				$item_name = '';
				$item_eid  = '';

				if ( false !== $prev_fh && ( count( $samples['removed'] ) < $sample_limit || count( $samples['quality_excluded'] ) < $sample_limit ) ) {
					fseek( $prev_fh, (int) $prev_item['pos'] );
					$line = fgets( $prev_fh );
					if ( false !== $line ) {
						$r = json_decode( trim( $line ), true );
						if ( is_array( $r ) ) {
							$item_name = (string) ( $r['name'] ?? '' );
							$item_eid  = (string) ( $r['eid'] ?? '' );
						}
					}
				}

				$is_qual = false;
				if ( class_exists( '\WPS\Pepita\Catalog\Product_Selection' ) ) {
					$sel = \WPS\Pepita\Catalog\Product_Selection::resolve( (int) $pid );
					if ( \WPS\Pepita\Catalog\Product_Selection::SOURCE_QUALITY_EXCLUDED === ( $sel['source'] ?? '' ) ) {
						$is_qual = true;
						++$quality_excluded_count;

						if ( count( $samples['quality_excluded'] ) < $sample_limit ) {
							$samples['quality_excluded'][] = array(
								'pid'    => (int) $pid,
								'eid'    => $item_eid,
								'name'   => $item_name,
								'reason' => 'quality_excluded',
							);
						}
					}
				}

				if ( count( $samples['removed'] ) < $sample_limit ) {
					$samples['removed'][] = array(
						'pid'              => (int) $pid,
						'eid'              => $item_eid,
						'name'             => $item_name,
						'price'            => (float) $prev_item['price'],
						'quality_excluded' => $is_qual,
					);
				}
			}
		}

		if ( false !== $prev_fh ) {
			fclose( $prev_fh );
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

		// Safety warning 3: unexpected drastic price spikes.
		if ( $price_spike_count >= $max_price_spikes ) {
			if ( 'ok' === $status ) {
				$status = 'warn';
			}

			$warnings[] = sprintf(
				/* translators: 1: count of price spikes, 2: drop percentage */
				__( 'Drasztikus árcsökkenés észlelve: %1$d termék ára esett több mint %2$d%%-kal.', 'wp-pepita-sync' ),
				$price_spike_count,
				$max_price_drop_percent
			);
		}

		// Safety warning 4: mass quality exclusions.
		if ( $quality_excluded_count >= $max_quality_exclusions ) {
			if ( 'ok' === $status ) {
				$status = 'warn';
			}

			$warnings[] = sprintf(
				/* translators: %d: count */
				__( 'Tömeges minőségi kizárás: %d termék került kizárásra minőségi hibák miatt.', 'wp-pepita-sync' ),
				$quality_excluded_count
			);
		}

		// Determine overall impact.
		$overall_impact = 'safe';
		if ( 'fail' === $status ) {
			$overall_impact = 'critical';
		} elseif ( 'warn' === $status ) {
			$overall_impact = 'warn';
		}

		if ( $require_approval && ( 'warn' === $status || 'fail' === $status || ! empty( $warnings ) || $price_spike_count > 0 || $quality_excluded_count > 0 ) ) {
			$overall_impact = 'approval_required';
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
			'price_spike_count'          => $price_spike_count,
			'stock_changed_count'        => $stock_changed_count,
			'category_changed_count'     => $category_changed_count,
			'variation_changed_count'    => $variation_changed_count,
			'quality_excluded_count'     => $quality_excluded_count,
			'tax_currency_changed_count' => $tax_currency_changed_count,
			'drop_percent'               => $drop_percent,
			'overall_impact'             => $overall_impact,
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
