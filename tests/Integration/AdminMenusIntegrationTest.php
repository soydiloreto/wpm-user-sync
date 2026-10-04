<?php
/**
 * The plugin's menus and the screens they draw, on a real network: who
 * gets each menu, what each screen shows, which tab a home page opens on,
 * and the notices that follow an action.
 *
 * @package WPMUS\Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration;

use WPMUS\Admin\NetworkHomePage;
use WPMUS\Admin\NetworkMenu;
use WPMUS\Admin\NetworkSyncActionsPage;
use WPMUS\Admin\NetworkSyncOptionsPage;
use WPMUS\Admin\SiteHomePage;
use WPMUS\Admin\SiteMenu;
use WPMUS\Admin\SiteSyncActionsPage;
use WPMUS\Assets;
use WPMUS\Config;
use WPMUS\Notices;
use WPMUS\Plugin;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\View\Header;

final class AdminMenusIntegrationTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->reset_menus();
	}

	protected function tearDown(): void {
		$this->reset_menus();
		unset( $_GET['tab'], $_GET['page'], $_GET['updated'], $_GET['synced'], $_GET['queued'], $_GET['nosynced'], $GLOBALS['sd_active_tab'] );
		wp_dequeue_style( 'wpmus_styles' );
		wp_deregister_style( 'wpmus_styles' );
		if ( ms_is_switched() ) {
			restore_current_blog();
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function reset_menus(): void {
		$GLOBALS['menu']                = array();
		$GLOBALS['submenu']             = array();
		$GLOBALS['admin_page_hooks']    = array();
		$GLOBALS['_registered_pages']   = array();
		$GLOBALS['_parent_pages']       = array();
		$GLOBALS['_wp_submenu_nopriv']  = array();
		$GLOBALS['_wp_real_parent_file'] = array();
	}

	private function plugin_file(): string {
		return dirname( __DIR__, 2 ) . '/wpm-user-sync.php';
	}

	private function network_menu(): NetworkMenu {
		$plugin = Plugin::instance();
		$this->assertNotNull( $plugin );
		$config = new Config( $this->plugin_file() );

		return new NetworkMenu( new Header(), new NetworkHomePage(), new NetworkSyncOptionsPage( $config ), new NetworkSyncActionsPage( new SiteRepository(), $plugin->engine(), new JobQueue() ) );
	}

	private function site_menu(): SiteMenu {
		$plugin = Plugin::instance();
		$this->assertNotNull( $plugin );

		return new SiteMenu( new Header(), new SiteHomePage(), new SiteSyncActionsPage( $plugin->engine() ) );
	}

	private function render( callable $render ): string {
		ob_start();
		$render();

		return (string) ob_get_clean();
	}

	/**
	 * The page slugs registered under a parent menu.
	 *
	 * @return string[]
	 */
	private function submenu_slugs( string $parent ): array {
		return array_map( static fn ( array $item ): string => (string) $item[2], $GLOBALS['submenu'][ $parent ] ?? array() );
	}

	private function site_admin( string $slug ): int {
		$admin_id = $this->make_user( $slug . '-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );
		$blog_id  = $this->make_site( $slug . '-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ), $admin_id );
		switch_to_blog( $blog_id );
		wp_set_current_user( $admin_id );

		return $admin_id;
	}

	public function test_a_super_admin_gets_the_network_menu_with_its_options_and_actions(): void {
		wp_set_current_user( 1 );

		$this->network_menu()->register();

		$this->assertArrayHasKey( 'wpmus-networkhome', $GLOBALS['admin_page_hooks'] );
		$this->assertSame( 'manage_network_options', $GLOBALS['menu'][100][1] );
		$this->assertSame( array( 'wpmus-networkhome', 'wpmus-networksyncoptions', 'wpmus-networksyncactions' ), $this->submenu_slugs( 'wpmus-networkhome' ) );
	}

	public function test_a_site_administrator_gets_no_network_screen(): void {
		$this->site_admin( 'netmenu' );

		$this->network_menu()->register();

		$this->assertSame( array(), $this->submenu_slugs( 'wpmus-networkhome' ) );
		$this->assertArrayHasKey( 'wpmus-networksyncoptions', $GLOBALS['_wp_submenu_nopriv']['wpmus-networkhome'] );
		$this->assertArrayHasKey( 'wpmus-networksyncactions', $GLOBALS['_wp_submenu_nopriv']['wpmus-networkhome'] );
	}

	public function test_a_super_admin_gets_the_site_menu_on_a_site(): void {
		wp_set_current_user( 1 );

		$this->site_menu()->register();

		$this->assertSame( 'manage_network_users', $GLOBALS['menu'][100][1] );
		$this->assertSame( array( 'wpmus-sitehome', 'wpmus-sitesyncactions' ), $this->submenu_slugs( 'wpmus-sitehome' ) );
	}

	public function test_a_site_administrator_gets_no_site_menu(): void {
		$this->site_admin( 'sitemenu' );
		$this->assertTrue( current_user_can( 'manage_options' ), 'Pre-condition: a site administrator.' );

		$this->site_menu()->register();

		$this->assertSame( array(), $this->submenu_slugs( 'wpmus-sitehome' ) );
		$this->assertArrayHasKey( 'wpmus-sitesyncactions', $GLOBALS['_wp_submenu_nopriv']['wpmus-sitehome'] );
	}

	public function test_every_network_screen_draws_the_header_and_its_content(): void {
		wp_set_current_user( 1 );
		$menu = $this->network_menu();

		$home    = $this->render( array( $menu, 'render_home' ) );
		$options = $this->render( array( $menu, 'render_options' ) );
		$actions = $this->render( array( $menu, 'render_actions' ) );

		foreach ( array( $home, $options, $actions ) as $html ) {
			$this->assertStringContainsString( '<h2>DiluxOne Multisite User Sync</h2>', $html );
			$this->assertStringContainsString( '<strong>best free user synchronization solution</strong>', $html );
		}
		$this->assertStringContainsString( 'Welcome to DiluxOne Multisite User Sync', $home );
		$this->assertStringContainsString( 'name="wpmus_newSiteSync"', $options );
		$this->assertStringContainsString( 'wpmusSyncNetworkFromScratch', $actions );
	}

	public function test_every_site_screen_draws_the_header_and_its_content(): void {
		wp_set_current_user( 1 );
		$menu = $this->site_menu();

		$home    = $this->render( array( $menu, 'render_home' ) );
		$actions = $this->render( array( $menu, 'render_actions' ) );

		foreach ( array( $home, $actions ) as $html ) {
			$this->assertStringContainsString( '<h2>DiluxOne Multisite User Sync</h2>', $html );
		}
		$this->assertStringContainsString( 'nav-tab-active', $home );
		$this->assertStringContainsString( 'wpmusSyncSiteSiteFromScratch', $actions );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function home_tabs(): array {
		return array(
			'no tab'      => array( '', 'welcome', 'Welcome to DiluxOne Multisite User Sync' ),
			'welcome'     => array( 'welcome', 'welcome', 'Welcome to DiluxOne Multisite User Sync' ),
			'concepts'    => array( 'concepts', 'concepts', 'User Sync Concepts' ),
			'about'       => array( 'about', 'about', 'About DiluxOne Multisite User Sync' ),
			'unknown tab' => array( 'nope', 'welcome', 'Welcome to DiluxOne Multisite User Sync' ),
		);
	}

	/**
	 * @dataProvider home_tabs
	 */
	public function test_the_network_home_opens_the_requested_tab( string $requested, string $active, string $heading ): void {
		if ( '' !== $requested ) {
			$_GET['tab'] = $requested;
		}
		$seen = array();
		$tabs = static function ( string $tab ) use ( &$seen ): void {
			$seen['tabs'] = $tab;
		};
		$body = static function ( string $tab ) use ( &$seen ): void {
			$seen['contents'] = $tab;
		};
		add_action( 'wpmus_network_home_tabs', $tabs );
		add_action( 'wpmus_network_home_contents', $body );

		$html = $this->render( array( new NetworkHomePage(), 'render' ) );

		remove_action( 'wpmus_network_home_tabs', $tabs );
		remove_action( 'wpmus_network_home_contents', $body );
		$this->assertStringContainsString( $heading, $html );
		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active" href="[^"]*wpmus-networkhome&#038;tab=' . $active . '"/', $html );
		$this->assertSame( 2, substr_count( $html, 'class="nav-tab"' ), 'The two other tabs are drawn inactive.' );
		$this->assertSame( array( 'tabs' => $active, 'contents' => $active ), $seen, 'Extensions are told which tab is open.' );
		$this->assertSame( $active, $GLOBALS['sd_active_tab'], 'The 1.4 global still names the open tab.' );
	}

	/**
	 * @dataProvider home_tabs
	 */
	public function test_the_site_home_opens_the_requested_tab( string $requested, string $active ): void {
		if ( '' !== $requested ) {
			$_GET['tab'] = $requested;
		}
		$seen = array();
		$tabs = static function ( string $tab ) use ( &$seen ): void {
			$seen['tabs'] = $tab;
		};
		$body = static function ( string $tab ) use ( &$seen ): void {
			$seen['contents'] = $tab;
		};
		add_action( 'wpmus_site_home_tabs', $tabs );
		add_action( 'wpmus_site_home_contents', $body );

		$html = $this->render( array( new SiteHomePage(), 'render' ) );

		remove_action( 'wpmus_site_home_tabs', $tabs );
		remove_action( 'wpmus_site_home_contents', $body );
		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active" href="[^"]*wpmus-sitehome&#038;tab=' . $active . '"/', $html );
		$this->assertSame( array( 'tabs' => $active, 'contents' => $active ), $seen );
		$this->assertSame( $active, $GLOBALS['sd_active_tab'] );
	}

	public function test_the_welcome_tab_links_every_step_and_the_support_forum(): void {
		$html = $this->render( array( new NetworkHomePage(), 'render' ) );

		$this->assertStringContainsString( 'page=wpmus-networksyncactions', $html );
		$this->assertStringContainsString( 'page=wpmus-networksyncoptions', $html );
		$this->assertStringContainsString( '<a href="https://wordpress.org/support/plugin/wpm-user-sync/">support forum</a>', $html );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function notices(): array {
		return array(
			'options saved'  => array( 'updated', 'updated', "Settings updated. You&#039;re the best!" ),
			'sync done'      => array( 'synced', 'updated', "Sync done. You&#039;re a champion!" ),
			'sync queued'    => array( 'queued', 'notice-info', 'runs in the background in batches' ),
			'no site picked' => array( 'nosynced', 'notice-warning', 'You must select at least one site!' ),
		);
	}

	/**
	 * @dataProvider notices
	 */
	public function test_each_action_is_followed_by_its_notice( string $flag, string $css_class, string $message ): void {
		$_GET['page'] = 'wpmus-networksyncactions';
		$_GET[ $flag ] = 'true';

		$html = $this->render( array( new Notices(), 'render' ) );

		$this->assertStringContainsString( '<div id="message" class="' . $css_class . ' notice is-dismissible">', $html );
		$this->assertStringContainsString( $message, $html );
		$this->assertStringContainsString( 'Dismiss this notice.', $html );
	}

	public function test_no_notice_without_a_page(): void {
		$_GET['updated'] = 'true';

		$this->assertSame( '', $this->render( array( new Notices(), 'render' ) ) );
	}

	public function test_the_stylesheet_loads_on_the_plugins_screens_only(): void {
		$assets = new Assets( $this->plugin_file() );

		$assets->enqueue_admin_styles( 'index.php' );
		$this->assertFalse( wp_style_is( 'wpmus_styles', 'enqueued' ) );

		$assets->enqueue_admin_styles( 'toplevel_page_wpmus-networkhome' );
		$this->assertTrue( wp_style_is( 'wpmus_styles', 'enqueued' ) );
		$this->assertStringEndsWith( '/css/wpmus_styles.css', (string) wp_styles()->registered['wpmus_styles']->src );
		$this->assertSame( WPMUS_VERSION, wp_styles()->registered['wpmus_styles']->ver );
	}
}
