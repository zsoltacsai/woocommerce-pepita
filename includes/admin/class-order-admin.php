<?php
/**
 * WooCommerce order operations: columns, metabox, diagnostic views and operator recovery.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Admin;

use WPS\Pepita\Logger;
use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Redactor;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

defined( 'ABSPATH' ) || exit;

final class Order_Admin {

	public const COLUMN_ID = 'wps_pepita_order';

	public function register(): void {
		// WooCommerce order list custom column (HPOS and legacy).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_column' ), 10, 2 );

		add_filter( 'manage_edit-shop_order_columns', array( $this, 'columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 10, 2 );

		// Single order details metabox (HPOS and legacy).
		add_action( 'add_meta_boxes', array( $this, 'register_metaboxes' ) );

		// Operator actions.
		add_action( 'admin_post_wps_pepita_retry_order', array( $this, 'handle_retry_order' ) );
		add_action( 'admin_post_wps_pepita_recover_order', array( $this, 'handle_recover_order' ) );
		add_action( 'admin_post_wps_pepita_download_label', array( $this, 'handle_download_label' ) );
	}

	/**
	 * Insert the Pepita column into the order list table.
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();
		$new     = array();
		$added   = false;

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'order_number' === $key || 'order_title' === $key ) {
				$new[ self::COLUMN_ID ] = __( 'Pepita', 'wp-pepita-sync' );
				$added                  = true;
			}
		}

		if ( ! $added ) {
			$new[ self::COLUMN_ID ] = __( 'Pepita', 'wp-pepita-sync' );
		}

		return $new;
	}

	/**
	 * Render column on HPOS screens.
	 *
	 * @param string                      $column
	 * @param \WC_Order|int|numeric-string $order
	 */
	public function render_hpos_column( string $column, $order ): void {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}

		if ( ! $order instanceof \WC_Order && is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order );
		}

		$this->render_column_cell( $order instanceof \WC_Order ? $order : null );
	}

	/**
	 * Render column on legacy post-table screens.
	 *
	 * @param string $column
	 * @param int    $post_id
	 */
	public function render_legacy_column( string $column, $post_id ): void {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $post_id ) : null;
		$this->render_column_cell( $order instanceof \WC_Order ? $order : null );
	}

	/**
	 * Output cell content for the Pepita column.
	 *
	 * @param \WC_Order|null $order
	 */
	public function render_column_cell( ?\WC_Order $order ): void {
		if ( null === $order ) {
			echo '—';
			return;
		}

		$external_id = (string) $order->get_meta( Importer::META_ORDER_ID );

		if ( '' === $external_id ) {
			echo '—';
			return;
		}

		$claim  = Order_Repository::get( $external_id );
		$status = null !== $claim ? (string) ( $claim['status'] ?? '' ) : Order_Repository::STATUS_COMPLETED;

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

		$view_url = admin_url( 'admin.php?page=wps-pepita-orders&action=view&id=' . rawurlencode( $external_id ) );

		printf(
			'<a href="%1$s" title="%2$s"><strong>%3$s</strong></a><br><span class="wps-badge %4$s">%5$s</span>',
			esc_url( $view_url ),
			esc_attr__( 'Pepita rendelés diagnosztika és részletek', 'wp-pepita-sync' ),
			esc_html( $external_id ),
			esc_attr( $badge_class ),
			esc_html( $status_label )
		);
	}

	/**
	 * Register metaboxes on both HPOS and legacy edit screens.
	 */
	public function register_metaboxes(): void {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hpos_screen = (string) wc_get_page_screen_id( 'shop-order' );
			if ( '' !== $hpos_screen && ! in_array( $hpos_screen, $screens, true ) ) {
				$screens[] = $hpos_screen;
			}
		}

		if ( ! in_array( 'woocommerce_page_wc-orders', $screens, true ) ) {
			$screens[] = 'woocommerce_page_wc-orders';
		}

		foreach ( $screens as $screen ) {
			add_meta_box(
				'wps_pepita_order_details',
				__( 'Pepita rendelés adatok', 'wp-pepita-sync' ),
				array( $this, 'render_order_metabox' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render metabox contents on order edit screen.
	 *
	 * @param mixed $post_or_order
	 */
	public function render_order_metabox( $post_or_order ): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			return;
		}

		$order = null;

		if ( $post_or_order instanceof \WC_Order ) {
			$order = $post_or_order;
		} elseif ( is_numeric( $post_or_order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $post_or_order );
		} elseif ( $post_or_order instanceof \WP_Post && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $post_or_order->ID );
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$external_id = (string) $order->get_meta( Importer::META_ORDER_ID );

		if ( '' === $external_id ) {
			echo '<p class="description">' . esc_html__( 'Ez nem egy Pepita piactérről importált rendelés.', 'wp-pepita-sync' ) . '</p>';
			return;
		}

		$origin         = (string) $order->get_meta( Importer::META_ORIGIN );
		$delivery_mode  = (string) $order->get_meta( Importer::META_DELIVERY_MODE );
		$payment_status = (string) $order->get_meta( Importer::META_PAYMENT_STATUS );
		$voucher        = (string) $order->get_meta( Importer::META_VOUCHER );
		$label_path     = (string) $order->get_meta( Importer::META_PACKAGE_LABEL );
		$has_label      = '' !== $label_path && file_exists( $label_path );

		$view_url = admin_url( 'admin.php?page=wps-pepita-orders&action=view&id=' . rawurlencode( $external_id ) );
		?>
		<div class="wps-order-metabox">
			<p>
				<strong><?php esc_html_e( 'Pepita azonosító:', 'wp-pepita-sync' ); ?></strong><br>
				<code><?php echo esc_html( $external_id ); ?></code>
			</p>
			<?php if ( '' !== $origin ) : ?>
				<p>
					<strong><?php esc_html_e( 'Piactér / Forrás:', 'wp-pepita-sync' ); ?></strong><br>
					<?php echo esc_html( $origin ); ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $delivery_mode ) : ?>
				<p>
					<strong><?php esc_html_e( 'Szállítási mód:', 'wp-pepita-sync' ); ?></strong><br>
					<?php echo esc_html( $delivery_mode ); ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $payment_status ) : ?>
				<p>
					<strong><?php esc_html_e( 'Fizetés állapota:', 'wp-pepita-sync' ); ?></strong><br>
					<?php echo esc_html( $payment_status ); ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $voucher && '0' !== $voucher ) : ?>
				<p>
					<strong><?php esc_html_e( 'Kupon / Kedvezmény:', 'wp-pepita-sync' ); ?></strong><br>
					<code><?php echo esc_html( $voucher ); ?></code>
				</p>
			<?php endif; ?>
			<p>
				<strong><?php esc_html_e( 'Csomagcímke:', 'wp-pepita-sync' ); ?></strong><br>
				<?php if ( $has_label ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wps_pepita_download_label&order_id=' . $order->get_id() ), 'wps_pepita_download_label_' . $order->get_id() ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Címke letöltése (PDF)', 'wp-pepita-sync' ); ?>
					</a>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'Nincs elérhető címke.', 'wp-pepita-sync' ); ?></span>
				<?php endif; ?>
			</p>
			<hr style="margin: 12px 0;">
			<p>
				<a href="<?php echo esc_url( $view_url ); ?>" class="button button-primary button-small">
					<?php esc_html_e( 'Diagnosztika és részletek &rarr;', 'wp-pepita-sync' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Operator action: Retry order ingestion from stored payload.
	 */
	public function handle_retry_order(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nincs jogosultsága ehhez a művelethez.', 'wp-pepita-sync' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '';
		check_admin_referer( 'wps_pepita_retry_order_' . $id );

		$claim = Order_Repository::get( $id );

		if ( null === $claim ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wps-pepita-orders', 'wps_notice' => 'order_not_found' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$raw_payload = (string) ( $claim['payload'] ?? '' );

		if ( '' === $raw_payload ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'wps-pepita-orders',
						'action'     => 'view',
						'id'         => $id,
						'wps_notice' => 'retry_no_payload',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$payload = json_decode( $raw_payload, true );

		if ( ! is_array( $payload ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'wps-pepita-orders',
						'action'     => 'view',
						'id'         => $id,
						'wps_notice' => 'retry_failed',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// If the claim was completed earlier and no WooCommerce order exists, reopen it so importer does not short-circuit.
		if ( Order_Repository::STATUS_COMPLETED === ( $claim['status'] ?? '' ) ) {
			$candidate_id = self::find_order_by_external_id( $id );
			if ( 0 === $candidate_id ) {
				Order_Repository::reopen_completed( $id );
			}
		}

		$importer = new Importer();
		$result   = $importer->import( $payload );

		if ( in_array( (string) ( $result['status'] ?? '' ), array( 'created', 'updated', 'success', 'duplicate' ), true ) ) {
			Logger::info(
				'order_retry',
				'Kezelői rendelés újrafeldolgozás sikeres.',
				array( 'external_id' => $id, 'order_id' => $result['order_id'] ?? 0 )
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'wps-pepita-orders',
						'action'     => 'view',
						'id'         => $id,
						'wps_notice' => 'retry_success',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		Logger::warning(
			'order_retry',
			'Kezelői rendelés újrafeldolgozás sikertelen.',
			array( 'external_id' => $id, 'error' => $result['message'] ?? '' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'wps-pepita-orders',
					'action'     => 'view',
					'id'         => $id,
					'wps_notice' => 'retry_failed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Operator action: Recover orphaned WooCommerce order or reopen lost claim.
	 */
	public function handle_recover_order(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nincs jogosultsága ehhez a művelethez.', 'wp-pepita-sync' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '';
		check_admin_referer( 'wps_pepita_recover_order_' . $id );

		$claim = Order_Repository::get( $id );

		if ( null === $claim ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wps-pepita-orders', 'wps_notice' => 'order_not_found' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$candidate_id = self::find_order_by_external_id( $id );

		if ( $candidate_id > 0 ) {
			$recovered = Order_Repository::recover_orphan_order( $id, $candidate_id );

			if ( ! $recovered ) {
				Logger::error(
					'order_recovery',
					'A rendelés kapcsolat helyreállítása meghiúsult.',
					array(
						'external_id' => $id,
						'wc_order_id' => $candidate_id,
					)
				);

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'       => 'wps-pepita-orders',
							'action'     => 'view',
							'id'         => $id,
							'wps_notice' => 'recover_failed',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			Logger::info(
				'order_recovery',
				'Rendelés kapcsolat sikeresen helyreállítva a meglévő WooCommerce rendeléshez.',
				array(
					'external_id' => $id,
					'wc_order_id' => $candidate_id,
				)
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'wps-pepita-orders',
						'action'     => 'view',
						'id'         => $id,
						'wps_notice' => 'recover_associated',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// No WooCommerce order found.
		if ( Order_Repository::STATUS_COMPLETED === ( $claim['status'] ?? '' ) ) {
			Order_Repository::reopen_completed( $id );

			Logger::info(
				'order_recovery',
				'Hiányzó WooCommerce rendelés: lezárt Pepita követelés újranyitva újrafeldolgozásra.',
				array( 'external_id' => $id )
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'wps-pepita-orders',
						'action'     => 'view',
						'id'         => $id,
						'wps_notice' => 'recover_reopened',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'wps-pepita-orders',
					'action'     => 'view',
					'id'         => $id,
					'wps_notice' => 'recover_already_open',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Validate a label file path ensuring it resides within the allowed labels directory and is a PDF.
	 *
	 * @param string $file
	 * @return string|false Canonical absolute path if valid, false otherwise.
	 */
	public static function get_valid_label_path( string $file ): string|false {
		if ( '' === $file ) {
			return false;
		}

		$label_dir = realpath( Storage::label_dir() );
		$real_file = realpath( $file );

		if ( false === $label_dir || false === $real_file ) {
			return false;
		}

		$label_dir_prefix = rtrim( $label_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		if ( ! str_starts_with( $real_file, $label_dir_prefix ) || ! is_file( $real_file ) || ! str_ends_with( strtolower( $real_file ), '.pdf' ) ) {
			return false;
		}

		return $real_file;
	}

	/**
	 * Download package label PDF securely with strict traversal prevention.
	 */
	public function handle_download_label(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nincs jogosultsága ehhez a művelethez.', 'wp-pepita-sync' ), 403 );
		}

		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		check_admin_referer( 'wps_pepita_download_label_' . $order_id );

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'A rendelés nem található.', 'wp-pepita-sync' ), 404 );
		}

		$file = (string) $order->get_meta( Importer::META_PACKAGE_LABEL );

		if ( '' === $file ) {
			wp_die( esc_html__( 'A rendeléshez nem tartozik csomagcímke.', 'wp-pepita-sync' ), 404 );
		}

		$real_file = self::get_valid_label_path( $file );

		if ( false === $real_file ) {
			wp_die( esc_html__( 'A csomagcímke fájl nem található vagy elérési útja érvénytelen.', 'wp-pepita-sync' ), 404 );
		}

		if ( ! apply_filters( 'wps_pepita_serve_label_download', true, $real_file, $order ) ) {
			return;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="pepita-label-' . $order->get_id() . '.pdf"' );
		header( 'Content-Length: ' . (int) filesize( $real_file ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, must-revalidate' );

		readfile( $real_file );
		exit;
	}

	/**
	 * Locate an existing WooCommerce order ID by external Pepita order ID.
	 */
	public static function find_order_by_external_id( string $external_id ): int {
		if ( ! function_exists( 'wc_get_orders' ) || '' === $external_id ) {
			return 0;
		}

		$orders = wc_get_orders(
			array(
				'limit'         => 1,
				'return'        => 'ids',
				'cache_results' => false,
				'meta_key'      => Importer::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'    => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'status'        => 'any',
			)
		);

		if ( is_array( $orders ) && isset( $orders[0] ) ) {
			$order = wc_get_order( (int) $orders[0] );

			if ( $order instanceof \WC_Order ) {
				if ( 'yes' === (string) $order->get_meta( Importer::META_IN_PROGRESS ) ) {
					return 0; // Exclude incomplete shell orders.
				}

				$stored = (string) $order->get_meta( Importer::META_ORDER_ID );

				if ( '' === $stored || hash_equals( $stored, $external_id ) ) {
					return (int) $orders[0];
				}
			}
		}

		return 0;
	}

	/**
	 * Get an admin URL to edit or view a WooCommerce order (supports HPOS and legacy).
	 */
	public static function get_order_edit_url( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return '';
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( $order instanceof \WC_Order && method_exists( $order, 'get_edit_order_url' ) ) {
			return (string) $order->get_edit_order_url();
		}

		$link = get_edit_post_link( $order_id );

		if ( is_string( $link ) && '' !== $link ) {
			return $link;
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}
}
