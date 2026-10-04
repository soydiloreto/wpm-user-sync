<?php
/**
 * Self-deactivates the plugin if the host WordPress install does not
 * meet the minimum supported version OR is not a multisite. Both
 * checks are part of the contract — `wpm-user-sync` is multisite-only
 * and refuses to run anywhere else.
 *
 * Hooked on `admin_init` so the deactivation runs in an admin request
 * and the user sees the `wp_die()` notice with an explanation.
 *
 * @package WPMUS
 */

declare(strict_types=1);

namespace WPMUS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Verifies the host WordPress install satisfies the plugin's runtime
 * requirements (minimum WP version + multisite enabled) and
 * self-deactivates with a `wp_die()` notice when either is missing.
 */
final class RequirementsChecker {

	private Config $config;

	/**
	 * @param Config $config Provides plugin metadata + the basename
	 *                       used by `is_plugin_active()`.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Hooked on `admin_init` from {@see \WPMUS\Plugin}. Walks the
	 * two checks (WP version, multisite) and `wp_die`s with the
	 * matching message when one fails.
	 */
	public function check(): void {
		global $wp_version;

		$plugin_basename = $this->config->plugin_basename();
		$plugin_data     = $this->config->plugin_data();
		$plugin_name     = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : 'DiluxOne Multisite User Sync';
		$required_wp     = $this->config->required_wp_version();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( '' !== $required_wp && version_compare( (string) $wp_version, $required_wp, '<' ) ) {
			if ( is_plugin_active( $plugin_basename ) ) {
				deactivate_plugins( $plugin_basename );
				$this->die_with_message( $plugin_name, $required_wp, 'wp-version' );
			}
			return;
		}

		if ( ! is_multisite() ) {
			if ( is_plugin_active( $plugin_basename ) ) {
				deactivate_plugins( $plugin_basename );
				$this->die_with_message( $plugin_name, $required_wp, 'multisite' );
			}
		}
	}

	/**
	 * Renders a translated `wp_die()` page explaining why the plugin
	 * was deactivated. `$reason` is `wp-version` or `multisite`.
	 */
	private function die_with_message( string $plugin_name, string $required_wp, string $reason ): void {
		$plugins_back_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( get_admin_url( null, 'plugins.php' ) ),
			esc_html__( 'Plugins page', 'wpm-user-sync' )
		);

		if ( 'wp-version' === $reason ) {
			$message = sprintf(
				/* translators: 1: plugin name, 2: required WP version, 3: link back to plugins page */
				esc_html__(
					'%1$s requires WordPress %2$s or higher, and has been deactivated! Please upgrade WordPress and try again. Back to the WordPress %3$s.',
					'wpm-user-sync'
				),
				'<strong>' . esc_html( $plugin_name ) . '</strong>',
				'<strong>' . esc_html( $required_wp ) . '</strong>',
				$plugins_back_link
			);
		} else {
			$message = sprintf(
				/* translators: 1: plugin name, 2: link back to plugins page */
				esc_html__(
					'%1$s requires WordPress Multisite, and has been deactivated! Please configure WordPress for multisite and try again. Back to the WordPress %2$s.',
					'wpm-user-sync'
				),
				'<strong>' . esc_html( $plugin_name ) . '</strong>',
				$plugins_back_link
			);
		}

		wp_die( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- message is built from esc_html_e/esc_url calls above.
	}
}
