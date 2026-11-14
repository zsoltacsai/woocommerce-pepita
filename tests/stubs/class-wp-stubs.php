<?php
/**
 * WordPress core stubs for the WP Pepita Sync test suite.
 *
 * Only the behaviour the plugin relies on is implemented, with WordPress
 * semantics preserved where it matters (add_option atomicity, option defaults,
 * post meta "single" handling, term ancestry, hooks, cron).
 *
 * @package WPS\Pepita\Tests
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-wps-test-state.php';

final class WP_Error {

	/** @var array<string, string[]> */
	private array $errors = array();

	/** @var array<string, mixed> */
	private array $data = array();

	public function __construct( string $code = '', string $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ][] = $message;
		}

		if ( '' !== $data ) {
			$this->data[ $code ] = $data;
		}
	}

	public function add( string $code, string $message, $data = '' ): void {
		$this->errors[ $code ][] = $message;

		if ( '' !== $data ) {
			$this->data[ $code ] = $data;
		}
	}

	public function get_error_code(): string {
		$codes = array_keys( $this->errors );

		return (string) ( $codes[0] ?? '' );
	}

	public function get_error_message( string $code = '' ): string {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}

		return (string) ( $this->errors[ $code ][0] ?? '' );
	}

	/**
	 * @return string[]
	 */
	public function get_error_messages( string $code = '' ): array {
		if ( '' === $code ) {
			$messages = array();

			foreach ( $this->errors as $list ) {
				$messages = array_merge( $messages, $list );
			}

			return $messages;
		}

		return $this->errors[ $code ] ?? array();
	}

	public function has_errors(): bool {
		return array() !== $this->errors;
	}
}

final class WP_Term {

	public int $term_id = 0;
	public int $term_taxonomy_id = 0;
	public string $name = '';
	public string $slug = '';
	public int $term_group = 0;
	public string $taxonomy = '';
	public string $description = '';
	public int $parent = 0;
	public int $count = 0;
	public string $filter = 'raw';

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->{$key} = $value;
			}
		}

		$this->term_taxonomy_id = $this->term_id;
	}
}

final class WP_User {

	public int $ID = 0;
	public string $user_email = '';
	public string $user_login = '';
	public string $first_name = '';
	public string $last_name = '';
	public string $display_name = '';
	/** @var string[] */
	public array $roles = array( 'customer' );

	public function __construct( int $id = 0, string $email = '', string $first = '', string $last = '' ) {
		$this->ID           = $id;
		$this->user_email   = $email;
		$this->user_login   = '' !== $email ? strstr( $email, '@', true ) : 'user' . $id;
		$this->first_name   = $first;
		$this->last_name    = $last;
		$this->display_name = trim( $first . ' ' . $last );
	}
}

final class WP_Query {

	/** @var int[] */
	public array $posts = array();
	public int $found_posts = 0;
	public int $post_count = 0;
	public int $max_num_pages = 0;
	public string $request = '';

	/** @var array<string, mixed> */
	private array $query_vars = array();

