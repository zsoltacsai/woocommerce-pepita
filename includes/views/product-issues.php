<?php
/**
 * Product issues and feed quality view.
 *
 * Displays catalog products with quality or compliance issues, their exact descriptions,
 * problem categories, current feed eligibility, and direct links to the native editor.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Product_Issues;
use WPS\Pepita\Settings;

$filters = array(
	'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'issue_type'  => isset( $_GET['issue_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['issue_type'] ) ) : 'all', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'feed_status' => isset( $_GET['feed_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['feed_status'] ) ) : 'all', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'page'        => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'per_page'    => 25,
);

if ( isset( $_GET['wps_refresh'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	Product_Issues::clear_cache();
}

$result = Product_Issues::query( $filters );
$pages  = $result['pages'];

$base_args = array(
	'page'        => 'wps-pepita-product-issues',
	's'           => $filters['search'],
	'issue_type'  => $filters['issue_type'],
	'feed_status' => $filters['feed_status'],
);

$auto_exclude_on = Settings::exclude_quality_issues();
$show_quality_score = (bool) Settings::get( 'show_internal_quality_score', false );
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita hibás termékek', 'wp-pepita-sync' ); ?></h1>

	<p class="wps-lead">
		<?php esc_html_e( 'A Pepita piactér minőségi és adatintegritási követelményeinek nem megfelelő termékek áttekintése. A blokkoló hibákkal rendelkező termékek az automatikus minőségi kizárás beállításával tarthatók távol a feedtől.', 'wp-pepita-sync' ); ?>
		<?php if ( $auto_exclude_on ) : ?>
			<span class="wps-badge wps-badge--ok" style="margin-left: 8px;"><?php esc_html_e( 'Automatikus minőségi kizárás: BEKAPCSOLVA', 'wp-pepita-sync' ); ?></span>
		<?php else : ?>
			<span class="wps-badge wps-badge--warn" style="margin-left: 8px;"><?php esc_html_e( 'Automatikus minőségi kizárás: KIKAPCSOLVA', 'wp-pepita-sync' ); ?></span>
		<?php endif; ?>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wps-filters">
		<input type="hidden" name="page" value="wps-pepita-product-issues" />

		<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Név, pontos SKU vagy ID…', 'wp-pepita-sync' ); ?>" />

		<select name="issue_type">
			<option value="all" <?php selected( $filters['issue_type'], 'all' ); ?>><?php esc_html_e( 'Minden hibatípus', 'wp-pepita-sync' ); ?></option>
			<option value="image_dim" <?php selected( $filters['issue_type'], 'image_dim' ); ?>><?php esc_html_e( 'Képméret (< 1200x630)', 'wp-pepita-sync' ); ?></option>
			<option value="invalid_image" <?php selected( $filters['issue_type'], 'invalid_image' ); ?>><?php esc_html_e( 'Nem ellenőrizhető vagy nem támogatott kép', 'wp-pepita-sync' ); ?></option>
			<option value="missing_image" <?php selected( $filters['issue_type'], 'missing_image' ); ?>><?php esc_html_e( 'Hiányzó kép', 'wp-pepita-sync' ); ?></option>
			<option value="webp" <?php selected( $filters['issue_type'], 'webp' ); ?>><?php esc_html_e( 'WebP konverziós hiba', 'wp-pepita-sync' ); ?></option>
			<option value="invalid_price" <?php selected( $filters['issue_type'], 'invalid_price' ); ?>><?php esc_html_e( 'Érvénytelen ár', 'wp-pepita-sync' ); ?></option>
			<option value="missing_category" <?php selected( $filters['issue_type'], 'missing_category' ); ?>><?php esc_html_e( 'Hiányzó kategória', 'wp-pepita-sync' ); ?></option>
			<option value="missing_gtin" <?php selected( $filters['issue_type'], 'missing_gtin' ); ?>><?php esc_html_e( 'Kötelező GTIN hiánya', 'wp-pepita-sync' ); ?></option>
			<option value="missing_name" <?php selected( $filters['issue_type'], 'missing_name' ); ?>><?php esc_html_e( 'Hiányzó név', 'wp-pepita-sync' ); ?></option>
			<option value="missing_description" <?php selected( $filters['issue_type'], 'missing_description' ); ?>><?php esc_html_e( 'Hiányzó leírás', 'wp-pepita-sync' ); ?></option>
			<option value="missing_sku" <?php selected( $filters['issue_type'], 'missing_sku' ); ?>><?php esc_html_e( 'Cikkszám (SKU) hiánya (nem blokkoló)', 'wp-pepita-sync' ); ?></option>
		</select>

		<select name="feed_status">
			<option value="all" <?php selected( $filters['feed_status'], 'all' ); ?>><?php esc_html_e( 'Minden feed státusz', 'wp-pepita-sync' ); ?></option>
			<option value="included" <?php selected( $filters['feed_status'], 'included' ); ?>><?php esc_html_e( 'Szerepel a feedben', 'wp-pepita-sync' ); ?></option>
			<option value="quality_excluded" <?php selected( $filters['feed_status'], 'quality_excluded' ); ?>><?php esc_html_e( 'Kizárva a minőség miatt', 'wp-pepita-sync' ); ?></option>
			<option value="product_excluded" <?php selected( $filters['feed_status'], 'product_excluded' ); ?>><?php esc_html_e( 'Manuálisan kizárva', 'wp-pepita-sync' ); ?></option>
			<option value="category_excluded" <?php selected( $filters['feed_status'], 'category_excluded' ); ?>><?php esc_html_e( 'Kategória miatt kizárva', 'wp-pepita-sync' ); ?></option>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Szűrés', 'wp-pepita-sync' ); ?></button>
		<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-product-issues' ) ); ?>"><?php esc_html_e( 'Szűrők törlése', 'wp-pepita-sync' ); ?></a>
		<a class="button" href="<?php echo esc_url( add_query_arg( 'wps_refresh', '1', admin_url( 'admin.php?page=wps-pepita-product-issues' ) ) ); ?>" style="margin-left: auto;"><?php esc_html_e( 'Adatok frissítése', 'wp-pepita-sync' ); ?></a>
	</form>

	<?php if ( ! empty( $result['available'] ) ) : ?>
		<div class="wps-bulk-bar">
			<span class="wps-bulk-count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: products checked on page, 2: issue products found on page, 3: total products matching the search */
						__( 'Ezen az oldalon %1$d terméket vizsgáltunk, ebből %2$d problémás. A keresés összesen %3$d terméket tartalmaz; lapozzon a teljes katalógus áttekintéséhez.', 'wp-pepita-sync' ),
						(int) $result['scanned'],
						(int) $result['total'],
						(int) $result['total_products']
					)
				);
				?>
			</span>
		</div>
	<?php else : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'A termékkatalógus lekérdezése nem volt elérhető, ezért a hibás termékek eredménye ismeretlen. Próbálja újra később.', 'wp-pepita-sync' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped wps-table">
		<thead>
			<tr>
				<th style="width: 60px;"><?php esc_html_e( 'ID', 'wp-pepita-sync' ); ?></th>
				<th style="width: 50px;"><?php esc_html_e( 'Kép', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Termék neve', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Elsődleges Pepita kategória', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Probléma kategória', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Pontos hibaleírás', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Jelenlegi feed státusz', 'wp-pepita-sync' ); ?></th>
				<th style="text-align: right;"><?php esc_html_e( 'Művelet', 'wp-pepita-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['available'] ) || array() === $result['items'] ) : ?>
				<tr>
					<td colspan="9" style="text-align: center; padding: 24px;">
						<em><?php esc_html_e( 'Ezen az oldalán nincs megjeleníthető találat. A teljes katalógushoz lapozzon végig a termékoldalakon.', 'wp-pepita-sync' ); ?></em>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $result['items'] as $item ) : ?>
				<tr>
					<td><code>#<?php echo esc_html( (string) $item['product_id'] ); ?></code></td>
					<td>
						<?php if ( '' !== $item['thumbnail_url'] ) : ?>
							<img src="<?php echo esc_url( $item['thumbnail_url'] ); ?>" alt="" width="40" height="40" style="object-fit: cover; border-radius: 3px; border: 1px solid #ddd;" />
						<?php else : ?>
							<span style="display:inline-block; width:40px; height:40px; line-height:40px; text-align:center; background:#eee; color:#888; border-radius:3px;">—</span>
						<?php endif; ?>
					</td>
					<td>
						<strong>
							<a href="<?php echo esc_url( $item['edit_url'] ); ?>">
								<?php echo esc_html( $item['name'] ); ?>
							</a>
						</strong>
						<?php if ( $show_quality_score ) : ?>
							<br /><small><?php echo esc_html( sprintf( /* translators: %d: internal diagnostic score */ __( 'Belső minőségi pont: %d/100', 'wp-pepita-sync' ), (int) $item['quality_summary']['score'] ) ); ?></small>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $item['sku'] ); ?></code></td>
					<td><?php echo esc_html( $item['primary_category'] ); ?></td>
					<td>
						<?php foreach ( $item['problem_categories'] as $cat_label ) : ?>
							<span class="wps-badge wps-badge--warn" style="margin-right: 4px; margin-bottom: 2px; display: inline-block;">
								<?php echo esc_html( $cat_label ); ?>
							</span>
						<?php endforeach; ?>
					</td>
					<td>
						<ul style="margin: 0; padding-left: 16px; list-style-type: disc;">
							<?php foreach ( $item['descriptions'] as $desc ) : ?>
								<li style="margin-bottom: 4px; font-size: 13px;"><?php echo esc_html( $desc ); ?></li>
							<?php endforeach; ?>
						</ul>
						<?php if ( ! empty( $item['quality_summary']['recommendations'] ) ) : ?>
							<ul style="margin: 6px 0 0; padding-left: 16px; list-style-type: circle;">
								<?php foreach ( $item['quality_summary']['recommendations'] as $recommendation ) : ?>
									<li style="margin-bottom: 4px; font-size: 12px;"><strong><?php esc_html_e( 'Javaslat:', 'wp-pepita-sync' ); ?></strong> <?php echo esc_html( $recommendation['text'] ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</td>
					<td>
						<span class="wps-badge <?php echo esc_attr( $item['feed_status']['class'] ); ?>">
							<?php echo esc_html( $item['feed_status']['label'] ); ?>
						</span>
					</td>
					<td style="text-align: right;">
						<a href="<?php echo esc_url( $item['edit_url'] ); ?>" class="button button-small" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Szerkesztés', 'wp-pepita-sync' ); ?>
						</a>
					</td>
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
