<?php
/**
 * Unit tests for {@see \WPMUS\Admin\NetworkMenu}: the network menu and
 * its capabilities, and that each screen is the header plus its page.
 *
 * @package WPMUS\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Stubs\AdminScreen;
use Tests\TestCase;
use WPMUS\Admin\NetworkHomePage;
use WPMUS\Admin\NetworkMenu;
use WPMUS\Admin\NetworkSyncActionsPage;
use WPMUS\Admin\NetworkSyncOptionsPage;
use WPMUS\Config;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Repositories\UserRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;
use WPMUS\View\Header;

final class NetworkMenuTest extends TestCase {

	use AdminScreen;

	private NetworkMenu $menu;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_admin_screen();
		Functions\when( 'wp_kses' )->returnArg();

		$config = Mockery::mock( Config::class );
		$config->shouldReceive( 'is_new_site_sync_enabled', 'is_new_user_sync_enabled', 'is_set_user_role_sync_enabled' )->andReturn( false );
		$sites = Mockery::mock( SiteRepository::class );
		$sites->shouldReceive( 'all_sites' )->andReturn( array() );
		$queue = Mockery::mock( JobQueue::class );
		$queue->shouldReceive( 'all' )->andReturn( array() );
		$engine = new SyncEngine( $config, $sites, Mockery::mock( UserRepository::class ), $queue );

		$this->menu = new NetworkMenu(
			new Header(),
			new NetworkHomePage(),
			new NetworkSyncOptionsPage( $config ),
			new NetworkSyncActionsPage( $sites, $engine, $queue )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sd_active_tab'] );
		parent::tearDown();
	}

	public function test_the_menu_needs_manage_network_options_and_opens_the_home_screen(): void {
		Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
		Functions\expect( 'add_menu_page' )
			->once()
			->with( 'DiluxOne Multisite User Sync', 'User Sync', 'manage_network_options', 'wpmus-networkhome', array( $this->menu, 'render_home' ), 'dashicons-admin-generic', 100 );

		$this->menu->register();
	}

	public function test_options_need_manage_network_options_and_actions_need_manage_network_users(): void {
		Functions\when( 'add_menu_page' )->justReturn( 'hook' );
		Functions\expect( 'add_submenu_page' )
			->once()
			->with( 'wpmus-networkhome', 'Network Sync Options', 'Network Sync Options', 'manage_network_options', 'wpmus-networksyncoptions', array( $this->menu, 'render_options' ) );
		Functions\expect( 'add_submenu_page' )
			->once()
			->with( 'wpmus-networkhome', 'Network Sync Actions', 'Network Sync Actions', 'manage_network_users', 'wpmus-networksyncactions', array( $this->menu, 'render_actions' ) );

		$this->menu->register();
	}

	/**
	 * Asserts the header comes first and `$marker` from the page after it.
	 */
	private function assert_header_then( string $marker, string $output ): void {
		$header = strpos( $output, '<h2>DiluxOne Multisite User Sync</h2>' );
		$page   = strpos( $output, $marker );
		$this->assertNotFalse( $header, 'The header is rendered.' );
		$this->assertNotFalse( $page, 'The page is rendered.' );
		$this->assertLessThan( $page, $header );
	}

	public function test_the_home_screen_is_the_header_then_the_home_page(): void {
		$menu = $this->menu;

		$this->assert_header_then( 'Welcome to DiluxOne Multisite User Sync', $this->output_of( array( $menu, 'render_home' ) ) );
	}

	public function test_the_options_screen_is_the_header_then_the_options_form(): void {
		$menu = $this->menu;

		$this->assert_header_then( 'Network Configuration', $this->output_of( array( $menu, 'render_options' ) ) );
	}

	public function test_the_actions_screen_is_the_header_then_the_sync_forms(): void {
		$menu = $this->menu;

		$this->assert_header_then( 'Network Sync Actions', $this->output_of( array( $menu, 'render_actions' ) ) );
	}
}