	/**
	 * @param array<string, mixed> $args
	 */
	public function __construct( array $args = array() ) {
		$this->query_vars = $args;

		$ids = array_keys( WPS_Test_State::$products );
		sort( $ids );

		$statuses = $args['post_status'] ?? array( 'publish' );
		$statuses = is_array( $statuses ) ? $statuses : array( $statuses );
		$any      = in_array( 'any', $statuses, true );

		$ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ) use ( $statuses, $any ): bool {
					$product = WPS_Test_State::$products[ $id ];

					return $any || in_array( $product->get_status(), $statuses, true );
				}
			)
		);

		if ( isset( $args['meta_key'] ) ) {
			$meta_key   = (string) $args['meta_key'];
			$meta_value = (string) ( $args['meta_value'] ?? '' );

			$ids = array_values(
				array_filter(
					$ids,
					static fn( int $id ): bool => (string) get_post_meta( $id, $meta_key, true ) === $meta_value
				)
			);
		}

		$meta_query = $args['meta_query'] ?? array();

		if ( is_array( $meta_query ) ) {
			foreach ( $meta_query as $clause ) {
				if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
					continue;
				}

				$key   = (string) $clause['key'];
				$value = $clause['value'] ?? null;

				$ids = array_values(
					array_filter(
						$ids,
						static function ( int $id ) use ( $key, $value ): bool {
							$actual = get_post_meta( $id, $key, true );

							return null === $value ? '' !== $actual : (string) $actual === (string) $value;
						}
					)
				);
			}
		}

		$tax_query = $args['tax_query'] ?? array();

		if ( is_array( $tax_query ) ) {
			foreach ( $tax_query as $clause ) {
				if ( ! is_array( $clause ) || ! isset( $clause['taxonomy'], $clause['terms'] ) ) {
					continue;
				}

				$taxonomy = (string) $clause['taxonomy'];
				$terms    = array_map( 'intval', (array) $clause['terms'] );
				$field    = (string) ( $clause['field'] ?? 'term_id' );

				if ( 'product_type' === $taxonomy ) {
					$slugs = array_map( 'strval', (array) $clause['terms'] );

					$ids = array_values(
						array_filter(
							$ids,
							static function ( int $id ) use ( $slugs ): bool {
								return in_array( WPS_Test_State::$products[ $id ]->get_type(), $slugs, true );
							}
						)
					);
					continue;
				}

				$ids = array_values(
					array_filter(
						$ids,
						static function ( int $id ) use ( $taxonomy, $terms, $field ): bool {
							$assigned = WPS_Test_State::$post_terms[ $id ][ $taxonomy ] ?? array();

							foreach ( $assigned as $term_id ) {
								if ( 'slug' === $field ) {
									$term = WPS_Test_State::$terms[ $term_id ] ?? null;

									if ( $term && in_array( $term->slug, array_map( 'strval', $terms ), true ) ) {
										return true;
									}

									continue;
								}

								if ( in_array( (int) $term_id, $terms, true ) ) {
									return true;
								}
							}

							return false;
						}
					)
				);
			}
		}

		$search = trim( (string) ( $args['s'] ?? '' ) );

		if ( '' !== $search ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( int $id ) use ( $search ): bool {
						$product = WPS_Test_State::$products[ $id ];

						return false !== stripos( $product->get_name(), $search )
							|| false !== stripos( $product->get_sku(), $search );
					}
				)
			);
		}

		$order = strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';

		if ( $order === 'DESC' ) {
			$ids = array_reverse( $ids );
		}

		$this->found_posts = count( $ids );

		$per_page = (int) ( $args['posts_per_page'] ?? -1 );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );

		if ( $per_page > 0 ) {
			$ids = array_slice( $ids, ( $paged - 1 ) * $per_page, $per_page );
			$this->max_num_pages = (int) ceil( $this->found_posts / $per_page );
		}

		$this->posts      = array_map( 'intval', $ids );
		$this->post_count = count( $this->posts );
	}

	/**
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		return $this->query_vars[ $key ] ?? $default;
	}
}

final class WP_REST_Request {

	/** @var array<string, mixed> */
	private array $params = array();

	/** @var array<string, string> */
	private array $headers = array();

	private string $body = '';

	public function __construct( array $params = array(), string $body = '', array $headers = array() ) {
		$this->params  = $params;
		$this->body    = $body;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * @return mixed
	 */
	public function get_param( string $key ) {
		return $this->params[ $key ] ?? '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_params(): array {
		return $this->params;
	}

	public function get_body(): string {
		return $this->body;
	}

	public function get_header( string $key ): string {
		return $this->headers[ strtolower( $key ) ] ?? '';
	}
}

final class WP_REST_Response {

	/** @var mixed */
	private $data;

	private int $status;

	/**
	 * @param mixed $data
	 */
	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	/**
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}

	public function get_status(): int {
		return $this->status;
	}
}

final class WP_Screen {

	public string $id = '';

	public function __construct( string $id = '' ) {
		$this->id = $id;
	}
}

final class WPS_Json_Response extends \RuntimeException {

	/** @var array<string, mixed> */
	public array $payload;

	public bool $success;

	/**
	 * @param array<string, mixed> $payload
	 */
	public function __construct( array $payload, bool $success ) {
		parent::__construct( 'json-response' );
		$this->payload = $payload;
		$this->success = $success;
	}
}

/* -------------------------------------------------------------------------
 * Options and transients
 * ---------------------------------------------------------------------- */

/**
 * @param mixed $default
 * @return mixed
 */
