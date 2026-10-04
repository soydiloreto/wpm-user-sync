<?php
/**
 * Unit test for `wpm-user-sync.php`: loading the main file on a
 * network defines the version, loads the autoloader and the 1.4
 * function names, and builds and registers the plugin.
 *
 * The file defines a constant and global functions, so the test runs
 * in its own process. The unit bootstrap already defines
 * `WPMUS_VERSION` (the classes need it without the main file), so the
 * file's own `define()` warns that it exists; that warning, and only
 * that one, is expected.
 *
 * @package WPMUS\Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey\Functions;
use Tests\TestCase;
use WPMUS\Plugin;

final class MainFileTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loading_the_main_file_on_a_network_builds_and_registers_the_plugin(): void {
		$main_file = dirname( __DIR__, 2 ) . '/wpm-user-sync.php';
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'register_activation_hook' )->once()->with( $main_file, \Mockery::type( 'array' ) );

		$warnings = array();
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				$warnings[] = $errstr;
				return true;
			},
			E_WARNING
		);
		try {
			require $main_file;
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'WPMUS_VERSION already defined', $warnings[0] );
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertNotFalse( has_action( 'admin_init' ) );
		$this->assertNotFalse( has_action( 'wp_initialize_site' ) );
		$this->assertTrue( function_exists( 'wpmus_sync_newsite' ), 'The 1.4 function names are loaded.' );
	}
}
