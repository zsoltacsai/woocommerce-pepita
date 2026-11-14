<?php
/**
 * Product category list and edit form: Pepita rules.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Catalog\Category_Rules;

defined( 'ABSPATH' ) || exit;

final class Category_List {

	public function register(): void {
		add_filter( 'manage_edit-product_cat_columns', array( $this, 'columns' ) );
		add_filter( 'manage_product_cat_custom_column', array( $this, 'render_column' ), 10, 3 );

		add_action( 'product_cat_edit_form_fields', array( $this, 'render_edit_fields' ), 10, 1 );
		add_action( 'product_cat_add_form_fields', array( $this, 'render_add_fields' ) );

		add_action( 'edited_product_cat', array( $this, 'save' ), 10, 1 );
		add_action( 'created_product_cat', array( $this, 'save' ), 10, 1 );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		$columns['wps_pepita_rule'] = __( 'Pepita szabály', 'wp-pepita-sync' );

		return $columns;
	}

	/**
	 * @param string $content
	 * @param string $column_name
	 * @param int    $term_id
	 */
	public function render_column( $content, $column_name, $term_id ) {
		if ( 'wps_pepita_rule' !== $column_name ) {
			return $content;
		}

		$term_id  = (int) $term_id;
		$decision = Category_Rules::decision( $term_id );
		$own      = (string) get_term_meta( $term_id, Category_Rules::META_INCLUDE, true );

		$label = match ( $decision ) {
			Category_Rules::DECISION_INCLUDE => __( 'Benne a feedben', 'wp-pepita-sync' ),
			Category_Rules::DECISION_EXCLUDE => __( 'Kizárva', 'wp-pepita-sync' ),
			default                          => __( 'Örökölt / nincs szabály', 'wp-pepita-sync' ),
		};

		$class = match ( $decision ) {
			Category_Rules::DECISION_INCLUDE => 'wps-badge--ok',
			Category_Rules::DECISION_EXCLUDE => 'wps-badge--bad',
			default                          => 'wps-badge--muted',
		};

		$inherited = '' === $own && Category_Rules::DECISION_NONE !== $decision;

		return sprintf(
			'<span class="wps-badge %1$s">%2$s</span>%3$s',
			esc_attr( $class ),
			esc_html( $label ),
			$inherited ? ' <span class="wps-muted">(' . esc_html__( 'örökölt', 'wp-pepita-sync' ) . ')</span>' : ''
		);
	}

	/**
	 * @param \WP_Term $term
	 */
	public function render_edit_fields( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$term_id = (int) $term->term_id;

		$include  = (string) get_term_meta( $term_id, Category_Rules::META_INCLUDE, true );
		$children = get_term_meta( $term_id, Category_Rules::META_INCLUDE_CHILDREN, true );
		$gtin     = '1' === (string) get_term_meta( $term_id, Category_Rules::META_GTIN_REQUIRED, true );
		$type     = (string) get_term_meta( $term_id, Category_Rules::META_PRICE_TYPE, true );
		$value    = (string) get_term_meta( $term_id, Category_Rules::META_PRICE_VALUE, true );
		$delay    = (string) get_term_meta( $term_id, Category_Rules::META_SHIPPING_DELAY, true );
		$inv_buf  = (string) get_term_meta( $term_id, \WPS\Pepita\Catalog\Marketplace_Inventory::TERM_META_BUFFER, true );
		$inv_max  = (string) get_term_meta( $term_id, \WPS\Pepita\Catalog\Marketplace_Inventory::TERM_META_MAX_QTY, true );
		$comm_pct = (string) get_term_meta( $term_id, \WPS\Pepita\Catalog\Marketplace_Pricing::TERM_META_COMMISSION_PERCENT, true );
		$min_mgn  = (string) get_term_meta( $term_id, \WPS\Pepita\Catalog\Marketplace_Pricing::TERM_META_MIN_MARGIN, true );

		$children = '' === $children ? '1' : (string) $children;
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Pepita szabály', 'wp-pepita-sync' ); ?></th>
			<td>
				<select name="wps_pepita_include" id="wps_pepita_include">
					<option value="inherit" <?php selected( $include, '' ); ?>><?php esc_html_e( 'Örökölje a szülő kategória szabályát', 'wp-pepita-sync' ); ?></option>
					<option value="include" <?php selected( $include, Category_Rules::DECISION_INCLUDE ); ?>><?php esc_html_e( 'Kategória termékei szerepeljenek a feedben', 'wp-pepita-sync' ); ?></option>
					<option value="exclude" <?php selected( $include, Category_Rules::DECISION_EXCLUDE ); ?>><?php esc_html_e( 'Kategória termékei ne szerepeljenek a feedben', 'wp-pepita-sync' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'A legmélyebb kategóriaszabály érvényesül. A kizárás mindig öröklődik, a felvétel csak akkor, ha az alábbi „gyermek kategóriák öröklése” be van kapcsolva.', 'wp-pepita-sync' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Gyermek kategóriák öröklése', 'wp-pepita-sync' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wps_pepita_include_children" value="1" <?php checked( $children, '1' ); ?> />
					<?php esc_html_e( 'A felvételi szabály érvényes a gyermek kategóriákra is', 'wp-pepita-sync' ); ?>
				</label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'GTIN kötelező', 'wp-pepita-sync' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wps_pepita_gtin_required" value="1" <?php checked( $gtin ); ?> />
					<?php esc_html_e( 'A kategória termékeinél a GTIN/EAN/ISBN kód kötelező', 'wp-pepita-sync' ); ?>
				</label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Árkorrekció', 'wp-pepita-sync' ); ?></th>
			<td>
				<select name="wps_pepita_price_type">
					<option value="" <?php selected( $type, '' ); ?>><?php esc_html_e( 'Örökölt / nincs', 'wp-pepita-sync' ); ?></option>
					<option value="none" <?php selected( $type, 'none' ); ?>><?php esc_html_e( 'Nincs korrekció', 'wp-pepita-sync' ); ?></option>
					<option value="percent" <?php selected( $type, 'percent' ); ?>><?php esc_html_e( 'Százalék', 'wp-pepita-sync' ); ?></option>
					<option value="fixed" <?php selected( $type, 'fixed' ); ?>><?php esc_html_e( 'Fix összeg', 'wp-pepita-sync' ); ?></option>
				</select>
				<input type="number" step="0.01" name="wps_pepita_price_value" value="<?php echo esc_attr( $value ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'Csak a Pepita feed árát módosítja, a WooCommerce árat nem.', 'wp-pepita-sync' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Szállítási idő (nap)', 'wp-pepita-sync' ); ?></th>
			<td>
				<input type="number" min="0" step="1" name="wps_pepita_shipping_delay" value="<?php echo esc_attr( $delay ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'Üresen hagyva az általános beállítás érvényesül.', 'wp-pepita-sync' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Készlet tartalék (buffer)', 'wp-pepita-sync' ); ?></th>
			<td>
				<input type="number" min="0" step="1" name="wps_pepita_inventory_buffer" value="<?php echo esc_attr( $inv_buf ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'A kategória termékeiből levonandó biztonsági készlet (db).', 'wp-pepita-sync' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Maximális készletplafon', 'wp-pepita-sync' ); ?></th>
			<td>
				<input type="number" min="1" step="1" name="wps_pepita_inventory_max_qty" value="<?php echo esc_attr( $inv_max ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'A kategória termékeinél a feedben megjelenő legmagasabb készlet (db).', 'wp-pepita-sync' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Piactéri jutalék (%)', 'wp-pepita-sync' ); ?></th>
			<td>
				<input type="number" min="0" max="100" step="0.01" name="wps_pepita_commission_percent" value="<?php echo esc_attr( $comm_pct ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'Kategória szintű jutalék százalék felülbírálás.', 'wp-pepita-sync' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Minimális elvárt árrés (%)', 'wp-pepita-sync' ); ?></th>
			<td>
				<input type="number" min="0" max="100" step="0.01" name="wps_pepita_min_margin" value="<?php echo esc_attr( $min_mgn ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'Kategória szintű minimális haszonkulcs elvárás.', 'wp-pepita-sync' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render_add_fields(): void {
		?>
		<div class="form-field">
			<label for="wps_pepita_include"><?php esc_html_e( 'Pepita szabály', 'wp-pepita-sync' ); ?></label>
			<select name="wps_pepita_include" id="wps_pepita_include">
				<option value="inherit"><?php esc_html_e( 'Örökölje a szülő kategória szabályát', 'wp-pepita-sync' ); ?></option>
				<option value="include"><?php esc_html_e( 'Kategória termékei szerepeljenek a feedben', 'wp-pepita-sync' ); ?></option>
				<option value="exclude"><?php esc_html_e( 'Kategória termékei ne szerepeljenek a feedben', 'wp-pepita-sync' ); ?></option>
			</select>
		</div>
		<div class="form-field">
			<label>
				<input type="checkbox" name="wps_pepita_include_children" value="1" checked="checked" />
				<?php esc_html_e( 'A felvételi szabály érvényes a gyermek kategóriákra is', 'wp-pepita-sync' ); ?>
			</label>
		</div>
		<div class="form-field">
			<label>
				<input type="checkbox" name="wps_pepita_gtin_required" value="1" />
				<?php esc_html_e( 'GTIN/EAN/ISBN kötelező a kategóriában', 'wp-pepita-sync' ); ?>
			</label>
		</div>
		<?php
	}

	public function save( int $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		// The category form is protected by WordPress' own nonce; verify it when present.
		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) );

			if ( ! wp_verify_nonce( $nonce, 'update-tag_' . $term_id ) && ! wp_verify_nonce( $nonce, 'add-tag' ) ) {
				return;
			}
		}

		$data = array(
			'include'                => isset( $_POST['wps_pepita_include'] ) ? sanitize_key( wp_unslash( (string) $_POST['wps_pepita_include'] ) ) : 'inherit',
			'include_children'       => ! empty( $_POST['wps_pepita_include_children'] ),
			'gtin_required'          => ! empty( $_POST['wps_pepita_gtin_required'] ),
			'price_adjustment_type'  => isset( $_POST['wps_pepita_price_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['wps_pepita_price_type'] ) ) : '',
			'price_adjustment_value' => isset( $_POST['wps_pepita_price_value'] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST['wps_pepita_price_value'] ) ) : 0,
			'shipping_delay'         => isset( $_POST['wps_pepita_shipping_delay'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wps_pepita_shipping_delay'] ) ) : '',
			'inventory_buffer'       => isset( $_POST['wps_pepita_inventory_buffer'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wps_pepita_inventory_buffer'] ) ) : '',
			'inventory_max_qty'      => isset( $_POST['wps_pepita_inventory_max_qty'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wps_pepita_inventory_max_qty'] ) ) : '',
			'commission_percent'     => isset( $_POST['wps_pepita_commission_percent'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wps_pepita_commission_percent'] ) ) : '',
			'min_margin'             => isset( $_POST['wps_pepita_min_margin'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wps_pepita_min_margin'] ) ) : '',
		);

		Category_Rules::save( $term_id, $data );
	}
}
