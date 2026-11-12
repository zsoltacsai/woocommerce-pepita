<?php
/**
 * Test runner entry point.
 *
 * Usage: php tests/run.php [name-filter]
 *
 * @package WPS\Pepita\Tests
 */

require_once __DIR__ . '/bootstrap.php';

$filter = isset( $argv[1] ) ? (string) $argv[1] : '';
$suite  = new WPS_Suite( $filter );

$GLOBALS['wps_suite'] = $suite;

$cases = glob( __DIR__ . '/cases/*-test.php' );

if ( ! is_array( $cases ) || array() === $cases ) {
	fwrite( STDERR, "No test cases found.\n" );
	exit( 1 );
}

sort( $cases );

foreach ( $cases as $case ) {
	require_once $case;
}

exit( $suite->run() );
