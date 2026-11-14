<?php
/**
 * Tools & Operational Reports View.
 *
 * Provides Configuration Profile Export/Import with Preview & Confirmation,
 * and Bounded Operational Reports.
 *
 * @package WPS\Pepita
 */

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Admin\Merchant_Profiles;
use WPS\Pepita\Admin\Operational_Reports;

defined( 'ABSPATH' ) || exit;

$days      = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30;
$summary   = Operational_Reports::get_summary( $days );
$user_id   = get_current_user_id();
$stage_key = 'wps_pepita_staged_profile_' . $user_id;
$staged    = ! empty( $_GET['wps_preview'] ) ? get_transient( $stage_key ) : null;
$preview   = is_array( $staged ) && isset( $staged['preview'] ) && is_array( $staged['preview'] ) ? $staged['preview'] : null;
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita &mdash; Eszközök és riportok', 'wp-pepita-sync' ); ?></h1>

	<div class="wps-grid">
		<?php if ( null !== $preview && ! empty( $staged['token'] ) ) : ?>
			<!-- Kártya: Profil Előnézet és Megerősítés -->
			<div class="wps-card" style="grid-column: 1 / -1; border-left: 4px solid #2271b1; background: #f6f7f7;">
				<h2><?php esc_html_e( 'Profil előnézet &mdash; Ellenőrzés megerősítés előtt', 'wp-pepita-sync' ); ?></h2>
				<p>
					<?php esc_html_e( 'Kérjük, ellenőrizze az alábbi módosításokat. A beállítások és kategóriaszabályok csak a „Módosítások jóváhagyása és alkalmazása” gombra kattintva lépnek érvénybe.', 'wp-pepita-sync' ); ?>
				</p>

				<div style="background: #fff; padding: 12px 16px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 15px;">
					<p style="margin: 4px 0;"><strong><?php esc_html_e( 'Profil neve:', 'wp-pepita-sync' ); ?></strong> <?php echo esc_html( $preview['profile_name'] ); ?></p>
					<p style="margin: 4px 0;"><strong><?php esc_html_e( 'Exportálás időpontja:', 'wp-pepita-sync' ); ?></strong> <?php echo esc_html( $preview['exported_at'] ); ?></p>
					<p style="margin: 4px 0;">
						<strong><?php esc_html_e( 'Adatintegritás (SHA-256 ellenőrző összeg):', 'wp-pepita-sync' ); ?></strong>
						<?php if ( true === $preview['checksum_valid'] ) : ?>
							<span style="color: #46b450; font-weight: bold;">✓ <?php esc_html_e( 'Sértetlen (ellenőrző összeg érvényes)', 'wp-pepita-sync' ); ?></span>
						<?php elseif ( false === $preview['checksum_valid'] ) : ?>
							<span style="color: #dc3232; font-weight: bold;">⚠ <?php esc_html_e( 'Eltérés észlelve (a fájl az exportálás óta módosulhatott)', 'wp-pepita-sync' ); ?></span>
						<?php else : ?>
							<span class="wps-muted"><?php esc_html_e( 'Nem tartalmaz ellenőrző összeget', 'wp-pepita-sync' ); ?></span>
						<?php endif; ?>
					</p>
				</div>

				<!-- Beállítások diff táblázat -->
				<h3><?php esc_html_e( 'Módosuló beállítások', 'wp-pepita-sync' ); ?> (<?php echo esc_html( (string) count( $preview['settings_diff'] ) ); ?>)</h3>
				<?php if ( empty( $preview['settings_diff'] ) ) : ?>
					<p class="wps-muted"><?php esc_html_e( 'Nincs módosuló beállítás (az áruház meglévő értékei megegyeznek a profilban lévőkkel).', 'wp-pepita-sync' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="margin-bottom: 20px;">
						<thead>
							<tr>
								<th style="width: 250px;"><?php esc_html_e( 'Beállítás kulcs', 'wp-pepita-sync' ); ?></th>
								<th><?php esc_html_e( 'Jelenlegi áruházi érték', 'wp-pepita-sync' ); ?></th>
								<th><?php esc_html_e( 'Új profil érték', 'wp-pepita-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $preview['settings_diff'] as $diff ) : ?>
								<tr>
									<td><code><?php echo esc_html( $diff['key'] ); ?></code></td>
									<td><pre style="margin: 0; font-size: 11px;"><?php echo esc_html( is_array( $diff['current'] ) ? wp_json_encode( $diff['current'] ) : (string) $diff['current'] ); ?></pre></td>
									<td><strong style="color: #2271b1;"><pre style="margin: 0; font-size: 11px;"><?php echo esc_html( is_array( $diff['proposed'] ) ? wp_json_encode( $diff['proposed'] ) : (string) $diff['proposed'] ); ?></pre></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<!-- Kategóriaszabályok diff táblázat -->
				<h3><?php esc_html_e( 'Módosuló kategóriaszabályok', 'wp-pepita-sync' ); ?> (<?php echo esc_html( (string) count( $preview['category_diff'] ) ); ?>)</h3>
				<?php if ( empty( $preview['category_diff'] ) ) : ?>
					<p class="wps-muted"><?php esc_html_e( 'Nincs módosuló kategóriaszabály.', 'wp-pepita-sync' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="margin-bottom: 20px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Kategória', 'wp-pepita-sync' ); ?></th>
								<th><?php esc_html_e( 'Slug', 'wp-pepita-sync' ); ?></th>
								<th><?php esc_html_e( 'Módosuló szabályok', 'wp-pepita-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $preview['category_diff'] as $cat_diff ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $cat_diff['name'] ); ?></strong></td>
									<td><code><?php echo esc_html( $cat_diff['slug'] ); ?></code></td>
									<td>
										<?php if ( ! empty( $cat_diff['rule_diffs'] ) ) : ?>
											<ul style="margin: 0; padding-left: 15px;">
												<?php foreach ( $cat_diff['rule_diffs'] as $r_diff ) : ?>
													<li>
														<code><?php echo esc_html( $r_diff['rule'] ); ?></code>:
														<del style="color: #646970;"><?php echo esc_html( '' === $r_diff['current'] ? 'alapértelmezett' : $r_diff['current'] ); ?></del>
														&rarr;
														<strong style="color: #2271b1;"><?php echo esc_html( $r_diff['proposed'] ); ?></strong>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<span class="wps-muted"><?php echo esc_html( $cat_diff['status_note'] ?? '' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<div style="display: flex; gap: 10px; margin-top: 20px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wps_pepita_confirm_import_profile" />
						<input type="hidden" name="confirm_token" value="<?php echo esc_attr( (string) $staged['token'] ); ?>" />
						<?php wp_nonce_field( 'wps_pepita_confirm_import_profile' ); ?>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Módosítások jóváhagyása és alkalmazása', 'wp-pepita-sync' ); ?>
						</button>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wps_pepita_cancel_import_profile" />
						<?php wp_nonce_field( 'wps_pepita_cancel_import_profile' ); ?>
						<button type="submit" class="button">
							<?php esc_html_e( 'Mégse (előnézet elvetése)', 'wp-pepita-sync' ); ?>
						</button>
					</form>
				</div>
			</div>
		<?php endif; ?>

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
				<?php esc_html_e( 'Töltsön fel egy korábban exportált Pepita konfigurációs profilt. A feltöltést követően előnézetben ellenőrizheti a változásokat a tényleges mentés előtt. A meglévő áruházi API kulcsok és titkos tokenek érintetlenek maradnak.', 'wp-pepita-sync' ); ?>
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
						<?php esc_html_e( 'Profil feltöltése és előnézet', 'wp-pepita-sync' ); ?>
					</button>
				</p>
			</form>
		</div>
	</div>

	<!-- Operációs összefoglaló kártyák -->
	<h2 style="margin-top: 30px;"><?php esc_html_e( 'Operációs Riport és Teljesítmény', 'wp-pepita-sync' ); ?> (<?php echo esc_html( (string) $days ); ?> <?php esc_html_e( 'nap', 'wp-pepita-sync' ); ?>)</h2>

	<?php if ( ! empty( $summary['is_partial_data'] ) ) : ?>
		<div class="notice notice-warning inline" style="margin-bottom: 20px;">
			<p>
				<strong><?php esc_html_e( 'Figyelem &mdash; Részleges adathalmaz:', 'wp-pepita-sync' ); ?></strong>
				<?php printf( esc_html__( 'Az operációs statisztika és az összesített GMV számítás a legutóbbi %d rekord alapján készült a rendszer memóriakorlátjának védelme érdekében. Nagy forgalmú áruházak esetén a teljes időszakra vetített adatok ennél magasabbak lehetnek.', 'wp-pepita-sync' ), (int) $summary['truncated_limit'] ); ?>
			</p>
		</div>
	<?php endif; ?>

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
				<dd>
					<strong><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $summary['total_gmv'] ) ) : esc_html( (string) $summary['total_gmv'] ); ?></strong>
					<?php if ( ! empty( $summary['gmv_truncated'] ) ) : ?>
						<span class="description" style="display: block; font-size: 11px;"><?php esc_html_e( '(Részleges összeg: a legutóbbi 1 000 rekord alapján korlátozva)', 'wp-pepita-sync' ); ?></span>
					<?php endif; ?>
				</dd>
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
