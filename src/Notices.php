<?php
/**
 * Renders the dismissible admin notices that follow the plugin's
 * post-action redirects. The redirects append `updated`, `synced`,
 * `queued` or `nosynced` to the query string and the matching notice is
 * rendered at the top of the next admin page render.
 *
 * The renderer is gated on `$_GET['page']` matching one of this
 * plugin's screens (`wpmus-*`) so the notices never leak onto an
 * unrelated admin page that happens to carry an `updated=1` param.
 *
 * @package WPMUS
 */

declare(strict_types=1);

namespace WPMUS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Renders the post-action admin notices that follow plugin redirects.
 */
final class Notices {

	/**
	 * Render the appropriate notice for the current admin request.
	 * Reads `$_GET['page']`, `$_GET['updated']`, `$_GET['synced']`,
	 * `$_GET['nosynced']`. No-op outside the plugin's own screens.
	 */
	public function render(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page || strncmp( $page, 'wpmus-', 6 ) !== 0 ) {
			return;
		}

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_notice(
				'updated',
				__( "Settings updated. You're the best!", 'wpm-user-sync' )
			);
		}

		if ( isset( $_GET['synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_notice(
				'updated',
				__( "Sync done. You're a champion!", 'wpm-user-sync' )
			);
		}

		if ( isset( $_GET['queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_notice(
				'notice-info',
				__( 'This sync is large, so it runs in the background in batches. Its progress is listed on the Network Sync Actions page.', 'wpm-user-sync' )
			);
		}

		if ( isset( $_GET['nosynced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_notice(
				'notice-warning',
				__( 'Sync did not happen. You must select at least one site!', 'wpm-user-sync' )
			);
		}
	}

	/**
	 * Renders a single dismissible admin notice. The caller passes a
	 * RAW (un-escaped) translated string; this method is the single
	 * escape point so apostrophes don't get encoded twice into HTML
	 * entities like `&#039;`.
	 */
	private function render_notice( string $css_class, string $message ): void {
		printf(
			'<div id="message" class="%1$s notice is-dismissible"><p>%2$s</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">%3$s</span></button></div>',
			esc_attr( $css_class ),
			esc_html( $message ),
			esc_html__( 'Dismiss this notice.', 'wpm-user-sync' )
		);
	}
}
