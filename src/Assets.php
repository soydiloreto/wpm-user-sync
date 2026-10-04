<?php
/**
 * Enqueues the plugin's admin stylesheet. The CSS file lives at
 * `css/wpmus_styles.css` (legacy filename retained for back-compat
 * with any third-party `wp_dequeue_style( 'wpmus_styles' )` calls).
 *
 * @package WPMUS
 */

declare(strict_types=1);

namespace WPMUS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Enqueues the plugin's admin stylesheet on its own admin pages.
 */
final class Assets {

	private string $plugin_file;

	/**
	 * @param string $plugin_file Absolute path to wpm-user-sync.php — used
	 *                            by `plugins_url()` to resolve the CSS URL.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Hooked on `admin_enqueue_scripts` from {@see \WPMUS\Plugin}.
	 * Loads the stylesheet on the plugin's own screens only, whose
	 * hook suffixes all carry the `wpmus-` page slug.
	 *
	 * @param string $hook_suffix The current admin screen.
	 */
	public function enqueue_admin_styles( string $hook_suffix = '' ): void {
		if ( false === strpos( $hook_suffix, 'wpmus-' ) ) {
			return;
		}
		wp_enqueue_style(
			'wpmus_styles',
			plugins_url( 'css/wpmus_styles.css', $this->plugin_file ),
			array(),
			WPMUS_VERSION
		);
	}
}
