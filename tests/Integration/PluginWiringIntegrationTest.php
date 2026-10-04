<?php
/**
 * How the plugin is wired into a real network: every hook it registers,
 * the requirements check, the 1.4 functions kept for compatibility, the
 * queue's lock and the triggers' guards against ids that name nothing.
 *
 * @package WPMUS\Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration;

use WPMUS\Config;
use WPMUS\Plugin;
use WPMUS\RequirementsChecker;
use WPMUS\Repositories\UserRepository;
use WPMUS\Sync\JobQueue;

final class PluginWiringIntegrationTest extends IntegrationTestCase {

	/** @var array<int, string> */
	private array $deprecated = array();

	protected function setUp(): void {
		parent::setUp();
		( new JobQueue() )->clear();
		add_action( 'deprecated_function_run', array( $this, 'record_deprecated' ) );
		add_filter( 'deprecated_function_trigger_error', '__return_false' );
	}

	protected function tearDown(): void {
		remove_action( 'deprecated_function_run', array( $this, 'record_deprecated' ) );
		remove_filter( 'deprecated_function_trigger_error', '__return_false' );
		remove_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		( new JobQueue() )->clear();
		parent::tearDown();
	}

	/**
	 * @param string $function_name The deprecated function that ran.
	 */
	public function record_deprecated( $function_name ): void {
		$this->deprecated[] = (string) $function_name;
	}

	/**
	 * @return callable
	 */
	public function die_handler() {
		return static function ( $message ): void {
			throw new \RuntimeException( is_string( $message ) ? $message : 'error' );
		};
	}

	private function plugin(): Plugin {
		$plugin = Plugin::instance();
		$this->assertNotNull( $plugin );

		return $plugin;
	}

	private function unique( string $slug ): string {
		return $slug . '-' . substr( str_replace( '.', '', (string) microtime( true ) ), -7 );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public function hooks(): array {
		return array(
			'requirements'      => array( 'admin_init', 'check', 10 ),
			'init'              => array( 'init', 'on_init', 10 ),
			'new site'          => array( 'wp_initialize_site', 'on_new_site', 11 ),
			'new user'          => array( 'wpmu_new_user', 'on_new_user', 10 ),
			'registered user'   => array( 'user_register', 'on_user_registered', 10 ),
			'activated invitee' => array( 'wpmu_activate_user', 'on_user_activated', 20 ),
			'end of request'    => array( 'shutdown', 'flush_registered_users', 10 ),
			'role change'       => array( 'set_user_role', 'on_role_changed', 10 ),
			'queue'             => array( JobQueue::CRON_HOOK, 'process_queue', 10 ),
			'removal'           => array( 'remove_user_from_blog', 'on_user_removed_from_blog', 10 ),
			'added back'        => array( 'add_user_to_blog', 'on_user_added_to_blog', 10 ),
			'options save'      => array( 'network_admin_edit_wpmusSaveGlobalConfig', 'handle_save', 10 ),
			'network sync'      => array( 'network_admin_edit_wpmusSyncNetworkFromScratch', 'handle_sync_all', 10 ),
			'sites sync'        => array( 'network_admin_edit_wpmusSyncNetworkSiteFromScratch', 'handle_sync_selected', 10 ),
			'site sync'         => array( 'admin_action_wpmusSyncSiteSiteFromScratch', 'handle_sync_current_site', 10 ),
			'network notices'   => array( 'network_admin_notices', 'render', 10 ),
			'site notices'      => array( 'admin_notices', 'render', 10 ),
			'network menu'      => array( 'network_admin_menu', 'register', 10 ),
			'site menu'         => array( 'admin_menu', 'register', 10 ),
		);
	}

	/**
	 * @dataProvider hooks
	 */
	public function test_on_a_network_the_plugin_hooks_each_callback( string $hook, string $method, int $priority ): void {
		$found = false;
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? array() as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && is_object( $function[0] ) && str_starts_with( get_class( $function[0] ), 'WPMUS\\' ) && $method === $function[1] ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, "WPMUS hooks {$method} on {$hook} at {$priority}." );
	}

	public function test_on_init_the_stylesheet_is_hooked_for_the_admin(): void {
		$this->plugin()->on_init();

		$this->assertNotFalse( has_action( 'admin_enqueue_scripts' ) );
		$hooked = false;
		foreach ( $GLOBALS['wp_filter']['admin_enqueue_scripts']->callbacks[10] as $callback ) {
			$hooked = $hooked || ( is_array( $callback['function'] ) && 'enqueue_admin_styles' === $callback['function'][1] );
		}
		$this->assertTrue( $hooked );
	}

	public function test_activating_does_no_work(): void {
		$before = get_site_option( Config::OPTION_NEW_USER_SYNC );

		$this->plugin()->on_activate();

		$this->assertSame( $before, get_site_option( Config::OPTION_NEW_USER_SYNC ) );
		$this->assertSame( array(), ( new JobQueue() )->all() );
	}

	public function test_on_a_network_the_requirements_check_leaves_the_plugin_active(): void {
		$config = new Config( dirname( __DIR__, 2 ) . '/wpm-user-sync.php' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$was_active = is_plugin_active( $config->plugin_basename() );

		( new RequirementsChecker( $config ) )->check();

		$this->assertSame( $was_active, is_plugin_active( $config->plugin_basename() ) );
	}

	public function test_on_an_older_wordpress_the_plugin_explains_and_deactivates(): void {
		global $wp_version;
		$config   = new Config( dirname( __DIR__, 2 ) . '/wpm-user-sync.php' );
		$basename = $config->plugin_basename();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$network_wide = is_plugin_active_for_network( $basename );
		if ( ! is_plugin_active( $basename ) ) {
			$this->markTestSkipped( 'The plugin is not active on the tests network.' );
		}
		$real_version = $wp_version;
		$wp_version   = '6.0'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the check reads it.
		add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );

		try {
			( new RequirementsChecker( $config ) )->check();
			$this->fail( 'The check must stop the request.' );
		} catch ( \RuntimeException $die ) {
			$this->assertStringContainsString( 'requires WordPress <strong>' . $config->required_wp_version() . '</strong> or higher, and has been deactivated!', $die->getMessage() );
			$this->assertStringContainsString( 'plugins.php">Plugins page</a>', $die->getMessage() );
			$this->assertFalse( is_plugin_active( $basename ) );
		} finally {
			$wp_version = $real_version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			activate_plugin( $basename, '', $network_wide, true );
		}
		$this->assertTrue( is_plugin_active( $basename ), 'The plugin is active again for the next tests.' );
	}

	public function test_the_plugin_reads_its_own_header(): void {
		$config = new Config( dirname( __DIR__, 2 ) . '/wpm-user-sync.php' );

		$this->assertSame( 'DiluxOne Multisite User Sync', $config->plugin_data()['Name'] );
		$this->assertSame( '6.6', $config->required_wp_version() );
		$this->assertStringEndsWith( '/wpm-user-sync.php', $config->plugin_basename() );
		$this->assertSame( dirname( __DIR__, 2 ) . '/wpm-user-sync.php', $config->plugin_file() );
	}

	public function test_the_1_4_new_site_function_still_syncs_and_says_it_is_deprecated(): void {
		update_site_option( Config::OPTION_NEW_SITE_SYNC, 'yes' );
		$user_id = $this->make_user( $this->unique( 'legacysite' ) );
		update_site_option( Config::OPTION_NEW_SITE_SYNC, '' );
		$blog_id = $this->make_site( $this->unique( 'legacysite-site' ) );
		update_site_option( Config::OPTION_NEW_SITE_SYNC, 'yes' );

		wpmus_sync_newsite( $blog_id );

		$this->assertTrue( $this->is_member( $user_id, $blog_id ) );
		$this->assertContains( 'wpmus_sync_newsite', $this->deprecated );
	}

	public function test_the_1_4_new_user_function_still_syncs_and_says_it_is_deprecated(): void {
		$blog_id = $this->make_site( $this->unique( 'legacyuser-site' ) );
		$user_id = $this->make_user( $this->unique( 'legacyuser' ) );
		update_site_option( Config::OPTION_NEW_USER_SYNC, 'yes' );

		wpmus_sync_newuser( $user_id );

		$this->assertTrue( $this->is_member( $user_id, $blog_id ) );
		$this->assertContains( 'wpmus_sync_newuser', $this->deprecated );
	}

	public function test_the_1_4_role_function_still_copies_the_role_and_says_it_is_deprecated(): void {
		$user_id = $this->make_user( $this->unique( 'legacyrole' ) );
		$first   = $this->make_site( $this->unique( 'legacyrole-a' ) );
		$second  = $this->make_site( $this->unique( 'legacyrole-b' ) );
		add_user_to_blog( $first, $user_id, 'subscriber' );
		add_user_to_blog( $second, $user_id, 'subscriber' );
		update_site_option( Config::OPTION_SET_USER_ROLE_SYNC, 'yes' );

		wpmus_sync_newrole( $user_id, 'editor' );

		switch_to_blog( $second );
		$roles = get_userdata( $user_id )->roles;
		restore_current_blog();
		$this->assertSame( array( 'editor' ), array_values( $roles ) );
		$this->assertContains( 'wpmus_sync_newrole', $this->deprecated );
	}

	public function test_the_1_4_maybe_sync_function_does_nothing_but_say_it_is_deprecated(): void {
		$blog_id = $this->make_site( $this->unique( 'legacymaybe-site' ) );
		$user_id = $this->make_user( $this->unique( 'legacymaybe' ) );
		update_site_option( Config::OPTION_NEW_USER_SYNC, 'yes' );

		wpmus_maybesync_newuser( (string) get_userdata( $user_id )->user_login );

		$this->assertFalse( $this->is_member( $user_id, $blog_id ) );
		$this->assertSame( array( 'wpmus_maybesync_newuser' ), $this->deprecated );
	}

	public function test_a_lock_held_by_a_live_run_keeps_another_run_out(): void {
		$queue = new JobQueue();

		$this->assertTrue( $queue->acquire_lock() );
		$this->assertFalse( $queue->acquire_lock(), 'A second run waits.' );
		$queue->release_lock();
		$this->assertTrue( $queue->acquire_lock(), 'Released, it can be taken again.' );
		$queue->release_lock();
	}

	public function test_a_lock_left_behind_by_a_run_that_died_is_taken_over(): void {
		$queue = new JobQueue();
		update_site_option( JobQueue::OPTION_LOCK, time() - JobQueue::LOCK_TTL - 1 );

		$this->assertTrue( $queue->acquire_lock() );
		$this->assertGreaterThanOrEqual( time() - 1, (int) get_site_option( JobQueue::OPTION_LOCK ), 'The lock now carries this run\'s time.' );
		$queue->release_lock();
	}

	public function test_a_queue_run_while_another_holds_the_lock_leaves_the_queue_alone(): void {
		$queue = new JobQueue();
		$queue->add( new \WPMUS\Sync\SyncJob( 'manual', null, null, false ) );
		$this->assertTrue( $queue->acquire_lock() );

		$this->plugin()->engine()->process_queue();

		$this->assertCount( 1, $queue->all(), 'The job is still there for the run holding the lock.' );
		$this->assertSame( 0, $queue->all()[0]->processed );
		$this->assertSame( $queue->lock_stale_at(), $this->next_run(), 'A retry waits for the lock to go stale, should its run have died.' );

		// The run holding the lock ends and leaves work: the next run is now.
		$queue->release_lock();
		$queue->schedule();
		$this->assertLessThanOrEqual( time(), $this->next_run() );
	}

	/**
	 * When the queue's next cron run is due on the main site, or 0.
	 */
	private function next_run(): int {
		switch_to_blog( get_main_site_id() );
		$next = wp_next_scheduled( JobQueue::CRON_HOOK );
		restore_current_blog();

		return (int) $next;
	}

	public function test_a_stored_queue_that_is_not_a_list_reads_as_empty(): void {
		update_site_option( JobQueue::OPTION_JOBS, 'garbage' );

		$this->assertSame( array(), ( new JobQueue() )->all() );
	}

	public function test_the_triggers_ignore_ids_that_name_nothing(): void {
		update_site_option( Config::OPTION_NEW_SITE_SYNC, 'yes' );
		update_site_option( Config::OPTION_NEW_USER_SYNC, 'yes' );
		$engine = $this->plugin()->engine();

		$engine->on_new_site( 0 );
		$engine->on_new_user( 0 );
		$engine->on_user_removed_from_blog( 0, 1 );
		$engine->on_user_removed_from_blog( 1, 0 );

		$this->assertSame( array(), ( new JobQueue() )->all() );
		$this->assertSame( array(), ( new UserRepository() )->removed_blog_ids( 1 ) );
	}

	public function test_a_sync_whose_every_site_is_skipped_finishes_at_once(): void {
		$blog_id = $this->make_site( $this->unique( 'archived' ) );
		$user_id = $this->make_user( $this->unique( 'archived-person' ) );
		update_blog_status( $blog_id, 'archived', '1' );

		$finished = $this->plugin()->engine()->sync_all_users_to_sites( array( $blog_id ) );

		$this->assertTrue( $finished );
		$this->assertFalse( $this->is_member( $user_id, $blog_id ) );
		$this->assertSame( array(), ( new JobQueue() )->all() );
	}

	public function test_a_user_removed_from_two_sites_and_added_back_to_one_stays_off_the_other(): void {
		$users   = new UserRepository();
		$user_id = $this->make_user( $this->unique( 'forget' ) );

		$users->record_removal( $user_id, 101 );
		$users->record_removal( $user_id, 102 );
		$users->forget_removal( $user_id, 101 );

		$this->assertSame( array( 102 ), $users->removed_blog_ids( $user_id ) );
	}
}