function get_option( string $name, $default = false ) {
	return array_key_exists( $name, WPS_Test_State::$options ) ? WPS_Test_State::$options[ $name ] : $default;
}

/**
 * @param mixed $value
 */
function update_option( string $name, $value, $autoload = null ): bool {
	WPS_Test_State::$options[ $name ] = $value;

	return true;
}

/**
 * @param mixed $value
 */
function add_option( string $name, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
	if ( array_key_exists( $name, WPS_Test_State::$options ) ) {
		return false;
	}

	WPS_Test_State::$options[ $name ] = $value;

	return true;
}

function delete_option( string $name ): bool {
	if ( ! array_key_exists( $name, WPS_Test_State::$options ) ) {
		return false;
	}

	unset( WPS_Test_State::$options[ $name ] );

	return true;
}

/**
 * @return mixed
 */
function get_transient( string $name ) {
	if ( ! array_key_exists( $name, WPS_Test_State::$transients ) ) {
		return false;
	}

	$entry = WPS_Test_State::$transients[ $name ];

	if ( isset( $entry['expires'] ) && $entry['expires'] > 0 && $entry['expires'] < time() ) {
		unset( WPS_Test_State::$transients[ $name ] );

		return false;
	}

	return $entry['value'];
}

/**
 * @param mixed $value
 */
function set_transient( string $name, $value, int $expiration = 0 ): bool {
	WPS_Test_State::$transients[ $name ] = array(
		'value'   => $value,
		'expires' => $expiration > 0 ? time() + $expiration : 0,
	);

	return true;
}

function delete_transient( string $name ): bool {
	unset( WPS_Test_State::$transients[ $name ] );

	return true;
}

