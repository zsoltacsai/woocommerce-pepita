<?php
/**
 * Feeds view.
 *
 * @package WPS\Pepita
 *
 * @var array<string, mixed> $full
 * @var array<string, mixed> $stock
 * @var bool                 $full_running
 * @var bool                 $stock_running
 * @var bool                 $full_exists
 * @var bool                 $stock_exists
 * @var string               $full_url
 * @var string               $stock_url
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Feed\Catalog_Diff;
use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Settings;

$render_feed_card = static function ( string $type, array $state, bool $running, bool $exists, string $url ) use ( $full_url, $stock_url ) {
	$is_full = Generator::TYPE_FULL === $type;
	$title   = $is_full ? __( 'Teljes termékfeed', 'wp-pepita-sync' ) : __( 'Készlet- és árfeed', 'wp-pepita-sync' );
	$counts  = is_array( $state['counts'] ?? null ) ? $state['counts'] : array();
	?>
	<div class="wps-card">
		<h2><?php echo esc_html( $title ); ?></h2>

		<p>
			<?php
			echo Admin::status_badge(
				! empty( $state['last_success'] ),
				__( 'Publikálva', 'wp-pepita-sync' ),
				__( 'Nincs publikált feed', 'wp-pepita-sync' )
			);
			?>
			<?php if ( $running ) : ?>
				<span class="wps-badge wps-badge--warn"><?php esc_html_e( 'Generálás folyamatban', 'wp-pepita-sync' ); ?></span>
			<?php endif; ?>
			<?php if ( ! $exists ) : ?>
				<span class="wps-badge wps-badge--muted"><?php esc_html_e( 'Fájl nem található', 'wp-pepita-sync' ); ?></span>
			<?php endif; ?>
		</p>

		<dl class="wps-stats">
			<dt><?php esc_html_e( 'Utolsó sikeres futás', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( Admin::date( (int) ( $state['last_success'] ?? 0 ) ) ); ?> <span class="wps-muted">(<?php echo esc_html( Admin::ago( (int) ( $state['last_success'] ?? 0 ) ) ); ?>)</span></dd>
			<dt><?php esc_html_e( 'Utolsó kísérlet', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( Admin::date( (int) ( $state['last_attempt'] ?? 0 ) ) ); ?></dd>
			<dt><?php esc_html_e( 'Exportált termék', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $counts['exported'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'Kihagyott (hibás)', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $counts['invalid'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'Kizárt termék', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $counts['excluded'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'Fájlméret', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( Admin::bytes( (int) ( $state['file_size'] ?? 0 ) ) ); ?></dd>
			<dt><?php esc_html_e( 'Futásidő', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( sprintf( '%d mp', (int) ( $state['duration'] ?? 0 ) ) ); ?></dd>
			<dt><?php esc_html_e( 'Feed URL', 'wp-pepita-sync' ); ?></dt>
			<dd>
				<input type="text" class="wps-copy" readonly value="<?php echo esc_attr( $url ); ?>" />
			</dd>
		</dl>

		<?php if ( ! empty( $state['last_error'] ) ) : ?>
			<p class="wps-error-text"><?php echo esc_html( (string) $state['last_error'] ); ?></p>
		<?php endif; ?>

		<p class="wps-button-row">
			<a class="button button-primary wps-generate" data-type="<?php echo esc_attr( $type ); ?>" href="<?php echo esc_url( Admin::action_url( 'wps_pepita_generate', array( 'type' => $type ) ) ); ?>">
				<?php echo esc_html( $is_full ? __( 'Teljes feed generálása', 'wp-pepita-sync' ) : __( 'Készletfeed generálása', 'wp-pepita-sync' ) ); ?>
			</a>
			<?php if ( $exists ) : ?>
				<a class="button" href="<?php echo esc_url( Admin::action_url( 'wps_pepita_download', array( 'type' => $type ) ) ); ?>">
					<?php esc_html_e( 'Letöltés', 'wp-pepita-sync' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Megnyitás', 'wp-pepita-sync' ); ?>
				</a>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $state['errors'] ) && is_array( $state['errors'] ) ) : ?>
			<details class="wps-details">
				<summary><?php esc_html_e( 'Kihagyott termékek az utolsó futásból', 'wp-pepita-sync' ); ?></summary>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Termék', 'wp-pepita-sync' ); ?></th>
							<th><?php esc_html_e( 'Pepita azonosító', 'wp-pepita-sync' ); ?></th>
							<th><?php esc_html_e( 'Hiba', 'wp-pepita-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $state['errors'] as $error ) : ?>
							<tr>
								<td>
									<?php if ( ! empty( $error['product_id'] ) ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $error['product_id'] ) ); ?>">#<?php echo esc_html( (string) (int) $error['product_id'] ); ?></a>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( (string) ( $error['export_id'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( (string) ( $error['message'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita feedek', 'wp-pepita-sync' ); ?></h1>

	<?php if ( Settings::is_beta_feed_only() ) : ?>
		<div class="notice notice-info inline" style="border-left-color: #2271b1; background: #f0f6fc; padding: 12px 16px; margin: 15px 0;">
			<h3 style="margin: 0 0 6px 0; color: #135e96;">
				<span class="dashicons dashicons-tag" style="font-size: 18px; line-height: 18px;"></span>
				<?php esc_html_e( 'Béta Feed-Only Konfiguráció', 'wp-pepita-sync' ); ?>
			</h3>
			<p style="margin: 0 0 4px 0;">
				<?php esc_html_e( 'A feed generálás és időzítés aktív. A feed URL-ek token-védettek. A rendelésfogadás teljesen le van tiltva. Csak a kijelölt kategóriák termékei kerülnek exportálásra.', 'wp-pepita-sync' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<p class="wps-lead">
		<?php esc_html_e( 'A feedek előre, ütemezetten készülnek el, és a Pepita az alábbi URL-ekről tölti le őket. A készletfeed csak a legutóbbi teljes feeddel azonos kínálatot tartalmazhat.', 'wp-pepita-sync' ); ?>
	</p>

	<div class="wps-notice wps-notice--info">
		<p>
			<strong><?php esc_html_e( 'Fontos:', 'wp-pepita-sync' ); ?></strong>
			<?php esc_html_e( 'A Pepita felé ezeket az URL-eket kell megadni a partneri felületen. A token védi a feedet, ezért az URL-t bizalmasan kezelje.', 'wp-pepita-sync' ); ?>
		</p>
	</div>

	<div class="wps-grid">
		<?php
		$render_feed_card( Generator::TYPE_FULL, $full, $full_running, $full_exists, $full_url );
		$render_feed_card( Generator::TYPE_STOCK, $stock, $stock_running, $stock_exists, $stock_url );
		?>
	</div>

	<?php
	$diff = Catalog_Diff::get_cached_diff();
	?>
	<div class="wps-card wps-card--wide">
		<h2><?php esc_html_e( 'Katalógus változásjelentés', 'wp-pepita-sync' ); ?></h2>
		<p>
			<?php
			$status_badge = match ( $diff['status'] ?? 'no_current' ) {
				'ok'      => '<span class="wps-badge wps-badge--ok">' . esc_html__( 'Stabil', 'wp-pepita-sync' ) . '</span>',
				'warn'    => '<span class="wps-badge wps-badge--warn">' . esc_html__( 'Figyelmeztetés', 'wp-pepita-sync' ) . '</span>',
				'fail'    => '<span class="wps-badge wps-badge--bad">' . esc_html__( 'Kritikus eltérés', 'wp-pepita-sync' ) . '</span>',
				'initial' => '<span class="wps-badge wps-badge--info">' . esc_html__( 'Kezdő állapot', 'wp-pepita-sync' ) . '</span>',
				default   => '<span class="wps-badge wps-badge--muted">' . esc_html__( 'Nincs adat', 'wp-pepita-sync' ) . '</span>',
			};
			echo $status_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<?php if ( ! empty( $diff['compared_at'] ) ) : ?>
				<span class="wps-muted"><?php echo esc_html( sprintf( /* translators: %s: relative time */ __( 'Összehasonlítva: %s', 'wp-pepita-sync' ), Admin::ago( (int) $diff['compared_at'] ) ) ); ?></span>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $diff['warnings'] ) ) : ?>
			<div class="wps-notice wps-notice--warn">
				<?php foreach ( (array) $diff['warnings'] as $warning ) : ?>
					<p><strong><?php esc_html_e( 'Figyelem:', 'wp-pepita-sync' ); ?></strong> <?php echo esc_html( (string) $warning ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<dl class="wps-stats wps-stats--inline">
			<dt><?php esc_html_e( 'Jelenlegi aktív termékek', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $diff['current_count'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'Előző aktív termékek', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $diff['previous_count'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'Új termékek', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $diff['added_count'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'Eltávolított termékek', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $diff['removed_count'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'Árváltozások', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $diff['price_changed_count'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'Variáció-módosulások', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $diff['variation_changed_count'] ?? 0 ) ); ?></dd>
			<dt><?php esc_html_e( 'ÁFA / Deviza változások', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( (string) (int) ( $diff['tax_currency_changed_count'] ?? 0 ) ); ?></dd>
			<?php if ( (float) ( $diff['drop_percent'] ?? 0 ) > 0 ) : ?>
				<dt><?php esc_html_e( 'Csökkenés aránya', 'wp-pepita-sync' ); ?></dt>
				<dd><?php echo esc_html( sprintf( '%.1f%%', (float) $diff['drop_percent'] ) ); ?></dd>
			<?php endif; ?>
		</dl>

		<?php if ( ! empty( $diff['samples']['removed'] ) || ! empty( $diff['samples']['price_changed'] ) || ! empty( $diff['samples']['variation_changed'] ) ) : ?>
			<details class="wps-details">
				<summary><?php esc_html_e( 'Részletes változásminták megtekintése', 'wp-pepita-sync' ); ?></summary>
				<?php if ( ! empty( $diff['samples']['removed'] ) ) : ?>
					<h4 style="margin: 1em 0 0.5em;"><?php esc_html_e( 'Eltávolított termékek (minta)', 'wp-pepita-sync' ); ?></h4>
					<ul>
						<?php foreach ( $diff['samples']['removed'] as $item ) : ?>
							<li>#<?php echo esc_html( (string) $item['pid'] ); ?>: <?php echo esc_html( $item['name'] ); ?> (<code><?php echo esc_html( $item['eid'] ); ?></code>)</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $diff['samples']['price_changed'] ) ) : ?>
					<h4 style="margin: 1em 0 0.5em;"><?php esc_html_e( 'Árváltozások (minta)', 'wp-pepita-sync' ); ?></h4>
					<ul>
						<?php foreach ( $diff['samples']['price_changed'] as $item ) : ?>
							<li>#<?php echo esc_html( (string) $item['pid'] ); ?>: <?php echo esc_html( $item['name'] ); ?> &mdash; <?php echo esc_html( (string) $item['old_price'] ); ?> &rarr; <?php echo esc_html( (string) $item['new_price'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $diff['samples']['variation_changed'] ) ) : ?>
					<h4 style="margin: 1em 0 0.5em;"><?php esc_html_e( 'Variáció változások (minta)', 'wp-pepita-sync' ); ?></h4>
					<ul>
						<?php foreach ( $diff['samples']['variation_changed'] as $item ) : ?>
							<li>#<?php echo esc_html( (string) $item['pid'] ); ?>: <?php echo esc_html( $item['name'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</details>
		<?php endif; ?>
	</div>

	<div class="wps-card wps-card--wide">
		<h2><?php esc_html_e( 'Ütemezés', 'wp-pepita-sync' ); ?></h2>
		<dl class="wps-stats wps-stats--inline">
			<dt><?php esc_html_e( 'Teljes feed gyakorisága', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( sprintf( '%d óra', (int) Settings::get( 'full_feed_interval', 24 ) ) ); ?></dd>
			<dt><?php esc_html_e( 'Készletfeed gyakorisága', 'wp-pepita-sync' ); ?></dt>
			<dd><?php echo esc_html( sprintf( '%d perc', (int) Settings::get( 'stock_feed_interval', 30 ) ) ); ?></dd>
			<dt><?php esc_html_e( 'Következő teljes feed', 'wp-pepita-sync' ); ?></dt>
			<dd>
				<?php
				$next_full = wp_next_scheduled( 'wps_pepita_generate_full' );
				echo esc_html( $next_full ? Admin::date( (int) $next_full ) : '—' );
				?>
			</dd>
			<dt><?php esc_html_e( 'Következő készletfeed', 'wp-pepita-sync' ); ?></dt>
			<dd>
				<?php
				$next_stock = wp_next_scheduled( 'wps_pepita_generate_stock' );
				echo esc_html( $next_stock ? Admin::date( (int) $next_stock ) : '—' );
				?>
			</dd>
		</dl>
		<p class="description">
			<?php esc_html_e( 'A WP-Cron csak látogatásra fut. Éles környezetben javasolt valódi rendszer cron beállítása (lásd a dokumentációt).', 'wp-pepita-sync' ); ?>
		</p>
	</div>

	<div class="wps-card wps-card--wide wps-card--danger">
		<h2><?php esc_html_e( 'Karbantartás', 'wp-pepita-sync' ); ?></h2>
		<p><?php esc_html_e( 'A generált feedek és a hozzájuk tartozó állapot törlése. A beállítások és a termékadatok megmaradnak. Ezután újra kell generálni a teljes feedet.', 'wp-pepita-sync' ); ?></p>
		<p>
			<a class="button wps-confirm" data-confirm="<?php echo esc_attr__( 'Biztosan törli a generált feedeket?', 'wp-pepita-sync' ); ?>" href="<?php echo esc_url( Admin::action_url( 'wps_pepita_reset_feeds' ) ); ?>">
				<?php esc_html_e( 'Feedek törlése', 'wp-pepita-sync' ); ?>
			</a>
		</p>
	</div>
</div>
