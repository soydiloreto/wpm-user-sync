<?php
/**
 * Unit tests for `legacy-deprecated.php`: each 1.4 function name still
 * exists, says it is deprecated, and dispatches to the live engine,
 * which reads its toggle; without a plugin instance it does nothing.
 *
 * The file declares global functions, so every test runs in its own
 * process.
 *
 * @package WPMUS\Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey\Functions;
use Tests\TestCase;
use WPMUS\Config;
use WPMUS\Plugin;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LegacyDeprecatedTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/legacy-deprecated.php';
	}

	public function test_sync_newsite_is_deprecated_in_favour_of_on_new_site(): void {
		Functions\expect( '_deprecated_function' )->once()->with( 'wpmus_sync_newsite', '1.5.0', '\\WPMUS\\Sync\\SyncEngine::on_new_site' );
		Functions\expect( 'get_site_option' )->never();

		$this->assertNull( Plugin::instance() );
		wpmus_sync_newsite( 7 );
	}

	public function test_sync_newsite_runs_the_new_site_trigger_of_the_live_engine(): void {
		Functions\when( '_deprecated_function' )->justReturn( null );
		new Plugin( '/path/to/wpm-user-sync.php' );
		Functions\expect( 'get_site_option' )->once()->with( Config::OPTION_NEW_SITE_SYNC )->andReturn( '' );

		wpmus_sync_newsite( 7 );
	}

	public function test_sync_newuser_is_deprecated_in_favour_of_on_new_user(): void {
		Functions\expect( '_deprecated_function' )->once()->with( 'wpmus_sync_newuser', '1.5.0', '\\WPMUS\\Sync\\SyncEngine::on_new_user' );
		Functions\expect( 'get_site_option' )->never();

		wpmus_sync_newuser( 5 );
	}

	public function test_sync_newuser_runs_the_new_user_trigger_of_the_live_engine(): void {
		Functions\when( '_deprecated_function' )->justReturn( null );
		new Plugin( '/path/to/wpm-user-sync.php' );
		Functions\expect( 'get_site_option' )->once()->with( Config::OPTION_NEW_USER_SYNC )->andReturn( '' );

		wpmus_sync_newuser( 5 );
	}

	public function test_sync_newrole_is_deprecated_in_favour_of_on_role_changed(): void {
		Functions\expect( '_deprecated_function' )->once()->with( 'wpmus_sync_newrole', '1.5.0', '\\WPMUS\\Sync\\SyncEngine::on_role_changed' );
		Functions\expect( 'get_site_option' )->never();

		wpmus_sync_newrole( 5, 'editor' );
	}

	public function test_sync_newrole_runs_the_role_trigger_of_the_live_engine(): void {
		Functions\when( '_deprecated_function' )->justReturn( null );
		new Plugin( '/path/to/wpm-user-sync.php' );
		Functions\expect( 'get_site_option' )->once()->with( Config::OPTION_SET_USER_ROLE_SYNC )->andReturn( '' );

		wpmus_sync_newrole( 5, 'editor' );
	}

	public function test_the_sign_in_catch_up_is_a_deprecated_no_op(): void {
		new Plugin( '/path/to/wpm-user-sync.php' );
		Functions\expect( '_deprecated_function' )->once()->with( 'wpmus_maybesync_newuser', '1.5.0' );
		Functions\expect( 'get_site_option' )->never();

		wpmus_maybesync_newuser( 'someone' );
	}
}
