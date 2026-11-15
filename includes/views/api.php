<?php
/**
 * Order API view.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Installer;
use WPS\Pepita\Orders\Api_Controller;
use WPS\Pepita\Settings;

$endpoint  = rest_url( Api_Controller::REST_NAMESPACE . Api_Controller::REST_ROUTE );
$key       = Settings::secret( 'order_api_key' );
$from_const = Settings::using_constant( 'order_api_key' );
$enabled   = (bool) Settings::get( 'order_api_enabled', false );
$legacy_off  = (int) get_option( Installer::ORDER_API_LEGACY_DISABLED_OPTION, 0 );
$full_url  = '' !== $key ? $endpoint . '?apikey=' . rawurlencode( $key ) : '';
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita rendelés API', 'wp-pepita-sync' ); ?></h1>

	<p class="wps-lead">
		<?php esc_html_e( 'A Pepita a rendelés leadásakor azonnali HTTP POST kérést küld erre a végpontra. A plugin a rendelést WooCommerce rendelésként rögzíti, és az ismételt értesítéseket biztonságosan kiszűri.', 'wp-pepita-sync' ); ?>
	</p>

	<?php if ( $legacy_off > 0 && ! $enabled ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'A rendelésfogadás frissítéskor biztonsági okból ki lett kapcsolva (korábbi, nem igazolt automatikus engedélyezés). A meglévő API kulcs nem változott. Bekapcsolás: Beállítások → Rendelés API → „Rendelésfogadás engedélyezése”, mentés után.', 'wp-pepita-sync' ); ?>
		</p></div>
	<?php endif; ?>

	<div class="wps-grid">
		<div class="wps-card">
			<h2><?php esc_html_e( 'Végpont adatai', 'wp-pepita-sync' ); ?></h2>

			<p>
				<?php
				echo Admin::status_badge(
					$enabled,
					__( 'Rendelésfogadás aktív', 'wp-pepita-sync' ),
					__( 'Rendelésfogadás kikapcsolva', 'wp-pepita-sync' )
				);
				?>
			</p>

			<p><label><strong><?php esc_html_e( 'Végpont URL', 'wp-pepita-sync' ); ?></strong></label></p>
			<input type="text" class="widefat wps-copy" readonly value="<?php echo esc_attr( $endpoint ); ?>" />

			<p><label><strong><?php esc_html_e( 'Teljes URL a kulccsal (ezt add meg a Pepitának)', 'wp-pepita-sync' ); ?></strong></label></p>
			<?php if ( '' !== $full_url ) : ?>
				<input type="text" class="widefat wps-copy" readonly value="<?php echo esc_attr( $full_url ); ?>" />
				<p class="description">
					<?php esc_html_e( 'A kulcsot a Pepita a dokumentáció szerinti apikey query paraméterben küldi. A végpont a X-WPS-Api-Key fejlécet is elfogadja.', 'wp-pepita-sync' ); ?>
				</p>
			<?php else : ?>
				<p class="wps-error-text"><?php esc_html_e( 'Nincs beállítva API kulcs, ezért minden kérés elutasításra kerül.', 'wp-pepita-sync' ); ?></p>
			<?php endif; ?>

			<p class="wps-button-row">
				<?php if ( $from_const ) : ?>
					<span class="wps-muted"><?php esc_html_e( 'A kulcs a wp-config.php fájlban van meghatározva.', 'wp-pepita-sync' ); ?></span>
				<?php else : ?>
					<a class="button wps-confirm" data-confirm="<?php echo esc_attr__( 'Új kulcs generálása? A régi kulcs azonnal érvénytelenné válik, és a Pepita felé is frissíteni kell.', 'wp-pepita-sync' ); ?>" href="<?php echo esc_url( Admin::action_url( 'wps_pepita_regenerate_secret', array( 'secret' => 'order_api_key' ) ) ); ?>">
						<?php esc_html_e( 'Új API kulcs generálása', 'wp-pepita-sync' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>

		<div class="wps-card">
			<h2><?php esc_html_e( 'Kérés formátuma', 'wp-pepita-sync' ); ?></h2>
			<pre class="wps-code">POST <?php echo esc_html( $endpoint ); ?>?apikey=KULCS
Content-Type: application/json

{
  "origin": "pepita.hu",
  "id": "123456",
  "date": "2026-01-15 10:30:00",
  "payment_mode": "cod",
  "payment_status": "unpaid",
  "status": "pending",
  "customer_message": "",
  "courier_message": "",
  "total_shipping_price": 1490,
  "total_shipping_price_currency": "HUF",
  "voucher": 0,
  "delivery_mod": "gls",
  "customer": { "email": "vasarlo@example.com", "first_name": "Teszt", "last_name": "Vásárló" },
  "products": [
    { "id": "wps-123", "sku": "SKU-1", "currency": "HUF", "quantity": 1, "price": 4990, "vat": 27 }
  ]
}</pre>
			<p class="description">
				<?php esc_html_e( 'A plugin kizárólag a hivatalos Pepita rendeléstovábbítási specifikáció mezőit dolgozza fel. Ismeretlen mezőket figyelmen kívül hagy.', 'wp-pepita-sync' ); ?>
			</p>
		</div>

		<div class="wps-card">
			<h2><?php esc_html_e( 'Válasz formátuma', 'wp-pepita-sync' ); ?></h2>
			<pre class="wps-code">{
  "isError": false,
  "responseCode": 200,
  "message": "A rendelés sikeresen fogadva."
}</pre>
			<p class="description">
				<?php esc_html_e( 'Hiba esetén isError = true és a HTTP státusznak megfelelő responseCode kerül visszaadásra. Ismételt, már importált rendelés esetén a válasz sikeres, hogy a Pepita ne próbálkozzon újra.', 'wp-pepita-sync' ); ?>
			</p>
		</div>

		<div class="wps-card">
			<h2><?php esc_html_e( 'Biztonság', 'wp-pepita-sync' ); ?></h2>
			<ul class="wps-list">
				<li><?php esc_html_e( 'A kulcs összehasonlítása időállandó (hash_equals) módon történik.', 'wp-pepita-sync' ); ?></li>
				<li><?php esc_html_e( 'Beállítható IP engedélyezőlista és HTTPS kényszer.', 'wp-pepita-sync' ); ?></li>
				<li><?php esc_html_e( 'Kérésméret korlát és percenkénti kéréslimit védi a szervert.', 'wp-pepita-sync' ); ?></li>
				<li><?php esc_html_e( 'A naplókba soha nem kerül nyers API kulcs vagy csomagcímke.', 'wp-pepita-sync' ); ?></li>
				<li><?php esc_html_e( 'A rendelés létrehozása a WooCommerce CRUD API-n keresztül történik (HPOS kompatibilis).', 'wp-pepita-sync' ); ?></li>
			</ul>
		</div>
	</div>

	<div class="wps-card wps-card--wide">
		<h2><?php esc_html_e( 'Beállítások', 'wp-pepita-sync' ); ?></h2>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-settings#wps-order-api' ) ); ?>">
				<?php esc_html_e( 'Rendelés API beállítások módosítása', 'wp-pepita-sync' ); ?>
			</a>
		</p>
		<p class="description">
			<?php esc_html_e( 'A fizetési és szállítási módok WooCommerce megfeleltetése, a létrehozandó rendelésstátuszok és az IP engedélyezőlista a beállítások oldalon találhatók.', 'wp-pepita-sync' ); ?>
		</p>
	</div>
</div>
