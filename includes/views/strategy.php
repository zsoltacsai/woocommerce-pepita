<?php
/**
 * Marketplace inventory and pricing strategy preview view.
 *
 * Provides a paginated preview and simulation table for merchants to inspect
 * inventory buffers, price transformations, fee deductions, and profit margins.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Catalog\Category_Rules;
use WPS\Pepita\Catalog\Marketplace_Inventory;
use WPS\Pepita\Catalog\Marketplace_Pricing;
use WPS\Pepita\Catalog\Price_Calculator;
use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Settings;

$filters = array(
	'search'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'category'      => isset( $_GET['cat'] ) ? (int) $_GET['cat'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'status_filter' => isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['status_filter'] ) ) : 'all', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'simulate'      => isset( $_GET['sim'] ) ? '1' === (string) $_GET['sim'] : true, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'page'          => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'per_page'      => 25,
);

$query_filters = array(
	'search'    => $filters['search'],
	'inclusion' => 'all',
	'category'  => $filters['category'],
	'page'      => $filters['page'],
	'per_page'  => $filters['per_page'],
);

$result = Product_Selection::query( $query_filters );

$categories = get_terms(
	array(
		'taxonomy'   => Category_Rules::TAXONOMY,
		'hide_empty' => false,
		'number'     => 1000,
	)
);
$categories = is_array( $categories ) ? $categories : array();

$pages = max( 1, (int) ceil( $result['total'] / $filters['per_page'] ) );

$inv_enabled_setting     = (bool) Settings::get( 'inventory_strategy_enabled', false );
$pricing_enabled_setting = (bool) Settings::get( 'pricing_strategy_enabled', false );
$global_buffer           = (int) Settings::get( 'inventory_buffer', 0 );
$global_max_qty          = Settings::get( 'inventory_max_qty', null );
$global_commission_pct   = (float) Settings::get( 'marketplace_commission_percent', 0.0 );
$global_commission_fix   = (float) Settings::get( 'marketplace_commission_fixed', 0.0 );
$global_packaging        = (float) Settings::get( 'marketplace_packaging_cost', 0.0 );
$global_shipping         = (float) Settings::get( 'marketplace_shipping_cost', 0.0 );
$global_min_margin       = Settings::get( 'marketplace_min_margin_percent', null );
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Piactéri készlet- és árazási stratégia előnézet', 'wp-pepita-sync' ); ?></h1>

	<p class="wps-lead">
		<?php esc_html_e( 'Ellenőrizze és szimulálja a Pepita feedekben érvényesülő készletvédelmi szabályokat és haszonkulcs-számításokat a változtatások élesítése előtt.', 'wp-pepita-sync' ); ?>
	</p>

	<div class="wps-strategy-summary" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
		<div class="wps-card" style="margin: 0;">
			<h2><?php esc_html_e( 'Készletstratégia állapota', 'wp-pepita-sync' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Állapot:', 'wp-pepita-sync' ); ?></strong>
				<?php echo Admin::status_badge( $inv_enabled_setting, __( 'Aktív (Éles)', 'wp-pepita-sync' ), __( 'Inaktív', 'wp-pepita-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><?php printf( esc_html__( 'Globális biztonsági tartalék (buffer): %d db', 'wp-pepita-sync' ), (int) $global_buffer ); ?></li>
				<li><?php printf( esc_html__( 'Globális maximális plafon: %s', 'wp-pepita-sync' ), null !== $global_max_qty ? sprintf( esc_html__( '%d db', 'wp-pepita-sync' ), (int) $global_max_qty ) : esc_html__( 'Nincs korlát', 'wp-pepita-sync' ) ); ?></li>
			</ul>
		</div>

		<div class="wps-card" style="margin: 0;">
			<h2><?php esc_html_e( 'Árazási és árrés modell állapota', 'wp-pepita-sync' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Állapot:', 'wp-pepita-sync' ); ?></strong>
				<?php echo Admin::status_badge( $pricing_enabled_setting, __( 'Aktív (Éles)', 'wp-pepita-sync' ), __( 'Inaktív', 'wp-pepita-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><?php printf( esc_html__( 'Jutalék: %s%% + %s Ft', 'wp-pepita-sync' ), esc_html( (string) $global_commission_pct ), esc_html( (string) $global_commission_fix ) ); ?></li>
				<li><?php printf( esc_html__( 'Csomagolási és szállítási költség: %s Ft + %s Ft', 'wp-pepita-sync' ), esc_html( (string) $global_packaging ), esc_html( (string) $global_shipping ) ); ?></li>
				<li><?php printf( esc_html__( 'Elvárt minimális árrés: %s', 'wp-pepita-sync' ), null !== $global_min_margin ? sprintf( esc_html__( '%s%%', 'wp-pepita-sync' ), esc_html( (string) $global_min_margin ) ) : esc_html__( 'Nincs megadva', 'wp-pepita-sync' ) ); ?></li>
			</ul>
		</div>
	</div>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wps-filters" style="margin-bottom: 20px;">
		<input type="hidden" name="page" value="wps-pepita-strategy" />

		<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Név, SKU vagy ID…', 'wp-pepita-sync' ); ?>" />

		<select name="cat">
			<option value=""><?php esc_html_e( 'Minden kategória', 'wp-pepita-sync' ); ?></option>
			<?php foreach ( $categories as $category ) : ?>
				<option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( $filters['category'], (int) $category->term_id ); ?>>
					<?php echo esc_html( $category->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<label style="display: inline-flex; align-items: center; gap: 6px; margin-left: 12px;">
			<input type="checkbox" name="sim" value="1" <?php checked( $filters['simulate'] ); ?> />
			<strong><?php esc_html_e( 'Szimuláció bekapcsolva', 'wp-pepita-sync' ); ?></strong>
			<span class="description" style="font-weight: normal;"><?php esc_html_e( '(Számítások mutatása akkor is, ha a stratégia inaktív)', 'wp-pepita-sync' ); ?></span>
		</label>

		<button type="submit" class="button button-primary" style="margin-left: 12px;"><?php esc_html_e( 'Szűrés és frissítés', 'wp-pepita-sync' ); ?></button>
		<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-strategy' ) ); ?>" style="margin-left: 8px;"><?php esc_html_e( 'Alaphelyzet', 'wp-pepita-sync' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-settings#wps-strategy' ) ); ?>" style="margin-left: auto;"><?php esc_html_e( 'Stratégia beállítások &rarr;', 'wp-pepita-sync' ); ?></a>
	</form>

	<table class="widefat striped wps-table" style="margin-top: 10px;">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Termék', 'wp-pepita-sync' ); ?></th>
				<th scope="col" style="text-align: right;"><?php esc_html_e( 'WooCommerce ár', 'wp-pepita-sync' ); ?></th>
				<th scope="col" style="text-align: right;"><?php esc_html_e( 'Pepita számított ár', 'wp-pepita-sync' ); ?></th>
				<th scope="col" style="text-align: center;"><?php esc_html_e( 'Árkülönbözet', 'wp-pepita-sync' ); ?></th>
				<th scope="col" style="text-align: center;"><?php esc_html_e( 'Készlet (Eredeti &rarr; Feed)', 'wp-pepita-sync' ); ?></th>
				<th scope="col" style="text-align: center;"><?php esc_html_e( 'Szabály forrása', 'wp-pepita-sync' ); ?></th>
				<th scope="col" style="text-align: right;"><?php esc_html_e( 'Beszerzési ár', 'wp-pepita-sync' ); ?></th>
				<th scope="col" style="text-align: right;"><?php esc_html_e( 'Becsült haszon és árrés', 'wp-pepita-sync' ); ?></th>
				<th scope="col" style="text-align: center;"><?php esc_html_e( 'Állapot / Figyelmeztetés', 'wp-pepita-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['ids'] ) ) : ?>
				<tr>
					<td colspan="9" style="text-align: center; padding: 20px;">
						<?php esc_html_e( 'Nem található termék a megadott feltételekkel.', 'wp-pepita-sync' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $result['ids'] as $product_id ) :
					$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
					if ( ! $product instanceof \WC_Product ) {
						continue;
					}

					$term_ids = Product_Selection::category_ids( $product_id );

					// Inventory calculations
					$inv_rules = Marketplace_Inventory::resolve_rules( $product_id, $term_ids );
					$raw_stock = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;

					// If simulate is on, temporarily force calculation
					$feed_stock = $filters['simulate'] || Marketplace_Inventory::is_enabled()
						? Marketplace_Inventory::calculate_quantity( $product, $term_ids, null, (bool) $filters['simulate'] )
						: ( $product->managing_stock() ? max( 0, (int) $product->get_stock_quantity() ) : null );

					// Pricing calculations
					$source_price = (float) $product->get_price();
					$source_gross = Price_Calculator::gross( $product );
					$adjustment   = Price_Calculator::resolve_adjustment( $product_id, $term_ids );
					$target_gross = Price_Calculator::adjust( $source_gross, $adjustment );

					$diff = round( $target_gross - $source_gross, 2 );

					$cost_inputs = Marketplace_Pricing::resolve_cost_inputs( $product_id, $term_ids );
					$pricing_calc = Marketplace_Pricing::calculate( $product, $target_gross, $cost_inputs );

					$source_labels = array(
						'product'          => __( 'Termék felülbírálás', 'wp-pepita-sync' ),
						'variation_parent' => __( 'Szülő termék', 'wp-pepita-sync' ),
						'category'         => __( 'Kategória szabály', 'wp-pepita-sync' ),
						'global'           => __( 'Globális beállítás', 'wp-pepita-sync' ),
					);
					$rule_source = $source_labels[ $cost_inputs['source'] ] ?? $cost_inputs['source'];

					$margin_status = $pricing_calc['margin_status'];
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $product_id ) ?: '' ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></strong>
							<div class="row-actions">
								<span>ID: <?php echo esc_html( (string) $product_id ); ?></span>
								<?php if ( $product->get_sku() ) : ?>
									| <span>SKU: <?php echo esc_html( $product->get_sku() ); ?></span>
								<?php endif; ?>
								| <span><?php echo esc_html( $product->get_type() ); ?></span>
							</div>
						</td>
						<td style="text-align: right;">
							<?php echo esc_html( number_format_i18n( $source_gross, 2 ) ); ?> Ft
						</td>
						<td style="text-align: right;">
							<strong><?php echo esc_html( number_format_i18n( $target_gross, 2 ) ); ?> Ft</strong>
						</td>
						<td style="text-align: center;">
							<?php if ( abs( $diff ) < 0.01 ) : ?>
								<span style="color: #666;">—</span>
							<?php elseif ( $diff > 0 ) : ?>
								<span style="color: #007017; font-weight: 600;">+<?php echo esc_html( number_format_i18n( $diff, 2 ) ); ?> Ft</span>
							<?php else : ?>
								<span style="color: #d63638; font-weight: 600;"><?php echo esc_html( number_format_i18n( $diff, 2 ) ); ?> Ft</span>
							<?php endif; ?>
						</td>
						<td style="text-align: center;">
							<?php if ( null === $raw_stock ) : ?>
								<em><?php esc_html_e( 'Nem kezelt', 'wp-pepita-sync' ); ?></em> &rarr; <em><?php esc_html_e( 'Nem kezelt', 'wp-pepita-sync' ); ?></em>
							<?php else : ?>
								<span><?php echo esc_html( (string) $raw_stock ); ?> db</span>
								&rarr;
								<strong style="color: #1d2327;"><?php echo esc_html( (string) $feed_stock ); ?> db</strong>
								<?php if ( $inv_rules['buffer'] > 0 || null !== $inv_rules['max_qty'] ) : ?>
									<div style="font-size: 11px; color: #666;">
										(Buffer: -<?php echo esc_html( (string) $inv_rules['buffer'] ); ?>
										<?php if ( null !== $inv_rules['max_qty'] ) : ?>
											| Max: <?php echo esc_html( (string) $inv_rules['max_qty'] ); ?>
										<?php endif; ?>)
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td style="text-align: center;">
							<span class="wps-badge wps-badge--muted"><?php echo esc_html( $rule_source ); ?></span>
						</td>
						<td style="text-align: right;">
							<?php if ( null !== $pricing_calc['cost_price'] ) : ?>
								<?php echo esc_html( number_format_i18n( $pricing_calc['cost_price'], 2 ) ); ?> Ft
							<?php else : ?>
								<span class="wps-badge wps-badge--muted"><?php esc_html_e( 'Nincs megadva', 'wp-pepita-sync' ); ?></span>
							<?php endif; ?>
						</td>
						<td style="text-align: right;">
							<?php if ( 'insufficient_data' === $margin_status ) : ?>
								<span class="wps-badge wps-badge--muted" title="<?php esc_attr_e( 'Nincs beszerzési ár (COGS) beállítva', 'wp-pepita-sync' ); ?>"><?php esc_html_e( 'ELÉGTELEN ADAT', 'wp-pepita-sync' ); ?></span>
							<?php else : ?>
								<div>
									<strong style="<?php echo ( $pricing_calc['estimated_profit'] < 0 ) ? 'color: #d63638;' : 'color: #007017;'; ?>">
										<?php echo esc_html( number_format_i18n( (float) $pricing_calc['estimated_profit'], 2 ) ); ?> Ft
									</strong>
								</div>
								<div style="font-size: 12px; <?php echo ( 'below_threshold' === $margin_status ) ? 'color: #d63638; font-weight: 600;' : 'color: #666;'; ?>">
									<?php printf( esc_html__( 'Árrés: %s%%', 'wp-pepita-sync' ), esc_html( (string) $pricing_calc['estimated_margin'] ) ); ?>
								</div>
							<?php endif; ?>
						</td>
						<td style="text-align: center;">
							<?php if ( 'below_threshold' === $margin_status ) : ?>
								<span class="wps-badge wps-badge--bad" style="background: #ffebe8; color: #cc1818; border: 1px solid #cc1818; font-weight: bold;">
									<?php esc_html_e( 'Árrés küszöb alatt!', 'wp-pepita-sync' ); ?>
								</span>
							<?php elseif ( null !== $feed_stock && 0 === $feed_stock ) : ?>
								<span class="wps-badge wps-badge--warning">
									<?php esc_html_e( 'Elfogyott / 0 db', 'wp-pepita-sync' ); ?>
								</span>
							<?php elseif ( 'ok' === $margin_status ) : ?>
								<span class="wps-badge wps-badge--ok">
									<?php esc_html_e( 'Rendben', 'wp-pepita-sync' ); ?>
								</span>
							<?php else : ?>
								<span class="wps-badge wps-badge--muted">
									<?php esc_html_e( 'Normál', 'wp-pepita-sync' ); ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav bottom" style="margin-top: 20px;">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( sprintf( /* translators: %d: product count */ __( '%d termék', 'wp-pepita-sync' ), $result['total'] ) ); ?></span>
				<span class="pagination-links">
					<?php
					$base_url = add_query_arg(
						array(
							'page' => 'wps-pepita-strategy',
							's'    => $filters['search'],
							'cat'  => $filters['category'] ?: '',
							'sim'  => $filters['simulate'] ? '1' : '0',
						),
						admin_url( 'admin.php' )
					);

					if ( $filters['page'] > 1 ) {
						printf(
							'<a class="prev-page button" href="%s">&lsaquo;</a> ',
							esc_url( add_query_arg( 'paged', $filters['page'] - 1, $base_url ) )
						);
					}

					printf(
						'<span class="paging-input">%d / <span class="total-pages">%d</span></span> ',
						(int) $filters['page'],
						(int) $pages
					);

					if ( $filters['page'] < $pages ) {
						printf(
							'<a class="next-page button" href="%s">&rsaquo;</a>',
							esc_url( add_query_arg( 'paged', $filters['page'] + 1, $base_url ) )
						);
					}
					?>
				</span>
			</div>
		</div>
	<?php endif; ?>
</div>
