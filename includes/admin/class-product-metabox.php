<?php
/**
 * Pepita metabox on the product edit screen.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Catalog\Product_Mapper;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Feed\Scheduler;
use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Product_Metabox {

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
	}

	public function add(): void {
		$screens = array( 'product' );

		foreach ( $screens as $screen ) {
			add_meta_box(
				'wps-pepita-product',
				__( 'Pepita piactér', 'wp-pepita-sync' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * @param \WP_Post $post
	 */
	public function render( $post ): void {
		if ( ! current_user_can( 'edit_product', (int) $post->ID ) ) {
			return;
		}

		$product_id = (int) $post->ID;
		$mode       = (string) get_post_meta( $product_id, Product_Selection::META_INCLUDE, true );
		$resolution = Product_Selection::resolve( $product_id );

		$meta = static function ( string $key ) use ( $product_id ): string {
			$value = get_post_meta( $product_id, $key, true );

			return is_scalar( $value ) ? (string) $value : '';
		};

		$export_id  = Product_Mapper::export_id( $product_id );
		$status     = $meta( Product_Mapper::META_LAST_STATUS );
		$last_at    = (int) $meta( Product_Mapper::META_LAST_AT );
		$last_error = $meta( Product_Mapper::META_LAST_MESSAGE );

		$source_labels = array(
			Product_Selection::SOURCE_PRODUCT_EXCLUDED => __( 'Termék szintű kizárás', 'wp-pepita-sync' ),
			Product_Selection::SOURCE_PRODUCT_INCLUDED => __( 'Termék szintű felvétel', 'wp-pepita-sync' ),
			Product_Selection::SOURCE_CATEGORY         => __( 'Kategória szabály', 'wp-pepita-sync' ),
			Product_Selection::SOURCE_DEFAULT          => __( 'Általános beállítás', 'wp-pepita-sync' ),
		);

		$source = $source_labels[ $resolution['source'] ] ?? $resolution['source'];
		?>
		<div class="wps-metabox" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
			<?php wp_nonce_field( 'wps_pepita_product_save', 'wps_pepita_product_nonce' ); ?>
			<p class="wps-metabox__status">
				<?php
				echo Admin::status_badge(
					(bool) $resolution['included'],
					__( 'A feedben szerepel', 'wp-pepita-sync' ),
					__( 'Nem szerepel a feedben', 'wp-pepita-sync' )
				);
				?>
				<span class="wps-metabox__source"><?php echo esc_html( $source ); ?></span>
			</p>

			<p class="wps-metabox__row">
				<label for="wps-include"><?php esc_html_e( 'Pepita besorolás', 'wp-pepita-sync' ); ?></label>
				<select id="wps-include" name="wps_include" class="widefat">
					<option value="default" <?php selected( $mode, '' ); ?>><?php esc_html_e( 'Kategória / általános szabály szerint', 'wp-pepita-sync' ); ?></option>
					<option value="include" <?php selected( $mode, Product_Selection::MODE_INCLUDE ); ?>><?php esc_html_e( 'Mindig szerepeljen a feedben', 'wp-pepita-sync' ); ?></option>
					<option value="exclude" <?php selected( $mode, Product_Selection::MODE_EXCLUDE ); ?>><?php esc_html_e( 'Soha ne szerepeljen a feedben', 'wp-pepita-sync' ); ?></option>
				</select>
			</p>

			<?php
			$assigned_terms = wp_get_post_terms( $product_id, \WPS\Pepita\Catalog\Category_Rules::TAXONOMY );
			$assigned_terms = is_array( $assigned_terms ) ? $assigned_terms : array();
			$primary_cat_id = (int) $meta( Product_Mapper::META_PRIMARY_CATEGORY );
			?>
			<p class="wps-metabox__row">
				<label for="wps-primary-category"><?php esc_html_e( 'Pepita elsődleges kategória', 'wp-pepita-sync' ); ?></label>
				<select id="wps-primary-category" name="wps_primary_category_id" class="widefat">
					<option value=""><?php esc_html_e( '-- Automatikus (stratégia szerint) --', 'wp-pepita-sync' ); ?></option>
					<?php foreach ( $assigned_terms as $term ) : ?>
						<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $primary_cat_id, (int) $term->term_id ); ?>>
							<?php echo esc_html( $term->name ); ?> (ID: <?php echo esc_html( (string) $term->term_id ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="wps-metabox__row">
				<label for="wps-gtin"><?php esc_html_e( 'GTIN / EAN / ISBN', 'wp-pepita-sync' ); ?></label>
				<input type="text" id="wps-gtin" name="wps_gtin" class="widefat" inputmode="numeric" value="<?php echo esc_attr( $meta( Product_Mapper::META_GTIN ) ); ?>" />
			</p>

			<p class="wps-metabox__row">
				<label for="wps-brand"><?php esc_html_e( 'Márka', 'wp-pepita-sync' ); ?></label>
				<input type="text" id="wps-brand" name="wps_brand" class="widefat" value="<?php echo esc_attr( $meta( Product_Mapper::META_BRAND ) ); ?>" />
			</p>

			<p class="wps-metabox__row">
				<label for="wps-manufacturer"><?php esc_html_e( 'Gyártó', 'wp-pepita-sync' ); ?></label>
				<input type="text" id="wps-manufacturer" name="wps_manufacturer" class="widefat" value="<?php echo esc_attr( $meta( Product_Mapper::META_MANUFACTURER ) ); ?>" />
			</p>

			<p class="wps-metabox__row">
				<label for="wps-mpn"><?php esc_html_e( 'Gyártói cikkszám (MPN)', 'wp-pepita-sync' ); ?></label>
				<input type="text" id="wps-mpn" name="wps_mpn" class="widefat" value="<?php echo esc_attr( $meta( Product_Mapper::META_MPN ) ); ?>" />
			</p>

			<p class="wps-metabox__row">
				<label for="wps-shipping-delay"><?php esc_html_e( 'Szállítási idő (nap)', 'wp-pepita-sync' ); ?></label>
				<input type="number" min="0" step="1" id="wps-shipping-delay" name="wps_shipping_delay" class="widefat" value="<?php echo esc_attr( $meta( Product_Mapper::META_SHIPPING_DELAY ) ); ?>" placeholder="<?php echo esc_attr( (string) Settings::get( 'shipping_delay', '' ) ); ?>" />
			</p>

			<p class="wps-metabox__row">
				<label for="wps-price-type"><?php esc_html_e( 'Árkorrekció', 'wp-pepita-sync' ); ?></label>
				<select id="wps-price-type" name="wps_price_adjustment_type" class="widefat">
					<option value="" <?php selected( $meta( Product_Mapper::META_PRICE_TYPE ), '' ); ?>><?php esc_html_e( 'Kategória / általános szerint', 'wp-pepita-sync' ); ?></option>
					<option value="none" <?php selected( $meta( Product_Mapper::META_PRICE_TYPE ), 'none' ); ?>><?php esc_html_e( 'Nincs korrekció', 'wp-pepita-sync' ); ?></option>
					<option value="percent" <?php selected( $meta( Product_Mapper::META_PRICE_TYPE ), 'percent' ); ?>><?php esc_html_e( 'Százalék', 'wp-pepita-sync' ); ?></option>
					<option value="fixed" <?php selected( $meta( Product_Mapper::META_PRICE_TYPE ), 'fixed' ); ?>><?php esc_html_e( 'Fix összeg', 'wp-pepita-sync' ); ?></option>
				</select>
			</p>

			<p class="wps-metabox__row">
				<label for="wps-price-value"><?php esc_html_e( 'Árkorrekció értéke', 'wp-pepita-sync' ); ?></label>
				<input type="number" step="0.01" id="wps-price-value" name="wps_price_adjustment_value" class="widefat" value="<?php echo esc_attr( $meta( Product_Mapper::META_PRICE_VALUE ) ); ?>" />
			</p>

			<p class="wps-metabox__row">
				<label for="wps-title-override"><?php esc_html_e( 'Pepita terméknév felülírása', 'wp-pepita-sync' ); ?></label>
				<textarea id="wps-title-override" name="wps_title_override" class="widefat" rows="2"><?php echo esc_textarea( $meta( Product_Mapper::META_TITLE_OVERRIDE ) ); ?></textarea>
			</p>

			<p class="wps-metabox__row">
				<label for="wps-description-override"><?php esc_html_e( 'Pepita leírás felülírása', 'wp-pepita-sync' ); ?></label>
				<textarea id="wps-description-override" name="wps_description_override" class="widefat" rows="4"><?php echo esc_textarea( $meta( Product_Mapper::META_DESCRIPTION_OVERRIDE ) ); ?></textarea>
			</p>

			<p class="wps-metabox__meta">
				<span><?php esc_html_e( 'Pepita azonosító:', 'wp-pepita-sync' ); ?> <code><?php echo esc_html( $export_id ); ?></code></span>
				<?php if ( '' !== $status ) : ?>
					<span>
						<?php esc_html_e( 'Utolsó export:', 'wp-pepita-sync' ); ?>
						<?php echo esc_html( 'exported' === $status ? __( 'sikeres', 'wp-pepita-sync' ) : __( 'hibás', 'wp-pepita-sync' ) ); ?>
						(<?php echo esc_html( Admin::date( $last_at ) ); ?>)
					</span>
				<?php endif; ?>
				<?php if ( '' !== $last_error ) : ?>
					<span class="wps-metabox__error"><?php echo esc_html( $last_error ); ?></span>
				<?php endif; ?>
			</p>

			<p>
				<button type="button" class="button wps-validate-product"><?php esc_html_e( 'Feed adatok ellenőrzése', 'wp-pepita-sync' ); ?></button>
			</p>

			<div class="wps-validation-result" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * Persist the metabox fields on the normal product save.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public function save( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['wps_pepita_product_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['wps_pepita_product_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'wps_pepita_product_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$field_map = array(
			'wps_include'                 => Product_Selection::META_INCLUDE,
			'wps_primary_category_id'     => Product_Mapper::META_PRIMARY_CATEGORY,
			'wps_gtin'                    => Product_Mapper::META_GTIN,
			'wps_brand'                   => Product_Mapper::META_BRAND,
			'wps_manufacturer'            => Product_Mapper::META_MANUFACTURER,
			'wps_mpn'                     => Product_Mapper::META_MPN,
			'wps_shipping_delay'          => Product_Mapper::META_SHIPPING_DELAY,
			'wps_price_adjustment_type'   => Product_Mapper::META_PRICE_TYPE,
			'wps_price_adjustment_value'  => Product_Mapper::META_PRICE_VALUE,
			'wps_title_override'          => Product_Mapper::META_TITLE_OVERRIDE,
			'wps_description_override'    => Product_Mapper::META_DESCRIPTION_OVERRIDE,
		);

		foreach ( $field_map as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $_POST ) ) {
				continue;
			}

			$value = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = is_array( $value ) ? '' : (string) $value;

			if ( 'wps_primary_category_id' === $field ) {
				$cat_id = (int) $value;
				if ( $cat_id > 0 ) {
					update_post_meta( $post_id, $meta_key, $cat_id );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}
				continue;
			}

			if ( 'wps_include' === $field ) {
				if ( in_array( $value, array( Product_Selection::MODE_INCLUDE, Product_Selection::MODE_EXCLUDE ), true ) ) {
					update_post_meta( $post_id, $meta_key, $value );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}

				continue;
			}

			if ( 'wps_price_adjustment_type' === $field && ! in_array( $value, array( 'none', 'fixed', 'percent' ), true ) ) {
				delete_post_meta( $post_id, $meta_key );

				continue;
			}

			$value = trim( $value );

			if ( in_array( $field, array( 'wps_shipping_delay', 'wps_price_adjustment_value' ), true ) && '' !== $value ) {
				$value = (string) max( 0, (float) str_replace( ',', '.', $value ) );
			}

			if ( 'wps_gtin' === $field ) {
				$value = \WPS\Pepita\Catalog\Description_Sanitizer::gtin( $value );
			}

			if ( 'wps_title_override' === $field ) {
				$value = \WPS\Pepita\Catalog\Description_Sanitizer::line( $value );
			}

			if ( 'wps_description_override' === $field ) {
				$value = \WPS\Pepita\Catalog\Description_Sanitizer::clean( $value );
			}

			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );

				continue;
			}

			update_post_meta( $post_id, $meta_key, $value );
		}

		Product_Selection::clear_cache();
		Scheduler::queue( Generator::TYPE_FULL );
		Scheduler::queue( Generator::TYPE_STOCK );
	}
}
