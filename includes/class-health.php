<?php
/**
 * Read-only system health checks shown on the dashboard.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita;

use WPS\Pepita\Feed\Generator;
use WPS\Pepita\Feed\Lock;

defined( 'ABSPATH' ) || exit;

final class Health {

	public const STATUS_OK   = 'ok';
	public const STATUS_WARN = 'warn';
	public const STATUS_FAIL = 'fail';

	/**
	 * @return array<int, array{label: string, status: string, value: string, hint: string}>
	 */
	public static function checks(): array {
		$checks = array();

		$checks[] = array(
			'label'  => __( 'WooCommerce', 'wp-pepita-sync' ),
			'status' => class_exists( 'WooCommerce' ) ? self::STATUS_OK : self::STATUS_FAIL,
			'value'  => class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) ? (string) WC_VERSION : __( 'nem aktív', 'wp-pepita-sync' ),
			'hint'   => __( 'A plugin működéséhez aktív WooCommerce szükséges.', 'wp-pepita-sync' ),
		);

		$hpos = 'no';

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';
		}

		$checks[] = array(
			'label'  => __( 'HPOS (egyedi rendeléstáblák)', 'wp-pepita-sync' ),
			'status' => self::STATUS_OK,
			'value'  => 'yes' === $hpos ? __( 'aktív', 'wp-pepita-sync' ) : __( 'nem aktív', 'wp-pepita-sync' ),
			'hint'   => __( 'A plugin a WooCommerce CRUD API-t használja, ezért HPOS módban is működik.', 'wp-pepita-sync' ),
		);

		$checks[] = array(
			'label'  => __( 'PHP verzió', 'wp-pepita-sync' ),
			'status' => version_compare( PHP_VERSION, '8.1', '>=' ) ? self::STATUS_OK : self::STATUS_FAIL,
			'value'  => PHP_VERSION,
			'hint'   => __( 'Minimum PHP 8.1 szükséges.', 'wp-pepita-sync' ),
		);

		$writable = Storage::ensure_directory();

		$checks[] = array(
			'label'  => __( 'Feed könyvtár', 'wp-pepita-sync' ),
			'status' => $writable ? self::STATUS_OK : self::STATUS_FAIL,
			'value'  => $writable ? __( 'írható', 'wp-pepita-sync' ) : __( 'nem írható', 'wp-pepita-sync' ),
			'hint'   => __( 'A feltöltési könyvtárnak írhatónak kell lennie a feed generáláshoz.', 'wp-pepita-sync' ),
		);

		$checks[] = array(
			'label'  => __( 'Feed token', 'wp-pepita-sync' ),
			'status' => '' !== Settings::secret( 'feed_token' ) ? self::STATUS_OK : self::STATUS_WARN,
			'value'  => '' !== Settings::secret( 'feed_token' )
				? ( Settings::using_constant( 'feed_token' ) ? __( 'wp-config konstans', 'wp-pepita-sync' ) : __( 'beállítva', 'wp-pepita-sync' ) )
				: __( 'hiányzik', 'wp-pepita-sync' ),
			'hint'   => __( 'Token nélkül a feed csak akkor érhető el, ha a nyilvános feed be van kapcsolva.', 'wp-pepita-sync' ),
		);

		$checks[] = array(
			'label'  => __( 'Rendelés API kulcs', 'wp-pepita-sync' ),
			'status' => '' !== Settings::secret( 'order_api_key' ) ? self::STATUS_OK : self::STATUS_WARN,
			'value'  => '' !== Settings::secret( 'order_api_key' )
				? ( Settings::using_constant( 'order_api_key' ) ? __( 'wp-config konstans', 'wp-pepita-sync' ) : __( 'beállítva', 'wp-pepita-sync' ) )
				: __( 'hiányzik', 'wp-pepita-sync' ),
			'hint'   => __( 'Kulcs nélkül minden beérkező rendelés elutasításra kerül.', 'wp-pepita-sync' ),
		);

		$checks[] = array(
			'label'  => __( 'HTTPS', 'wp-pepita-sync' ),
			'status' => function_exists( 'is_ssl' ) && is_ssl() ? self::STATUS_OK : self::STATUS_WARN,
			'value'  => function_exists( 'is_ssl' ) && is_ssl() ? __( 'aktív', 'wp-pepita-sync' ) : __( 'nem aktív', 'wp-pepita-sync' ),
			'hint'   => __( 'A Pepita rendelés API éles használatához HTTPS szükséges.', 'wp-pepita-sync' ),
		);

		$as = function_exists( 'as_next_scheduled_action' );

		$checks[] = array(
			'label'  => __( 'Ütemező', 'wp-pepita-sync' ),
			'status' => self::STATUS_OK,
			'value'  => $as ? __( 'Action Scheduler', 'wp-pepita-sync' ) : __( 'WP-Cron', 'wp-pepita-sync' ),
			'hint'   => __( 'Az Action Scheduler megbízhatóbb, de a WP-Cron is működik.', 'wp-pepita-sync' ),
		);

		$generator = new Generator();
		$full      = $generator->get_feed_state( Generator::TYPE_FULL );
		$age       = time() - (int) ( $full['last_success'] ?? 0 );

		$checks[] = array(
			'label'  => __( 'Teljes feed kora', 'wp-pepita-sync' ),
			'status' => empty( $full['last_success'] )
				? self::STATUS_WARN
				: ( $age > 2 * Settings::full_feed_interval() ? self::STATUS_WARN : self::STATUS_OK ),
			'value'  => empty( $full['last_success'] ) ? __( 'még nem készült el', 'wp-pepita-sync' ) : human_time_diff( (int) $full['last_success'], time() ),
			'hint'   => __( 'Ha a feed a beállított gyakoriságnál régebbi, érdemes ellenőrizni a cron működését.', 'wp-pepita-sync' ),
		);

		$checks[] = array(
			'label'  => __( 'Zárolás', 'wp-pepita-sync' ),
			'status' => Lock::is_locked( Generator::TYPE_FULL ) || Lock::is_locked( Generator::TYPE_STOCK ) ? self::STATUS_WARN : self::STATUS_OK,
			'value'  => Lock::is_locked( Generator::TYPE_FULL ) || Lock::is_locked( Generator::TYPE_STOCK )
				? __( 'generálás folyamatban', 'wp-pepita-sync' )
				: __( 'szabad', 'wp-pepita-sync' ),
			'hint'   => __( 'A lejárt zárak automatikusan felszabadulnak.', 'wp-pepita-sync' ),
		);

		return $checks;
	}

	/**
	 * @return array{ok: int, warn: int, fail: int}
	 */
	public static function summary(): array {
		$summary = array(
			'ok'   => 0,
			'warn' => 0,
			'fail' => 0,
		);

		foreach ( self::checks() as $check ) {
			if ( isset( $summary[ $check['status'] ] ) ) {
				++$summary[ $check['status'] ];
			}
		}

		return $summary;
	}
}
