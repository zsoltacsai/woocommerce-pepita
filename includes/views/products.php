<?php
/**
 * Products view.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;

$filters = array(
	'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'inclusion' => isset( $_GET['inclusion'] ) ? sanitize_key( wp_unslash( (string) $_GET['inclusion'] ) ) : 'all', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'category'  => isset( $_GET['cat'] ) ? (int) $_GET['cat'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'type'      => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'page'      => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'per_page'  => 25,
);

$result = Product_Selection::query( $filters );

$categories = get_terms(
	array(
		'taxonomy'   => Category_Rules::TAXONOMY,
		'hide_empty' => false,
		'number'     => 2000,
	)
);

$categories = is_array( $categories ) ? $categories : array();

$pages = max( 1, (int) ceil( $result['total'] / $filters['per_page'] ) );

$base_args = array(
	'page'      => 'wps-pepita-products',
	's'         => $filters['search'],
	'inclusion' => $filters['inclusion'],
	'cat'       => $filters['category'] ?: '',
	'type'      => $filters['type'],
);
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita termékek', 'wp-pepita-sync' ); ?></h1>

	<p class="wps-lead">
		<?php esc_html_e( 'A besorolás sorrendje: termék szintű kizárás → termék szintű felvétel → kategória szabály → általános beállítás. A kizárás mindig erősebb a felvételnél.', 'wp-pepita-sync' ); ?>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wps-filters">
		<input type="hidden" name="page" value="wps-pepita-products" />

		<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Név, SKU vagy ID…', 'wp-pepita-sync' ); ?>" />

		<select name="inclusion">
			<option value="all" <?php selected( $filters['inclusion'], 'all' ); ?>><?php esc_html_e( 'Minden termék', 'wp-pepita-sync' ); ?></option>
			<option value="included" <?php selected( $filters['inclusion'], 'included' ); ?>><?php esc_html_e( 'Feedbe kerülő termékek', 'wp-pepita-sync' ); ?></option>
			<option value="excluded" <?php selected( $filters['inclusion'], 'excluded' ); ?>><?php esc_html_e( 'Feedből kizárt termékek', 'wp-pepita-sync' ); ?></option>
			<option value="individual" <?php selected( $filters['inclusion'], 'individual' ); ?>><?php esc_html_e( 'Csak egyedi felvétel', 'wp-pepita-sync' ); ?></option>
			<option value="category" <?php selected( $filters['inclusion'], 'category' ); ?>><?php esc_html_e( 'Kategória szabály alapján', 'wp-pepita-sync' ); ?></option>
			<option value="invalid" <?php selected( $filters['inclusion'], 'invalid' ); ?>><?php esc_html_e( 'Hibás feed adatok', 'wp-pepita-sync' ); ?></option>
		</select>

		<select name="cat">
			<option value=""><?php esc_html_e( 'Minden kategória', 'wp-pepita-sync' ); ?></option>
			<?php foreach ( $categories as $category ) : ?>
				<option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( $filters['category'], (int) $category->term_id ); ?>>
					<?php echo esc_html( $category->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="type">
			<option value="" <?php selected( $filters['type'], '' ); ?>><?php esc_html_e( 'Minden típus', 'wp-pepita-sync' ); ?></option>
			<option value="simple" <?php selected( $filters['type'], 'simple' ); ?>><?php esc_html_e( 'Egyszerű termék', 'wp-pepita-sync' ); ?></option>
			<option value="variable" <?php selected( $filters['type'], 'variable' ); ?>><?php esc_html_e( 'Variációs termék', 'wp-pepita-sync' ); ?></option>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Szűrés', 'wp-pepita-sync' ); ?></button>
		<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-products' ) ); ?>"><?php esc_html_e( 'Szűrők törlése', 'wp-pepita-sync' ); ?></a>
	</form>

	<div class="wps-bulk-bar">
		<select id="wps-bulk-mode">
			<option value="include"><?php esc_html_e( 'Felvétel a feedbe', 'wp-pepita-sync' ); ?></option>
			<option value="exclude"><?php esc_html_e( 'Kizárás a feedből', 'wp-pepita-sync' ); ?></option>
			<option value="default"><?php esc_html_e( 'Visszaállítás kategória szabályra', 'wp-pepita-sync' ); ?></option>
		</select>
		<button type="button" class="button" id="wps-bulk-apply"><?php esc_html_e( 'Alkalmazás a kijelöltekre', 'wp-pepita-sync' ); ?></button>
		<span class="wps-bulk-count"><?php echo esc_html( sprintf( /* translators: %d: product count */ __( '%d termék', 'wp-pepita-sync' ), $result['total'] ) ); ?></span>
	</div>

	<?php if ( $result['truncated'] ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'A lista az első 5000 átvizsgált terméket tartalmazza. Szűkítse a keresést a pontosabb eredményhez.', 'wp-pepita-sync' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped wps-table">
		<thead>
			<tr>
				<td class="check-column"><input type="checkbox" id="wps-select-all" /></td>
				<th><?php esc_html_e( 'Termék', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Típus', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Ár', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Pepita állapot', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Forrás', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Pepita azonosító', 'wp-pepita-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $result['ids'] ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'Nincs a szűrésnek megfelelő termék.', 'wp-pepita-sync' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $result['ids'] as $product_id ) : ?>
				<?php
				$product = wc_get_product( $product_id );

				if ( ! $product instanceof \WC_Product ) {
					continue;
				}

				$resolution = Product_Selection::resolve( $product_id );
				$status     = (string) get_post_meta( $product_id, Product_Mapper::META_LAST_STATUS, true );
				$message    = (string) get_post_meta( $product_id, Product_Mapper::META_LAST_MESSAGE, true );
				$source     = match ( $resolution['source'] ) {
					Product_Selection::SOURCE_PRODUCT_EXCLUDED => __( 'Termék kizárva', 'wp-pepita-sync' ),
					Product_Selection::SOURCE_PRODUCT_INCLUDED => __( 'Termék felvéve', 'wp-pepita-sync' ),
					Product_Selection::SOURCE_CATEGORY         => __( 'Kategória', 'wp-pepita-sync' ),
					default                                    => __( 'Általános', 'wp-pepita-sync' ),
				};
				?>
				<tr>
					<th scope="row" class="check-column">
						<input type="checkbox" class="wps-row-check" value="<?php echo esc_attr( (string) $product_id ); ?>" />
					</th>
					<td>
						<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $product_id ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></strong>
						<div class="row-actions">
							<span><a href="<?php echo esc_url( (string) get_permalink( $product_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Megtekintés', 'wp-pepita-sync' ); ?></a></span>
						</div>
					</td>
					<td><?php echo esc_html( '' !== $product->get_sku() ? $product->get_sku() : '—' ); ?></td>
					<td><?php echo esc_html( $product->get_type() ); ?></td>
					<td><?php echo esc_html( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ) ); ?></td>
					<td>
						<?php if ( ! $resolution['included'] ) : ?>
							<span class="wps-badge wps-badge--muted"><?php esc_html_e( 'Nem exportált', 'wp-pepita-sync' ); ?></span>
						<?php elseif ( 'invalid' === $status ) : ?>
							<span class="wps-badge wps-badge--bad" title="<?php echo esc_attr( $message ); ?>"><?php esc_html_e( 'Hibás', 'wp-pepita-sync' ); ?></span>
						<?php elseif ( 'exported' === $status ) : ?>
							<span class="wps-badge wps-badge--ok"><?php esc_html_e( 'Exportálva', 'wp-pepita-sync' ); ?></span>
						<?php else : ?>
							<span class="wps-badge wps-badge--warn"><?php esc_html_e( 'Exportra vár', 'wp-pepita-sync' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $source ); ?></td>
					<td><code><?php echo esc_html( Product_Mapper::export_id( $product_id ) ); ?></code></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( array_merge( $base_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
							'format'    => '',
							'current'   => $filters['page'],
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>
</div>
