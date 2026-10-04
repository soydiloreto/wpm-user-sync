<?php
/**
 * Every form handler, posted the way the browser posts it: a valid nonce
 * from someone without the network capability is refused before anything
 * changes, and a super admin's request does its work and lands back on the
 * screen with the right notice.
 *
 * @package WPMUS\Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration;

use WPMUS\Admin\NetworkSyncActionsPage;
use WPMUS\Admin\NetworkSyncOptionsPage;
use WPMUS\Config;
use WPMUS\Plugin;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Sync\JobQueue;

final class AdminHandlersIntegrationTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		unset( $_REQUEST['_wpnonce'], $_POST['wpmus_newSiteSync'], $_POST['wpmus_newUserSync'], $_POST['wpmus_setUserRoleSync'], $_POST['listSites'], $_POST['wpmus_force'] );
		if ( ms_is_switched() ) {
			restore_current_blog();
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @return callable
	 */
	public function die_handler() {
		return static function ( $message, $title = '', $args = array() ): void {
			throw new \RuntimeException( 'wp_die ' . ( is_array( $args ) ? (int) ( $args['response'] ?? 0 ) : 0 ) . ': ' . ( is_string( $message ) ? $message : 'error' ) );
		};
	}

	/**
	 * @param string $location Redirect target.
	 * @return string
	 */
	public function stop_redirect( $location ) {
		throw new \LogicException( (string) $location );
	}

	private function options_page(): NetworkSyncOptionsPage {
		return new NetworkSyncOptionsPage( new Config( dirname( __DIR__, 2 ) . '/wpm-user-sync.php' ) );
	}

	private function actions_page(): NetworkSyncActionsPage {
		$plugin = Plugin::instance();
		$this->assertNotNull( $plugin );

		return new NetworkSyncActionsPage( new SiteRepository(), $plugin->engine(), new JobQueue() );
	}

	/**
	 * Logs in a site administrator who is not a super admin, on their own
	 * site, with a valid nonce: only the capability can stop them.
	 */
	private function as_site_administrator( string $slug ): int {
		$slug     = $slug . '-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 );
		$admin_id = $this->make_user( $slug );
		$blog_id  = $this->make_site( $slug . '-site', $admin_id );
		switch_to_blog( $blog_id );
		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can( 'manage_options' ), 'Pre-condition: a site administrator.' );
		$this->assertFalse( current_user_can( 'manage_network_users' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Config::NONCE_ACTION );

		return $blog_id;
	}

	private function as_super_admin(): void {
		wp_set_current_user( 1 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Config::NONCE_ACTION );
	}

	/**
	 * Runs a handler that must end in a redirect, and returns where to.
	 */
	private function redirect_of( callable $handler ): string {
		try {
			$handler();
		} catch ( \LogicException $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( 'The handler must redirect when it is done.' );
	}

	/**
	 * Runs a handler that must refuse, and returns the wp_die message.
	 */
	private function refusal_of( callable $handler ): string {
		try {
			$handler();
		} catch ( \RuntimeException $die ) {
			return $die->getMessage();
		} catch ( \LogicException $redirect ) {
			$this->fail( 'The handler went ahead: ' . $redirect->getMessage() );
		}
		$this->fail( 'The handler must refuse.' );
	}

	public function test_a_site_administrator_cannot_change_the_network_options(): void {
		$this->as_site_administrator( 'optdeny' );
		$_POST['wpmus_newUserSync'] = 'yes';

		$message = $this->refusal_of( array( $this->options_page(), 'handle_save' ) );

		$this->assertSame( 'wp_die 403: You do not have permission to change network sync options.', $message );
		$this->assertFalse( get_site_option( Config::OPTION_NEW_USER_SYNC ), 'Nothing was stored.' );
	}

	public function test_a_super_admin_saves_the_options_and_goes_back_to_them(): void {
		$this->as_super_admin();
		$_POST['wpmus_newSiteSync']     = 'yes';
		$_POST['wpmus_setUserRoleSync'] = 'yes';

		$location = $this->redirect_of( array( $this->options_page(), 'handle_save' ) );

		$this->assertSame( network_admin_url( 'admin.php?page=wpmus-networksyncoptions&updated=true' ), $location );
		$this->assertSame( 'yes', get_site_option( Config::OPTION_NEW_SITE_SYNC ) );
		$this->assertSame( '', get_site_option( Config::OPTION_NEW_USER_SYNC ), 'A box left unticked is stored off.' );
		$this->assertSame( 'yes', get_site_option( Config::OPTION_SET_USER_ROLE_SYNC ) );
	}

	public function test_a_value_other_than_yes_is_stored_off(): void {
		$this->as_super_admin();
		$_POST['wpmus_newSiteSync'] = 'on';

		$this->redirect_of( array( $this->options_page(), 'handle_save' ) );

		$this->assertSame( '', get_site_option( Config::OPTION_NEW_SITE_SYNC ) );
	}

	public function test_a_site_administrator_cannot_sync_the_whole_network(): void {
		// Made before switching, so the account starts on no site but the main one.
		$bystander = $this->make_user( 'alldeny-bystander-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );
		$blog_id   = $this->as_site_administrator( 'alldeny' );

		$message = $this->refusal_of( array( $this->actions_page(), 'handle_sync_all' ) );

		$this->assertSame( 'wp_die 403: You do not have permission to run a network-wide sync.', $message );
		$this->assertFalse( $this->is_member( $bystander, $blog_id ), 'Nobody was synced.' );
	}

	public function test_a_site_administrator_cannot_sync_chosen_sites(): void {
		// Made before switching, so the account starts on no site but the main one.
		$bystander = $this->make_user( 'seldeny-bystander-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );
		$blog_id   = $this->as_site_administrator( 'seldeny' );
		$_POST['listSites'] = array( (string) $blog_id );

		$message = $this->refusal_of( array( $this->actions_page(), 'handle_sync_selected' ) );

		$this->assertSame( 'wp_die 403: You do not have permission to run a network sync.', $message );
		$this->assertFalse( $this->is_member( $bystander, $blog_id ), 'Nobody was synced.' );
	}

	public function test_a_sync_of_chosen_sites_with_none_ticked_warns_and_syncs_nothing(): void {
		$this->as_super_admin();
		$blog_id = $this->make_site( 'selnone-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );
		$user_id = $this->make_user( 'selnone-person-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );
		$_POST['listSites'] = array( '0', 'not-a-number' );

		$location = $this->redirect_of( array( $this->actions_page(), 'handle_sync_selected' ) );

		$this->assertSame( network_admin_url( 'admin.php?page=wpmus-networksyncactions&nosynced=true' ), $location );
		$this->assertFalse( $this->is_member( $user_id, $blog_id ) );
	}

	public function test_a_sync_of_chosen_sites_reports_it_is_done(): void {
		$this->as_super_admin();
		$blog_id = $this->make_site( 'seldone-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );
		$user_id = $this->make_user( 'seldone-person-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );
		$_POST['listSites'] = array( (string) $blog_id );

		$location = $this->redirect_of( array( $this->actions_page(), 'handle_sync_selected' ) );

		$this->assertSame( network_admin_url( 'admin.php?page=wpmus-networksyncactions&synced=true' ), $location );
		$this->assertTrue( $this->is_member( $user_id, $blog_id ) );
	}

	public function test_a_whole_network_sync_reports_it_is_done(): void {
		$this->as_super_admin();
		$blog_id = $this->make_site( 'alldone-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );
		$user_id = $this->make_user( 'alldone-person-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 ) );

		$location = $this->redirect_of( array( $this->actions_page(), 'handle_sync_all' ) );

		$this->assertSame( network_admin_url( 'admin.php?page=wpmus-networksyncactions&synced=true' ), $location );
		$this->assertTrue( $this->is_member( $user_id, $blog_id ) );
	}

	public function test_without_the_nonce_no_handler_runs(): void {
		wp_set_current_user( 1 );
		$_REQUEST['_wpnonce'] = 'forged';
		$_POST['wpmus_newUserSync'] = 'yes';

		foreach ( array( array( $this->options_page(), 'handle_save' ), array( $this->actions_page(), 'handle_sync_all' ), array( $this->actions_page(), 'handle_sync_selected' ) ) as $handler ) {
			$this->assertStringStartsWith( 'wp_die 403:', $this->refusal_of( $handler ) );
		}
		$this->assertFalse( get_site_option( Config::OPTION_NEW_USER_SYNC ) );
	}
}
