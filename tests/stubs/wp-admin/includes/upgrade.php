<?php
/**
 * dbDelta() stub used by Installer during activation tests.
 *
 * It registers every CREATE TABLE statement in the in-memory database.
 *
 * @package WPS\Pepita\Tests
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param string|string[] $queries
 * @return array<string, string>
 */
function dbDelta( $queries ) {
	global $wpdb;

	$results = array();

	foreach ( (array) $queries as $sql ) {
		if ( ! preg_match( '/CREATE\s+TABLE\s+([^\s(]+)\s*\((.*)\)[^)]*$/is', (string) $sql, $match ) ) {
			continue;
		}

		$table   = trim( $match[1], '` ' );
		$body    = $match[2];
		$columns = array();

		foreach ( explode( "\n", $body ) as $line ) {
			$line = trim( $line );

			if ( preg_match( '/^`?([a-zA-Z0-9_]+)`?\s+(bigint|int|varchar|longtext|mediumtext|text|datetime|tinyint|decimal|smallint)/i', $line, $column ) ) {
				$columns[] = $column[1];
			}
		}

		$unique = null;

		if ( preg_match( '/UNIQUE\s+KEY\s+`?[a-zA-Z0-9_]+`?\s*\(\s*`?([a-zA-Z0-9_]+)`?/i', $body, $unique_match ) ) {
			$unique = $unique_match[1];
		}

		if ( array() === $columns ) {
			$columns = array( 'id' );
		}

		$wpdb->register_table( $table, $columns, $unique );
		$results[ $table ] = 'Created';
	}

	return $results;
}
