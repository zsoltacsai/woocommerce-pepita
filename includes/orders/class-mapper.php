<?php
/**
 * Pepita payment / delivery mode mapping.
 *
 * @package WPS\Pepita
 */

namespace WPS\Pepita\Orders;

use WPS\Pepita\Settings;

defined( 'ABSPATH' ) || exit;

final class Mapper {

	public const PAYMENT_LABELS = array(
		'cod'        => 'Utánvét',
		'transfer'   => 'Átutalás',
		'creditcard' => 'Bankkártya',
	);

	public const SHIPPING_LABELS = array(
		'shipping'         => 'Alapértelmezett szállítás',
		'gls'              => 'GLS házhozszállítás',
		'gls_parcellocker' => 'GLS csomagautomata',
		'gls_xxl'          => 'GLS XXL házhozszállítás',
		'mpl'              => 'MPL házhozszállítás',
	);

	/**
	 * @return array{id: string, title: string}
	 */
	public static function payment( string $mode ): array {
		$mode  = sanitize_key( $mode );
		$map   = Settings::payment_map();
		$id    = (string) ( $map[ $mode ] ?? '' );
		$title = (string) ( self::PAYMENT_LABELS[ $mode ] ?? $mode );

		return array(
			'id'    => $id,
			'title' => $title,
		);
	}

	/**
	 * @return array{id: string, title: string}
	 */
	public static function shipping( string $mode ): array {
		$mode  = sanitize_key( $mode );
		$map   = Settings::shipping_map();
		$id    = (string) ( $map[ $mode ] ?? '' );
		$title = (string) ( self::SHIPPING_LABELS[ $mode ] ?? ( '' !== $mode ? $mode : 'Szállítás' ) );

		return array(
			'id'    => '' !== $id ? $id : 'wps_pepita_shipping',
			'title' => $title,
		);
	}

	public static function is_known_payment( string $mode ): bool {
		return array_key_exists( sanitize_key( $mode ), self::PAYMENT_LABELS );
	}

	public static function is_known_shipping( string $mode ): bool {
		return array_key_exists( sanitize_key( $mode ), self::SHIPPING_LABELS );
	}
}
