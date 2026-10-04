<?php
/**
 * Unit tests for {@see \WPMUS\Config}.
 *
 * Brain Monkey stubs `get_site_option`, `update_site_option`, and
 * `plugin_basename` so the tests exercise the Config wrapper logic
 * without a WordPress runtime.
 *
 * @package WPMUS\Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey\Functions;
use Tests\Stubs\AdminIncludes;
use Tests\TestCase;
use WPMUS\Config;

final class ConfigTest extends TestCase {

	private function make_config(): Config {
		return new Config( '/path/to/wpm-user-sync.php' );
	}

	// ---------------------------------------------------------------------
	// is_*_enabled accessors
	// ---------------------------------------------------------------------

	public function test_new_site_sync_is_enabled_when_option_is_yes(): void {
		Functions\when( 'get_site_option' )->justReturn( 'yes' );

		$this->assertTrue( $this->make_config()->is_new_site_sync_enabled() );
	}

	public function test_new_site_sync_is_disabled_when_option_is_empty_string(): void {
		Functions\when( 'get_site_option' )->justReturn( '' );

		$this->assertFalse( $this->make_config()->is_new_site_sync_enabled() );
	}

	public function test_new_site_sync_is_disabled_when_option_is_missing(): void {
		Functions\when( 'get_site_option' )->justReturn( false );

		$this->assertFalse( $this->make_config()->is_new_site_sync_enabled() );
	}

	public function test_new_user_sync_reads_correct_option_key(): void {
		Functions\expect( 'get_site_option' )
			->once()
			->with( Config::OPTION_NEW_USER_SYNC )
			->andReturn( 'yes' );

		$this->assertTrue( $this->make_config()->is_new_user_sync_enabled() );
	}

	public function test_set_user_role_sync_reads_correct_option_key(): void {
		Functions\expect( 'get_site_option' )
			->once()
			->with( Config::OPTION_SET_USER_ROLE_SYNC )
			->andReturn( 'yes' );

		$this->assertTrue( $this->make_config()->is_set_user_role_sync_enabled() );
	}

	// ---------------------------------------------------------------------
	// save_toggles normalises any input that is not exactly 'yes' to ''
	// ---------------------------------------------------------------------

	public function test_save_toggles_writes_yes_for_yes_input(): void {
		Functions\expect( 'update_site_option' )
			->once()
			->with( Config::OPTION_NEW_SITE_SYNC, 'yes' );
		Functions\expect( 'update_site_option' )
			->once()
			->with( Config::OPTION_NEW_USER_SYNC, 'yes' );
		Functions\expect( 'update_site_option' )
			->once()
			->with( Config::OPTION_SET_USER_ROLE_SYNC, 'yes' );

		$this->make_config()->save_toggles( 'yes', 'yes', 'yes' );
		// Brain Monkey verifies the three `expect(...)` calls at
		// tearDown but doesn't bump PHPUnit's assertion counter.
		// Bump it manually so the test is not flagged as risky.
		$this->addToAssertionCount( 3 );
	}

	public function test_save_toggles_normalises_non_yes_inputs_to_empty_string(): void {
		Functions\expect( 'update_site_option' )
			->once()
			->with( Config::OPTION_NEW_SITE_SYNC, '' );
		Functions\expect( 'update_site_option' )
			->once()
			->with( Config::OPTION_NEW_USER_SYNC, '' );
		Functions\expect( 'update_site_option' )
			->once()
			->with( Config::OPTION_SET_USER_ROLE_SYNC, '' );

		// Inputs the form might submit when the matching checkbox is
		// unticked (PHP populates them as missing → empty in the
		// handler) plus a couple of garbage values for good measure.
		$this->make_config()->save_toggles( '', 'no', 'on' );
		$this->addToAssertionCount( 3 );
	}

	// ---------------------------------------------------------------------
	// plugin_file / plugin_basename / plugin_data
	// ---------------------------------------------------------------------

	public function test_plugin_file_returns_constructor_argument(): void {
		$config = new Config( '/some/absolute/path/wpm-user-sync.php' );

		$this->assertSame( '/some/absolute/path/wpm-user-sync.php', $config->plugin_file() );
	}

	public function test_plugin_basename_delegates_to_wp_helper(): void {
		Functions\expect( 'plugin_basename' )
			->once()
			->with( '/some/absolute/path/wpm-user-sync.php' )
			->andReturn( 'wpm-user-sync/wpm-user-sync.php' );

		$config = new Config( '/some/absolute/path/wpm-user-sync.php' );

		$this->assertSame( 'wpm-user-sync/wpm-user-sync.php', $config->plugin_basename() );
	}

	public function test_required_wp_version_reads_from_plugin_data(): void {
		Functions\expect( 'get_plugin_data' )
			->once()
			->andReturn( array( 'Name' => 'DiluxOne Multisite User Sync', 'RequiresWP' => '6.6' ) );

		$this->assertSame( '6.6', $this->make_config()->required_wp_version() );
	}

	public function test_required_wp_version_returns_empty_string_when_missing(): void {
		Functions\expect( 'get_plugin_data' )
			->once()
			->andReturn( array( 'Name' => 'DiluxOne Multisite User Sync' ) );

		$this->assertSame( '', $this->make_config()->required_wp_version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_plugin_data_loads_the_admin_plugin_api_when_it_is_missing(): void {
		AdminIncludes::install();
		$this->assertFalse( function_exists( 'get_plugin_data' ) );

		$data = ( new Config( '/some/absolute/path/wpm-user-sync.php' ) )->plugin_data();

		$this->assertSame( AdminIncludes::NAME, $data['Name'] );
		$this->assertSame( '/some/absolute/path/wpm-user-sync.php', $data['File'] );
	}
}
