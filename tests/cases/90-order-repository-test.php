<?php
/**
 * Durable order claim / deduplication store.
 *
 * @package WPS\Pepita\Tests
 */

use WPS\Pepita\Orders\Order_Repository;

$suite = $GLOBALS['wps_suite'];

$suite->add(
	'orders repository: a new external id is claimed exactly once',
	static function (): void {
		$claim = Order_Repository::claim( 'PEP-1', 'pepita.hu', '{"id":"PEP-1"}', 'hash-1' );

		WPS_Assert::same( 'claimed', $claim['state'] );
		WPS_Assert::same( 'processing', $claim['row']['status'] );
		WPS_Assert::same( 1, (int) $claim['row']['attempts'] );
		WPS_Assert::same( '{"id":"PEP-1"}', $claim['row']['payload'] );

		$second = Order_Repository::claim( 'PEP-1', 'pepita.hu', '{}', 'hash-2' );

		WPS_Assert::same( 'busy', $second['state'] );
		WPS_Assert::same( 1, Order_Repository::query()['total'] );
	}
);

$suite->add(
	'orders repository: a completed order is never claimed again',
	static function (): void {
		Order_Repository::claim( 'PEP-2', 'pepita.hu', '{}', 'hash' );
		Order_Repository::complete( 'PEP-2', 77 );

		$claim = Order_Repository::claim( 'PEP-2', 'pepita.hu', '{}', 'hash' );

		WPS_Assert::same( 'completed', $claim['state'] );
		WPS_Assert::same( 77, (int) $claim['row']['wc_order_id'] );
		WPS_Assert::same( 1, Order_Repository::count_completed() );
		WPS_Assert::same( 'completed', Order_Repository::get( 'PEP-2' )['status'] );
	}
);

$suite->add(
	'orders repository: a failed claim can be retried and bumps the attempt counter',
	static function (): void {
		Order_Repository::claim( 'PEP-3', 'pepita.hu', '{"v":1}', 'hash-1' );
		Order_Repository::fail( 'PEP-3', 'Átmeneti hiba' );

		$failed = Order_Repository::get( 'PEP-3' );

		WPS_Assert::same( 'failed', $failed['status'] );
		WPS_Assert::same( 'Átmeneti hiba', $failed['last_error'] );

		$claim = Order_Repository::claim( 'PEP-3', 'pepita.hu', '{"v":2}', 'hash-2' );

		WPS_Assert::same( 'claimed', $claim['state'] );
		WPS_Assert::same( 2, (int) $claim['row']['attempts'] );
		WPS_Assert::same( '{"v":2}', $claim['row']['payload'] );
		WPS_Assert::same( null, $claim['row']['last_error'] );
	}
);

$suite->add(
	'orders repository: a stale processing claim is retryable',
	static function (): void {
		Order_Repository::claim( 'PEP-4', 'pepita.hu', '{}', 'hash' );

		global $wpdb;

		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ) ),
			array( 'external_id' => 'PEP-4' )
		);

		$claim = Order_Repository::claim( 'PEP-4', 'pepita.hu', '{}', 'hash' );

		WPS_Assert::same( 'claimed', $claim['state'] );
		WPS_Assert::same( 2, (int) $claim['row']['attempts'] );
	}
);

$suite->add(
	'orders repository: a fresh processing claim is not retryable',
	static function (): void {
		Order_Repository::claim( 'PEP-5', 'pepita.hu', '{}', 'hash' );

		global $wpdb;

		$wpdb->update(
			Order_Repository::table(),
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'external_id' => 'PEP-5' )
		);

		WPS_Assert::same( 'busy', Order_Repository::claim( 'PEP-5', 'pepita.hu', '{}', 'hash' )['state'] );
	}
);

$suite->add(
	'orders repository: queries filter by status and search, with pagination',
	static function (): void {
		foreach ( array( 'PEP-100', 'PEP-101', 'OTHER-1' ) as $id ) {
			Order_Repository::claim( $id, 'pepita.hu', '{}', 'hash' );
		}

		Order_Repository::complete( 'PEP-101', 5 );

		$all = Order_Repository::query( array( 'per_page' => 2 ) );

		WPS_Assert::same( 3, $all['total'] );
		WPS_Assert::count( 2, $all['items'] );

		$completed = Order_Repository::query( array( 'status' => 'completed' ) );

		WPS_Assert::same( 1, $completed['total'] );
		WPS_Assert::same( 'PEP-101', $completed['items'][0]['external_id'] );

		$search = Order_Repository::query( array( 'search' => 'PEP-10' ) );

		WPS_Assert::same( 2, $search['total'] );

		$page_two = Order_Repository::query( array( 'per_page' => 2, 'page' => 2 ) );

		WPS_Assert::count( 1, $page_two['items'] );
	}
);

$suite->add(
	'orders repository: lookup by WooCommerce order id',
	static function (): void {
		Order_Repository::claim( 'PEP-6', 'pepita.hu', '{}', 'hash' );
		Order_Repository::complete( 'PEP-6', 321 );

		$row = Order_Repository::get_by_wc_order( 321 );

		WPS_Assert::same( 'PEP-6', $row['external_id'] );
		WPS_Assert::is_null( Order_Repository::get_by_wc_order( 0 ) );
		WPS_Assert::is_null( Order_Repository::get_by_wc_order( 999 ) );
	}
);

$suite->add(
	'orders repository: old payloads are purged but the dedupe row survives',
	static function (): void {
		Order_Repository::claim( 'PEP-7', 'pepita.hu', '{"secret":"data"}', 'hash' );
		Order_Repository::complete( 'PEP-7', 9 );

		global $wpdb;

		$wpdb->update(
			Order_Repository::table(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
			array( 'external_id' => 'PEP-7' )
		);

		$purged = Order_Repository::purge_payloads( 14 );

		WPS_Assert::same( 1, $purged );
		WPS_Assert::same( null, Order_Repository::get( 'PEP-7' )['payload'] );
		WPS_Assert::same( 'completed', Order_Repository::claim( 'PEP-7', 'pepita.hu', '{}', 'hash' )['state'] );
	}
);

$suite->add(
	'orders repository: a missing table degrades safely',
	static function (): void {
		global $wpdb;

		$wpdb->drop_table( 'wp_wps_pepita_orders' );
		Order_Repository::reset_table_cache();

		$claim = Order_Repository::claim( 'PEP-8', 'pepita.hu', '{}', 'hash' );

		WPS_Assert::same( 'failed', $claim['state'] );
		WPS_Assert::is_null( $claim['row'] );
		WPS_Assert::same( 0, Order_Repository::query()['total'] );
		WPS_Assert::same( 0, Order_Repository::count_completed() );
		WPS_Assert::is_null( Order_Repository::get_by_wc_order( 1 ) );
		WPS_Assert::same( 0, Order_Repository::purge_payloads( 1 ) );
	}
);
