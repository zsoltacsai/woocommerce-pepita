<?php
/**
 * Very small test runner.
 *
 * @package WPS\Pepita\Tests
 */

defined( 'ABSPATH' ) || exit;

final class WPS_Suite {

	/** @var array<int, array{name: string, callback: callable}> */
	private array $tests = array();

	private string $filter = '';

	public function __construct( string $filter = '' ) {
		$this->filter = $filter;
	}

	public function add( string $name, callable $callback ): void {
		$this->tests[] = array(
			'name'     => $name,
			'callback' => $callback,
		);
	}

	public function run(): int {
		$passed  = 0;
		$failed  = 0;
		$skipped = 0;
		$start   = microtime( true );

		foreach ( $this->tests as $test ) {
			if ( '' !== $this->filter && false === stripos( $test['name'], $this->filter ) ) {
				++$skipped;
				continue;
			}

			wps_test_reset_env();

			try {
				call_user_func( $test['callback'] );
				++$passed;
				echo '  PASS  ' . $test['name'] . "\n";
			} catch ( WPS_Assertion_Failed $failure ) {
				++$failed;
				echo '  FAIL  ' . $test['name'] . "\n";
				echo '        ' . $failure->getMessage() . "\n";
			} catch ( \Throwable $throwable ) {
				++$failed;
				echo '  ERROR ' . $test['name'] . "\n";
				echo '        ' . get_class( $throwable ) . ': ' . $throwable->getMessage() . "\n";
				echo '        at ' . $throwable->getFile() . ':' . $throwable->getLine() . "\n";
			}
		}

		$duration = microtime( true ) - $start;

		printf(
			"\n%d passed, %d failed, %d skipped in %.2fs\n",
			$passed,
			$failed,
			$skipped,
			$duration
		);

		return $failed > 0 ? 1 : 0;
	}
}
