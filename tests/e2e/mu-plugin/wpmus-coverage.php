<?php
/**
 * Plugin Name: WPMUS end-to-end coverage
 * Description: Test-only. While `make coverage-e2e` runs (Xdebug in coverage mode and build/coverage/e2e/ present in the plugin's folder), records which of the plugin's lines each request executes, so the end-to-end suite's coverage can be measured. Does nothing otherwise. Its folder is mapped as mu-plugins by .wp-env.json and the Plugin Check environment; never shipped (tests/ is in .distignore).
 *
 * @package WPMUS\Tests\Coverage
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	if ( ! function_exists( 'xdebug_start_code_coverage' ) || ! in_array( 'coverage', explode( ',', (string) ini_get( 'xdebug.mode' ) ), true ) ) {
		return;
	}

	// The plugin's folder is the repository's, whatever it is named: the
	// first folder holding the main file. Both wp-env environments mount the
	// plugin once; a second copy would be measured in its place.
	$main = glob( WP_PLUGIN_DIR . '/*/wpm-user-sync.php' );
	if ( ! $main ) {
		return;
	}
	$plugin = dirname( $main[0] );
	$out    = $plugin . '/build/coverage/e2e';
	if ( ! is_dir( $out ) ) {
		return;
	}

	xdebug_start_code_coverage( XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE );

	register_shutdown_function(
		static function () use ( $plugin, $out ): void {
			$lines = array();
			foreach ( xdebug_get_code_coverage() as $file => $hits ) {
				if ( str_starts_with( $file, $plugin . '/src/' ) || in_array( substr( $file, strlen( $plugin ) + 1 ), array( 'wpm-user-sync.php', 'uninstall.php', 'legacy-deprecated.php' ), true ) ) {
					$lines[ substr( $file, strlen( $plugin ) + 1 ) ] = array_keys( array_filter( $hits, static fn ( $hit ) => 1 === $hit ) );
				}
			}
			if ( $lines ) {
				file_put_contents( $out . '/' . uniqid( '', true ) . '.json', (string) wp_json_encode( $lines ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test-only, writes into the checkout's build/.
			}
		}
	);
} )();
