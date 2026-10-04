<?php
/**
 * Site (per-blog) home page — three tabs: Welcome, Concepts, About.
 * Mirrors the structure of {@see NetworkHomePage} with copy adapted
 * for site administrators (who have no options to set, only an
 * action button).
 *
 * @package WPMUS\Admin
 */

declare(strict_types=1);

namespace WPMUS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Per-site home page renderer with three built-in tabs (Welcome,
 * Concepts, About) and the legacy `wpmus_site_home_tabs` /
 * `_contents` extension hooks for third-party tab plugins.
 */
final class SiteHomePage {

	/**
	 * Render the entire page: tabs row + active tab body. Sets the
	 * legacy `$GLOBALS['sd_active_tab']` and fires the extension
	 * actions.
	 */
	public function render(): void {
		$active_tab = $this->active_tab();

		// Legacy 1.4 set this global as the API for tab callbacks. Kept
		// in place so any external extension that hooked into the
		// `wpmus_site_home_tabs` / `_contents` actions and read
		// `$sd_active_tab` still works after the OOP refactor.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['sd_active_tab'] = $active_tab;
		?>
		<h2 class="nav-tab-wrapper">
			<?php $this->render_tab( 'welcome', __( 'Welcome', 'wpm-user-sync' ), $active_tab ); ?>
			<?php $this->render_tab( 'concepts', __( 'Concepts', 'wpm-user-sync' ), $active_tab ); ?>
			<?php $this->render_tab( 'about', __( 'About', 'wpm-user-sync' ), $active_tab ); ?>
			<?php
			/**
			 * Fires after the built-in site-home tabs are rendered.
			 *
			 * Extensions may hook into this action to append their own
			 * `<a class="nav-tab">` links.
			 *
			 * @since 1.0.0
			 *
			 * @param string $active_tab Slug of the currently selected tab.
			 */
			do_action( 'wpmus_site_home_tabs', $active_tab );
			?>
		</h2>
		<?php

		switch ( $active_tab ) {
			case 'concepts':
				$this->render_concepts();
				break;
			case 'about':
				$this->render_about();
				break;
			case 'welcome':
			default:
				$this->render_welcome();
				break;
		}

		/**
		 * Fires after the built-in site-home tab body is rendered.
		 *
		 * Extensions may hook into this action to append additional
		 * content for their own tabs.
		 *
		 * @since 1.0.0
		 *
		 * @param string $active_tab Slug of the currently selected tab.
		 */
		do_action( 'wpmus_site_home_contents', $active_tab );
	}

	/**
	 * Resolves the currently active tab from `$_GET['tab']`, defaulting
	 * to `welcome` and rejecting any value not in the allow-list.
	 */
	private function active_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( 'welcome', 'concepts', 'about' ), true ) ? $tab : 'welcome';
	}

	/**
	 * Renders a single tab link in the nav-tab-wrapper row.
	 */
	private function render_tab( string $slug, string $label, string $active_tab ): void {
		$url     = admin_url( 'admin.php?page=wpmus-sitehome&tab=' . $slug );
		$classes = 'nav-tab' . ( $slug === $active_tab ? ' nav-tab-active' : '' );
		printf(
			'<a class="%1$s" href="%2$s">%3$s</a>',
			esc_attr( $classes ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Body of the "Welcome" tab — quick-start links.
	 */
	private function render_welcome(): void {
		$concepts_url = admin_url( 'admin.php?page=wpmus-sitehome&tab=concepts' );
		$actions_url  = admin_url( 'admin.php?page=wpmus-sitesyncactions' );
		$about_url    = admin_url( 'admin.php?page=wpmus-sitehome&tab=about' );
		?>
		<h3><?php esc_html_e( 'Welcome to DiluxOne Multisite User Sync', 'wpm-user-sync' ); ?></h3>
		<p><?php esc_html_e( 'Thank you for choosing DiluxOne Multisite User Sync.', 'wpm-user-sync' ); ?></p>
		<p><?php esc_html_e( 'If you are new to this plugin we recommend you check the basic synchronization concepts. If this is your first time using the plugin, you can also do your first full sync.', 'wpm-user-sync' ); ?></p>

		<a href="<?php echo esc_url( $concepts_url ); ?>" class="cuadrado"><?php esc_html_e( '1. Review basic concepts', 'wpm-user-sync' ); ?></a>
		<a href="<?php echo esc_url( $actions_url ); ?>" class="cuadrado"><?php esc_html_e( '2. Complete the initial Users Sync', 'wpm-user-sync' ); ?></a>
		<a href="<?php echo esc_url( $about_url ); ?>" class="cuadrado"><?php esc_html_e( '3. Meet the Authors & Support Us', 'wpm-user-sync' ); ?></a>
		<?php
	}

	/**
	 * Body of the "Concepts" tab — site-admin perspective on the
	 * sync features.
	 */
	private function render_concepts(): void {
		?>
		<h3><?php esc_html_e( 'Site User Sync Concepts', 'wpm-user-sync' ); ?></h3>
		<p><?php esc_html_e( 'DiluxOne Multisite User Sync has some simple but important concepts. Knowing all of them will help you get a better experience with the tool.', 'wpm-user-sync' ); ?></p>

		<h4><?php esc_html_e( 'What exactly does this plugin do?', 'wpm-user-sync' ); ?></h4>
		<p><?php esc_html_e( 'DiluxOne Multisite User Sync enables user synchronization in your WordPress Multisite — see the Network admin Concepts tab for the full explanation. Here, a super admin can sync every network user into this one site.', 'wpm-user-sync' ); ?></p>

		<h4><?php esc_html_e( 'What can a site administrator configure?', 'wpm-user-sync' ); ?></h4>
		<p><?php esc_html_e( 'Nothing. All triggers and toggles are configured at the network level, and these pages are only shown to super admins: the "sync from scratch" action adds every network user to this site with its default role, which is a network decision. Existing memberships are not modified, and people removed from this site stay removed.', 'wpm-user-sync' ); ?></p>
		<?php
	}

	/**
	 * Body of the "About" tab — author + project links.
	 */
	private function render_about(): void {
		?>
		<h3><?php esc_html_e( 'About', 'wpm-user-sync' ); ?></h3>
		<p><?php esc_html_e( 'This plugin was developed by Pablo Ariel Di Loreto:', 'wpm-user-sync' ); ?></p>
		<ul>
			<li>- <a href="https://www.linkedin.com/in/pablodiloreto/" target="_blank" rel="noopener"><?php esc_html_e( 'LinkedIn Contact', 'wpm-user-sync' ); ?></a>.</li>
			<li>- <a href="https://diluxone.com/plugins-wordpress" target="_blank" rel="noopener"><?php esc_html_e( 'DiluxOne plugins for WordPress', 'wpm-user-sync' ); ?></a>.</li>
			<li>- <a href="https://wordpress.org/plugins/wpm-user-sync/" target="_blank" rel="noopener"><?php esc_html_e( 'Plugin Homepage', 'wpm-user-sync' ); ?></a>.</li>
		</ul>
		<?php
	}
}
