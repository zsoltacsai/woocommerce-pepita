<?php
/**
 * WooCommerce product list: Pepita column, filter and bulk actions.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Feed\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Product_List {

	private const BULK_INCLUDE = 'wps_pepita_include';
	private const BULK_EXCLUDE = 'wps_pepita_exclude';
	private const BULK_RESET   = 'wps_pepita_reset';

	public function register(): void {
		add_filter( 'manage_edit-product_columns', array( $this, 'columns' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		add_filter( 'posts_clauses', array( $this, 'filter_query' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'name' === $key ) {
				$new['wps_pepita'] = __( 'Pepita', 'wp-pepita-sync' );
			}
		}

		if ( ! isset( $new['wps_pepita'] ) ) {
			$new['wps_pepita'] = __( 'Pepita', 'wp-pepita-sync' );
		}

		return $new;
	}

	/**
	 * @param string $column
	 * @param int    $post_id
	 */
	public function render_column( $column, $post_id ): void {
		if ( 'wps_pepita' !== $column ) {
			return;
		}

		$product_id = (int) $post_id;
		$resolution = Product_Selection::resolve( $product_id );

		if ( ! $resolution['included'] ) {
			echo '<span class="wps-badge wps-badge--muted">' . esc_html__( 'Nem exportált', 'wp-pepita-sync' ) . '</span>';

			return;
		}

		$status  = (string) get_post_meta( $product_id, Product_Mapper::META_LAST_STATUS, true );
		$message = (string) get_post_meta( $product_id, Product_Mapper::META_LAST_MESSAGE, true );

		if ( 'invalid' === $status ) {
			printf(
				'<span class="wps-badge wps-badge--bad" title="%1$s">%2$s</span>',
				esc_attr( $message ),
				esc_html__( 'Hibás', 'wp-pepita-sync' )
			);

			return;
		}

		if ( 'exported' === $status ) {
			echo '<span class="wps-badge wps-badge--ok">' . esc_html__( 'Exportálva', 'wp-pepita-sync' ) . '</span>';

			return;
		}

		echo '<span class="wps-badge wps-badge--warn">' . esc_html__( 'Exportra vár', 'wp-pepita-sync' ) . '</span>';
	}

	public function render_filter(): void {
		global $typenow;

		if ( 'product' !== $typenow ) {
			return;
		}

		$current = isset( $_GET['wps_pepita_filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['wps_pepita_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options = array(
			''            => __( 'Minden Pepita állapot', 'wp-pepita-sync' ),
			'exported'    => __( 'Exportálva', 'wp-pepita-sync' ),
			'invalid'     => __( 'Hibás', 'wp-pepita-sync' ),
			'included'    => __( 'Pepita besorolás: benne', 'wp-pepita-sync' ),
			'excluded'    => __( 'Pepita besorolás: kizárt', 'wp-pepita-sync' ),
		);
		?>
		<select name="wps_pepita_filter">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply the Pepita filter on the product list query.
	 *
	 * @param array<string, string> $clauses
	 * @param \WP_Query             $query
	 * @return array<string, string>
	 */
	public function filter_query( $clauses, $query ): array {
		$clauses = is_array( $clauses ) ? $clauses : array();

		if ( ! is_object( $query ) || 'product' !== $query->get( 'post_type' ) || ! is_admin() ) {
			return $clauses;
		}

		$filter = isset( $_GET['wps_pepita_filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['wps_pepita_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $filter ) {
			return $clauses;
		}

		global $wpdb;

		if ( 'exported' === $filter ) {
			$clauses['join'] .= " INNER JOIN {$wpdb->postmeta} wps_export ON ( wps_export.post_id = {$wpdb->posts}.ID AND wps_export.meta_key = '_wps_last_export_status' AND wps_export.meta_value = 'exported' )";
		} elseif ( 'invalid' === $filter ) {
			$clauses['join'] .= " INNER JOIN {$wpdb->postmeta} wps_invalid ON ( wps_invalid.post_id = {$wpdb->posts}.ID AND wps_invalid.meta_key = '_wps_last_export_status' AND wps_invalid.meta_value = 'invalid' )";
		} elseif ( 'included' === $filter ) {
			$clauses['join'] .= " INNER JOIN {$wpdb->postmeta} wps_inc ON ( wps_inc.post_id = {$wpdb->posts}.ID AND wps_inc.meta_key = '_wps_include' AND wps_inc.meta_value = 'include' )";
		} elseif ( 'excluded' === $filter ) {
			$clauses['join'] .= " INNER JOIN {$wpdb->postmeta} wps_exc ON ( wps_exc.post_id = {$wpdb->posts}.ID AND wps_exc.meta_key = '_wps_include' AND wps_exc.meta_value = 'exclude' )";
		}

		return $clauses;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function bulk_actions( $actions ): array {
		$actions = is_array( $actions ) ? $actions : array();

		$actions[ self::BULK_INCLUDE ] = __( 'Pepita: felvétel a feedbe', 'wp-pepita-sync' );
		$actions[ self::BULK_EXCLUDE ] = __( 'Pepita: kizárás a feedből', 'wp-pepita-sync' );
		$actions[ self::BULK_RESET ]   = __( 'Pepita: kategória szabály visszaállítása', 'wp-pepita-sync' );

		return $actions;
	}

	/**
	 * @param string $redirect_to
	 * @param string $action
	 * @param int[]  $post_ids
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ): string {
		$map = array(
			self::BULK_INCLUDE => Product_Selection::MODE_INCLUDE,
			self::BULK_EXCLUDE => Product_Selection::MODE_EXCLUDE,
			self::BULK_RESET   => Product_Selection::MODE_DEFAULT,
		);

		if ( ! isset( $map[ $action ] ) ) {
			return (string) $redirect_to;
		}

		$ids     = array_map( 'intval', is_array( $post_ids ) ? $post_ids : array() );
		$changed = Product_Selection::bulk_set( $ids, $map[ $action ] );

		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );

		return add_query_arg(
			array(
				'wps_pepita_bulk_done' => $changed,
				'wps_pepita_bulk_mode' => $map[ $action ],
			),
			(string) $redirect_to
		);
	}

	public function bulk_notice(): void {
		if ( ! isset( $_GET['wps_pepita_bulk_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$count = (int) $_GET['wps_pepita_bulk_done']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mode  = isset( $_GET['wps_pepita_bulk_mode'] ) ? sanitize_key( wp_unslash( (string) $_GET['wps_pepita_bulk_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$label = match ( $mode ) {
			Product_Selection::MODE_INCLUDE => __( 'felvételre került a feedbe', 'wp-pepita-sync' ),
			Product_Selection::MODE_EXCLUDE => __( 'kizárásra került a feedből', 'wp-pepita-sync' ),
			default                         => __( 'visszaállt kategória szabályra', 'wp-pepita-sync' ),
		};

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: count, 2: action label */
					__( '%1$d termék %2$s.', 'wp-pepita-sync' ),
					$count,
					$label
				)
			)
		);
	}
}
