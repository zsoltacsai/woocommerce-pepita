<?php
/**
 * Minimal in-memory $wpdb replacement for the plugin's own SQL.
 *
 * It understands exactly the query shapes used by Logger and
 * Order_Repository (plus simple SELECT/INSERT/UPDATE/DELETE variants used by
 * the test fixtures). It is NOT a general SQL engine.
 *
 * @package WPS\Pepita\Tests
 */

defined( 'ABSPATH' ) || exit;

final class Fake_WPDB {

	public string $prefix      = 'wp_';
	public string $base_prefix = 'wp_';
	public string $posts       = 'wp_posts';
	public string $postmeta    = 'wp_postmeta';
	public string $options     = 'wp_options';
	public string $terms       = 'wp_terms';
	public string $termmeta    = 'wp_termmeta';

	public string $last_error    = '';
	public int $insert_id        = 0;
	public int $rows_affected    = 0;
	public int $num_rows         = 0;
	public bool $suppress_errors = false;

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $tables = array();

	/** @var array<string, string> */
	private array $unique = array();

	/** @var array<string, string> */
	private array $auto = array();

	/**
	 * Register a table with the given column defaults.
	 *
	 * @param array<int, string> $columns
	 */
	public function register_table( string $table, array $columns, ?string $unique_column = null ): void {
		$empty = array();

		foreach ( $columns as $column ) {
			$empty[ $column ] = null;
		}

		$this->tables[ $table ] = array();
		$this->auto[ $table ]   = 'id';

		if ( null !== $unique_column ) {
			$this->unique[ $table ] = $unique_column;
		}
	}

	public function has_table( string $table ): bool {
		return isset( $this->tables[ $table ] );
	}

	public function drop_table( string $table ): void {
		unset( $this->tables[ $table ] );
	}

