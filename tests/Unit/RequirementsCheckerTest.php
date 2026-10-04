<?php
/**
 * Unit tests for {@see \WPMUS\RequirementsChecker}: on a network that
 * meets the minimum it does nothing; anywhere else it deactivates the
 * plugin and explains why.
 *
 * @package WPMUS\Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;
use Tests\Stubs\AdminIncludes;
use Tests\TestCase;
use WPMUS\Config;
use WPMUS\RequirementsChecker;

final class RequirementsCheckerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '7.1';
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_admin_url' )->justReturn( 'https://example.test/wp-admin/plugins.php' );
		// wp_die() ends the request; here it ends the test with what it was told.
		Functions\when( 'wp_die' )->alias(
			static function ( $message ): void {
				throw new RuntimeException( (string) $message );
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_version'] );
		parent::tearDown();
	}

	private function checker( string $requires_wp = '6.6' ): RequirementsChecker {
		$config = Mockery::mock( Config::class );
		$config->shouldReceive( 'plugin_basename' )->andReturn( 'wpm-user-sync/wpm-user-sync.php' );
		$config->shouldReceive( 'plugin_data' )->andReturn( array( 'Name' => 'DiluxOne Multisite User Sync' ) );
		$config->shouldReceive( 'required_wp_version' )->andReturn( $requires_wp );

		return new RequirementsChecker( $config );
	}

	public function test_a_network_on_a_supported_wordpress_is_left_alone(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'deactivate_plugins' )->never();

		$this->checker()->check();
	}

	public function test_a_single_site_deactivates_the_plugin_and_says_it_needs_a_network(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'deactivate_plugins' )->once()->with( 'wpm-user-sync/wpm-user-sync.php' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'requires WordPress Multisite, and has been deactivated' );

		$this->checker()->check();
	}

	public function test_a_single_site_where_the_plugin_is_already_inactive_shows_nothing(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'deactivate_plugins' )->never();
		Functions\expect( 'wp_die' )->never();

		$this->checker()->check();
	}

	public function test_an_older_wordpress_deactivates_the_plugin_and_names_the_version(): void {
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'deactivate_plugins' )->once();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'requires WordPress <strong>6.6</strong> or higher' );

		$this->checker()->check();
	}

	public function test_an_older_wordpress_where_the_plugin_is_already_inactive_shows_nothing(): void {
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\expect( 'is_multisite' )->never();
		Functions\expect( 'deactivate_plugins' )->never();

		$this->checker()->check();
	}

	public function test_without_a_minimum_version_only_the_network_is_checked(): void {
		$GLOBALS['wp_version'] = '3.0';
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'deactivate_plugins' )->never();

		$this->checker( '' )->check();
	}

	public function test_the_plugin_name_falls_back_when_the_header_has_none(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'deactivate_plugins' )->justReturn( null );
		$config = Mockery::mock( Config::class );
		$config->shouldReceive( 'plugin_basename' )->andReturn( 'wpm-user-sync/wpm-user-sync.php' );
		$config->shouldReceive( 'plugin_data' )->andReturn( array() );
		$config->shouldReceive( 'required_wp_version' )->andReturn( '6.6' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '<strong>DiluxOne Multisite User Sync</strong> requires WordPress Multisite' );

		( new RequirementsChecker( $config ) )->check();
	}

	public function test_the_message_links_back_to_the_plugins_page(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'deactivate_plugins' )->justReturn( null );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Back to the WordPress <a href="https://example.test/wp-admin/plugins.php">Plugins page</a>.' );

		$this->checker()->check();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_admin_plugin_api_is_loaded_when_it_is_missing(): void {
		AdminIncludes::install();
		$this->assertFalse( function_exists( 'is_plugin_active' ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'deactivate_plugins' )->never();

		$this->checker()->check();

		$this->assertTrue( function_exists( 'is_plugin_active' ) );
	}
}