/* -------------------------------------------------------------------------
 * Hooks
 * ---------------------------------------------------------------------- */

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	WPS_Test_State::$hooks[ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function remove_filter( string $hook, $callback, int $priority = 10 ): bool {
	if ( empty( WPS_Test_State::$hooks[ $hook ] ) ) {
		return false;
	}

	WPS_Test_State::$hooks[ $hook ] = array_values(
		array_filter(
			WPS_Test_State::$hooks[ $hook ],
			static fn( array $entry ): bool => $entry['callback'] !== $callback || $entry['priority'] !== $priority
		)
	);

	return true;
}

function remove_action( string $hook, $callback, int $priority = 10 ): bool {
	return remove_filter( $hook, $callback, $priority );
}

function has_action( string $hook, $callback = false ): bool {
	if ( empty( WPS_Test_State::$hooks[ $hook ] ) ) {
		return false;
	}

	if ( false === $callback ) {
		return true;
	}

	foreach ( WPS_Test_State::$hooks[ $hook ] as $entry ) {
		if ( $entry['callback'] === $callback ) {
			return true;
		}
	}

	return false;
}

/**
 * @param mixed $value
 * @return mixed
 */
function apply_filters( string $hook, $value, ...$args ) {
	if ( empty( WPS_Test_State::$hooks[ $hook ] ) ) {
		return $value;
	}

	$entries = WPS_Test_State::$hooks[ $hook ];
	usort( $entries, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

	foreach ( $entries as $entry ) {
		$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
	}

	return $value;
}

/**
 * @param mixed ...$args
 */
function do_action( string $hook, ...$args ): void {
	if ( empty( WPS_Test_State::$hooks[ $hook ] ) ) {
		return;
	}

	$entries = WPS_Test_State::$hooks[ $hook ];
	usort( $entries, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

	foreach ( $entries as $entry ) {
		call_user_func_array( $entry['callback'], $args );
	}
}

function did_action( string $hook ): int {
	return 0;
}

function __return_true() {
	return true;
}

function __return_false() {
	return false;
}

/* -------------------------------------------------------------------------
 * Capabilities, users, nonces
 * ---------------------------------------------------------------------- */

function current_user_can( string $capability, $object_id = null ): bool {
	return ! empty( WPS_Test_State::$current_user_caps[ $capability ] );
}

function wp_get_current_user(): WP_User {
	return new WP_User( 1, 'admin@example.test', 'Admin', 'User' );
}

/**
 * @return WP_User|false
 */
function get_user_by( string $field, $value ) {
	if ( 'email' === $field ) {
		return WPS_Test_State::$users_by_email[ (string) $value ] ?? false;
	}

	if ( 'id' === $field ) {
		foreach ( WPS_Test_State::$users_by_email as $user ) {
			if ( $user->ID === (int) $value ) {
				return $user;
			}
		}
	}

	if ( 'login' === $field ) {
		foreach ( WPS_Test_State::$users_by_email as $user ) {
			if ( $user->user_login === (string) $value ) {
				return $user;
			}
		}
	}

	return false;
}

function wp_create_nonce( string $action = '-1' ): string {
	return substr( hash( 'sha256', 'nonce|' . $action ), 0, 10 );
}

function wp_verify_nonce( $nonce, string $action = '-1' ): bool {
	return is_string( $nonce ) && wp_create_nonce( $action ) === $nonce;
}

function check_admin_referer( string $action = '-1', string $query_arg = '_wpnonce' ): bool {
	$nonce = isset( $_REQUEST[ $query_arg ] ) ? (string) $_REQUEST[ $query_arg ] : '';

	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		throw new \RuntimeException( 'Invalid admin nonce: ' . $action );
	}

	return true;
}

function check_ajax_referer( string $action = '-1', $query_arg = false, bool $stop = true ): bool {
	$key   = false === $query_arg ? '_ajax_nonce' : (string) $query_arg;
	$nonce = isset( $_REQUEST[ $key ] ) ? (string) $_REQUEST[ $key ] : '';

	if ( wp_verify_nonce( $nonce, $action ) ) {
		return true;
	}

	if ( $stop ) {
		throw new \RuntimeException( 'Invalid ajax nonce: ' . $action );
	}

	return false;
}

function wp_nonce_field( string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';

	if ( $display ) {
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $field;
}

function wp_nonce_url( string $actionurl, string $action = '-1', string $name = '_wpnonce' ): string {
	return add_query_arg( array( $name => wp_create_nonce( $action ) ), $actionurl );
}

/* -------------------------------------------------------------------------
 * Meta
 * ---------------------------------------------------------------------- */

/**
 * @param mixed $default
 * @return mixed
 */
function get_post_meta( int $post_id, string $key = '', bool $single = false, $default = '' ) {
	if ( '' === $key ) {
		return WPS_Test_State::$post_meta[ $post_id ] ?? array();
	}

	$values = WPS_Test_State::$post_meta[ $post_id ][ $key ] ?? array();

	if ( array() === $values ) {
		return $single ? $default : array();
	}

	return $single ? $values[0] : $values;
}

/**
 * @param mixed $value
 * @return int|bool
 */
function update_post_meta( int $post_id, string $key, $value, $prev_value = '' ) {
	WPS_Test_State::$post_meta[ $post_id ][ $key ] = array( $value );

	return true;
}

/**
 * @param mixed $value
 * @return int|bool
 */
function add_post_meta( int $post_id, string $key, $value, bool $unique = false ) {
	if ( $unique && isset( WPS_Test_State::$post_meta[ $post_id ][ $key ] ) ) {
		return false;
	}

	WPS_Test_State::$post_meta[ $post_id ][ $key ][] = $value;

	return true;
}

function delete_post_meta( int $post_id, string $key, $value = '' ): bool {
	if ( ! isset( WPS_Test_State::$post_meta[ $post_id ][ $key ] ) ) {
		return false;
	}

	unset( WPS_Test_State::$post_meta[ $post_id ][ $key ] );

	return true;
}

/**
 * @param mixed $default
 * @return mixed
 */
function get_term_meta( int $term_id, string $key = '', bool $single = false, $default = '' ) {
	if ( '' === $key ) {
		return WPS_Test_State::$term_meta[ $term_id ] ?? array();
	}

	if ( ! array_key_exists( $key, WPS_Test_State::$term_meta[ $term_id ] ?? array() ) ) {
		return $single ? $default : array();
	}

	$value = WPS_Test_State::$term_meta[ $term_id ][ $key ];

	return $single ? $value : array( $value );
}

/**
 * @param mixed $value
 * @return int|bool
 */
function update_term_meta( int $term_id, string $key, $value, $prev_value = '' ) {
	WPS_Test_State::$term_meta[ $term_id ][ $key ] = $value;

	return true;
}

function delete_term_meta( int $term_id, string $key, $value = '' ): bool {
	if ( ! array_key_exists( $key, WPS_Test_State::$term_meta[ $term_id ] ?? array() ) ) {
		return false;
	}

	unset( WPS_Test_State::$term_meta[ $term_id ][ $key ] );

	return true;
}

/* -------------------------------------------------------------------------
 * Terms and taxonomies
 * ---------------------------------------------------------------------- */

function taxonomy_exists( string $taxonomy ): bool {
	return in_array( $taxonomy, WPS_Test_State::$taxonomies, true );
}

function is_taxonomy( string $taxonomy ): bool {
	return taxonomy_exists( $taxonomy );
}

/**
 * @return WP_Term|WP_Error|null
 */
function get_term( int $term_id, string $taxonomy = '' ) {
	if ( ! isset( WPS_Test_State::$terms[ $term_id ] ) ) {
		return new WP_Error( 'invalid_term', 'Empty Term.' );
	}

	$term = WPS_Test_State::$terms[ $term_id ];

	if ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) {
		return new WP_Error( 'invalid_term', 'Empty Term.' );
	}

	return $term;
}

/**
 * @return WP_Term|WP_Error|null
 */
function get_term_by( string $field, $value, string $taxonomy = '' ) {
	foreach ( WPS_Test_State::$terms as $term ) {
		if ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) {
			continue;
		}

		if ( 'slug' === $field && $term->slug === (string) $value ) {
			return $term;
		}

		if ( 'name' === $field && $term->name === (string) $value ) {
			return $term;
		}

		if ( 'id' === $field && $term->term_id === (int) $value ) {
			return $term;
		}

		if ( 'term_id' === $field && $term->term_id === (int) $value ) {
			return $term;
		}
	}

	return false;
}

/**
 * @param array<string, mixed> $args
 * @return WP_Term[]|int[]|WP_Error
 */
function get_terms( $args = array() ) {
	$args     = is_array( $args ) ? $args : array();
	$taxonomy = (string) ( $args['taxonomy'] ?? '' );
	$fields   = (string) ( $args['fields'] ?? 'all' );
	$number   = (int) ( $args['number'] ?? 0 );
	$terms    = array();

	foreach ( WPS_Test_State::$terms as $term ) {
		if ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) {
			continue;
		}

		$meta_query = $args['meta_query'] ?? array();

		if ( is_array( $meta_query ) ) {
			$keep = true;

			foreach ( $meta_query as $clause ) {
				if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
					continue;
				}

				$actual = get_term_meta( $term->term_id, (string) $clause['key'], true );

				if ( (string) $actual !== (string) ( $clause['value'] ?? '' ) ) {
					$keep = false;
				}
			}

			if ( ! $keep ) {
				continue;
			}
		}

		$terms[] = $term;
	}

	if ( 'ids' === $fields ) {
		$terms = array_map( static fn( WP_Term $term ): int => $term->term_id, $terms );
	}

	if ( $number > 0 ) {
		$terms = array_slice( $terms, 0, $number );
	}

	return $terms;
}

