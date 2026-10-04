<?php
/**
 * Unit tests for {@see \WPMUS\Admin\SiteMenu}: a site's menu shows to
 * those who manage the network's users only, never to a site
 * administrator, and each screen is the header plus its page.
 *
 * @package WPMUS\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Stubs\AdminScreen;
use Tests\TestCase;
use WPMUS\Admin\SiteHomePage;
use WPMUS\Admin\SiteMenu;
use WPMUS\Admin\SiteSyncActionsPage;
use WPMUS\Config;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Repositories\UserRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;
use WPMUS\View\Header;

final class SiteMenuTest extends TestCase {

	use AdminScreen;

	private SiteMenu $menu;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_admin_screen();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );

		$engine     = new SyncEngine(
			Mockery::mock( Config::class ),
			Mockery::mock( SiteRepository::class ),
			Mockery::mock( UserRepository::class ),
			Mockery::mock( JobQueue::class )
		);
		$this->menu = new SiteMenu( new Header(), new SiteHomePage(), new SiteSyncActionsPage( $engine ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sd_active_tab'] );
		parent::tearDown();
	}

	public function test_the_menu_needs_manage_network_users_and_opens_the_home_screen(): void {
		Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
		Functions\expect( 'add_menu_page' )
			->once()
			->with( 'DiluxOne Multisite User Sync', 'User Sync', 'manage_network_users', 'wpmus-sitehome', array( $this->menu, 'render_home' ), 'dashicons-admin-generic', 100 );

		$this->menu->register();
	}

	public function test_the_actions_submenu_needs_manage_network_users(): void {
		Functions\when( 'add_menu_page' )->justReturn( 'hook' );
		Functions\expect( 'add_submenu_page' )
			->once()
			->with( 'wpmus-sitehome', 'Site Sync Actions', 'Site Sync Actions', 'manage_network_users', 'wpmus-sitesyncactions', array( $this->menu, 'render_actions' ) );

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

	public function test_the_actions_screen_is_the_header_then_the_sync_form(): void {
		$menu = $this->menu;

		$this->assert_header_then( '<h3>Site Sync Actions</h3>', $this->output_of( array( $menu, 'render_actions' ) ) );
	}
}
