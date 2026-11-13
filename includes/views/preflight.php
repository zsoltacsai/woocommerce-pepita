<?php
/**
 * Preflight catalog audit view.
 *
 * @package WPS\Pepita
 *
 * @var array{summary: array<string, mixed>, items: array<int, array<string, mixed>>} $report
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;

$summary = $report['summary'] ?? array();
$items   = $report['items'] ?? array();
$cats    = $summary['category_stats'] ?? array( 'total' => 0, 'included' => 0, 'excluded' => 0, 'none' => 0 );

$tax_mode_labels = array(
	'auto'  => __( 'Automatikus (WooCommerce adóbeállítások)', 'wp-pepita-sync' ),
	'gross' => __( 'Bruttó forrásár (nincs kettős adóztatás)', 'wp-pepita-sync' ),
	'net'   => __( 'Nettó forrásár (+ áfa felszámolás)', 'wp-pepita-sync' ),
);

$cat_strategy_labels = array(
	'deepest'      => __( 'Legmélyebb kategória (alapértelmezett)', 'wp-pepita-sync' ),
	'lowest_id'    => __( 'Legalacsonyabb ID', 'wp-pepita-sync' ),
	'highest_id'   => __( 'Legmagasabb ID', 'wp-pepita-sync' ),
	'alphabetical' => __( 'Betűrendi sorrend', 'wp-pepita-sync' ),
);
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita import-előkészítés / Feed ellenőrzés', 'wp-pepita-sync' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Ez a jelentés a Pepita piactér követelményei (készletplafond, egyetlen elsődleges kategória hierarchia, bruttó/nettó adózási mód, WebP kompatibilitás, 300x300 képméret és cikkszám leképezés) alapján ellenőrzi a WooCommerce katalógust.', 'wp-pepita-sync' ); ?>
	</p>

	<div class="wps-cards">
		<div class="wps-card">
			<h2><?php esc_html_e( 'Katalógus lefedettség', 'wp-pepita-sync' ); ?></h2>
			<dl class="wps-stats">
				<dt><?php esc_html_e( 'Összes WooCommerce termék', 'wp-pepita-sync' ); ?></dt>
				<dd><strong><?php echo esc_html( (string) ( $summary['total_products'] ?? 0 ) ); ?></strong></dd>

				<dt><?php esc_html_e( 'Feedbe felvett (jogosult)', 'wp-pepita-sync' ); ?></dt>
				<dd><span class="wps-badge wps-badge--ok"><?php echo esc_html( (string) ( $summary['eligible_products'] ?? 0 ) ); ?></span></dd>

				<dt><?php esc_html_e( 'Kizárt termékek', 'wp-pepita-sync' ); ?></dt>
				<dd><span class="wps-badge wps-badge--muted"><?php echo esc_html( (string) ( $summary['excluded_products'] ?? 0 ) ); ?></span></dd>

				<dt><?php esc_html_e( 'Azonnal exportálható (hibátlan)', 'wp-pepita-sync' ); ?></dt>
				<dd><strong style="color: #46b450;"><?php echo esc_html( (string) ( $summary['ready_products'] ?? 0 ) ); ?></strong></dd>

				<dt><?php esc_html_e( 'Javítandó hibás termékek', 'wp-pepita-sync' ); ?></dt>
				<dd><span class="wps-badge wps-badge--error"><?php echo esc_html( (string) ( $summary['products_with_errors'] ?? 0 ) ); ?></span></dd>
			</dl>
		</div>

		<div class="wps-card">
			<h2><?php esc_html_e( 'Pepita kompatibilitási audit', 'wp-pepita-sync' ); ?></h2>
			<dl class="wps-stats">
				<dt><?php esc_html_e( 'WooCommerce árak értelmezése', 'wp-pepita-sync' ); ?></dt>
				<dd><code><?php echo esc_html( $tax_mode_labels[ $summary['tax_mode'] ?? 'auto' ] ?? (string) $summary['tax_mode'] ); ?></code></dd>

				<dt><?php esc_html_e( 'Elsődleges kategória stratégia', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( $cat_strategy_labels[ $summary['primary_cat_strategy'] ?? 'deepest' ] ?? (string) $summary['primary_cat_strategy'] ); ?></dd>

				<dt><?php esc_html_e( 'Béta üzemmód (szigorú allowlist)', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo ! empty( $summary['beta_feed_only'] ) ? '<span class="wps-badge wps-badge--warn">' . esc_html__( 'Aktív', 'wp-pepita-sync' ) . '</span>' : esc_html__( 'Nem aktív', 'wp-pepita-sync' ); ?></dd>

				<dt><?php esc_html_e( 'Kategóriák (összes / felvett / kizárt)', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( sprintf( '%d / %d / %d', $cats['total'], $cats['included'], $cats['excluded'] ) ); ?></dd>

				<dt><?php esc_html_e( 'Készletkorlátozás (999 999 cap)', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) ( $summary['capped_quantities'] ?? 0 ) ); ?> <?php esc_html_e( 'termék', 'wp-pepita-sync' ); ?></dd>

				<dt><?php esc_html_e( 'WebP képérintettség', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) ( $summary['webp_conversions'] ?? 0 ) ); ?> <?php esc_html_e( 'termék (JPEG-re konvertálva)', 'wp-pepita-sync' ); ?></dd>

				<dt><?php esc_html_e( 'Felbontási figyelmeztetés (< 300x300)', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) ( $summary['resolution_issues'] ?? 0 ) ); ?> <?php esc_html_e( 'termék', 'wp-pepita-sync' ); ?></dd>

				<dt><?php esc_html_e( 'Cikkszám (SKU) hiány (figyelmeztetés)', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) ( $summary['missing_skus'] ?? 0 ) ); ?> <?php esc_html_e( 'termék', 'wp-pepita-sync' ); ?></dd>

				<dt><?php esc_html_e( 'Érvényes GTIN / EAN megléte', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) ( $summary['available_gtins'] ?? 0 ) ); ?> <?php esc_html_e( 'termék', 'wp-pepita-sync' ); ?></dd>
			</dl>
		</div>
	</div>

	<div class="wps-card wps-card--wide" style="margin-top: 20px;">
		<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
			<h2 style="margin: 0;"><?php esc_html_e( 'Észlelt eltérések és figyelmeztetések', 'wp-pepita-sync' ); ?> (<?php echo esc_html( (string) count( $items ) ); ?>)</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0;">
				<input type="hidden" name="action" value="wps_pepita_export_preflight" />
				<?php wp_nonce_field( 'wps_pepita_export_preflight' ); ?>
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Teljes ellenőrzési lista letöltése (CSV)', 'wp-pepita-sync' ); ?>
				</button>
			</form>
		</div>

		<?php if ( array() === $items ) : ?>
			<p style="color: #46b450; font-weight: bold;">
				<?php esc_html_e( 'Minden jogosult termék teljes mértékben megfelel a Pepita feed követelményeinek! Nincs észlelt hiba.', 'wp-pepita-sync' ); ?>
			</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 80px;"><?php esc_html_e( 'ID', 'wp-pepita-sync' ); ?></th>
						<th style="width: 140px;"><?php esc_html_e( 'Cikkszám (SKU)', 'wp-pepita-sync' ); ?></th>
						<th><?php esc_html_e( 'Terméknév', 'wp-pepita-sync' ); ?></th>
						<th><?php esc_html_e( 'Elsődleges kategória', 'wp-pepita-sync' ); ?></th>
						<th style="width: 110px;"><?php esc_html_e( 'Súlyosság', 'wp-pepita-sync' ); ?></th>
						<th style="width: 150px;"><?php esc_html_e( 'Kód', 'wp-pepita-sync' ); ?></th>
						<th><?php esc_html_e( 'Részletek / Üzenet', 'wp-pepita-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $items, 0, 100 ) as $row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $row['id'] ) ?: '#' ); ?>" target="_blank">
									#<?php echo esc_html( (string) $row['id'] ); ?>
								</a>
							</td>
							<td><code><?php echo esc_html( (string) ( $row['sku'] ?: '-' ) ); ?></code></td>
							<td><strong><?php echo esc_html( (string) $row['name'] ); ?></strong></td>
							<td><small><?php echo esc_html( (string) ( $row['primary_category'] ?: '—' ) ); ?></small></td>
							<td>
								<?php if ( 'error' === $row['severity'] ) : ?>
									<span class="wps-badge wps-badge--error"><?php esc_html_e( 'Hiba', 'wp-pepita-sync' ); ?></span>
								<?php else : ?>
									<span class="wps-badge wps-badge--warn"><?php esc_html_e( 'Figyelmeztetés', 'wp-pepita-sync' ); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( (string) $row['code'] ); ?></code></td>
							<td><?php echo esc_html( (string) $row['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $items ) > 100 ) : ?>
				<p class="description" style="margin-top: 10px;">
					<?php echo esc_html( sprintf( __( 'A táblázat az első 100 tételt mutatja. A teljes lista (%d tétel) a fenti gombbal tölthető le CSV formátumban.', 'wp-pepita-sync' ), count( $items ) ) ); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
