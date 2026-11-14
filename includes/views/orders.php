<?php
/**
 * Orders view: list, filter, pagination, single-order diagnostics and operator actions.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Admin\Order_Admin;
use WPS\Pepita\Logger;
use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Redactor;
use WPS\Pepita\Settings;

$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$id     = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( 'view' === $action && '' !== $id ) :
	$claim = Order_Repository::get( $id );

	if ( null === $claim ) :
		?>
		<div class="wrap wps-wrap">
			<h1><?php esc_html_e( 'Pepita rendelés nem található', 'wp-pepita-sync' ); ?></h1>
			<p class="wps-lead"><?php esc_html_e( 'A megadott azonosítóhoz nem tartozik rögzített Pepita rendelés a nyilvántartásban.', 'wp-pepita-sync' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-orders' ) ); ?>" class="button">&larr; <?php esc_html_e( 'Vissza a rendelésekhez', 'wp-pepita-sync' ); ?></a></p>
		</div>
		<?php
		return;
	endif;

	$status      = (string) ( $claim['status'] ?? '' );
	$badge_class = match ( $status ) {
		Order_Repository::STATUS_COMPLETED  => 'wps-badge--ok',
		Order_Repository::STATUS_PROCESSING => 'wps-badge--warn',
		default                             => 'wps-badge--bad',
	};
	$status_label = match ( $status ) {
		Order_Repository::STATUS_COMPLETED  => __( 'Importálva', 'wp-pepita-sync' ),
		Order_Repository::STATUS_PROCESSING => __( 'Feldolgozás alatt', 'wp-pepita-sync' ),
		default                             => __( 'Sikertelen', 'wp-pepita-sync' ),
	};

	$wc_order_id = (int) ( $claim['wc_order_id'] ?? 0 );
	$wc_order    = $wc_order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $wc_order_id ) : null;
	$candidate_id = 0 === $wc_order_id ? Order_Admin::find_order_by_external_id( $id ) : 0;
	$orphan_order = $candidate_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $candidate_id ) : null;

	$raw_payload = (string) ( $claim['payload'] ?? '' );
	$has_payload = '' !== $raw_payload;

	$label_path = $wc_order instanceof \WC_Order ? (string) $wc_order->get_meta( Importer::META_PACKAGE_LABEL ) : '';
	$has_label  = '' !== $label_path && file_exists( $label_path );

	$logs = Logger::query(
		array(
			'search'   => $id,
			'per_page' => 20,
		)
	);
	?>
	<div class="wrap wps-wrap">
		<p style="margin-bottom: 12px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-orders' ) ); ?>" class="button button-secondary">
				&larr; <?php esc_html_e( 'Vissza a rendelések listájához', 'wp-pepita-sync' ); ?>
			</a>
		</p>

		<h1>
			<?php
			printf(
				/* translators: %s: Pepita order ID */
				esc_html__( 'Pepita rendelés: %s', 'wp-pepita-sync' ),
				'<code>' . esc_html( $id ) . '</code>'
			);
			?>
			<span class="wps-badge <?php echo esc_attr( $badge_class ); ?>" style="margin-left: 10px; font-size: 13px; vertical-align: middle;">
				<?php echo esc_html( $status_label ); ?>
			</span>
		</h1>

		<div class="wps-grid">
			<!-- Kártya 1: Nyilvántartási és claim adatok -->
			<div class="wps-card">
				<h2><?php esc_html_e( 'Rendelés nyilvántartási adatai', 'wp-pepita-sync' ); ?></h2>
				<dl class="wps-stats">
					<dt><?php esc_html_e( 'Pepita azonosító:', 'wp-pepita-sync' ); ?></dt>
					<dd><code><?php echo esc_html( $id ); ?></code></dd>

					<dt><?php esc_html_e( 'Piactér forrás:', 'wp-pepita-sync' ); ?></dt>
					<dd><?php echo esc_html( (string) ( $claim['origin'] ?? 'pepita.hu' ) ); ?></dd>

					<dt><?php esc_html_e( 'Állapot:', 'wp-pepita-sync' ); ?></dt>
					<dd><span class="wps-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $status_label ); ?></span></dd>

					<dt><?php esc_html_e( 'Próbálkozások száma:', 'wp-pepita-sync' ); ?></dt>
					<dd><?php echo esc_html( (string) (int) ( $claim['attempts'] ?? 1 ) ); ?></dd>

					<dt><?php esc_html_e( 'Beérkezett:', 'wp-pepita-sync' ); ?></dt>
					<dd><?php echo esc_html( (string) ( $claim['created_at'] ?? '—' ) ); ?></dd>

					<dt><?php esc_html_e( 'Utolsó frissítés:', 'wp-pepita-sync' ); ?></dt>
					<dd><?php echo esc_html( (string) ( $claim['updated_at'] ?? '—' ) ); ?></dd>

					<dt><?php esc_html_e( 'Adatcsomag lenyomat (SHA-256):', 'wp-pepita-sync' ); ?></dt>
					<dd><code style="font-size: 10px; word-break: break-all;"><?php echo esc_html( (string) ( $claim['payload_hash'] ?? '—' ) ); ?></code></dd>
				</dl>

				<?php if ( ! empty( $claim['last_error'] ) ) : ?>
					<div style="margin-top: 15px; padding: 10px; background: #fcf0f1; border-left: 4px solid #b32d2e;">
						<strong><?php esc_html_e( 'Utolsó hibaüzenet:', 'wp-pepita-sync' ); ?></strong><br>
						<span class="wps-error-text"><?php echo esc_html( (string) $claim['last_error'] ); ?></span>
					</div>
				<?php endif; ?>
			</div>

			<!-- Kártya 2: WooCommerce kapcsolat és Csomagcímke -->
			<div class="wps-card">
				<h2><?php esc_html_e( 'WooCommerce integráció', 'wp-pepita-sync' ); ?></h2>
				<dl class="wps-stats">
					<dt><?php esc_html_e( 'WooCommerce rendelés:', 'wp-pepita-sync' ); ?></dt>
					<dd>
						<?php if ( $wc_order instanceof \WC_Order ) : ?>
							<a href="<?php echo esc_url( Order_Admin::get_order_edit_url( $wc_order->get_id() ) ); ?>" class="button button-small" style="vertical-align: middle;">
								#<?php echo esc_html( (string) $wc_order->get_id() ); ?> &rarr;
							</a>
							<span style="margin-left: 8px; font-weight: normal;">
								(<?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $wc_order->get_status() ) : $wc_order->get_status() ); ?>,
								<?php echo esc_html( function_exists( 'wc_price' ) ? wc_price( $wc_order->get_total(), array( 'currency' => $wc_order->get_currency() ) ) : (string) $wc_order->get_total() ); ?>)
							</span>
						<?php elseif ( $orphan_order instanceof \WC_Order ) : ?>
							<span style="color: #b32d2e; font-weight: bold;">
								<?php
								printf(
									/* translators: %d: orphaned order ID */
									esc_html__( 'Árva rendelés észlelve: #%d', 'wp-pepita-sync' ),
									(int) $orphan_order->get_id()
								);
								?>
							</span>
							<p class="description">
								<?php esc_html_e( 'A rendelés létezik a WooCommerce-ben, de nincs a Pepita nyilvántartáshoz kapcsolva. A helyreállítás gombbal összeköthető.', 'wp-pepita-sync' ); ?>
							</p>
						<?php else : ?>
							<span class="wps-muted"><?php esc_html_e( 'Nincs társított WooCommerce rendelés.', 'wp-pepita-sync' ); ?></span>
						<?php endif; ?>
					</dd>

					<dt><?php esc_html_e( 'Csomagcímke állapota:', 'wp-pepita-sync' ); ?></dt>
					<dd>
						<?php if ( $has_label && $wc_order instanceof \WC_Order ) : ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wps_pepita_download_label&order_id=' . $wc_order->get_id() ), 'wps_pepita_download_label_' . $wc_order->get_id() ) ); ?>" class="button button-small button-secondary">
								<?php esc_html_e( 'Címke letöltése (PDF)', 'wp-pepita-sync' ); ?>
							</a>
						<?php else : ?>
							<span class="wps-muted"><?php esc_html_e( 'Nincs letölthető címke.', 'wp-pepita-sync' ); ?></span>
						<?php endif; ?>
					</dd>

					<dt><?php esc_html_e( 'Szállítás / Fizetés:', 'wp-pepita-sync' ); ?></dt>
					<dd>
						<?php
						$del = $wc_order instanceof \WC_Order ? (string) $wc_order->get_meta( Importer::META_DELIVERY_MODE ) : '';
						$pay = $wc_order instanceof \WC_Order ? (string) $wc_order->get_meta( Importer::META_PAYMENT_STATUS ) : '';
						echo esc_html( ( '' !== $del ? $del : '—' ) . ' / ' . ( '' !== $pay ? $pay : '—' ) );
						?>
					</dd>
				</dl>

				<hr style="margin: 18px 0;">

				<h2><?php esc_html_e( 'Kezelői műveletek', 'wp-pepita-sync' ); ?></h2>
				<div style="display: flex; gap: 10px; flex-wrap: wrap;">
					<!-- Újrafeldolgozás -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Biztosan újraindítja a rendelés feldolgozását?', 'wp-pepita-sync' ) ); ?>');">
						<?php wp_nonce_field( 'wps_pepita_retry_order_' . $id ); ?>
						<input type="hidden" name="action" value="wps_pepita_retry_order">
						<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
						<button type="submit" class="button button-primary" <?php disabled( ! $has_payload ); ?>>
							<?php esc_html_e( 'Rendelés újrafeldolgozása', 'wp-pepita-sync' ); ?>
						</button>
					</form>

					<!-- Helyreállítás / Újranyitás -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Biztosan végrehajtja a kapcsolat helyreállítását vagy a követelés újranyitását?', 'wp-pepita-sync' ) ); ?>');">
						<?php wp_nonce_field( 'wps_pepita_recover_order_' . $id ); ?>
						<input type="hidden" name="action" value="wps_pepita_recover_order">
						<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
						<button type="submit" class="button">
							<?php esc_html_e( 'Kapcsolat helyreállítása / Újranyitás', 'wp-pepita-sync' ); ?>
						</button>
					</form>
				</div>
				<?php if ( ! $has_payload ) : ?>
					<p class="description" style="margin-top: 8px;">
						<?php esc_html_e( 'Az újrafeldolgozás tiltva van, mert a nyers adatcsomag megőrzése ki volt kapcsolva vagy a megőrzési határidő lejárt. Kérjen újraküldést a Pepita rendszeréből.', 'wp-pepita-sync' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Kártya 3: Megtisztított nyers adatcsomag -->
		<div class="wps-card wps-card--wide">
			<h2><?php esc_html_e( 'Nyers Pepita adatcsomag (Személyes adatok maszkolva)', 'wp-pepita-sync' ); ?></h2>
			<?php if ( $has_payload ) : ?>
				<?php
				$decoded  = json_decode( $raw_payload, true );
				$redacted = is_array( $decoded ) ? Redactor::redact( $decoded ) : array( 'raw' => $raw_payload );
				$json_out = wp_json_encode( $redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				?>
				<pre style="background: #f6f7f7; padding: 12px; border: 1px solid #dcdcde; border-radius: 4px; overflow: auto; max-height: 400px; font-family: monospace; font-size: 12px;"><code><?php echo esc_html( (string) $json_out ); ?></code></pre>
				<p class="description">
					<?php esc_html_e( 'A személyes adatok (vásárló neve, számlázási és szállítási címe, telefonszáma, e-mail címe) a megtekintés előtt a GDPR-nak megfelelően automatikusan kitakarásra kerültek.', 'wp-pepita-sync' ); ?>
				</p>
			<?php else : ?>
				<p class="wps-muted">
					<?php esc_html_e( 'A nyers adatcsomag nem elérhető (a megőrzési idő lejárt vagy az adatmegőrzés ki volt kapcsolva). Az adatok sértetlenségét a fenti SHA-256 ellenőrzőösszeg tanúsítja.', 'wp-pepita-sync' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Kártya 4: Audit napló ehhez a rendeléshez -->
		<div class="wps-card wps-card--wide">
			<h2><?php esc_html_e( 'Audit naplóbejegyzések ehhez a rendeléshez', 'wp-pepita-sync' ); ?></h2>
			<table class="widefat striped wps-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Időpont', 'wp-pepita-sync' ); ?></th>
						<th><?php esc_html_e( 'Szint', 'wp-pepita-sync' ); ?></th>
						<th><?php esc_html_e( 'Kontextus', 'wp-pepita-sync' ); ?></th>
						<th><?php esc_html_e( 'Üzenet', 'wp-pepita-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $logs['items'] ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Nincs rögzített naplóbejegyzés ehhez a rendeléshez.', 'wp-pepita-sync' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $logs['items'] as $log_row ) : ?>
						<?php
						$log_level = (string) ( $log_row['level'] ?? 'info' );
						$log_badge = match ( $log_level ) {
							'error', 'critical', 'emergency' => 'wps-badge--bad',
							'warning'                        => 'wps-badge--warn',
							default                          => 'wps-badge--ok',
						};
						?>
						<tr>
							<td style="white-space: nowrap;"><?php echo esc_html( (string) ( $log_row['created_at'] ?? '' ) ); ?></td>
							<td><span class="wps-badge <?php echo esc_attr( $log_badge ); ?>"><?php echo esc_html( strtoupper( $log_level ) ); ?></span></td>
							<td><code><?php echo esc_html( (string) ( $log_row['event'] ?? $log_row['context'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $log_row['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
	return;
endif;

// --- Normál rendeléslista nézet ---
$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$result = Order_Repository::query(
	array(
		'status'   => $status,
		'search'   => $search,
		'page'     => $paged,
		'per_page' => 25,
	)
);

$pages = max( 1, (int) ceil( $result['total'] / 25 ) );
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita rendelések', 'wp-pepita-sync' ); ?></h1>

	<p class="wps-lead">
		<?php esc_html_e( 'A Pepita a rendelés leadása után azonnal meghívja az áruház API végpontját. Az itt látható rekordok biztosítják az ismételt értesítések kiszűrését és az auditálhatóságot.', 'wp-pepita-sync' ); ?>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wps-filters">
		<input type="hidden" name="page" value="wps-pepita-orders" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Pepita rendelésazonosító…', 'wp-pepita-sync' ); ?>" />
		<select name="status">
			<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'Minden állapot', 'wp-pepita-sync' ); ?></option>
			<option value="completed" <?php selected( $status, Order_Repository::STATUS_COMPLETED ); ?>><?php esc_html_e( 'Importálva', 'wp-pepita-sync' ); ?></option>
			<option value="processing" <?php selected( $status, Order_Repository::STATUS_PROCESSING ); ?>><?php esc_html_e( 'Feldolgozás alatt', 'wp-pepita-sync' ); ?></option>
			<option value="failed" <?php selected( $status, Order_Repository::STATUS_FAILED ); ?>><?php esc_html_e( 'Sikertelen', 'wp-pepita-sync' ); ?></option>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Szűrés', 'wp-pepita-sync' ); ?></button>
	</form>

	<table class="widefat striped wps-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Pepita azonosító', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'WooCommerce rendelés', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Állapot', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Próbálkozás', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Beérkezés', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Utolsó hiba', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Műveletek', 'wp-pepita-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $result['items'] ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Még nem érkezett Pepita rendelés.', 'wp-pepita-sync' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $result['items'] as $row ) : ?>
				<?php
				$row_id      = (string) ( $row['external_id'] ?? '' );
				$wc_order_id = (int) ( $row['wc_order_id'] ?? 0 );
				$row_status  = (string) ( $row['status'] ?? '' );
				$label       = match ( $row_status ) {
					Order_Repository::STATUS_COMPLETED  => __( 'Importálva', 'wp-pepita-sync' ),
					Order_Repository::STATUS_PROCESSING => __( 'Feldolgozás alatt', 'wp-pepita-sync' ),
					default                             => __( 'Sikertelen', 'wp-pepita-sync' ),
				};
				$badge       = match ( $row_status ) {
					Order_Repository::STATUS_COMPLETED  => 'wps-badge--ok',
					Order_Repository::STATUS_PROCESSING => 'wps-badge--warn',
					default                             => 'wps-badge--bad',
				};
				$view_url    = admin_url( 'admin.php?page=wps-pepita-orders&action=view&id=' . rawurlencode( $row_id ) );
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( $view_url ); ?>">
							<code><?php echo esc_html( $row_id ); ?></code>
						</a>
					</td>
					<td>
						<?php if ( $wc_order_id > 0 ) : ?>
							<a href="<?php echo esc_url( Order_Admin::get_order_edit_url( $wc_order_id ) ); ?>">#<?php echo esc_html( (string) $wc_order_id ); ?></a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td><span class="wps-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span></td>
					<td><?php echo esc_html( (string) (int) ( $row['attempts'] ?? 1 ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
					<td class="wps-error-text"><?php echo esc_html( (string) ( $row['last_error'] ?? '' ) ); ?></td>
					<td>
						<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">
							<?php esc_html_e( 'Részletek', 'wp-pepita-sync' ); ?>
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
							'base'      => add_query_arg(
								array(
									'page'   => 'wps-pepita-orders',
									'status' => $status,
									's'      => $search,
									'paged'  => '%#%',
								),
								admin_url( 'admin.php' )
							),
							'format'    => '',
							'current'   => $paged,
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

	<div class="wps-card wps-card--wide">
		<h2><?php esc_html_e( 'Adatmegőrzés és biztonság', 'wp-pepita-sync' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: payload retention days, 2: label retention days */
				esc_html__( 'A rendelésekhez tartozó nyers Pepita adatcsomag alapértelmezésben nem kerül tárolásra (csak a SHA-256 lenyomat). Ha a megőrzés be van kapcsolva, legfeljebb %1$d napig marad meg. A csomagcímke PDF-ek %2$d nap után törlődnek, kivéve a még nyitott rendelésekét. A rendelés és annak adatai a WooCommerce-ben maradnak.', 'wp-pepita-sync' ),
				(int) Settings::get( 'log_retention_days', 30 ),
				(int) Settings::get( 'order_api_label_retention_days', 30 )
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'A rendelések importálásakor a plugin nem küld e-mailt a vásárlónak, és nem indít fizetési átjárót.', 'wp-pepita-sync' ); ?>
		</p>
	</div>
</div>
