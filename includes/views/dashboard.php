<?php
/**
 * Dashboard view.
 *
 * @package WPS\Pepita
 *
 * @var array<string, mixed> $full
 * @var array<string, mixed> $stock
 * @var bool                 $full_running
 * @var bool                 $stock_running
 * @var array<int, mixed>    $errors
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Catalog\Product_Selection;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Settings;

$counts = Product_Selection::count_selected();
$full_ok  = ! empty( $full['last_success'] );
$stock_ok = ! empty( $stock['last_success'] );
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'WP Pepita Sync', 'wp-pepita-sync' ); ?></h1>
	<p class="wps-lead">
		<?php esc_html_e( 'WooCommerce termékkatalógus publikálása a Pepita piactérre, valamint Pepita rendelések automatikus fogadása.', 'wp-pepita-sync' ); ?>
	</p>

	<div class="wps-grid">
		<div class="wps-card">
			<h2><?php esc_html_e( 'Teljes feed', 'wp-pepita-sync' ); ?></h2>
			<p>
				<?php echo Admin::status_badge( $full_ok, __( 'Publikálva', 'wp-pepita-sync' ), __( 'Még nem készült el', 'wp-pepita-sync' ) ); ?>
				<?php if ( $full_running ) : ?>
					<span class="wps-badge wps-badge--warn"><?php esc_html_e( 'Generálás folyamatban', 'wp-pepita-sync' ); ?></span>
				<?php endif; ?>
			</p>
			<dl class="wps-stats">
				<dt><?php esc_html_e( 'Utolsó sikeres futás', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( Admin::ago( (int) ( $full['last_success'] ?? 0 ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Exportált termék', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) (int) ( $full['counts']['exported'] ?? 0 ) ); ?></dd>
				<dt><?php esc_html_e( 'Kihagyott (hibás) termék', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) (int) ( $full['counts']['invalid'] ?? 0 ) ); ?></dd>
				<dt><?php esc_html_e( 'Fájlméret', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( Admin::bytes( (int) ( $full['file_size'] ?? 0 ) ) ); ?></dd>
			</dl>
			<p>
				<a class="button button-primary wps-generate" data-type="<?php echo esc_attr( Generator::TYPE_FULL ); ?>" href="<?php echo esc_url( Admin::action_url( 'wps_pepita_generate', array( 'type' => Generator::TYPE_FULL ) ) ); ?>">
					<?php esc_html_e( 'Teljes feed generálása', 'wp-pepita-sync' ); ?>
				</a>
			</p>
			<?php if ( ! empty( $full['last_error'] ) ) : ?>
				<p class="wps-error-text"><?php echo esc_html( (string) $full['last_error'] ); ?></p>
			<?php endif; ?>
		</div>

		<div class="wps-card">
			<h2><?php esc_html_e( 'Készlet- és árfeed', 'wp-pepita-sync' ); ?></h2>
			<p>
				<?php echo Admin::status_badge( $stock_ok, __( 'Publikálva', 'wp-pepita-sync' ), __( 'Még nem készült el', 'wp-pepita-sync' ) ); ?>
				<?php if ( $stock_running ) : ?>
					<span class="wps-badge wps-badge--warn"><?php esc_html_e( 'Generálás folyamatban', 'wp-pepita-sync' ); ?></span>
				<?php endif; ?>
			</p>
			<dl class="wps-stats">
				<dt><?php esc_html_e( 'Utolsó sikeres futás', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( Admin::ago( (int) ( $stock['last_success'] ?? 0 ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Exportált termék', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) (int) ( $stock['counts']['exported'] ?? 0 ) ); ?></dd>
				<dt><?php esc_html_e( 'Fájlméret', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( Admin::bytes( (int) ( $stock['file_size'] ?? 0 ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Következő ütemezett futás', 'wp-pepita-sync' ); ?></dt>
				<dd>
					<?php
					$next = wp_next_scheduled( 'wps_pepita_generate_stock' );
					echo esc_html( $next ? Admin::date( (int) $next ) : '—' );
					?>
				</dd>
			</dl>
			<p>
				<a class="button wps-generate" data-type="<?php echo esc_attr( Generator::TYPE_STOCK ); ?>" href="<?php echo esc_url( Admin::action_url( 'wps_pepita_generate', array( 'type' => Generator::TYPE_STOCK ) ) ); ?>">
					<?php esc_html_e( 'Készletfeed generálása', 'wp-pepita-sync' ); ?>
				</a>
			</p>
			<?php if ( ! empty( $stock['last_error'] ) ) : ?>
				<p class="wps-error-text"><?php echo esc_html( (string) $stock['last_error'] ); ?></p>
			<?php endif; ?>
		</div>

		<div class="wps-card">
			<h2><?php esc_html_e( 'Katalógus', 'wp-pepita-sync' ); ?></h2>
			<dl class="wps-stats">
				<dt><?php esc_html_e( 'Feedbe kiválasztott termék', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) $counts['selected'] ); ?></dd>
				<dt><?php esc_html_e( 'Átvizsgált termék', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (string) $counts['scanned'] ); ?></dd>
				<dt><?php esc_html_e( 'Általános szabály', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( (bool) Settings::get( 'default_included', false ) ? __( 'Minden termék benne', 'wp-pepita-sync' ) : __( 'Csak a kijelöltek', 'wp-pepita-sync' ) ); ?></dd>
			</dl>
			<?php if ( $counts['truncated'] ) : ?>
				<p class="wps-muted"><?php esc_html_e( 'A számlálás az első 5000 termékre vonatkozik.', 'wp-pepita-sync' ); ?></p>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-products' ) ); ?>"><?php esc_html_e( 'Termékek kezelése', 'wp-pepita-sync' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-categories' ) ); ?>"><?php esc_html_e( 'Kategóriák', 'wp-pepita-sync' ); ?></a>
			</p>
		</div>

		<div class="wps-card">
			<h2><?php esc_html_e( 'Gyors műveletek', 'wp-pepita-sync' ); ?></h2>
			<ul class="wps-actions">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-feeds' ) ); ?>"><?php esc_html_e( 'Feed URL-ek és állapot', 'wp-pepita-sync' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-api' ) ); ?>"><?php esc_html_e( 'Rendelés API beállítása', 'wp-pepita-sync' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-settings' ) ); ?>"><?php esc_html_e( 'Plugin beállítások', 'wp-pepita-sync' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-logs' ) ); ?>"><?php esc_html_e( 'Napló megtekintése', 'wp-pepita-sync' ); ?></a></li>
			</ul>
		</div>

		<div class="wps-card">
			<h2><?php esc_html_e( 'Rendszerállapot', 'wp-pepita-sync' ); ?></h2>
			<table class="widefat striped wps-health">
				<tbody>
					<?php foreach ( \WPS\Pepita\Health::checks() as $check ) : ?>
						<tr>
							<td>
								<?php
								$health_badge = match ( $check['status'] ) {
									\WPS\Pepita\Health::STATUS_OK   => 'wps-badge--ok',
									\WPS\Pepita\Health::STATUS_WARN => 'wps-badge--warn',
									default                         => 'wps-badge--bad',
								};
								?>
								<span class="wps-badge <?php echo esc_attr( $health_badge ); ?>" title="<?php echo esc_attr( $check['hint'] ); ?>">
									<?php echo esc_html( $check['label'] ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $check['value'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ( array() !== $errors ) : ?>
		<div class="wps-card wps-card--wide">
			<h2><?php esc_html_e( 'Legutóbbi hibák', 'wp-pepita-sync' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Időpont', 'wp-pepita-sync' ); ?></th>
						<th><?php esc_html_e( 'Esemény', 'wp-pepita-sync' ); ?></th>
						<th><?php esc_html_e( 'Üzenet', 'wp-pepita-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $errors as $error ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $error['created_at'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $error['event'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $error['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wps-pepita-logs&level=error' ) ); ?>"><?php esc_html_e( 'Összes hiba', 'wp-pepita-sync' ); ?></a></p>
		</div>
	<?php endif; ?>
</div>