/**
 * @return int[]
 */
function get_ancestors( int $object_id, string $object_type = '', string $resource_type = '' ): array {
	$ancestors = WPS_Test_State::$term_ancestors[ $object_id ] ?? array();

	return array_map( 'intval', $ancestors );
}

/**
 * @param array<string, mixed> $args
 * @return WP_Term[]|int[]|string[]
 */
function wp_get_post_terms( int $post_id, string $taxonomy = 'post_tag', $args = array() ) {
	$args   = is_array( $args ) ? $args : array();
	$fields = (string) ( $args['fields'] ?? 'all' );
	$ids    = WPS_Test_State::$post_terms[ $post_id ][ $taxonomy ] ?? array();
	$ids    = array_values( array_unique( array_map( 'intval', $ids ) ) );

	if ( 'ids' === $fields ) {
		return $ids;
	}

	$terms = array();

	foreach ( $ids as $term_id ) {
		if ( isset( WPS_Test_State::$terms[ $term_id ] ) ) {
			$terms[] = WPS_Test_State::$terms[ $term_id ];
		}
	}

	if ( 'names' === $fields ) {
		return array_map( static fn( WP_Term $term ): string => $term->name, $terms );
	}

	return $terms;
}

function update_object_term_cache( $object_ids, string $object_type = 'post' ): void {
	// The stubs read term relationships directly.
}

