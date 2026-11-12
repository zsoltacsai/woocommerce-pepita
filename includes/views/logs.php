<?php
/**
 * Logs view.
 *
 * @package WPS\Pepita
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Admin;
use WPS\Pepita\Logger;

$level  = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( (string) $_GET['level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$event  = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( (string) $_GET['event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$result = Logger::query(
	array(
		'level'    => $level,
		'event'    => $event,
		'search'   => $search,
		'date_from'=> $from,
		'date_to'  => $to,
		'page'     => $paged,
		'per_page' => 50,
	)
);

$events = Logger::event_types();
$pages  = max( 1, (int) ceil( $result['total'] / 50 ) );

$badge = static function ( string $level ): string {
	return match ( $level ) {
		'error'   => 'wps-badge--bad',
		'warning' => 'wps-badge--warn',
		'debug'   => 'wps-badge--muted',
		default   => 'wps-badge--ok',
	};
};
?>
<div class="wrap wps-wrap">
	<h1><?php esc_html_e( 'Pepita napló', 'wp-pepita-sync' ); ?></h1>

	<p class="wps-lead">
		<?php esc_html_e( 'A napló a feedgenerálás, a rendelésimport és az API műveletek eseményeit rögzíti. A titkos kulcsokat és a csomagcímkét a plugin nem naplózza.', 'wp-pepita-sync' ); ?>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wps-filters">
		<input type="hidden" name="page" value="wps-pepita-logs" />

		<select name="level">
			<option value="" <?php selected( $level, '' ); ?>><?php esc_html_e( 'Minden szint', 'wp-pepita-sync' ); ?></option>
			<?php foreach ( array( 'debug', 'info', 'warning', 'error' ) as $value ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $level, $value ); ?>><?php echo esc_html( $value ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="event">
			<option value="" <?php selected( $event, '' ); ?>><?php esc_html_e( 'Minden esemény', 'wp-pepita-sync' ); ?></option>
			<?php foreach ( $events as $value ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $event, $value ); ?>><?php echo esc_html( $value ); ?></option>
			<?php endforeach; ?>
		</select>

		<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" />
		<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Keresés az üzenetekben…', 'wp-pepita-sync' ); ?>" />
		<button type="submit" class="button"><?php esc_html_e( 'Szűrés', 'wp-pepita-sync' ); ?></button>
	</form>

	<p class="wps-button-row">
		<a class="button wps-confirm" data-confirm="<?php echo esc_attr__( 'Biztosan törli a teljes naplót?', 'wp-pepita-sync' ); ?>" href="<?php echo esc_url( Admin::action_url( 'wps_pepita_clear_logs' ) ); ?>">
			<?php esc_html_e( 'Napló törlése', 'wp-pepita-sync' ); ?>
		</a>
		<span class="wps-muted"><?php echo esc_html( sprintf( /* translators: %d: log entry count */ __( 'Összesen %d bejegyzés', 'wp-pepita-sync' ), $result['total'] ) ); ?></span>
	</p>

	<table class="widefat striped wps-table wps-table--logs">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Időpont', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Szint', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Esemény', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Üzenet', 'wp-pepita-sync' ); ?></th>
				<th><?php esc_html_e( 'Kontextus', 'wp-pepita-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $result['items'] ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nincs a szűrésnek megfelelő naplóbejegyzés.', 'wp-pepita-sync' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $result['items'] as $row ) : ?>
				<tr>
					<td class="wps-nowrap"><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
					<td><span class="wps-badge <?php echo esc_attr( $badge( (string) ( $row['level'] ?? 'info' ) ) ); ?>"><?php echo esc_html( (string) ( $row['level'] ?? '' ) ); ?></span></td>
					<td><code><?php echo esc_html( (string) ( $row['event'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
					<td>
						<?php
						$context = (string) ( $row['context'] ?? '' );

						if ( '' !== $context ) {
							$decoded = json_decode( $context, true );

							if ( is_array( $decoded ) ) {
								echo '<details><summary>' . esc_html__( 'Részletek', 'wp-pepita-sync' ) . '</summary><pre class="wps-code wps-code--small">';
								echo esc_html( (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
								echo '</pre></details>';
							} else {
								echo esc_html( $context );
							}
						} else {
							echo '—';
						}
						?>
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
									'page'  => 'wps-pepita-logs',
									'level' => $level,
									'event' => $event,
									's'     => $search,
									'from'  => $from,
									'to'    => $to,
									'paged' => '%#%',
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
</div>
