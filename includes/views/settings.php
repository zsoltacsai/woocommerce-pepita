<?php
/**
 * Settings view.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Orders\Mapper;
use WPS\Pepita\Settings;

$settings = Settings::all();

$statuses = array();

if ( function_exists( 'wc_get_order_statuses' ) ) {
	foreach ( wc_get_order_statuses() as $key => $label ) {
		$statuses[ str_replace( 'wc-', '', $key ) ] = $label;
	}
}

if ( array() === $statuses ) {
	$statuses = array(
		'pending'    => 'Függőben lévő fizetés',
		'processing' => 'Feldolgozás alatt',
		'on-hold'    => 'Várakozik',
		'completed'  => 'Teljesítve',
	);
}

$payment_options = array(
	'' => __( '— Nincs megfeleltetés —', 'wp-pepita-sync' ),
);

if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
	foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
		$payment_options[ $gateway_id ] = $gateway->get_title() . ' (' . $gateway_id . ')';
	}
}

$payment_options['cod']  = $payment_options['cod'] ?? 'Utánvét (cod)';
$payment_options['bacs'] = $payment_options['bacs'] ?? 'Banki átutalás (bacs)';

$shipping_methods = array(
	'' => __( '— Nincs megfeleltetés —', 'wp-pepita-sync' ),
);

if ( function_exists( 'WC' ) && WC()->shipping() ) {
	foreach ( WC()->shipping()->get_shipping_methods() as $method_id => $method ) {
		$shipping_methods[ $method_id ] = $method->get_method_title() . ' (' . $method_id . ')';
	}
}

$shipping_methods['flat_rate'] = $shipping_methods['flat_rate'] ?? 'Flat rate (flat_rate)';

$field = static function ( string $key ) use ( $settings ) {
	return $settings[ $key ] ?? '';
};

$checked = static function ( string $key ) use ( $settings ): string {
	return ! empty( $settings[ $key ] ) ? 'checked="checked"' : '';
};
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita beállítások', 'wp-pepita-sync' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wps_pepita_save_settings" />
		<?php wp_nonce_field( 'wps_pepita_save_settings' ); ?>

		<div class="wps-card wps-card--wide" id="wps-catalog">
			<h2><?php esc_html_e( 'Katalógus', 'wp-pepita-sync' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Béta üzemmód (Feed-only)', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[beta_feed_only]" value="1" <?php echo $checked( 'beta_feed_only' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php disabled( defined( 'WPS_PEPITA_BETA_FEED_ONLY' ) ); ?> />
							<?php esc_html_e( 'Szigorú kategória engedélyezési lista és rendelésfogadás tiltása', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Bekapcsolva a plugin feed-only béta üzemmódban működik: a rendelés API teljesen inaktív (503), és a feedbe kizárólag az engedélyezett kategóriák termékei kerülhetnek be (a termékszintű engedélyezés nem bírálja felül a kategórialistát).', 'wp-pepita-sync' ); ?>
							<?php if ( defined( 'WPS_PEPITA_BETA_FEED_ONLY' ) ) : ?>
								<br><em><?php esc_html_e( 'A wp-config.php WPS_PEPITA_BETA_FEED_ONLY konstansa felülbírálja ezt a beállítást.', 'wp-pepita-sync' ); ?></em>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Általános szabály', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[default_included]" value="1" <?php echo $checked( 'default_included' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Minden termék szerepeljen a feedben, kivéve a kizártakat', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Kikapcsolva csak a kifejezetten felvett termékek és kategóriák kerülnek a feedbe.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Gyermek kategóriák öröklése', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[include_child_categories]" value="1" <?php echo $checked( 'include_child_categories' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'A felvételi szabály alapértelmezésben érvényes a gyermek kategóriákra', 'wp-pepita-sync' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Minőségi hibás termékek kizárása', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[exclude_quality_issues]" value="1" <?php echo $checked( 'exclude_quality_issues' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Hibás termékek automatikus kizárása a feedből', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Bekapcsolva a blokkoló minőségi hibákkal (pl. nem megfelelő képméret, hiányzó kötelező adatok) rendelkező termékek automatikusan kimaradnak mind a teljes, mind a készlet feedből.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Belső minőségi pontszám', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[show_internal_quality_score]" value="1" <?php echo $checked( 'show_internal_quality_score' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Mutassa a belső 0–100 diagnosztikai pontszámot a hibás termékek listájában', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Ez a saját ellenőrzéseinkből számított tájékoztató érték; nem Pepita-rangsor és nem írja felül a kötelező validációt.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-primary-category-strategy"><?php esc_html_e( 'Elsődleges kategória kiválasztási stratégia', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<select id="wps-primary-category-strategy" name="wps[primary_category_strategy]">
							<option value="approved_parent" <?php selected( (string) $field( 'primary_category_strategy' ), 'approved_parent' ); ?>><?php esc_html_e( 'Engedélyezett főkategória használata', 'wp-pepita-sync' ); ?></option>
							<option value="deepest" <?php selected( (string) $field( 'primary_category_strategy' ), 'deepest' ); ?>><?php esc_html_e( 'Legmélyebb kategória (alapértelmezett)', 'wp-pepita-sync' ); ?></option>
							<option value="lowest_id" <?php selected( (string) $field( 'primary_category_strategy' ), 'lowest_id' ); ?>><?php esc_html_e( 'Legalacsonyabb ID', 'wp-pepita-sync' ); ?></option>
							<option value="highest_id" <?php selected( (string) $field( 'primary_category_strategy' ), 'highest_id' ); ?>><?php esc_html_e( 'Legmagasabb ID', 'wp-pepita-sync' ); ?></option>
							<option value="alphabetical" <?php selected( (string) $field( 'primary_category_strategy' ), 'alphabetical' ); ?>><?php esc_html_e( 'Betűrendi sorrend', 'wp-pepita-sync' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Több kategóriához rendelt termék esetén pontosan egy elsődleges kategória hierarchia kerül a feedbe. A termékszintű beállítás és a Yoast/RankMath elsődleges kategória megelőzi ezt a stratégiát.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Engedélyezett termékállapotok', 'wp-pepita-sync' ); ?></th>
					<td>
						<?php
						$allowed = is_array( $field( 'allowed_product_statuses' ) ) ? $field( 'allowed_product_statuses' ) : array();
						$labels  = array(
							'publish' => __( 'Publikált', 'wp-pepita-sync' ),
							'private' => __( 'Privát', 'wp-pepita-sync' ),
							'draft'   => __( 'Piszkozat', 'wp-pepita-sync' ),
							'pending' => __( 'Jóváhagyásra vár', 'wp-pepita-sync' ),
						);
						?>
						<?php foreach ( $labels as $value => $label ) : ?>
							<label class="wps-inline-label">
								<input type="checkbox" name="wps[allowed_product_statuses][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $allowed, true ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'A Pepita felé csak a kiválasztott állapotú termékek jelennek meg.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-max-category-paths"><?php esc_html_e( 'Kategória útvonalak száma', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="1" max="20" id="wps-max-category-paths" name="wps[max_category_paths]" value="<?php echo esc_attr( (string) $field( 'max_category_paths' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Egy termékhez legfeljebb ennyi kategóriaútvonal kerül a feedbe.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-max-gallery"><?php esc_html_e( 'Galériaképek száma', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="0" max="50" id="wps-max-gallery" name="wps[max_gallery_images]" value="<?php echo esc_attr( (string) $field( 'max_gallery_images' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'A kiemelt kép mindig szerepel; ezen felül ennyi galériakép kerül a feedbe.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="wps-card wps-card--wide" id="wps-pricing">
			<h2><?php esc_html_e( 'Ár és áfa', 'wp-pepita-sync' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wps-currency"><?php esc_html_e( 'Pénznem', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="text" id="wps-currency" name="wps[currency]" value="<?php echo esc_attr( (string) $field( 'currency' ) ); ?>" class="small-text" maxlength="3" placeholder="<?php echo esc_attr( function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'HUF' ); ?>" />
						<p class="description"><?php esc_html_e( 'Hárombetűs ISO kód. Üresen hagyva a WooCommerce pénzneme érvényesül.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-price-tax-mode"><?php esc_html_e( 'WooCommerce termékárak értelmezése', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<select id="wps-price-tax-mode" name="wps[price_tax_mode]">
							<option value="auto" <?php selected( (string) $field( 'price_tax_mode' ), 'auto' ); ?>><?php esc_html_e( 'Automatikus (WooCommerce adóbeállítások szerint)', 'wp-pepita-sync' ); ?></option>
							<option value="gross" <?php selected( (string) $field( 'price_tax_mode' ), 'gross' ); ?>><?php esc_html_e( 'A forrásár már bruttó (nem adódik hozzá újra az áfa)', 'wp-pepita-sync' ); ?></option>
							<option value="net" <?php selected( (string) $field( 'price_tax_mode' ), 'net' ); ?>><?php esc_html_e( 'A forrásár nettó (a rendszer rászámolja az áfát)', 'wp-pepita-sync' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Ha a WooCommerce termékárak már bruttó fogyasztói árak (pl. FN-DECO 1300 Ft bruttó 27% áfával), válassza a "A forrásár már bruttó" opciót a kettős adóztatás elkerülése érdekében.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Áfa meghatározása', 'wp-pepita-sync' ); ?></th>
					<td>
						<label class="wps-inline-label">
							<input type="radio" name="wps[vat_mode]" value="auto" <?php checked( (string) $field( 'vat_mode' ), 'auto' ); ?> />
							<?php esc_html_e( 'WooCommerce adóosztály szerint', 'wp-pepita-sync' ); ?>
						</label>
						<label class="wps-inline-label">
							<input type="radio" name="wps[vat_mode]" value="fixed" <?php checked( (string) $field( 'vat_mode' ), 'fixed' ); ?> />
							<?php esc_html_e( 'Fix mérték', 'wp-pepita-sync' ); ?>
						</label>
						<input type="number" step="0.01" min="0" max="100" name="wps[vat_fixed]" value="<?php echo esc_attr( (string) $field( 'vat_fixed' ) ); ?>" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Automatikus módban a termék adóosztályához tartozó első adókulcs kerül a feedbe. Adó nélküli boltban 0.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Árkorrekció', 'wp-pepita-sync' ); ?></th>
					<td>
						<select name="wps[price_adjustment_type]">
							<option value="none" <?php selected( (string) $field( 'price_adjustment_type' ), 'none' ); ?>><?php esc_html_e( 'Nincs', 'wp-pepita-sync' ); ?></option>
							<option value="percent" <?php selected( (string) $field( 'price_adjustment_type' ), 'percent' ); ?>><?php esc_html_e( 'Százalék', 'wp-pepita-sync' ); ?></option>
							<option value="fixed" <?php selected( (string) $field( 'price_adjustment_type' ), 'fixed' ); ?>><?php esc_html_e( 'Fix összeg', 'wp-pepita-sync' ); ?></option>
						</select>
						<input type="number" step="0.01" name="wps[price_adjustment_value]" value="<?php echo esc_attr( (string) $field( 'price_adjustment_value' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Csak a Pepita feed árát módosítja. A WooCommerce ár változatlan marad.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kerekítés', 'wp-pepita-sync' ); ?></th>
					<td>
						<select name="wps[rounding]">
							<?php
							$rounding = array(
								'none'        => __( 'Nincs (2 tizedesjegy)', 'wp-pepita-sync' ),
								'nearest_1'   => __( 'Egész számra', 'wp-pepita-sync' ),
								'nearest_5'   => __( '5-re', 'wp-pepita-sync' ),
								'nearest_10'  => __( '10-re', 'wp-pepita-sync' ),
								'nearest_100' => __( '100-ra', 'wp-pepita-sync' ),
								'up_1'        => __( 'Felfelé egészre', 'wp-pepita-sync' ),
							);
							?>
							<?php foreach ( $rounding as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $field( 'rounding' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Beszerzési nettó ár', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[include_purchase_net]" value="1" <?php echo $checked( 'include_purchase_net' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'PurchaseNetPrice elem küldése (a bruttó árból számítva)', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Csak akkor küldje, ha a Pepita partnermegállapodás ezt kéri. A plugin nem talál ki beszerzési árat.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="wps-card wps-card--wide" id="wps-shipping">
			<h2><?php esc_html_e( 'Szállítás és garancia', 'wp-pepita-sync' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wps-shipping-delay"><?php esc_html_e( 'Szállítási idő (nap)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="0" max="60" id="wps-shipping-delay" name="wps[shipping_delay]" value="<?php echo esc_attr( (string) $field( 'shipping_delay' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'ShippingDelay elem értéke. Kategória és termék szinten felülírható.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-shipping-price"><?php esc_html_e( 'Szállítási díj', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="text" id="wps-shipping-price" name="wps[shipping_price]" value="<?php echo esc_attr( (string) $field( 'shipping_price' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Opcionális, fix szállítási díj a feedben. Üresen hagyva nem kerül a feedbe.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Garancia', 'wp-pepita-sync' ); ?></th>
					<td>
						<select name="wps[warranty_type]">
							<?php foreach ( array( 'None', 'Day', 'Week', 'Month', 'Year' ) as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( (string) $field( 'warranty_type' ), $type ); ?>><?php echo esc_html( $type ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="number" min="0" step="1" name="wps[warranty_duration]" value="<?php echo esc_attr( (string) $field( 'warranty_duration' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'A Pepita által elfogadott értékek: None, Day, Week, Month, Year. Termék szinten felülírható.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="wps-card wps-card--wide" id="wps-content">
			<h2><?php esc_html_e( 'Tartalom és azonosítók', 'wp-pepita-sync' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Leírás forrása', 'wp-pepita-sync' ); ?></th>
					<td>
						<select name="wps[description_source]">
							<option value="long" <?php selected( (string) $field( 'description_source' ), 'long' ); ?>><?php esc_html_e( 'Hosszú leírás (ha üres, rövid)', 'wp-pepita-sync' ); ?></option>
							<option value="short" <?php selected( (string) $field( 'description_source' ), 'short' ); ?>><?php esc_html_e( 'Rövid leírás', 'wp-pepita-sync' ); ?></option>
							<option value="long_short" <?php selected( (string) $field( 'description_source' ), 'long_short' ); ?>><?php esc_html_e( 'Hosszú + rövid leírás', 'wp-pepita-sync' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'A HTML eltávolításra kerül, a termék eredeti leírása nem változik.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Márka forrása', 'wp-pepita-sync' ); ?></th>
					<td>
						<select name="wps[brand_source]">
							<option value="meta" <?php selected( (string) $field( 'brand_source' ), 'meta' ); ?>><?php esc_html_e( 'Termék meta mező', 'wp-pepita-sync' ); ?></option>
							<option value="taxonomy" <?php selected( (string) $field( 'brand_source' ), 'taxonomy' ); ?>><?php esc_html_e( 'Taxonómia', 'wp-pepita-sync' ); ?></option>
							<option value="none" <?php selected( (string) $field( 'brand_source' ), 'none' ); ?>><?php esc_html_e( 'Nincs márka', 'wp-pepita-sync' ); ?></option>
						</select>
						<p>
							<label><?php esc_html_e( 'Meta kulcs:', 'wp-pepita-sync' ); ?>
								<input type="text" name="wps[brand_meta_key]" value="<?php echo esc_attr( (string) $field( 'brand_meta_key' ) ); ?>" class="regular-text" />
							</label>
						</p>
						<p>
							<label><?php esc_html_e( 'Taxonómia:', 'wp-pepita-sync' ); ?>
								<input type="text" name="wps[brand_taxonomy]" value="<?php echo esc_attr( (string) $field( 'brand_taxonomy' ) ); ?>" class="regular-text" />
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-manufacturer"><?php esc_html_e( 'Alapértelmezett gyártó', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="text" id="wps-manufacturer" name="wps[manufacturer_default]" value="<?php echo esc_attr( (string) $field( 'manufacturer_default' ) ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-attribute-name-map"><?php esc_html_e( 'Attribútumnév-megfeleltetések', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<textarea id="wps-attribute-name-map" name="wps[attribute_name_map]" class="large-text code" rows="5"><?php echo esc_textarea( (string) $field( 'attribute_name_map' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Opcionális, újrahasználható szabályok soronként: WooCommerce attribútumnév=Pepita attribútumnév (például pa_color=Szín). Csak a kimeneti nevet módosítja; az érték és a termékadat változatlan.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-gtin-keys"><?php esc_html_e( 'GTIN meta kulcsok', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="text" id="wps-gtin-keys" name="wps[gtin_meta_keys]" value="<?php echo esc_attr( (string) $field( 'gtin_meta_keys' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Vesszővel elválasztott meta kulcsok, ebben a sorrendben. Csak 8, 12, 13 vagy 14 jegyű, érvényes kód kerül a feedbe.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-gtin-cats"><?php esc_html_e( 'GTIN kötelező kategóriák', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="text" id="wps-gtin-cats" name="wps[gtin_required_categories]" value="<?php echo esc_attr( implode( ', ', array_map( 'strval', is_array( $field( 'gtin_required_categories' ) ) ? $field( 'gtin_required_categories' ) : array() ) ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Kategória ID-k vesszővel elválasztva. Ha egy termékhez nincs érvényes GTIN, az adott kategóriában a termék kimarad a feedből.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="wps-card wps-card--wide" id="wps-feed">
			<h2><?php esc_html_e( 'Feed generálás', 'wp-pepita-sync' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wps-batch-size"><?php esc_html_e( 'Kötegméret', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="10" max="1000" id="wps-batch-size" name="wps[batch_size]" value="<?php echo esc_attr( (string) $field( 'batch_size' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Egy kötegben feldolgozott termékek száma.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-batches"><?php esc_html_e( 'Kötegek futásonként', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="1" max="100" id="wps-batches" name="wps[batches_per_run]" value="<?php echo esc_attr( (string) $field( 'batches_per_run' ) ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-runtime"><?php esc_html_e( 'Maximális futásidő (mp)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="5" max="300" id="wps-runtime" name="wps[max_runtime]" value="<?php echo esc_attr( (string) $field( 'max_runtime' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'A generálás ezen idő után biztonságosan folytatható állapotban leáll, és a következő ütemezett futás folytatja.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-full-interval"><?php esc_html_e( 'Teljes feed gyakorisága (óra)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="1" max="168" id="wps-full-interval" name="wps[full_feed_interval]" value="<?php echo esc_attr( (string) $field( 'full_feed_interval' ) ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-stock-interval"><?php esc_html_e( 'Készletfeed gyakorisága (perc)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="5" max="1440" id="wps-stock-interval" name="wps[stock_feed_interval]" value="<?php echo esc_attr( (string) $field( 'stock_feed_interval' ) ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Éjszakai / terheléscsökkentett idősáv (Off-peak)', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[offpeak_schedule_enabled]" value="1" <?php echo $checked( 'offpeak_schedule_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Teljes feed újraépítésének korlátozása éjszakai órákra', 'wp-pepita-sync' ); ?>
						</label>
						<br><br>
						<span><?php esc_html_e( 'Idősáv:', 'wp-pepita-sync' ); ?></span>
						<input type="number" min="0" max="23" name="wps[offpeak_start_hour]" value="<?php echo esc_attr( (string) $field( 'offpeak_start_hour' ) ); ?>" class="small-text" />
						<span>:00 &mdash;</span>
						<input type="number" min="0" max="23" name="wps[offpeak_end_hour]" value="<?php echo esc_attr( (string) $field( 'offpeak_end_hour' ) ); ?>" class="small-text" />
						<span>:00</span>
						<p class="description"><?php esc_html_e( 'A teljes feed erőforrás-igényes újraépítése csak ebben az idősávban indul el automatikusan. A készlet- és árfeed ettől függetlenül, folyamatosan frissül.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kivezetett termékek kezelése', 'wp-pepita-sync' ); ?></th>
					<td>
						<select name="wps[removal_policy]">
							<option value="omit" <?php selected( (string) $field( 'removal_policy' ), 'omit' ); ?>><?php esc_html_e( 'Azonnal kimarad a feedből', 'wp-pepita-sync' ); ?></option>
							<option value="zero_stock" <?php selected( (string) $field( 'removal_policy' ), 'zero_stock' ); ?>><?php esc_html_e( 'Előbb 0 készlettel szerepel (türelmi idő)', 'wp-pepita-sync' ); ?></option>
						</select>
						<input type="number" min="0" max="60" name="wps[removal_grace_days]" value="<?php echo esc_attr( (string) $field( 'removal_grace_days' ) ); ?>" class="small-text" />
						<span><?php esc_html_e( 'nap', 'wp-pepita-sync' ); ?></span>
						<p class="description"><?php esc_html_e( 'A 0 készletes megjelenítés megkíméli a Pepita katalógusát a hirtelen eltűnéstől, de a termék nem lesz vásárolható.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Üres feed engedélyezése', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[allow_empty_feed]" value="1" <?php echo $checked( 'allow_empty_feed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Ha a generált feed 0 terméket tartalmaz, akkor is publikálható', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Biztonsági okból alapértelmezésben kikapcsolva: így egy hibás beállítás nem törli a Pepita katalógusát.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-drop-threshold"><?php esc_html_e( 'Termékcsökkenési biztonsági küszöb (%)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="5" max="95" id="wps-drop-threshold" name="wps[feed_safety_drop_threshold]" value="<?php echo esc_attr( (string) $field( 'feed_safety_drop_threshold' ) ); ?>" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Figyelmeztetést generál, ha az előző sikeres katalógushoz képest a termékek száma legalább ekkora arányban csökken (alapértelmezett: 20%).', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Publikálás blokkolása jelentős csökkenéskor', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[feed_safety_block_publication]" value="1" <?php echo $checked( 'feed_safety_block_publication' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Feed publikálás megszakítása, ha a termékcsökkenés eléri a küszöbértéket', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Védi a Pepita katalógust a véletlen tömeges terméktörléstől vagy hibás szűrés miatti kiürüléstől.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Variáció-módosulás figyelése', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[feed_safety_variation_change_warning]" value="1" <?php echo $checked( 'feed_safety_variation_change_warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Figyelmeztetés variációk vagy variációs azonosítók megváltozásakor', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Jelzi a változásjelentésben, ha a meglévő termékek variációs szerkezete módosult.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kézi jóváhagyás kockázatos változásokkor', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[feed_safety_require_approval]" value="1" <?php echo $checked( 'feed_safety_require_approval' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Feed publikálás előtti jóváhagyási kapu aktiválása, ha figyelmeztetés vagy eltérés lép fel', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Ha be van kapcsolva, a rendszer nem publikálja automatikusan a feedet figyelmeztetés esetén, hanem várakozó állapotba (staged) helyezi jóváhagyásig.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-price-drop"><?php esc_html_e( 'Drasztikus árcsökkenési küszöb (%)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="10" max="95" id="wps-price-drop" name="wps[feed_safety_max_price_drop_percent]" value="<?php echo esc_attr( (string) $field( 'feed_safety_max_price_drop_percent' ) ); ?>" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Figyelmeztetést generál, ha egy termék ára legalább ekkora arányban esik (alapértelmezett: 50%).', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Vészhelyzeti feed-leállítás', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[feed_emergency_disabled]" value="1" <?php echo $checked( 'feed_emergency_disabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php disabled( defined( 'WPS_PEPITA_FEED_EMERGENCY_DISABLED' ) || defined( 'WPS_PEPITA_FEED_DISABLED' ) ); ?> />
							<strong style="color: #d63638;"><?php esc_html_e( 'Minden feed-kiszolgálás azonnali felfüggesztése (HTTP 503)', 'wp-pepita-sync' ); ?></strong>
						</label>
						<p class="description">
							<?php esc_html_e( 'Vészhelyzet esetén azonnal letiltja a feedek elérését a partnerek felé anélkül, hogy a béta üzemmódot vagy a rendeléskezelést érintené.', 'wp-pepita-sync' ); ?>
							<?php if ( defined( 'WPS_PEPITA_FEED_EMERGENCY_DISABLED' ) || defined( 'WPS_PEPITA_FEED_DISABLED' ) ) : ?>
								<br><em><?php esc_html_e( 'A wp-config.php konstans felülbírálja ezt a beállítást.', 'wp-pepita-sync' ); ?></em>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Nyilvános feed', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[feed_public]" value="1" <?php echo $checked( 'feed_public' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'A feed token nélkül is letölthető', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Nem javasolt. Token nélkül bárki letöltheti a teljes katalógust.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-cache"><?php esc_html_e( 'Cache élettartam (mp)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="0" max="86400" id="wps-cache" name="wps[feed_cache_max_age]" value="<?php echo esc_attr( (string) $field( 'feed_cache_max_age' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'A feed válasz Cache-Control fejléce. Az ETag és a 304 válasz mindig aktív.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Feed URL azonosítók', 'wp-pepita-sync' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Teljes:', 'wp-pepita-sync' ); ?>
							<input type="text" name="wps[feed_full_slug]" value="<?php echo esc_attr( (string) $field( 'feed_full_slug' ) ); ?>" class="regular-text" />
						</label>
						<br />
						<label><?php esc_html_e( 'Készlet:', 'wp-pepita-sync' ); ?>
							<input type="text" name="wps[feed_stock_slug]" value="<?php echo esc_attr( (string) $field( 'feed_stock_slug' ) ); ?>" class="regular-text" />
						</label>
						<p class="description"><?php esc_html_e( 'A wps_feed query paraméter értékei. Módosítás után a Pepita felé is frissíteni kell az URL-t.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="wps-card wps-card--wide" id="wps-alerts">
			<h2><?php esc_html_e( 'Értesítések és felügyelet (Monitoring)', 'wp-pepita-sync' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'E-mail értesítések', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[feed_alert_email_enabled]" value="1" <?php echo $checked( 'feed_alert_email_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Riasztás küldése sikertelen futás, biztonsági kapu aktiválódása vagy jóváhagyási kérés esetén', 'wp-pepita-sync' ); ?>
						</label>
						<br /><br />
						<label for="wps-alert-email"><?php esc_html_e( 'Címzett e-mail címe:', 'wp-pepita-sync' ); ?></label>
						<input type="email" id="wps-alert-email" name="wps[feed_alert_email]" value="<?php echo esc_attr( (string) $field( 'feed_alert_email' ) ); ?>" class="regular-text" placeholder="admin@pelda.hu" />
						<p class="description"><?php esc_html_e( 'Hagyja üresen az alapértelmezett WordPress adminisztrátori cím használatához.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook riasztások (SSRF-védett)', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[feed_alert_webhook_enabled]" value="1" <?php echo $checked( 'feed_alert_webhook_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Telemetria továbbítása külső felügyeleti rendszernek (pl. Slack, Discord, Uptime monitor)', 'wp-pepita-sync' ); ?>
						</label>
						<br /><br />
						<label for="wps-alert-webhook"><?php esc_html_e( 'Webhook URL (HTTPS):', 'wp-pepita-sync' ); ?></label>
						<input type="url" id="wps-alert-webhook" name="wps[feed_alert_webhook_url]" value="<?php echo esc_attr( (string) $field( 'feed_alert_webhook_url' ) ); ?>" class="large-text" placeholder="https://pelda.hu/webhook" />
						<p class="description"><?php esc_html_e( 'Szigorúan csak publikus HTTPS cím engedélyezett (belső/loopback IP-k tiltva). A webhook kizárólag nem érzékeny műveleti telemetriát küld, jelszavakat vagy ügyféladatokat soha nem továbbít.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="wps-card wps-card--wide" id="wps-order-api">
			<h2><?php esc_html_e( 'Rendelés API', 'wp-pepita-sync' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Rendelésfogadás', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[order_api_enabled]" value="1" <?php echo $checked( 'order_api_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php disabled( Settings::is_beta_feed_only() ); ?> />
							<?php esc_html_e( 'Pepita rendelések automatikus fogadása', 'wp-pepita-sync' ); ?>
						</label>
						<?php if ( Settings::is_beta_feed_only() ) : ?>
							<p class="description" style="color: #d63638;">
								<strong><?php esc_html_e( 'Zárolva: a feed-only béta üzemmódban a rendelésfogadás szigorúan inaktív (HTTP 503).', 'wp-pepita-sync' ); ?></strong>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API kulcs', 'wp-pepita-sync' ); ?></th>
					<td>
						<code><?php echo esc_html( Admin::mask( Settings::secret( 'order_api_key' ) ) ); ?></code>
						<?php if ( Settings::using_constant( 'order_api_key' ) ) : ?>
							<p class="description"><?php esc_html_e( 'A WPS_PEPITA_ORDER_API_KEY konstans a wp-config.php fájlban van meghatározva.', 'wp-pepita-sync' ); ?></p>
						<?php else : ?>
							<p class="description">
								<a href="<?php echo esc_url( Admin::action_url( 'wps_pepita_regenerate_secret', array( 'secret' => 'order_api_key' ) ) ); ?>" class="wps-confirm" data-confirm="<?php echo esc_attr__( 'Új kulcs generálása? A régi kulcs azonnal érvénytelenné válik.', 'wp-pepita-sync' ); ?>">
									<?php esc_html_e( 'Új kulcs generálása', 'wp-pepita-sync' ); ?>
								</a>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-allowed-ips"><?php esc_html_e( 'Engedélyezett IP címek', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<textarea id="wps-allowed-ips" name="wps[order_api_allowed_ips]" class="regular-text" rows="3"><?php echo esc_textarea( (string) $field( 'order_api_allowed_ips' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Opcionális. Vesszővel vagy sortöréssel elválasztott IP címek. Üresen hagyva bármely IP hívhatja a végpontot (a kulcs továbbra is kötelező).', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-trusted-proxies"><?php esc_html_e( 'Megbízható proxyk', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<textarea id="wps-trusted-proxies" name="wps[order_api_trusted_proxies]" class="regular-text" rows="3"><?php echo esc_textarea( (string) $field( 'order_api_trusted_proxies' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Csak akkor töltse ki, ha a WordPress előtt reverse proxy vagy terheléselosztó áll. IP címek vagy CIDR tartományok (pl. 10.0.0.0/8). A rendszer csak ezekről a címekről fogadja el az X-Forwarded-For és X-Forwarded-Proto fejléceket; minden más kliens IP-jét a közvetlen kapcsolat adja.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'HTTPS kényszer', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[order_api_require_https]" value="1" <?php echo $checked( 'order_api_require_https' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Csak HTTPS kapcsolaton fogadjon rendelést', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'A TLS lezárása történhet a reverse proxyn is, de csak a fenti megbízható proxy listán szereplő címről elfogadott X-Forwarded-Proto: https fejléc számít. A kulcs csak titkosított kapcsolaton kerül összehasonlításra. Éles környezetben a TLS-t a proxy vagy a webszerver bejáratánál kell kikényszeríteni.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Létrehozott rendelésstátusz', 'wp-pepita-sync' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Fizetett:', 'wp-pepita-sync' ); ?>
							<select name="wps[order_api_status_paid]">
								<?php foreach ( $statuses as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $field( 'order_api_status_paid' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<br />
						<label><?php esc_html_e( 'Nem fizetett:', 'wp-pepita-sync' ); ?>
							<select name="wps[order_api_status_unpaid]">
								<?php foreach ( $statuses as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $field( 'order_api_status_unpaid' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<p class="description"><?php esc_html_e( 'A plugin soha nem állítja fizetettre a rendelést, ha a Pepita payment_status értéke nem „paid”.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fizetési mód megfeleltetés', 'wp-pepita-sync' ); ?></th>
					<td>
						<?php $payment_map = is_array( $field( 'payment_map' ) ) ? $field( 'payment_map' ) : array(); ?>
						<?php foreach ( Mapper::PAYMENT_LABELS as $mode => $label ) : ?>
							<p>
								<label>
									<?php echo esc_html( $label ); ?> (<?php echo esc_html( $mode ); ?>):
									<select name="wps[payment_map][<?php echo esc_attr( $mode ); ?>]">
										<?php foreach ( $payment_options as $value => $option_label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $payment_map[ $mode ] ?? '' ), $value ); ?>><?php echo esc_html( $option_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Szállítási mód megfeleltetés', 'wp-pepita-sync' ); ?></th>
					<td>
						<?php $shipping_map = is_array( $field( 'shipping_map' ) ) ? $field( 'shipping_map' ) : array(); ?>
						<?php foreach ( Mapper::SHIPPING_LABELS as $mode => $label ) : ?>
							<p>
								<label>
									<?php echo esc_html( $label ); ?> (<?php echo esc_html( $mode ); ?>):
									<select name="wps[shipping_map][<?php echo esc_attr( $mode ); ?>]">
										<?php foreach ( $shipping_methods as $value => $option_label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $shipping_map[ $mode ] ?? '' ), $value ); ?>><?php echo esc_html( $option_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'A szállítási mód csak megjelenítési célt szolgál a rendelésen; a szállítási díj a Pepita által küldött összeg.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-max-body"><?php esc_html_e( 'Maximális kérésméret (KB)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="16" max="8192" id="wps-max-body" name="wps[order_api_max_body_kb]" value="<?php echo esc_attr( (string) $field( 'order_api_max_body_kb' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'A csomagcímke base64 kódolva jelentős méretet is elérhet, ezért az alapérték 512 KB.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Csomagcímke mentése', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[order_api_save_label]" value="1" <?php echo $checked( 'order_api_save_label' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'A Pepita által küldött PDF csomagcímke mentése a rendeléshez', 'wp-pepita-sync' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-label-retention"><?php esc_html_e( 'Csomagcímke megőrzése (nap)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="1" max="3650" id="wps-label-retention" name="wps[order_api_label_retention_days]" value="<?php echo esc_attr( (string) $field( 'order_api_label_retention_days' ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'A megőrzési idő letelte után a csomagcímke PDF törlődik. A még nyitott (függőben lévő, feldolgozás alatt álló vagy várakozó) rendelések címkéi megmaradnak, amíg a rendelés le nem zárul.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Nyers adatcsomag megőrzése', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[order_api_keep_payloads]" value="1" <?php echo $checked( 'order_api_keep_payloads' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'A beérkezett JSON megőrzése a naplózási időtartamig (hibakereséshez)', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Alapértelmezésben kikapcsolva: a plugin csak a rendelésazonosítóhoz tartozó SHA-256 lenyomatot tárolja, a nyers, személyes adatokat tartalmazó csomagot nem. Bekapcsolva a tárolt adat a megőrzési idő letelte után automatikusan törlődik.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="wps-card wps-card--wide" id="wps-logging">
			<h2><?php esc_html_e( 'Naplózás és életciklus', 'wp-pepita-sync' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Naplószint', 'wp-pepita-sync' ); ?></th>
					<td>
						<select name="wps[log_level]">
							<option value="debug" <?php selected( (string) $field( 'log_level' ), 'debug' ); ?>>debug</option>
							<option value="info" <?php selected( (string) $field( 'log_level' ), 'info' ); ?>>info</option>
							<option value="warning" <?php selected( (string) $field( 'log_level' ), 'warning' ); ?>>warning</option>
							<option value="error" <?php selected( (string) $field( 'log_level' ), 'error' ); ?>>error</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wps-log-retention"><?php esc_html_e( 'Napló megőrzése (nap)', 'wp-pepita-sync' ); ?></label></th>
					<td>
						<input type="number" min="1" max="3650" id="wps-log-retention" name="wps[log_retention_days]" value="<?php echo esc_attr( (string) $field( 'log_retention_days' ) ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Export állapot mentése', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[record_export_meta]" value="1" <?php echo $checked( 'record_export_meta' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'Termékenként rögzítse az utolsó export állapotát', 'wp-pepita-sync' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Eltávolítás', 'wp-pepita-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wps[delete_data_on_uninstall]" value="1" <?php echo $checked( 'delete_data_on_uninstall' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php esc_html_e( 'A plugin törlésekor minden beállítás, napló és generált feed törlése', 'wp-pepita-sync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Alapértelmezésben kikapcsolva: a rendelések és a termék-meta adatok megmaradnak.', 'wp-pepita-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Beállítások mentése', 'wp-pepita-sync' ); ?></button>
		</p>
	</form>
</div>
