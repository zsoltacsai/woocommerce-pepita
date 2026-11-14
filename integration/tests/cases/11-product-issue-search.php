<?php
/** M9-F real WooCommerce product search coverage for HPOS and legacy stores. */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Admin\Product_Issues;

$seed = WPS_IT::seed();

WPS_IT::test(
	'product issue search resolves product name, exact SKU and numeric product ID in the real datastore',
	static function () use ( $seed ): void {
		$by_name = Product_Issues::query( array( 'search' => 'WPS Integration Invalid Product' ) );
		WPS_IT::same( 1, (int) $by_name['total_products'], 'Name search should find the seeded product.' );
		WPS_IT::same( (int) $seed['p4'], (int) $by_name['items'][0]['product_id'], 'Name search returned the wrong product.' );

		$by_sku = Product_Issues::query( array( 'search' => 'WPS-IT-004' ) );
		WPS_IT::same( 1, (int) $by_sku['total_products'], 'Exact SKU search should find the seeded product.' );
		WPS_IT::same( (int) $seed['p4'], (int) $by_sku['items'][0]['product_id'], 'SKU search returned the wrong product.' );

		$by_id = Product_Issues::query( array( 'search' => (string) $seed['p4'] ) );
		WPS_IT::same( 1, (int) $by_id['total_products'], 'Numeric search should resolve the exact product ID.' );
		WPS_IT::same( (int) $seed['p4'], (int) $by_id['items'][0]['product_id'], 'ID search returned the wrong product.' );
	}
);

WPS_IT::test(
	'variation SKU search displays the parent product in the product issue center',
	static function () use ( $seed ): void {
		$variation_sku = Product_Issues::query( array( 'search' => 'WPS-IT-VAR-S' ) );
		WPS_IT::same( 1, (int) $variation_sku['total_products'], 'Variation SKU should resolve to one parent product.' );
		WPS_IT::same( (int) $seed['variable'], (int) $variation_sku['items'][0]['product_id'], 'Variation SKU should display its parent product row.' );
	}
);

WPS_IT::test(
	'product issue search clamps extreme page values before querying the datastore',
	static function (): void {
		$result = Product_Issues::query( array( 'page' => PHP_INT_MAX, 'per_page' => 50 ) );
		WPS_IT::same( 1000, (int) $result['page'], 'Page offset should be capped at 1000.' );
		WPS_IT::true( (int) $result['scanned'] <= 50, 'The issue query must inspect no more than one page.' );
	}
);
