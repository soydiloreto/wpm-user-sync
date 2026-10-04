<?php
/**
 * Backwards-compatibility wrappers for the procedural function names
 * the plugin exposed before the 1.5.0 OOP refactor.
 *
 * No third-party caller has been identified across the maintainer's
 * accessible WordPress installations; these wrappers exist as a
 * defensive courtesy in case a network somewhere still hooks into
 * one of them. They will be removed in a future major release.
 *
 * Each wrapper resolves the live plugin instance via
 * {@see \WPMUS\Plugin::instance()} and dispatches to the matching
 * `SyncEngine` method. By the time anyone could be calling these,
 * `wpm-user-sync.php` has already constructed the singleton.
 *
 * @package WPMUS
 * @deprecated 1.5.0 Use the OOP API in src/ instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
// The legacy function names are intentionally kept in their original
// camel/snake mix for back-compat. These are deprecated and will be
// removed in a future major release.

/**
 * @deprecated 1.5.0 Use \WPMUS\Sync\SyncEngine::on_new_site() instead.
 */
function wpmus_sync_newsite( int $blog_id ): void {
	_deprecated_function( __FUNCTION__, '1.5.0', '\\WPMUS\\Sync\\SyncEngine::on_new_site' );
	$plugin = \WPMUS\Plugin::instance();
	if ( null !== $plugin ) {
		$plugin->engine()->on_new_site( $blog_id );
	}
}

/**
 * @deprecated 1.5.0 Use \WPMUS\Sync\SyncEngine::on_new_user() instead.
 */
function wpmus_sync_newuser( int $user_id ): void {
	_deprecated_function( __FUNCTION__, '1.5.0', '\\WPMUS\\Sync\\SyncEngine::on_new_user' );
	$plugin = \WPMUS\Plugin::instance();
	if ( null !== $plugin ) {
		$plugin->engine()->on_new_user( $user_id );
	}
}

/**
 * The sign-in catch-up sync is gone: it added people back to sites an
 * administrator had removed them from. Kept as a no-op so a caller
 * does not fatal.
 *
 * @deprecated 1.5.0 No replacement; use the manual network sync.
 */
function wpmus_maybesync_newuser( string $user_login ): void {
	_deprecated_function( __FUNCTION__, '1.5.0' );
	unset( $user_login );
}

/**
 * @deprecated 1.5.0 Use \WPMUS\Sync\SyncEngine::on_role_changed() instead.
 */
function wpmus_sync_newrole( int $user_id, string $role ): void {
	_deprecated_function( __FUNCTION__, '1.5.0', '\\WPMUS\\Sync\\SyncEngine::on_role_changed' );
	$plugin = \WPMUS\Plugin::instance();
	if ( null !== $plugin ) {
		$plugin->engine()->on_role_changed( $user_id, $role );
	}
}

// phpcs:enable