function wp_set_post_terms( int $post_id, $terms, string $taxonomy = 'post_tag', bool $append = false ): bool {
	$ids = array_map( 'intval', (array) $terms );

	WPS_Test_State::$post_terms[ $post_id ][ $taxonomy ] = array_values(
		array_unique( $append ? array_merge( WPS_Test_State::$post_terms[ $post_id ][ $taxonomy ] ?? array(), $ids ) : $ids )
	);

	return true;
}

/* -------------------------------------------------------------------------
 * URLs, uploads, attachments
 * ---------------------------------------------------------------------- */

function home_url( string $path = '' ): string {
	return rtrim( WPS_Test_State::$home_url, '/' ) . '/' . ltrim( $path, '/' );
}

function site_url( string $path = '' ): string {
	return rtrim( WPS_Test_State::$site_url, '/' ) . '/' . ltrim( $path, '/' );
}

function admin_url( string $path = '', string $scheme = 'admin' ): string {
	return rtrim( WPS_Test_State::$site_url, '/' ) . '/wp-admin/' . ltrim( $path, '/' );
}

function rest_url( string $path = '' ): string {
	return rtrim( WPS_Test_State::$site_url, '/' ) . '/wp-json/' . ltrim( $path, '/' );
}

function add_query_arg( $args, string $url = '' ) {
	if ( ! is_array( $args ) ) {
		return $url;
	}

	$parts = wp_parse_url( $url );
	$base  = '';

	if ( isset( $parts['scheme'], $parts['host'] ) ) {
		$base = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	$base .= $parts['path'] ?? '';
	$query = array();

	if ( isset( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}

	foreach ( $args as $key => $value ) {
		$query[ $key ] = $value;
	}

	$query_string = http_build_query( $query );

	return '' !== $query_string ? $base . '?' . $query_string : $base;
}

/**
 * @return array<string, mixed>
 */
function wp_parse_url( string $url, int $component = -1 ) {
	$parts = parse_url( $url );

	if ( false === $parts ) {
		return array();
	}

	if ( -1 !== $component ) {
		return parse_url( $url, $component );
	}

	return $parts;
}

/**
 * @return array<string, string>
 */
function wp_upload_dir( $time = null, bool $create_dir = true ): array {
	$base = WP_CONTENT_DIR . '/uploads';

	if ( $create_dir && ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
	}

	return array(
		'path'    => $base,
		'url'     => WPS_Test_State::$site_url . '/wp-content/uploads',
		'subdir'  => '',
		'basedir' => $base,
		'baseurl' => WPS_Test_State::$site_url . '/wp-content/uploads',
		'error'   => false,
	);
}

function wp_mkdir_p( string $target ): bool {
	if ( is_dir( $target ) ) {
		return true;
	}

	return @mkdir( $target, 0775, true ) || is_dir( $target );
}

function wp_get_attachment_image_url( int $attachment_id, $size = 'thumbnail', bool $icon = false ): string {
	return WPS_Test_State::$attachments[ $attachment_id ] ?? '';
}

function wp_get_attachment_url( int $attachment_id ): string {
	return WPS_Test_State::$attachments[ $attachment_id ] ?? '';
}

function wp_get_attachment_metadata( int $attachment_id ) {
	return WPS_Test_State::$attachment_metadata[ $attachment_id ] ?? null;
}

function get_attached_file( int $attachment_id, bool $unfiltered = false ): string {
	return WPS_Test_State::$attached_files[ $attachment_id ] ?? '';
}

function wp_get_original_image_url( int $attachment_id ): string {
	return WPS_Test_State::$original_image_urls[ $attachment_id ] ?? '';
}

function wp_get_original_image_path( int $attachment_id ): string {
	return WPS_Test_State::$original_image_files[ $attachment_id ] ?? '';
}

function get_post_mime_type( $post = null ): string {
	$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
	return WPS_Test_State::$mime_types[ $id ] ?? '';
}

function get_permalink( $post = 0 ) {
	$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;

	return WPS_Test_State::$permalinks[ $id ] ?? '';
}

function get_edit_post_link( $post = 0, string $context = 'display' ): string {
	$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;

	return admin_url( 'post.php?post=' . $id . '&action=edit' );
}

function get_edit_term_link( $term = 0, string $taxonomy = '', string $object_type = '' ): string {
	$id = is_object( $term ) ? (int) ( $term->term_id ?? 0 ) : (int) $term;

	return admin_url( 'term.php?taxonomy=' . $taxonomy . '&tag_ID=' . $id );
}

/**
 * @param array<string, mixed> $args
 * @return int[]
 */
function get_posts( array $args = array() ): array {
	$query = new WP_Query( $args );

	return $query->posts;
}

function wp_is_post_revision( $post ): bool {
	return false;
}

/* -------------------------------------------------------------------------
 * Formatting and sanitising
 * ---------------------------------------------------------------------- */

function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
}

function sanitize_text_field( string $str ): string {
	$str = wp_strip_all_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str ) ?? $str;

	return trim( $str );
}

