<?php
/**
 * Plugin configuration: reads the three site options that drive the
 * sync triggers and exposes plugin metadata gathered from WordPress.
 *
 * The three options are stored at network level via `*_site_option()`
 * (NOT `*_option()`), so the same toggle applies to every site in the
 * multisite network. They are each a string — `'yes'` enables the
 * matching trigger, anything else (including missing) disables it.
 *
 * @package WPMUS
 */

declare(strict_types=1);

namespace WPMUS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Note: not declared `final` so Mockery can subclass it for unit
 * tests. The class is still treated as a leaf-of-the-hierarchy in
 * production — there are no extension points exposed here, and
 * subclassing it is a code smell rather than an extension API.
 */
class Config {

	public const OPTION_NEW_SITE_SYNC      = 'wpmus_newSiteSync';
	public const OPTION_NEW_USER_SYNC      = 'wpmus_newUserSync';
	public const OPTION_SET_USER_ROLE_SYNC = 'wpmus_setUserRoleSync';

	public const NONCE_ACTION = 'wpmus-validate';

	private string $plugin_file;

	/**
	 * @param string $plugin_file Absolute path to wpm-user-sync.php —
	 *                            used by `plugin_basename()` and
	 *                            `get_plugin_data()` calls below.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * True when the network-level "New Site Automatic Sync" toggle is
	 * enabled.
	 */
	public function is_new_site_sync_enabled(): bool {
		return 'yes' === (string) get_site_option( self::OPTION_NEW_SITE_SYNC );
	}

	/**
	 * True when the network-level "New User Automatic Sync" toggle is
	 * enabled.
	 */
	public function is_new_user_sync_enabled(): bool {
		return 'yes' === (string) get_site_option( self::OPTION_NEW_USER_SYNC );
	}

	/**
	 * True when the network-level "Set User Role Automatic Sync" toggle
	 * is enabled.
	 */
	public function is_set_user_role_sync_enabled(): bool {
		return 'yes' === (string) get_site_option( self::OPTION_SET_USER_ROLE_SYNC );
	}

	/**
	 * Persist the three toggles. Values that are not exactly `'yes'`
	 * are stored as the empty string, so re-reads return `false` from
	 * the matching `is_*_enabled()` accessor.
	 */
	public function save_toggles( string $new_site_sync, string $new_user_sync, string $set_user_role_sync ): void {
		update_site_option( self::OPTION_NEW_SITE_SYNC, 'yes' === $new_site_sync ? 'yes' : '' );
		update_site_option( self::OPTION_NEW_USER_SYNC, 'yes' === $new_user_sync ? 'yes' : '' );
		update_site_option( self::OPTION_SET_USER_ROLE_SYNC, 'yes' === $set_user_role_sync ? 'yes' : '' );
	}

	/**
	 * Absolute path to the plugin's main file (the value passed to
	 * the constructor). Returned as-is.
	 */
	public function plugin_file(): string {
		return $this->plugin_file;
	}

	/**
	 * Plugin basename (`wpm-user-sync/wpm-user-sync.php`-shape) — used
	 * to address the plugin in `is_plugin_active()` / `deactivate_plugins()`.
	 */
	public function plugin_basename(): string {
		return plugin_basename( $this->plugin_file );
	}

	/**
	 * Plugin metadata as returned by WordPress's `get_plugin_data()`.
	 *
	 * @return array<string,string>
	 */
	public function plugin_data(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		/** @var array<string,string> $data */
		$data = get_plugin_data( $this->plugin_file );
		return $data;
	}

	/**
	 * Reads the `Requires at least` header from the plugin metadata
	 * and returns it as a string. Empty string when the header is
	 * absent — callers should treat that as "no minimum".
	 */
	public function required_wp_version(): string {
		$data = $this->plugin_data();
		return isset( $data['RequiresWP'] ) ? (string) $data['RequiresWP'] : '';
	}
}
