<?php
/**
 * Tools & Operational Reports View.
 *
 * Provides Configuration Profile Export/Import and Bounded Operational Reports.
 *
 * @package WPS\Pepita
 */

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Admin\Merchant_Profiles;
use WPS\Pepita\Admin\Operational_Reports;

defined( 'ABSPATH' ) || exit;

$days    = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30;
$summary = Operational_Reports::get_summary( $days );
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita &mdash; Eszközök és riportok', 'wp-pepita-sync' ); ?></h1>

	<div class="wps-grid">
		<!-- Kártya 1: Konfigurációs Profil Export -->
		<div class="wps-card">
			<h2><?php esc_html_e( 'Konfigurációs profil exportálása', 'wp-pepita-sync' ); ?></h2>
			<p>
				<?php esc_html_e( 'Exportálja az áruház Pepita beállításait (kategóriaszabályok, termék-felülbírálások, készlet- és árrésstratégia, attribútum-megfeleltetések) egy hordozható JSON profilfájlba más áruházak számára vagy biztonsági mentésként.', 'wp-pepita-sync' ); ?>
			</p>
			<p class="description">
				<strong><?php esc_html_e( 'Biztonsági garancia:', 'wp-pepita-sync' ); ?></strong>
				<?php esc_html_e( 'Az exportált profil szigorúan mentes minden API kulcstól, titkos tokentől, belső hálózati címtől és személyes adattól.', 'wp-pepita-sync' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 15px;">
				<input type="hidden" name="action" value="wps_pepita_export_profile" />
				<?php wp_nonce_field( 'wps_pepita_export_profile' ); ?>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Profil letöltése (.json)', 'wp-pepita-sync' ); ?>
				</button>
			</form>
		</div>

		<!-- Kártya 2: Konfigurációs Profil Import -->
		<div class="wps-card">
			<h2><?php esc_html_e( 'Konfigurációs profil importálása', 'wp-pepita-sync' ); ?></h2>
			<p>
				<?php esc_html_e( 'Töltsön fel vagy illesszen be egy korábban exportált Pepita konfigurációs profilt. A meglévő áruházi API kulcsok és titkos tokenek érintetlenek maradnak.', 'wp-pepita-sync' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top: 15px;">
				<input type="hidden" name="action" value="wps_pepita_import_profile" />
				<?php wp_nonce_field( 'wps_pepita_import_profile' ); ?>
				<p>
					<label for="profile_file"><strong><?php esc_html_e( 'JSON profilfájl kiválasztása:', 'wp-pepita-sync' ); ?></strong></label><br />
					<input type="file" id="profile_file" name="profile_file" accept=".json,application/json" />
				</p>
				<p style="margin-top: 10px;">
					<label>
						<input type="checkbox" name="import_categories" value="1" checked="checked" />
						<?php esc_html_e( 'Kategóriaszabályok importálása (slug alapú azonosítással)', 'wp-pepita-sync' ); ?>
					</label>
				</p>
				<p style="margin-top: 15px;">
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Profil importálása', 'wp-pepita-sync' ); ?>
					</button>
				</p>
			</form>
		</div>
	</div>

	<!-- Operációs összefoglaló kártyák -->
	<h2 style="margin-top: 30px;"><?php esc_html_e( 'Operációs Riport és Teljesítmény', 'wp-pepita-sync' ); ?> (<?php echo esc_html( (string) $days ); ?> <?php esc_html_e( 'nap', 'wp-pepita-sync' ); ?>)</h2>

	<div class="wps-grid">
		<div class="wps-card">
			<h3><?php esc_html_e( 'Rendelésfeldolgozási statisztika', 'wp-pepita-sync' ); ?></h3>
			<dl class="wps-stats">
				<dt><?php esc_html_e( 'Összes beérkezett értesítés:', 'wp-pepita-sync' ); ?></dt>
				<dd><strong><?php echo esc_html( (string) $summary['total_orders'] ); ?></strong></dd>

				<dt><?php esc_html_e( 'Sikeresen importálva:', 'wp-pepita-sync' ); ?></dt>
				<dd><span style="color: #46b450; font-weight: bold;"><?php echo esc_html( (string) $summary['completed_orders'] ); ?></span></dd>

				<dt><?php esc_html_e( 'Sikertelen kísérlet:', 'wp-pepita-sync' ); ?></dt>
				<dd><span style="color: #dc3232; font-weight: bold;"><?php echo esc_html( (string) $summary['failed_orders'] ); ?></span></dd>

				<dt><?php esc_html_e( 'Árva rendelések:', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) $summary['orphan_orders'] ); ?></dd>

				<dt><?php esc_html_e( 'Összesített GMV:', 'wp-pepita-sync' ); ?></dt>
				<dd><strong><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $summary['total_gmv'] ) ) : esc_html( (string) $summary['total_gmv'] ); ?></strong></dd>
			</dl>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 15px;">
				<input type="hidden" name="action" value="wps_pepita_export_report_csv" />
				<input type="hidden" name="days" value="<?php echo esc_attr( (string) $days ); ?>" />
				<?php wp_nonce_field( 'wps_pepita_export_report_csv' ); ?>
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Operációs riport letöltése (.csv, PII-mentes)', 'wp-pepita-sync' ); ?>
				</button>
			</form>
		</div>

		<div class="wps-card">
			<h3><?php esc_html_e( 'Gyakori feldolgozási hibák', 'wp-pepita-sync' ); ?></h3>
			<?php if ( empty( $summary['top_errors'] ) ) : ?>
				<p class="wps-muted"><?php esc_html_e( 'Nincs regisztrált hiba a vizsgált időszakban.', 'wp-pepita-sync' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Hibaüzenet (Személyes adatok védve)', 'wp-pepita-sync' ); ?></th>
							<th style="width: 80px; text-align: right;"><?php esc_html_e( 'Előfordulás', 'wp-pepita-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $summary['top_errors'] as $err ) : ?>
							<tr>
								<td><code><?php echo esc_html( $err['error'] ); ?></code></td>
								<td style="text-align: right;"><strong><?php echo esc_html( (string) $err['count'] ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