function sanitize_title( string $title, string $fallback = '', string $context = 'save' ): string {
	$title = strtolower( remove_accents( $title ) );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
	$title = trim( $title, '-' );

	return '' !== $title ? $title : $fallback;
}

function remove_accents( string $text ): string {
	$map = array(
		'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
		'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I',
		'Ó' => 'O', 'Ö' => 'O', 'Ő' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U',
	);

	return strtr( $text, $map );
}

function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ?? $text;
	$text = strip_tags( $text );

	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? $text;
	}

	return trim( $text );
}

function strip_shortcodes( string $content ): string {
	return preg_replace( '/\[[^\]]+\]/', '', $content ) ?? $content;
}

function wp_kses_post( string $data ): string {
	return $data;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( string $url ): string {
	return $url;
}

function esc_js( string $text ): string {
	return addslashes( $text );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}

function esc_attr__( string $text, string $domain = 'default' ): string {
	return esc_attr( $text );
}

function esc_html_e( string $text, string $domain = 'default' ): void {
	echo esc_html( $text );
}

function esc_attr_e( string $text, string $domain = 'default' ): void {
	echo esc_attr( $text );
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function _e( string $text, string $domain = 'default' ): void {
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function _x( string $text, string $context = '', string $domain = 'default' ): string {
	return $text;
}

function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return 1 === $number ? $single : $plural;
}

function number_format_i18n( float $number, int $decimals = 0 ): string {
	return number_format( $number, $decimals, '.', '' );
}

function size_format( int $bytes, int $decimals = 0 ): string {
	if ( $bytes >= 1048576 ) {
		return number_format( $bytes / 1048576, $decimals ) . ' MB';
	}

	if ( $bytes >= 1024 ) {
		return number_format( $bytes / 1024, $decimals ) . ' KB';
	}

	return $bytes . ' B';
}

function human_time_diff( int $from, int $to = 0 ): string {
	$to  = $to > 0 ? $to : time();
	$diff = abs( $to - $from );

	return $diff . ' sec';
}

function wp_date( string $format, ?int $timestamp = null, $timezone = null ): string {
	return gmdate( $format, $timestamp ?? time() );
}

function current_time( string $type, bool $gmt = false ) {
	if ( 'timestamp' === $type || 'U' === $type ) {
		return time();
	}

	return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
}

/* -------------------------------------------------------------------------
 * JSON, misc helpers
 * ---------------------------------------------------------------------- */

function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_check_invalid_utf8( string $string, bool $strip = false ): string {
	if ( '' === $string ) {
		return '';
	}

	if ( mb_check_encoding( $string, 'UTF-8' ) ) {
		return $string;
	}

	return $strip ? ( mb_convert_encoding( $string, 'UTF-8', 'UTF-8' ) ?: '' ) : '';
}

function is_email( string $email ): bool {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function sanitize_email( string $email ): string {
	$sanitized = filter_var( $email, FILTER_SANITIZE_EMAIL );
	return false !== $sanitized ? (string) $sanitized : '';
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	if ( $special_chars ) {
		$chars .= '!@#$%^&*()';
	}

	$out = '';
	$max = strlen( $chars ) - 1;

	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, $max ) ];
	}

	return $out;
}

function is_ssl(): bool {
	return WPS_Test_State::$ssl;
}

function is_admin(): bool {
	return WPS_Test_State::$is_admin;
}

function get_current_screen() {
	return null;
}

function status_header( int $code ): void {
	// No-op in CLI tests.
}

function nocache_headers(): void {
	// No-op in CLI tests.
}

