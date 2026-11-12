<?php
/**
 * Categories view.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Catalog\Category_Rules;

$terms = get_terms(
	array(
		'taxonomy'   => Category_Rules::TAXONOMY,
		'hide_empty' => false,
		'number'     => 2000,
	)
);

$terms = is_array( $terms ) ? $terms : array();

$by_parent = array();

foreach ( $terms as $term ) {
	$by_parent[ (int) $term->parent ][] = $term;
}

$flat = array();

$walk = static function ( int $parent, int $depth ) use ( &$walk, &$flat, $by_parent ): void {
	if ( ! isset( $by_parent[ $parent ] ) ) {
		return;
	}

	foreach ( $by_parent[ $parent ] as $term ) {
		$term->wps_depth  = $depth;
		$flat[]           = $term;
		$walk( (int) $term->term_id, $depth + 1 );
	}
};

$walk( 0, 0 );
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita kategóriák', 'wp-pepita-sync' ); ?></h1>

	<p class="wps-lead">
		<?php esc_html_e( 'A kategóriaszabályok határozzák meg, mely termékek kerülnek a feedbe. A legmélyebb szabály érvényesül; a kizárás mindig öröklődik, a felvétel csak akkor, ha a gyermek kategóriák öröklése engedélyezett.', 'wp-pepita-sync' ); ?>
	</p>

	<?php if ( array() === $flat ) : ?>
		<div class="wps-notice wps-notice--info"><p><?php esc_html_e( 'Még nincs termékkategória.', 'wp-pepita-sync' ); ?></p></div>
	<?php else : ?>
		<table class="widefat striped wps-table wps-table--categories">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Kategória', 'wp-pepita-sync' ); ?></th>
					<th><?php esc_html_e( 'Termékszám', 'wp-pepita-sync' ); ?></th>
					<th><?php esc_html_e( 'Pepita szabály', 'wp-pepita-sync' ); ?></th>
					<th><?php esc_html_e( 'Gyermek öröklés', 'wp-pepita-sync' ); ?></th>
					<th><?php esc_html_e( 'GTIN kötelező', 'wp-pepita-sync' ); ?></th>
					<th><?php esc_html_e( 'Árkorrekció', 'wp-pepita-sync' ); ?></th>
					<th><?php esc_html_e( 'Szállítási nap', 'wp-pepita-sync' ); ?></th>
					<th><?php esc_html_e( 'Művelet', 'wp-pepita-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $flat as $term ) : ?>
					<?php
					$term_id  = (int) $term->term_id;
					$own      = (string) get_term_meta( $term_id, Category_Rules::META_INCLUDE, true );
					$decision = Category_Rules::decision( $term_id );
					$children = get_term_meta( $term_id, Category_Rules::META_INCLUDE_CHILDREN, true );
					$children = '' === $children ? '1' : (string) $children;
					$gtin     = '1' === (string) get_term_meta( $term_id, Category_Rules::META_GTIN_REQUIRED, true );
					$type     = (string) get_term_meta( $term_id, Category_Rules::META_PRICE_TYPE, true );
					$value    = (string) get_term_meta( $term_id, Category_Rules::META_PRICE_VALUE, true );
					$delay    = (string) get_term_meta( $term_id, Category_Rules::META_SHIPPING_DELAY, true );
					?>
					<tr class="wps-category-row" data-term-id="<?php echo esc_attr( (string) $term_id ); ?>">
						<td>
							<span style="padding-left: <?php echo esc_attr( (string) ( (int) $term->wps_depth * 18 ) ); ?>px"></span>
							<?php if ( (int) $term->wps_depth > 0 ) : ?>
								<span class="wps-tree">└</span>
							<?php endif; ?>
							<strong><?php echo esc_html( $term->name ); ?></strong>
							<div class="row-actions">
								<span><a href="<?php echo esc_url( (string) get_edit_term_link( $term_id, Category_Rules::TAXONOMY ) ); ?>"><?php esc_html_e( 'Szerkesztés', 'wp-pepita-sync' ); ?></a></span>
							</div>
							<?php if ( '' === $own && Category_Rules::DECISION_NONE !== $decision ) : ?>
								<div class="wps-muted"><?php esc_html_e( 'Örökölt szabály érvényesül', 'wp-pepita-sync' ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) (int) $term->count ); ?></td>
						<td>
							<select class="wps-cat-include">
								<option value="inherit" <?php selected( $own, '' ); ?>><?php esc_html_e( 'Örökölt', 'wp-pepita-sync' ); ?></option>
								<option value="include" <?php selected( $own, 'include' ); ?>><?php esc_html_e( 'Benne', 'wp-pepita-sync' ); ?></option>
								<option value="exclude" <?php selected( $own, 'exclude' ); ?>><?php esc_html_e( 'Kizárva', 'wp-pepita-sync' ); ?></option>
							</select>
						</td>
						<td><input type="checkbox" class="wps-cat-children" <?php checked( $children, '1' ); ?> /></td>
						<td><input type="checkbox" class="wps-cat-gtin" <?php checked( $gtin ); ?> /></td>
						<td>
							<select class="wps-cat-price-type">
								<option value="" <?php selected( $type, '' ); ?>><?php esc_html_e( 'Örökölt', 'wp-pepita-sync' ); ?></option>
								<option value="none" <?php selected( $type, 'none' ); ?>><?php esc_html_e( 'Nincs', 'wp-pepita-sync' ); ?></option>
								<option value="percent" <?php selected( $type, 'percent' ); ?>>%</option>
								<option value="fixed" <?php selected( $type, 'fixed' ); ?>><?php esc_html_e( 'Fix', 'wp-pepita-sync' ); ?></option>
							</select>
							<input type="number" step="0.01" class="small-text wps-cat-price-value" value="<?php echo esc_attr( $value ); ?>" />
						</td>
						<td><input type="number" min="0" step="1" class="small-text wps-cat-delay" value="<?php echo esc_attr( $delay ); ?>" /></td>
						<td><button type="button" class="button wps-cat-save"><?php esc_html_e( 'Mentés', 'wp-pepita-sync' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
