<?php
/**
 * Data retention: payloads and package-label PDFs.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Orders\Importer;
use WPS\Pepita\Orders\Order_Repository;
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

$payload = static function ( string $id, string $label = '' ): array {
	return array(
		'origin'               => 'pepita.hu',
		'id'                   => $id,
		'payment_mode'         => 'transfer',
		'payment_status'       => 'paid',
		'total_shipping_price' => 0,
		'delivery_mod'         => 'gls',
		'package_label'        => '' !== $label ? base64_encode( $label ) : '',
		'customer'             => array(
			'email'      => 'retention@example.test',
			'first_name' => 'Ret',
			'last_name'  => 'Test',
		),
		'products'             => array(
			array(
				'sku'      => 'SKU-RET',
				'currency' => 'HUF',
				'quantity' => 1,
				'price'    => 1000,
				'vat'      => 27,
			),
		),
	);
};

$seed = static function (): void {
	WPS_Test_State::add_product(
		array(
			'id'    => 950,
			'name'  => 'Retention termék',
			'sku'   => 'SKU-RET',
			'price' => '1000',
		)
	);
};

$suite->add(
	'retention: labels of open orders are kept, closed-order labels are deleted',
	static function () use ( $payload, $seed ): void {
		$seed();
		Settings::update( array( 'order_api_save_label' => true ) );

		$result = ( new Importer() )->import( $payload( 'PEP-LABEL-OPEN', "%PDF-1.4\nlabel\n" ) );

		WPS_Assert::same( 'created', $result['status'] );

		$order = WPS_Test_State::$orders[ $result['order_id'] ];
		$file  = (string) $order->get_meta( Importer::META_PACKAGE_LABEL );

		WPS_Assert::true( '' !== $file && is_file( $file ) );

		// Age the file past the retention window.
		touch( $file, time() - 40 * DAY_IN_SECONDS );

		// The order is processing: the label is operationally needed.
		WPS_Assert::same( 0, Order_Repository::purge_labels( 30 ) );
		WPS_Assert::true( is_file( $file ) );

		// Closing the order makes the label eligible.
		$order->set_status( 'completed' );
		$order->save();

		WPS_Assert::same( 1, Order_Repository::purge_labels( 30 ) );
		WPS_Assert::false( is_file( $file ) );
		WPS_Assert::same( '', $order->get_meta( Importer::META_PACKAGE_LABEL ) );
	}
);

$suite->add(
	'retention: a fresh label is never deleted early',
	static function () use ( $payload, $seed ): void {
		$seed();

		$result = ( new Importer() )->import( $payload( 'PEP-LABEL-FRESH', "%PDF-1.4\nfresh\n" ) );

		$order = WPS_Test_State::$orders[ $result['order_id'] ];
		$file  = (string) $order->get_meta( Importer::META_PACKAGE_LABEL );

		$order->set_status( 'completed' );
		$order->save();

		WPS_Assert::same( 0, Order_Repository::purge_labels( 30 ) );
		WPS_Assert::true( is_file( $file ) );

		@unlink( $file );
	}
);