function wp_die( $message = '', $title = '', $args = array() ): void {
	throw new \RuntimeException( is_string( $message ) ? $message : 'wp_die' );
}

function wp_safe_redirect( string $location, int $status = 302 ): bool {
	throw new \RuntimeException( 'redirect:' . $location );
}

function wp_send_json_success( $value = null, int $status_code = 200 ): void {
	throw new WPS_Json_Response( array( 'success' => true, 'data' => $value ), true );
}

function wp_send_json_error( $value = null, int $status_code = 200 ): void {
	throw new WPS_Json_Response( array( 'success' => false, 'data' => $value ), false );
}

function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all' ): void {
}

function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, bool $in_footer = false ): void {
}

function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
	return true;
}

function add_menu_page( ...$args ): string {
	return 'wps-menu';
}

function add_submenu_page( ...$args ) {
	return 'wps-submenu';
}

function add_meta_box( ...$args ): void {
}

function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ): bool {
	WPS_Test_State::$rest_routes[ $namespace . $route ] = $args;

	return true;
}

function load_plugin_textdomain( string $domain, bool $deprecated = false, string $plugin_rel_path = '' ): bool {
	return true;
}

function wp_next_scheduled( string $hook, array $args = array() ) {
	return WPS_Test_State::$cron[ $hook ] ?? false;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
	WPS_Test_State::$cron[ $hook ] = $timestamp;

	return true;
}

function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
	WPS_Test_State::$cron[ $hook ] = $timestamp;

	return true;
}

function wp_clear_scheduled_hook( string $hook, array $args = array() ): int {
	$existed = isset( WPS_Test_State::$cron[ $hook ] );
	unset( WPS_Test_State::$cron[ $hook ] );

	return $existed ? 1 : 0;
}

function as_has_scheduled_action( string $hook, $args = null, $group = '' ): bool {
	return isset( WPS_Test_State::$cron[ $hook ] );
}

function as_next_scheduled_action( string $hook, $args = null, $group = '' ) {
	return WPS_Test_State::$cron[ $hook ] ?? false;
}

function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
	WPS_Test_State::$cron[ $hook ] = $timestamp;

	return 1;
}

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false ): int {
	WPS_Test_State::$cron[ $hook ] = $timestamp;

	return 1;
}

function as_unschedule_all_actions( string $hook, $args = null, $group = '' ): void {
	unset( WPS_Test_State::$cron[ $hook ] );
}

function version_compare_php( string $a, string $b ): int {
	return version_compare( $a, $b );
}

function plugin_dir_path( string $file ): string {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function plugin_basename( string $file ): string {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function register_activation_hook( string $file, $callback ): void {
	WPS_Test_State::$hooks['activate_' . plugin_basename( $file )][] = array(
		'callback' => $callback,
		'priority' => 10,
		'args'     => 1,
	);
}

function register_deactivation_hook( string $file, $callback ): void {
	WPS_Test_State::$hooks['deactivate_' . plugin_basename( $file )][] = array(
		'callback' => $callback,
		'priority' => 10,
		'args'     => 1,
	);
}

function selected( $selected, $current = true, bool $display = true ): string {
	$result = (string) $selected === (string) $current ? " selected='selected'" : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

function checked( $checked, $current = true, bool $display = true ): string {
	$result = (string) $checked === (string) $current ? " checked='checked'" : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

function disabled( $disabled, $current = true, bool $display = true ): string {
	$result = (string) $disabled === (string) $current ? " disabled='disabled'" : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

function wp_mail( $to, string $subject, string $message, $headers = '', array $attachments = array() ): bool {
	WPS_Test_State::$mails[] = array(
		'to'      => (string) $to,
		'subject' => $subject,
		'message' => $message,
	);

	return true;
}

function wp_remote_post( string $url, array $args = array() ): array {
	WPS_Test_State::$remote_posts[] = array(
		'url'  => $url,
		'args' => $args,
	);

	return array(
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'body'     => '{"success":true}',
	);
}

function wp_safe_remote_post( string $url, array $args = array() ): array {
	$args['reject_unsafe_urls'] = true;
	return wp_remote_post( $url, $args );
}

function wp_http_validate_url( string $url ) {
	if ( '' === $url ) {
		return false;
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return false;
	}

	if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}

	return $url;
}