	public function truncate_table( string $table ): void {
		if ( isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function rows( string $table ): array {
		return $this->tables[ $table ] ?? array();
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @param string $query
	 * @param mixed  ...$args
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$index = 0;

		return preg_replace_callback(
			'/%[sdf]/',
			function ( array $match ) use ( &$index, $args ): string {
				$value = $args[ $index ] ?? '';
				++$index;

				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}

				if ( '%f' === $match[0] ) {
					return (string) (float) $value;
				}

				if ( null === $value ) {
					return 'NULL';
				}

				return "'" . $this->escape( (string) $value ) . "'";
			},
			(string) $query
		) ?? (string) $query;
	}

	public function escape( string $value ): string {
		return str_replace(
			array( '\\', "'", "\0", "\n", "\r" ),
			array( '\\\\', "\\'", '\\0', '\\n', '\\r' ),
			$value
		);
	}

	/**
	 * @param string $query
	 * @return int|bool
	 */
	public function query( $query ) {
		$this->last_error   = '';
		$this->insert_id    = 0;
		$this->rows_affected = 0;

		$sql = trim( (string) $query );

		if ( '' === $sql ) {
			return false;
		}

		if ( preg_match( '/^INSERT\s+/i', $sql ) ) {
			return $this->run_insert_sql( $sql );
		}

		if ( preg_match( '/^UPDATE\s+/i', $sql ) ) {
			return $this->run_update_sql( $sql );
		}

		if ( preg_match( '/^DELETE\s+/i', $sql ) ) {
			return $this->run_delete_sql( $sql );
		}

		if ( preg_match( '/^TRUNCATE\s+(?:TABLE\s+)?(\S+)/i', $sql, $m ) ) {
			$table = $this->normalize_table( $m[1] );

			if ( ! isset( $this->tables[ $table ] ) ) {
				return false;
			}

			$count                  = count( $this->tables[ $table ] );
			$this->tables[ $table ] = array();

			return $count;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<int, string>|null $format
	 * @return int|false
	 */
	public function insert( string $table, array $data, $format = null ) {
		$table = $this->normalize_table( $table );

		return $this->insert_row( $table, $data, false );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
		$table = $this->normalize_table( $table );

		if ( ! isset( $this->tables[ $table ] ) ) {
			return false;
		}

		$changed = 0;

		foreach ( $this->tables[ $table ] as $index => $row ) {
			if ( ! $this->row_matches( $row, $where ) ) {
				continue;
			}

			foreach ( $data as $key => $value ) {
				$this->tables[ $table ][ $index ][ $key ] = $value;
			}

			++$changed;
		}

		$this->rows_affected = $changed;

		return $changed;
	}

	/**
	 * @param array<string, mixed> $where
	 * @return int|false
	 */
	public function delete( string $table, array $where, $where_format = null ) {
		$table = $this->normalize_table( $table );

		if ( ! isset( $this->tables[ $table ] ) ) {
			return false;
		}

		$kept    = array();
		$deleted = 0;

		foreach ( $this->tables[ $table ] as $row ) {
			if ( $this->row_matches( $row, $where ) ) {
				++$deleted;
				continue;
			}

			$kept[] = $row;
		}

		$this->tables[ $table ] = $kept;
		$this->rows_affected   = $deleted;

		return $deleted;
	}

	/**
	 * @param string $query
	 * @param string $output
	 * @return array<string, mixed>|object|null
	 */
	public function get_row( $query, $output = OBJECT ) {
		$rows = $this->run_select( (string) $query );

		if ( array() === $rows ) {
			return null;
		}

		$row = $rows[0];

		return OBJECT === $output ? (object) $row : $row;
	}

	/**
	 * @param string $query
	 * @return string|null
	 */
	public function get_var( $query ) {
		$rows = $this->run_select( (string) $query );

		if ( array() === $rows ) {
			return null;
		}

		$first = reset( $rows[0] );

		return null === $first ? null : (string) $first;
	}

	/**
	 * @param string $query
	 * @return array<int, string>
	 */
	public function get_col( $query ) {
		$rows = $this->run_select( (string) $query );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[] = (string) reset( $row );
		}

		return $out;
	}

	/**
	 * @param string $query
	 * @param string $output
	 * @return array<int, array<string, mixed>|object>
	 */
	public function get_results( $query, $output = OBJECT ) {
		$rows = $this->run_select( (string) $query );

		if ( OBJECT === $output ) {
			return array_map( static fn( array $row ) => (object) $row, $rows );
		}

		return $rows;
	}

	private function normalize_table( string $table ): string {
		return str_replace( array( '`', ' ' ), '', $table );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $where
	 */
	private function row_matches( array $row, array $where ): bool {
		foreach ( $where as $key => $value ) {
			if ( ! array_key_exists( $key, $row ) ) {
				return false;
			}

			if ( null === $value ) {
				if ( null !== $row[ $key ] ) {
					return false;
				}

				continue;
			}

			if ( (string) $row[ $key ] !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return int|false
	 */
	private function insert_row( string $table, array $data, bool $ignore ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->last_error = 'Table does not exist';

			return false;
		}

		$unique = $this->unique[ $table ] ?? '';

		if ( '' !== $unique && array_key_exists( $unique, $data ) ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (string) ( $row[ $unique ] ?? '' ) === (string) $data[ $unique ] ) {
					$this->last_error = 'Duplicate entry';

					return $ignore ? 0 : false;
				}
			}
		}

		$auto = $this->auto[ $table ] ?? '';

		if ( '' !== $auto && empty( $data[ $auto ] ) ) {
			$max = 0;

			foreach ( $this->tables[ $table ] as $row ) {
				$max = max( $max, (int) ( $row[ $auto ] ?? 0 ) );
			}

			$data[ $auto ] = $max + 1;
		}

		$this->tables[ $table ][] = $data;
		$this->insert_id          = (int) ( $data[ $auto ] ?? 0 );
		$this->rows_affected      = 1;

		return 1;
	}

	/**
	 * @return int|false
	 */
	private function run_insert_sql( string $sql ) {
		if ( ! preg_match( '/^INSERT\s+(IGNORE\s+)?INTO\s+(\S+)\s*\(([^)]*)\)\s*VALUES\s*\((.*)\)\s*;?$/is', $sql, $m ) ) {
			return false;
		}

		$ignore  = '' !== trim( (string) $m[1] );
		$table   = $this->normalize_table( $m[2] );
		$columns = array_map( 'trim', explode( ',', $m[3] ) );
		$values  = $this->split_list( $m[4] );

		if ( count( $columns ) !== count( $values ) ) {
			return false;
		}

		$data = array();

		foreach ( $columns as $index => $column ) {
			$data[ trim( $column, " `\t\n\r" ) ] = $this->parse_value( $values[ $index ] );
		}

		return $this->insert_row( $table, $data, $ignore );
	}

	/**
	 * @return int|false
	 */
	private function run_update_sql( string $sql ) {
		if ( ! preg_match( '/^UPDATE\s+(\S+)\s+SET\s+(.+?)\s+WHERE\s+(.+?)\s*;?$/is', $sql, $m ) ) {
			return false;
		}

		$table = $this->normalize_table( $m[1] );

		if ( ! isset( $this->tables[ $table ] ) ) {
			return false;
		}

		$set    = $this->parse_assignments( $m[2] );
		$where  = $m[3];
		$tokens = $this->tokenize( $where );
		$pos    = 0;
		$count  = 0;

		foreach ( $this->tables[ $table ] as $index => $row ) {
			$pos = 0;

			if ( ! $this->parse_or( $tokens, $pos, $row ) ) {
				continue;
			}

			foreach ( $set as $column => $value ) {
				if ( is_array( $value ) && isset( $value['increment'] ) ) {
					$this->tables[ $table ][ $index ][ $column ] = (int) ( $row[ $column ] ?? 0 ) + (int) $value['increment'];
					continue;
				}

				$this->tables[ $table ][ $index ][ $column ] = $value;
			}

			++$count;
		}

		$this->rows_affected = $count;

		return $count;
	}

	/**
	 * @return int|false
	 */
	private function run_delete_sql( string $sql ) {
		if ( ! preg_match( '/^DELETE\s+FROM\s+(\S+)\s+WHERE\s+(.+?)\s*;?$/is', $sql, $m ) ) {
			return false;
		}

		$table = $this->normalize_table( $m[1] );

		if ( ! isset( $this->tables[ $table ] ) ) {
			return false;
		}

		$tokens = $this->tokenize( $m[2] );
		$kept   = array();
		$count  = 0;

		foreach ( $this->tables[ $table ] as $row ) {
			$pos = 0;

			if ( $this->parse_or( $tokens, $pos, $row ) ) {
				++$count;
				continue;
			}

			$kept[] = $row;
		}

		$this->tables[ $table ] = $kept;
		$this->rows_affected   = $count;

		return $count;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parse_assignments( string $sql ): array {
		$out = array();

		foreach ( $this->split_list( $sql ) as $assignment ) {
			if ( ! preg_match( '/^\s*([a-zA-Z0-9_]+)\s*=\s*(.+)$/s', $assignment, $m ) ) {
				continue;
			}

			$column = $m[1];
			$value  = trim( $m[2] );

			if ( preg_match( '/^([a-zA-Z0-9_]+)\s*\+\s*(\d+)$/', $value, $inc ) ) {
				$out[ $column ] = array( 'increment' => (int) $inc[2] );
				continue;
			}

			if ( 'NULL' === strtoupper( $value ) ) {
				$out[ $column ] = null;
				continue;
			}

			$out[ $column ] = $this->parse_value( $value );
		}

		return $out;
	}

	/**
	 * Split a comma separated list, respecting quotes and parentheses.
	 *
	 * @return array<int, string>
	 */
	private function split_list( string $sql ): array {
		$out     = array();
		$current = '';
		$depth   = 0;
		$quoted  = false;
		$length  = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];

			if ( $quoted ) {
				$current .= $char;

				if ( '\\' === $char && $i + 1 < $length ) {
					$current .= $sql[ ++$i ];
					continue;
				}

				if ( "'" === $char ) {
					$quoted = false;
				}

				continue;
			}

			if ( "'" === $char ) {
				$quoted   = true;
				$current .= $char;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
				$current .= $char;
				continue;
			}

			if ( ')' === $char ) {
				--$depth;
				$current .= $char;
				continue;
			}

			if ( ',' === $char && 0 === $depth ) {
				$out[]   = trim( $current );
				$current = '';
				continue;
			}

			$current .= $char;
		}

		if ( '' !== trim( $current ) ) {
			$out[] = trim( $current );
		}

		return $out;
	}

	/**
	 * @return mixed
	 */
	private function parse_value( string $token ) {
		$token = trim( $token );

		if ( '' === $token || 'NULL' === strtoupper( $token ) ) {
			return null;
		}

		if ( "'" === $token[0] ) {
			$inner = substr( $token, 1, -1 );

			return str_replace(
				array( "\\'", '\\\\', '\\n', '\\r', '\\0' ),
				array( "'", '\\', "\n", "\r", "\0" ),
				$inner
			);
		}

		if ( is_numeric( $token ) ) {
			return false !== strpos( $token, '.' ) ? (float) $token : (int) $token;
		}

		return $token;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function run_select( string $sql ): array {
		$sql = trim( $sql );

		if ( preg_match( '/^SHOW\s+TABLES\s+LIKE\s+\'(.+)\'\s*;?$/i', $sql, $show ) ) {
			$table = $this->normalize_table( str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $show[1] ) );

			return isset( $this->tables[ $table ] ) ? array( array( 'table' => $table ) ) : array();
		}

		if ( ! preg_match( '/^SELECT\s+(.+?)\s+FROM\s+(\S+)/is', $sql, $m ) ) {
			return array();
		}

		$fields = trim( $m[1] );
		$table  = $this->normalize_table( $m[2 ] );
		$rest   = trim( substr( $sql, strlen( $m[0] ) ) );

		$where  = '';
		$order  = '';
		$limit  = null;
		$offset = 0;

		if ( preg_match( '/\s+LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?\s*;?$/i', $rest, $lm ) ) {
			$limit = (int) $lm[1];
			$offset = (int) ( $lm[2] ?? 0 );
			$rest  = trim( substr( $rest, 0, strlen( $rest ) - strlen( $lm[0] ) ) );
		}

		if ( preg_match( '/\s+ORDER\s+BY\s+(.+)$/is', $rest, $om ) ) {
			$order = trim( $om[1] );
			$rest  = trim( substr( $rest, 0, strlen( $rest ) - strlen( $om[0] ) ) );
		}

		if ( preg_match( '/^WHERE\s+(.+)$/is', $rest, $wm ) ) {
			$where = trim( $wm[1] );
		}

		if ( ! isset( $this->tables[ $table ] ) ) {
			return array();
		}

		$rows = $this->tables[ $table ];

		if ( '' !== $where ) {
			$tokens = $this->tokenize( $where );
			$rows   = array_values(
				array_filter(
					$rows,
					function ( array $row ) use ( $tokens ): bool {
						$pos = 0;

						return $this->parse_or( $tokens, $pos, $row );
					}
				)
			);
		}

		if ( '' !== $order ) {
			$rows = $this->apply_order( $rows, $order );
		}

		if ( null !== $limit ) {
			$rows = array_slice( $rows, $offset, $limit );
		}

		return $this->project( $rows, $fields );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_order( array $rows, string $order ): array {
		$rules = array();

		foreach ( $this->split_list( $order ) as $part ) {
			if ( preg_match( '/^\s*([a-zA-Z0-9_.]+)\s*(ASC|DESC)?\s*$/i', $part, $m ) ) {
				$rules[] = array(
					'column' => str_replace( array( '`', $this->prefix ), '', $m[1] ),
					'desc'   => 'DESC' === strtoupper( (string) ( $m[2] ?? '' ) ),
				);
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ) use ( $rules ): int {
				foreach ( $rules as $rule ) {
					$left  = $a[ $rule['column'] ] ?? null;
					$right = $b[ $rule['column'] ] ?? null;

					if ( is_numeric( $left ) && is_numeric( $right ) ) {
						$cmp = (float) $left <=> (float) $right;
					} else {
						$cmp = strcmp( (string) $left, (string) $right );
					}

					if ( 0 !== $cmp ) {
						return $rule['desc'] ? -$cmp : $cmp;
					}
				}

				return 0;
			}
		);

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function project( array $rows, string $fields ): array {
		$fields = trim( $fields );

		if ( '*' === $fields ) {
			return $rows;
		}

		if ( preg_match( '/^COUNT\s*\(\s*\*\s*\)$/i', $fields ) ) {
			return array( array( 'COUNT(*)' => count( $rows ) ) );
		}

		if ( preg_match( '/^DISTINCT\s+([a-zA-Z0-9_.]+)$/i', $fields, $m ) ) {
			$column = $m[1];
			$seen   = array();
			$out    = array();

			foreach ( $rows as $row ) {
				$value = $row[ $column ] ?? null;

				if ( isset( $seen[ (string) $value ] ) ) {
					continue;
				}

				$seen[ (string) $value ] = true;
				$out[]                   = array( $column => $value );
			}

			return $out;
		}

		$columns = array_map( 'trim', explode( ',', $fields ) );
		$out     = array();

		foreach ( $rows as $row ) {
			$picked = array();

			foreach ( $columns as $column ) {
				$column = trim( $column, " `\t\n\r" );
				$picked[ $column ] = $row[ $column ] ?? null;
			}

			$out[] = $picked;
		}

		return $out;
	}

	/**
	 * @return array<int, array{0: string, 1: mixed}>
	 */
	private function tokenize( string $sql ): array {
		$tokens = array();
		$length = strlen( $sql );
		$i      = 0;

		while ( $i < $length ) {
			$char = $sql[ $i ];

			if ( ctype_space( $char ) ) {
				++$i;
				continue;
			}

			if ( '(' === $char || ')' === $char || ',' === $char ) {
				$tokens[] = array( $char, $char );
				++$i;
				continue;
			}

			if ( "'" === $char ) {
				$j     = $i + 1;
				$value = '';

				while ( $j < $length ) {
					if ( '\\' === $sql[ $j ] && $j + 1 < $length ) {
						$value .= $sql[ $j + 1 ];
						$j     += 2;
						continue;
					}

					if ( "'" === $sql[ $j ] ) {
						break;
					}

					$value .= $sql[ $j ];
					++$j;
				}

				$tokens[] = array( 'str', $value );
				$i        = $j + 1;
				continue;
			}

			$matched = false;

			foreach ( array( '>=', '<=', '<>', '!=', '=' ) as $operator ) {
				if ( substr( $sql, $i, strlen( $operator ) ) === $operator ) {
					$tokens[] = array( 'op', $operator );
					$i       += strlen( $operator );
					$matched  = true;
					break;
				}
			}

			if ( $matched ) {
				continue;
			}

			if ( '<' === $char || '>' === $char ) {
				$tokens[] = array( 'op', $char );
				++$i;
				continue;
			}

			$j = $i;

			while ( $j < $length
				&& ! ctype_space( $sql[ $j ] )
				&& ! in_array( $sql[ $j ], array( '(', ')', ',', '=', '<', '>', '!', "'" ), true )
			) {
				++$j;
			}

			$word = substr( $sql, $i, $j - $i );
			$i    = $j;
			$upper = strtoupper( $word );

			if ( in_array( $upper, array( 'AND', 'OR', 'IS', 'NOT', 'NULL', 'LIKE' ), true ) ) {
				$tokens[] = array( 'kw', $upper );
				continue;
			}

			if ( is_numeric( $word ) ) {
				$tokens[] = array( 'num', false !== strpos( $word, '.' ) ? (float) $word : (int) $word );
				continue;
			}

			$tokens[] = array( 'id', $word );
		}

		return $tokens;
	}

	/**
	 * @param array<int, array{0: string, 1: mixed}> $tokens
	 * @param array<string, mixed> $row
	 */
	private function parse_or( array $tokens, int &$pos, array $row ): bool {
		$value = $this->parse_and( $tokens, $pos, $row );

		while ( isset( $tokens[ $pos ] ) && 'kw' === $tokens[ $pos ][0] && 'OR' === $tokens[ $pos ][1] ) {
			++$pos;
			$right  = $this->parse_and( $tokens, $pos, $row );
			$value  = $value || $right;
		}

		return $value;
	}

	/**
	 * @param array<int, array{0: string, 1: mixed}> $tokens
	 * @param array<string, mixed> $row
	 */
	private function parse_and( array $tokens, int &$pos, array $row ): bool {
		$value = $this->parse_primary( $tokens, $pos, $row );

		while ( isset( $tokens[ $pos ] ) && 'kw' === $tokens[ $pos ][0] && 'AND' === $tokens[ $pos ][1] ) {
			++$pos;
			$right = $this->parse_primary( $tokens, $pos, $row );
			$value = $value && $right;
		}

		return $value;
	}

	/**
	 * @param array<int, array{0: string, 1: mixed}> $tokens
	 * @param array<string, mixed> $row
	 */
	private function parse_primary( array $tokens, int &$pos, array $row ): bool {
		if ( ! isset( $tokens[ $pos ] ) ) {
			return false;
		}

		if ( '(' === $tokens[ $pos ][0] ) {
			++$pos;
			$value = $this->parse_or( $tokens, $pos, $row );

			if ( isset( $tokens[ $pos ] ) && ')' === $tokens[ $pos ][0] ) {
				++$pos;
			}

			return $value;
		}

		$token = $tokens[ $pos ];
		++$pos;

		$left = ( 'id' === $token[0] )
			? ( $row[ str_replace( array( '`', $this->prefix ), '', (string) $token[1] ) ] ?? null )
			: $token[1];

		if ( ! isset( $tokens[ $pos ] ) ) {
			return false;
		}

		$operator = $tokens[ $pos ];

		if ( 'kw' === $operator[0] && 'IS' === $operator[1] ) {
			++$pos;
			$negate = false;

			if ( isset( $tokens[ $pos ] ) && 'kw' === $tokens[ $pos ][0] && 'NOT' === $tokens[ $pos ][1] ) {
				$negate = true;
				++$pos;
			}

			if ( isset( $tokens[ $pos ] ) && 'kw' === $tokens[ $pos ][0] && 'NULL' === $tokens[ $pos ][1] ) {
				++$pos;
			}

			$is_null = null === $left;

			return $negate ? ! $is_null : $is_null;
		}

		if ( 'op' !== $operator[0] && ! ( 'kw' === $operator[0] && 'LIKE' === $operator[1] ) ) {
			return false;
		}

		++$pos;

		$right_token = $tokens[ $pos ] ?? array( 'str', null );
		++$pos;

		$right = ( 'id' === $right_token[0] )
			? ( $row[ str_replace( array( '`', $this->prefix ), '', (string) $right_token[1] ) ] ?? null )
			: $right_token[1];

		if ( 'kw' === $operator[0] && 'LIKE' === $operator[1] ) {
			$pattern = '/^' . str_replace( array( '%', '_' ), array( '.*', '.' ), preg_quote( (string) $right, '/' ) ) . '$/is';

			return (bool) preg_match( $pattern, (string) $left );
		}

		return $this->compare( $left, (string) $operator[1], $right );
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 */
	private function compare( $left, string $operator, $right ): bool {
		if ( null === $left || null === $right ) {
			return match ( $operator ) {
				'=', '<=>' => $left === $right,
				'!=', '<>' => $left !== $right,
				default    => false,
			};
		}

		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			$left  = (float) $left;
			$right = (float) $right;
		} else {
			$left  = (string) $left;
			$right = (string) $right;
		}

		return match ( $operator ) {
			'='       => $left === $right,
			'!=' , '<>' => $left !== $right,
			'>'       => $left > $right,
			'>='      => $left >= $right,
			'<'       => $left < $right,
			'<='      => $left <= $right,
			default   => false,
		};
	}
}
