<?php
/** Integration suite entry point. */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/bootstrap.php';

$cases = glob( __DIR__ . '/cases/*.php' );
sort( $cases );

foreach ( $cases as $case ) {
	require_once $case;
}

exit( WPS_IT::summary() );
