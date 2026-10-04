<?php
/**
 * Main plugin orchestrator. Constructed once from the bootstrap in
 * `wpm-user-sync.php`, builds the dependency graph, and registers
 * every WordPress hook the plugin uses. After `register()` returns
 * the plugin is fully wired and the WordPress runtime takes over.
 *
 * The class is deliberately small — its job is the wiring, not the
 * logic. Logic lives in the per-feature classes under `src/`.
 *
 * @package WPMUS
 */

declare(strict_types=1);

namespace WPMUS;

use WPMUS\Admin\NetworkHomePage;
use WPMUS\Admin\NetworkMenu;
use WPMUS\Admin\NetworkSyncActionsPage;
use WPMUS\Admin\NetworkSyncOptionsPage;
use WPMUS\Admin\SiteHomePage;
use WPMUS\Admin\SiteMenu;
use WPMUS\Admin\SiteSyncActionsPage;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Repositories\UserRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;
use WPMUS\View\Header;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Singleton bootstrap class for the plugin. Constructs the dependency
 * graph and wires the WordPress hooks. Constructed exactly once from
 * `wpm-user-sync.php` on every request; the singleton handle is then
 * reachable via {@see Plugin::instance()}.
 */
final class Plugin {

	private static ?self $instance = null;

	private string $plugin_file;
	private Config $config;
	private RequirementsChecker $requirements;
	private Assets $assets;
	private Notices $notices;
	private SyncEngine $engine;
	private NetworkMenu $network_menu;
	private NetworkSyncOptionsPage $network_options;
	private NetworkSyncActionsPage $network_actions;
	private SiteMenu $site_menu;
	private SiteSyncActionsPage $site_actions;

	/**
	 * @param string $plugin_file Absolute path to wpm-user-sync.php.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
		self::$instance    = $this;

		$this->config       = new Config( $plugin_file );
		$this->requirements = new RequirementsChecker( $this->config );
		$this->assets       = new Assets( $plugin_file );
		$this->notices      = new Notices();

		$site_repo    = new SiteRepository();
		$user_repo    = new UserRepository();
		$queue        = new JobQueue();
		$this->engine = new SyncEngine( $this->config, $site_repo, $user_repo, $queue );

		$header                = new Header();
		$network_home          = new NetworkHomePage();
		$this->network_options = new NetworkSyncOptionsPage( $this->config );
		$this->network_actions = new NetworkSyncActionsPage( $site_repo, $this->engine, $queue );
		$this->network_menu    = new NetworkMenu( $header, $network_home, $this->network_options, $this->network_actions );

		$site_home          = new SiteHomePage();
		$this->site_actions = new SiteSyncActionsPage( $this->engine );
		$this->site_menu    = new SiteMenu( $header, $site_home, $this->site_actions );
	}

	/**
	 * Wire every WordPress hook the plugin listens on.
	 */
	public function register(): void {
		// Lifecycle. The requirements check deactivates the plugin with an
		// explanation where it cannot run.
		add_action( 'admin_init', array( $this->requirements, 'check' ) );

		// Outside a network there is nothing to sync, so nothing else is
		// hooked: no menus, no triggers, no queue.
		if ( ! is_multisite() ) {
			return;
		}

		register_activation_hook( $this->plugin_file, array( $this, 'on_activate' ) );
		add_action( 'init', array( $this, 'on_init' ) );

		// Admin menus.
		add_action( 'network_admin_menu', array( $this->network_menu, 'register' ) );
		add_action( 'admin_menu', array( $this->site_menu, 'register' ) );

		// Admin form save handlers.
		add_action( 'network_admin_edit_wpmusSaveGlobalConfig', array( $this->network_options, 'handle_save' ) );
		add_action( 'network_admin_edit_wpmusSyncNetworkFromScratch', array( $this->network_actions, 'handle_sync_all' ) );
		add_action( 'network_admin_edit_wpmusSyncNetworkSiteFromScratch', array( $this->network_actions, 'handle_sync_selected' ) );
		add_action( 'admin_action_wpmusSyncSiteSiteFromScratch', array( $this->site_actions, 'handle_sync_current_site' ) );

		// Admin notices.
		add_action( 'network_admin_notices', array( $this->notices, 'render' ) );
		add_action( 'admin_notices', array( $this->notices, 'render' ) );

		// Sync triggers. They are always hooked and each callback reads
		// its toggle when it runs, so turning a trigger on or off takes
		// effect at once, and the toggle is checked in one place.
		//
		// A new site: `wp_initialize_site` (WordPress 5.1+) replaces the
		// deprecated `wpmu_new_blog`; priority 11 runs after core has
		// populated the site at 10 and switched back.
		add_action( 'wp_initialize_site', array( $this->engine, 'on_new_site' ), 11, 1 );
		// A new user: `wpmu_new_user` fires from wpmu_create_user() once
		// core has stripped the default membership; `user_register`
		// catches accounts made with wp_insert_user() alone (a plugin's
		// own registration). The engine runs each user once.
		add_action( 'wpmu_new_user', array( $this->engine, 'on_new_user' ) );
		add_action( 'user_register', array( $this->engine, 'on_user_registered' ) );
		add_action( 'wpmu_activate_user', array( $this->engine, 'on_user_activated' ), 20 );
		add_action( 'shutdown', array( $this->engine, 'flush_registered_users' ) );
		add_action( 'set_user_role', array( $this->engine, 'on_role_changed' ), 10, 3 );

		// Big syncs run in batches from WP-Cron.
		add_action( JobQueue::CRON_HOOK, array( $this->engine, 'process_queue' ) );

		// Removals are recorded whatever the toggles say, so a trigger
		// turned on later still leaves those people off those sites.
		add_action( 'remove_user_from_blog', array( $this->engine, 'on_user_removed_from_blog' ), 10, 2 );
		add_action( 'add_user_to_blog', array( $this->engine, 'on_user_added_to_blog' ), 10, 3 );
	}

	/**
	 * Plugin-activation callback. Reserved for future activation work
	 * (table creation, default option seeding, etc.); kept registered
	 * so anyone reading the plugin metadata sees a real activation
	 * entry point.
	 */
	public function on_activate(): void {
		// Reserved for future activation work; kept as a registered
		// callback so anyone reading the plugin metadata sees a real
		// activation entry point.
	}

	/**
	 * `init` hook callback. Registers the admin-asset enqueuer.
	 */
	public function on_init(): void {
		add_action( 'admin_enqueue_scripts', array( $this->assets, 'enqueue_admin_styles' ) );
	}

	/**
	 * Returns the live Plugin singleton, or null if no instance has
	 * been constructed yet. The singleton is set in `__construct()`
	 * (not `register()`), so once `wpm-user-sync.php` has run its
	 * `new Plugin( __FILE__ )` line this is non-null for the rest of
	 * the request. Used by the deprecated procedural wrappers in
	 * `legacy-deprecated.php` to dispatch into the live engine.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Live SyncEngine instance. Exposed so the deprecated wrappers
	 * can dispatch into it without rebuilding the dependency graph.
	 */
	public function engine(): SyncEngine {
		return $this->engine;
	}
}
