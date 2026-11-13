<?php
/**
 * M9-C operational checks: catalog diff, feed safety, stock health and CSV export.
 *
 * @package WPS\Pepita\Integration
 */

defined( 'ABSPATH' ) || exit;

use WPS\Pepita\Feed\Catalog_Diff;
use WPS\Pepita\Health;
use WPS\Pepita\Logger;
use WPS\Pepita\Settings;
use WPS\Pepita\Storage;

WPS_IT::line( 'M9-C operations: diff, safety, health and CSV export' );

WPS_IT::test(
	'Catalog_Diff correctly generates difference report and caches results',
	static function (): void {
		Catalog_Diff::clear_cache();
		$diff = Catalog_Diff::get_cached_diff();

		WPS_IT::true( is_array( $diff ), 'Diff report is not an array.' );
		WPS_IT::true( isset( $diff['status'] ), 'Diff status is missing.' );
		WPS_IT::true( isset( $diff['current_count'] ), 'Current count is missing.' );
		WPS_IT::true( isset( $diff['added_count'] ), 'Added count is missing.' );
		WPS_IT::true( isset( $diff['removed_count'] ), 'Removed count is missing.' );
		WPS_IT::true( isset( $diff['price_changed_count'] ), 'Price changed count is missing.' );
		WPS_IT::true( isset( $diff['variation_changed_count'] ), 'Variation changed count is missing.' );
		WPS_IT::true( isset( $diff['tax_currency_changed_count'] ), 'Tax/currency changed count is missing.' );
		WPS_IT::true( isset( $diff['warnings'] ), 'Warnings array is missing.' );

		Catalog_Diff::clear_cache();
	}
);

WPS_IT::test(
	'Health::stock_feed_check returns complete health diagnostic without side-effects',
	static function (): void {
		$check = Health::stock_feed_check();

		WPS_IT::same( 'Készletfeed állapota', $check['label'], 'Label does not match expected.' );
		WPS_IT::true( in_array( $check['status'], array( Health::STATUS_OK, Health::STATUS_WARN, Health::STATUS_FAIL ), true ), 'Invalid status.' );
		WPS_IT::true( '' !== $check['value'], 'Value should not be empty.' );
		WPS_IT::true( '' !== $check['hint'], 'Hint should not be empty.' );
	}
);

WPS_IT::test(
	'Logger::sanitize_csv_field neutralises formula injection in live WordPress environment',
	static function (): void {
		WPS_IT::same( "'=SUM(1,2)", Logger::sanitize_csv_field( '=SUM(1,2)' ), 'Equals sign not escaped.' );
		WPS_IT::same( "'+cmd", Logger::sanitize_csv_field( '+cmd' ), 'Plus sign not escaped.' );
		WPS_IT::same( "'-cmd", Logger::sanitize_csv_field( '-cmd' ), 'Minus sign not escaped.' );
		WPS_IT::same( "'@SUM", Logger::sanitize_csv_field( '@SUM' ), 'At sign not escaped.' );
		WPS_IT::same( "'\tcmd", Logger::sanitize_csv_field( "\tcmd" ), 'Tab not escaped.' );
		WPS_IT::same( "'\rcmd", Logger::sanitize_csv_field( "\rcmd" ), 'Return not escaped.' );
		WPS_IT::same( "'  =TRIMMED", Logger::sanitize_csv_field( '  =TRIMMED' ), 'Leading space formula not escaped.' );
		WPS_IT::same( 'Normal log', Logger::sanitize_csv_field( 'Normal log' ), 'Normal text altered.' );
	}
);

WPS_IT::test(
	'M9-C settings sanitize correctly in live settings system',
	static function (): void {
		$clean = Settings::sanitize(
			array(
				'feed_safety_drop_threshold' => 150,
				'feed_safety_block_publication' => '1',
				'feed_safety_variation_change_warning' => '0',
			)
		);

		WPS_IT::same( 95, $clean['feed_safety_drop_threshold'], 'Drop threshold should clamp to 95.' );
		WPS_IT::same( true, $clean['feed_safety_block_publication'], 'Block publication should be boolean true.' );
		WPS_IT::same( false, $clean['feed_safety_variation_change_warning'], 'Variation warning should be boolean false.' );
	}
);
