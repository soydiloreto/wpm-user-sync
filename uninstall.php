<?php
/**
 * Uninstall handler. Runs only when the user explicitly deletes the
 * plugin from the wp-admin Plugins screen — `WP_UNINSTALL_PLUGIN` is
 * defined by WordPress before this file is loaded. Removes what the
 * plugin stored, all of it network-scoped: the three trigger toggles
 * and the background sync queue (network options), the queue's cron
 * event on the main site, and the record of who was removed from which
 * site (user meta). Users, roles and memberships are deliberately NOT
 * touched.
 *
 * The plugin's classes are not loaded here, so the keys are literals;
 * they match Config, JobQueue and UserRepository.
 *
 * @package WPMUS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // @codeCoverageIgnore
}

delete_site_option( 'wpmus_newSiteSync' );
delete_site_option( 'wpmus_newUserSync' );
delete_site_option( 'wpmus_setUserRoleSync' );
delete_site_option( 'wpmus_sync_jobs' );
delete_site_option( 'wpmus_sync_lock' );

switch_to_blog( get_main_site_id() );
wp_clear_scheduled_hook( 'wpmus_process_sync_queue' );
restore_current_blog();

delete_metadata( 'user', 0, 'wpmus_removed_from_blogs', '', true );
