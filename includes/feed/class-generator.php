<?php
/**
 * Resumable, streaming feed generator.
 *
 * A generation is split into invocations: every run processes a bounded
 * number of products, persists its cursor and schedules a continuation. The
 * previously published feed is only replaced after the new file has been
 * fully written and validated.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Feed;

use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Catalog\Validator;
use WPS\Pepita\Logger;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

defined( 'ABSPATH' ) || exit;

final class Generator {

	public const TYPE_FULL  = 'full';
	public const TYPE_STOCK = 'stock';

	private Xml_Writer $writer;
	private float $run_start = 0.0;

	public function __construct() {
		$this->writer = new Xml_Writer();
	}

	/**
	 * Run (or resume) a generation.
	 *
	 * @param array<string, mixed> $options { max_runtime?: int }
	 * @return array<string, mixed>
	 */
	public function run( string $type, array $options = array() ): array {
		$type            = self::TYPE_STOCK === $type ? self::TYPE_STOCK : self::TYPE_FULL;
		$this->run_start = microtime( true );

		$budget = array_key_exists( 'max_runtime', $options )
			? (int) $options['max_runtime']
			: (int) Settings::get( 'max_runtime', 20 );

		$state = $this->get_state( $type );

		if ( null === $state ) {
			$started = $this->start( $type );

			if ( isset( $started['error'] ) || in_array( (string) ( $started['status'] ?? '' ), array( 'error', 'skipped' ), true ) ) {
				return $started;
			}

			$state = $this->get_state( $type );

			if ( null === $state ) {
				return array(
					'status'  => 'error',
					'message' => 'A generálási állapot nem hozható létre.',
				);
			}
		} else {
			$token = (string) ( $state['lock_token'] ?? '' );

			if ( ! Lock::is_owner( $type, $token ) ) {
				$this->discard_state( $type, $state );

				return array(
					'status'  => 'error',
					'message' => 'A generálási zár elveszett vagy lejárt. A folyamat biztonságosan leállt, a korábbi feed megmaradt.',
				);
			}

			Lock::refresh( $type, $token );
		}

		$progress = $this->process( $type, $state, $budget );

		if ( $progress['done'] ) {
			return $this->finalize( $type, $state );
		}

		Scheduler::schedule_continuation( $type );

		return array(
			'status'   => 'running',
			'type'     => $type,
			'counts'   => $state['counters'],
			'planned'  => (int) ( $state['planned'] ?? 0 ),
			'progress' => (int) ( $state['planned'] ?? 0 ) > 0
				? min( 99, (int) floor( ( (int) $state['counters']['processed'] / (int) $state['planned'] ) * 100 ) )
				: 0,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function start( string $type ): array {
		$lock = Lock::acquire( $type );

		if ( null === $lock ) {
			return array(
				'status'  => 'skipped',
				'message' => 'Már fut egy generálás ehhez a feedhez.',
			);
		}

		if ( ! Storage::ensure_directory() ) {
			Lock::release( $type, $lock );

			return array(
				'status'  => 'error',
				'message' => 'A feed könyvtár nem hozható létre vagy nem írható.',
			);
		}

		if ( Settings::is_beta_feed_only() && ! Category_Rules::has_included_categories() ) {
			Lock::release( $type, $lock );

			return array(
				'status'  => 'error',
				'message' => 'Béta üzemmódban legalább egy kategória engedélyezése kötelező a feed generálásához.',
			);
		}

		if ( self::TYPE_STOCK === $type ) {
			$full = $this->get_feed_state( self::TYPE_FULL );

			if ( empty( $full['last_success'] ) || ! Storage::exists( self::TYPE_FULL ) ) {
				Lock::release( $type, $lock );

				return array(
					'status'  => 'error',
					'message' => 'A készletfeed nem generálható sikeres teljes feed nélkül. Először teljes feedet kell készíteni.',
				);
			}
		}

		$ids_file   = Storage::path( $type . '.ids-' . preg_replace( '/[^a-z0-9]/i', '', $lock ) . '.txt' );
		$feed_temp  = Storage::temp_path( $type, $lock );
		$index_temp = Storage::catalog_index_temp_path( $lock );

		$feed = fopen( $feed_temp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $feed ) {
			Lock::release( $type, $lock );

			return array(
				'status'  => 'error',
				'message' => 'A ideiglenes feed fájl nem hozható létre.',
			);
		}

		fwrite( $feed, $this->writer->header() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $feed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$index = fopen( $index_temp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false !== $index ) {
			fclose( $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$plan = $this->build_plan( $ids_file );

		if ( isset( $plan['error'] ) ) {
			Lock::release( $type, $lock );
			@unlink( $ids_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $feed_temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $index_temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return array(
				'status'  => 'error',
				'message' => $plan['error'],
			);
		}

		$state = array(
			'type'        => $type,
			'lock_token'  => $lock,
			'started_at'  => time(),
			'heartbeat'   => time(),
			'offset'      => 0,
			'ids_file'    => $ids_file,
			'feed_temp'   => $feed_temp,
			'index_temp'  => $index_temp,
			'planned'     => (int) $plan['eligible'],
			'signature'   => Catalog_Index::start_signature(),
			'counters'    => array(
				'processed' => 0,
				'exported'  => 0,
				'invalid'   => 0,
				'excluded'  => (int) $plan['excluded'],
				'warnings'  => 0,
			),
			'errors'      => array(),
			'warnings'    => array(),
		);

		$this->save_state( $type, $state );

		Logger::info(
			'feed_generation',
			sprintf( 'Feed generálás elindult (%s).', $type ),
			array(
				'eligible' => (int) $plan['eligible'],
				'excluded' => (int) $plan['excluded'],
			)
		);

		return array( 'status' => 'started' );
	}

	/**
	 * Build the stable list of product ids for this generation.
	 *
	 * @return array{eligible: int, excluded: int}|array{error: string}
	 */
	private function build_plan( string $ids_file ): array {
		$handle = fopen( $ids_file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return array( 'error' => 'A terméklista fájl nem hozható létre.' );
		}

		$eligible = 0;
		$excluded = 0;
		$page     = 1;

		try {
			do {
				$batch = wc_get_products(
					array(
						'limit'   => 200,
						'page'    => $page,
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
					if ( Product_Selection::is_included( $product_id ) ) {
						fwrite( $handle, $product_id . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						++$eligible;
					} else {
						++$excluded;
					}
				}

				++$page;
			} while ( true );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return array(
			'eligible' => $eligible,
			'excluded' => $excluded,
		);
	}

	/**
	 * Process a bounded number of batches.
	 *
	 * @param array<string, mixed> $state
	 * @return array{done: bool, progress: int}
	 */
	private function process( string $type, array &$state, int $budget ): array {
		$feed  = fopen( (string) $state['feed_temp'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$index = fopen( (string) $state['index_temp'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$ids   = fopen( (string) $state['ids_file'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $feed || false === $index || false === $ids ) {
			foreach ( array( $feed, $index, $ids ) as $handle ) {
				if ( is_resource( $handle ) ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
			}

			return array(
				'done'     => false,
				'progress' => 0,
			);
		}

		fseek( $ids, (int) $state['offset'] );

		$batch_size    = max( 10, min( 1000, (int) Settings::get( 'batch_size', 100 ) ) );
		$max_batches   = max( 1, (int) Settings::get( 'batches_per_run', 10 ) );
		$variable_types = Catalog_Index::load_types();
		$done          = false;

		for ( $batch_index = 0; $batch_index < $max_batches; $batch_index++ ) {
			$batch = array();

			while ( count( $batch ) < $batch_size && false !== ( $line = fgets( $ids ) ) ) {
				$product_id = (int) trim( $line );

				if ( $product_id > 0 ) {
					$batch[] = $product_id;
				}
			}

			if ( array() === $batch ) {
				$done = true;
				break;
			}

			// Persist the cursor before writing, so a fatal error can never
			// produce duplicate product nodes (it fails the final count check).
			$state['offset'] = (int) ftell( $ids );
			$this->save_state( $type, $state );

			update_object_term_cache( $batch, 'product' );

			foreach ( $batch as $product_id ) {
				$this->process_product( $type, $product_id, $state, $feed, $index, $variable_types );
			}

			$state['heartbeat'] = time();
			$this->save_state( $type, $state );

			if ( $budget > 0 && ( microtime( true ) - $this->run_start ) >= $budget ) {
				break;
			}
		}

		fclose( $ids ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $done ) {
			$this->handle_stale( $type, $state, $feed, $index );
		}

		fclose( $feed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'done'     => $done,
			'progress' => (int) ( $state['planned'] ?? 0 ) > 0
				? min( 99, (int) floor( ( (int) $state['counters']['processed'] / (int) $state['planned'] ) * 100 ) )
				: 0,
		);
	}

	/**
	 * @param array<string, mixed> $state
	 * @param resource             $feed
	 * @param resource             $index
	 * @param array<int, string>   $variable_types
	 */
	private function process_product( string $type, int $product_id, array &$state, $feed, $index, array $variable_types ): void {
		$data = Product_Mapper::map( $product_id );

		if ( isset( $variable_types[ $product_id ] ) && 'variable' === $variable_types[ $product_id ] && 'variable' !== $data['type'] ) {
			$data['errors'][] = array(
				'code'    => 'variable_to_simple_forbidden',
				'message' => 'A termék korábban variációs termékként került exportálásra, ezért a Pepita szabályai szerint nem alakítható egyszerű termékké. A terméket kihagytuk a feedből.',
			);
		}

		$validation = Validator::validate( $data );

		++$state['counters']['processed'];
		$state['counters']['warnings'] += count( $validation['warnings'] );

		foreach ( $validation['warnings'] as $warning ) {
			if ( count( $state['warnings'] ) < 20 ) {
				$state['warnings'][] = array(
					'product_id' => $product_id,
					'export_id'  => (string) $data['export_id'],
					'code'       => (string) $warning['code'],
					'message'    => (string) $warning['message'],
				);
			}
		}

		if ( ! $validation['valid'] ) {
			++$state['counters']['invalid'];

			$first = $validation['errors'][0] ?? array(
				'code'    => 'unknown',
				'message' => 'Ismeretlen validációs hiba.',
			);

			if ( count( $state['errors'] ) < 50 ) {
				$state['errors'][] = array(
					'product_id' => $product_id,
					'export_id'  => (string) $data['export_id'],
					'code'       => (string) $first['code'],
					'message'    => (string) $first['message'],
				);
			}

			if ( count( $state['errors'] ) <= 20 ) {
				Logger::warning(
					'feed_validation',
					sprintf( 'Termék kihagyva a feedből: %s', (string) $first['message'] ),
					array(
						'product_id' => $product_id,
						'export_id'  => (string) $data['export_id'],
						'code'       => (string) $first['code'],
						'errors'     => $validation['errors'],
					),
					'product',
					$product_id
				);
			}

			if ( self::TYPE_FULL === $type && (bool) Settings::get( 'record_export_meta', true ) ) {
				update_post_meta( $product_id, Product_Mapper::META_LAST_STATUS, 'invalid' );
				update_post_meta( $product_id, Product_Mapper::META_LAST_AT, time() );
				update_post_meta( $product_id, Product_Mapper::META_LAST_MESSAGE, (string) $first['message'] );
			}

			return;
		}

		Product_Mapper::ensure_export_id( $product_id );

		$fragment = self::TYPE_FULL === $type
			? $this->writer->product( $data )
			: $this->writer->stock_product( $data );

		fwrite( $feed, $fragment ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		Catalog_Index::append( $index, Catalog_Index::row_from_data( $data ) );

		$state['signature'] = Catalog_Index::add_signature(
			(string) $state['signature'],
			Catalog_Index::signature_key( $data )
		);

		++$state['counters']['exported'];

		if ( self::TYPE_FULL === $type && (bool) Settings::get( 'record_export_meta', true ) ) {
			update_post_meta( $product_id, Product_Mapper::META_LAST_STATUS, 'exported' );
			update_post_meta( $product_id, Product_Mapper::META_LAST_AT, time() );
			delete_post_meta( $product_id, Product_Mapper::META_LAST_MESSAGE );
		}
	}

	/**
	 * Handle previously exported products that are no longer eligible.
	 *
	 * @param array<string, mixed> $state
	 * @param resource             $feed
	 * @param resource             $index
	 */
	private function handle_stale( string $type, array &$state, $feed, $index ): void {
		if ( 'zero_stock' !== (string) Settings::get( 'removal_policy', 'omit' ) ) {
			return;
		}

		$grace = max( 0, (int) Settings::get( 'removal_grace_days', 3 ) ) * DAY_IN_SECONDS;

		$exported = array();

		foreach ( Catalog_Index::read( (string) $state['index_temp'] ) as $row ) {
			$exported[ (int) $row['pid'] ] = true;
		}

		foreach ( Catalog_Index::read() as $row ) {
			$product_id = (int) $row['pid'];

			if ( isset( $exported[ $product_id ] ) ) {
				continue;
			}

			$retired_at = (int) ( $row['retired_at'] ?? 0 );

			if ( $retired_at > 0 && ( $retired_at + $grace ) < time() ) {
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$data       = Product_Mapper::map( $product_id );
			$validation = Validator::validate( $data );

			if ( ! $validation['valid'] ) {
				Logger::warning(
					'feed_removal',
					'Korábban exportált termék már nem exportálható, ezért a Pepita törölni fogja.',
					array(
						'product_id' => $product_id,
						'export_id'  => (string) $data['export_id'],
						'errors'     => $validation['errors'],
					),
					'product',
					$product_id
				);
				continue;
			}

			$data['available'] = false;
			$data['quantity']  = 0;
			$retired_at        = $retired_at > 0 ? $retired_at : time();

			$fragment = self::TYPE_FULL === $type
				? $this->writer->product( $data )
				: $this->writer->stock_product( $data );

			fwrite( $feed, $fragment ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			Catalog_Index::append( $index, Catalog_Index::row_from_data( $data, $retired_at ) );

			$state['signature'] = Catalog_Index::add_signature(
				(string) $state['signature'],
				Catalog_Index::signature_key( $data )
			);

			++$state['counters']['exported'];

			Logger::info(
				'feed_removal',
				'Korábban exportált, már nem kiválasztott termék 0 készlettel szerepel a feedben (visszavonási türelmi idő).',
				array(
					'product_id' => $product_id,
					'export_id'  => (string) $data['export_id'],
				),
				'product',
				$product_id
			);
		}
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function finalize( string $type, array $state ): array {
		$feed_temp  = (string) $state['feed_temp'];
		$index_temp = (string) $state['index_temp'];

		file_put_contents( $feed_temp, $this->writer->footer(), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$validation = Validator::validate_xml_file( $feed_temp );
		$exported   = (int) $state['counters']['exported'];

		if ( ! $validation['valid'] ) {
			return $this->abort(
				$type,
				$state,
				'Az XML ellenőrzés sikertelen: ' . implode( ' | ', array_slice( $validation['errors'], 0, 3 ) )
			);
		}

		if ( $validation['products'] !== $exported ) {
			return $this->abort(
				$type,
				$state,
				sprintf(
					'A feedben %d termék található, de %d terméket vártunk. A generálás megszakítva, a korábbi feed megmaradt.',
					$validation['products'],
					$exported
				)
			);
		}

		if ( Settings::is_beta_feed_only() && ! Category_Rules::has_included_categories() ) {
			return $this->abort(
				$type,
				$state,
				'Béta üzemmódban legalább egy kategória engedélyezése kötelező a feed publikálásához.'
			);
		}

		if ( 0 === $exported && ! (bool) Settings::get( 'allow_empty_feed', false ) ) {
			return $this->abort(
				$type,
				$state,
				'A generált feed üres lenne. A biztonság kedvéért nem cseréltük le a korábbi feedet. Ha ez szándékos, engedélyezd az üres feed lehetőségét a beállításokban.'
			);
		}

		if ( self::TYPE_STOCK === $type ) {
			$full = $this->get_feed_state( self::TYPE_FULL );

			if ( empty( $full['last_success'] ) ) {
				return $this->abort( $type, $state, 'Nincs sikeres teljes feed, ezért a készletfeed nem publikálható.' );
			}

			if ( (string) $full['signature'] !== (string) $state['signature'] ) {
				Scheduler::mark_dirty( self::TYPE_FULL );

				return $this->abort(
					$type,
					$state,
					'A készletfeed kínálata eltér a legutóbbi teljes feedtől. A készletfeed nem került publikálásra, hogy ne sérüljön a katalógus konzisztenciája. Kérlek futtass teljes feed generálást.'
				);
			}
		}

		if ( self::TYPE_FULL === $type ) {
			$catalog_diff = Catalog_Diff::compare( $index_temp, Storage::catalog_index_path() );

			if ( ! empty( $catalog_diff['warnings'] ) ) {
				foreach ( $catalog_diff['warnings'] as $warning ) {
					$state['warnings'][] = $warning;
					Logger::warning( 'feed_safety', $warning, array( 'drop_percent' => $catalog_diff['drop_percent'] ) );
				}
			}

			if ( (bool) Settings::get( 'feed_safety_block_publication', false ) && 'ok' !== $catalog_diff['status'] && ! empty( $catalog_diff['warnings'] ) ) {
				return $this->abort(
					$type,
					$state,
					sprintf( 'A publikálás a feed biztonsági védelem miatt megszakítva: %s', implode( ' ', $catalog_diff['warnings'] ) )
				);
			}
		}

		$hash = hash_file( 'sha256', $feed_temp );

		if ( self::TYPE_FULL === $type ) {
			if ( ! Storage::publish_full( $feed_temp, $index_temp, null, (string) $state['signature'] ) ) {
				return $this->abort( $type, $state, 'A teljes feed és a katalógus index közzététele nem sikerült (konzisztencia megőrizve).' );
			}

			if ( isset( $catalog_diff ) && is_array( $catalog_diff ) ) {
				update_option( Catalog_Diff::OPTION_DIFF_FULL, $catalog_diff, false );
			}
		} else {
			if ( ! Storage::publish( $type, $feed_temp, null, (string) $state['signature'] ) ) {
				return $this->abort( $type, $state, 'A kész feed nem helyezhető át a végleges helyére.' );
			}

			@unlink( $index_temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$feed_state = $this->get_feed_state( $type );
		$feed_state = array_merge(
			$feed_state,
			array(
				'last_success'  => time(),
				'last_attempt'  => time(),
				'last_error'    => '',
				'last_error_at' => 0,
				'counts'        => $state['counters'],
				'signature'     => (string) $state['signature'],
				'file_size'     => (int) Storage::size( $type ),
				'hash'          => is_string( $hash ) ? $hash : '',
				'duration'      => max( 0, time() - (int) $state['started_at'] ),
				'generation_id' => (string) $state['lock_token'],
				'errors'        => array_slice( (array) $state['errors'], 0, 50 ),
				'warnings'      => array_slice( (array) $state['warnings'], 0, 20 ),
				'planned'       => (int) ( $state['planned'] ?? 0 ),
			)
		);

		update_option( $this->feed_state_option( $type ), $feed_state, false );

		Scheduler::clear_dirty( $type );

		@unlink( (string) $state['ids_file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		delete_option( $this->state_option( $type ) );
		Lock::release( $type, (string) $state['lock_token'] );

		Logger::info(
			'feed_generation',
			sprintf( 'Feed sikeresen publikálva (%s).', $type ),
			array(
				'exported'  => $exported,
				'invalid'   => (int) $state['counters']['invalid'],
				'excluded'  => (int) $state['counters']['excluded'],
				'warnings'  => (int) $state['counters']['warnings'],
				'duration'  => (int) $feed_state['duration'],
				'file_size' => (int) $feed_state['file_size'],
			)
		);

		return array(
			'status'    => 'completed',
			'type'      => $type,
			'counts'    => $state['counters'],
			'duration'  => (int) $feed_state['duration'],
			'file_size' => (int) $feed_state['file_size'],
			'signature' => (string) $state['signature'],
		);
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function abort( string $type, array $state, string $message ): array {
		@unlink( (string) $state['feed_temp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( (string) $state['index_temp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( (string) $state['ids_file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$feed_state = $this->get_feed_state( $type );

		$feed_state['last_attempt']       = time();
		$feed_state['last_error']         = $message;
		$feed_state['last_error_at']      = time();
		$feed_state['attempt_counts']     = $state['counters'] ?? array();
		$feed_state['attempt_errors']     = array_slice( (array) ( $state['errors'] ?? array() ), 0, 50 );

		update_option( $this->feed_state_option( $type ), $feed_state, false );

		delete_option( $this->state_option( $type ) );
		Lock::release( $type, (string) ( $state['lock_token'] ?? '' ) );

		Scheduler::mark_dirty( $type );

		Logger::error(
			'feed_generation',
			sprintf( 'A(z) %s feed generálása sikertelen: %s', $type, $message ),
			array(
				'counts' => $state['counters'] ?? array(),
				'errors' => array_slice( (array) ( $state['errors'] ?? array() ), 0, 20 ),
			)
		);

		return array(
			'status'  => 'error',
			'message' => $message,
			'counts'  => $state['counters'] ?? array(),
		);
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function discard_state( string $type, array $state ): void {
		@unlink( (string) ( $state['feed_temp'] ?? '' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( (string) ( $state['index_temp'] ?? '' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( (string) ( $state['ids_file'] ?? '' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		delete_option( $this->state_option( $type ) );

		Logger::warning( 'feed_generation', 'A generálás megszakadt, mert a zár elveszett.', array( 'type' => $type ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_state( string $type ): ?array {
		$state = get_option( $this->state_option( $type ), null );

		return is_array( $state ) && isset( $state['lock_token'] ) ? $state : null;
	}

	public function is_running( string $type ): bool {
		return null !== $this->get_state( $type ) || Lock::is_locked( $type );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_feed_state( string $type ): array {
		$defaults = array(
			'last_success'  => 0,
			'last_attempt'  => 0,
			'last_error'    => '',
			'last_error_at' => 0,
			'counts'        => array(
				'processed' => 0,
				'exported'  => 0,
				'invalid'   => 0,
				'excluded'  => 0,
				'warnings'  => 0,
			),
			'signature'     => '',
			'file_size'     => 0,
			'hash'          => '',
			'duration'      => 0,
			'generation_id' => '',
			'errors'        => array(),
			'warnings'      => array(),
			'planned'       => 0,
		);

		$stored = get_option( $this->feed_state_option( $type ), array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array_merge( $defaults, $stored );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function save_state( string $type, array $state ): void {
		update_option( $this->state_option( $type ), $state, false );
	}

	private function state_option( string $type ): string {
		return 'wps_pepita_generation_' . $type;
	}

	private function feed_state_option( string $type ): string {
		return 'wps_pepita_feed_state_' . $type;
	}
}
