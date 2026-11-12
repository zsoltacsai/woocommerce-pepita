<?php
/**
 * Orders view.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

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
		<?php esc_html_e( 'A Pepita a rendelés leadása után azonnal meghívja az áruház API végpontját. Az itt látható rekordok biztosítják az ismételt értesítések kiszűrését.', 'wp-pepita-sync' ); ?>
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
				<th><?php esc_html_e( 'Pepita rendelésazonosító', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'WooCommerce rendelés', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Állapot', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Próbálkozás', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Beérkezés', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Utolsó hiba', 'wp-pepita-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $result['items'] ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Még nem érkezett Pepita rendelés.', 'wp-pepita-sync' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $result['items'] as $row ) : ?>
				<?php
				$wc_order_id = (int) ( $row['wc_order_id'] ?? 0 );
				$row_status  = (string) ( $row['status'] ?? '' );
				$label       = match ( $row_status ) {
					Order_Repository::STATUS_COMPLETED => __( 'Importálva', 'wp-pepita-sync' ),
					Order_Repository::STATUS_PROCESSING => __( 'Feldolgozás alatt', 'wp-pepita-sync' ),
					default                            => __( 'Sikertelen', 'wp-pepita-sync' ),
				};
				$badge       = match ( $row_status ) {
					Order_Repository::STATUS_COMPLETED => 'wps-badge--ok',
					Order_Repository::STATUS_PROCESSING => 'wps-badge--warn',
					default                            => 'wps-badge--bad',
				};
				?>
				<tr>
					<td><code><?php echo esc_html( (string) ( $row['external_id'] ?? '' ) ); ?></code></td>
					<td>
						<?php if ( $wc_order_id > 0 ) : ?>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $wc_order_id ) ); ?>">#<?php echo esc_html( (string) $wc_order_id ); ?></a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td><span class="wps-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span></td>
					<td><?php echo esc_html( (string) (int) ( $row['attempts'] ?? 1 ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
					<td class="wps-error-text"><?php echo esc_html( (string) ( $row['last_error'] ?? '' ) ); ?></td>
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
		<h2><?php esc_html_e( 'Adatmegőrzés', 'wp-pepita-sync' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %d: number of days */
				esc_html__( 'A rendelésekhez tartozó nyers Pepita adatcsomag %d nap után automatikusan törlődik. A rendelés és annak összes adata a WooCommerce-ben marad.', 'wp-pepita-sync' ),
				(int) Settings::get( 'log_retention_days', 30 )
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'A rendelések importálásakor a plugin nem küld e-mailt a vásárlónak, és nem indít fizetési átjárót.', 'wp-pepita-sync' ); ?>
		</p>
	</div>
</div>
