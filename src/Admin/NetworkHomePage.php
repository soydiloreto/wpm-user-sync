<?php
/**
 * Network admin home page: a tabbed introduction with three tabs —
 * Welcome, Concepts, About. The active tab is read from the `tab`
 * query parameter and defaults to "welcome".
 *
 * The verbose copy in concepts/about is intentionally hard-coded in
 * this class for now; long-term, those bodies could move to template
 * partials but the legacy code did the same and a refactor PR is the
 * wrong moment to also restructure the copy. Linter-cleanup PR (PR 5)
 * is a safer venue.
 *
 * @package WPMUS\Admin
 */

declare(strict_types=1);

namespace WPMUS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Network-home page renderer with three built-in tabs (Welcome,
 * Concepts, About) and the legacy `wpmus_network_home_tabs` /
 * `_contents` extension hooks for third-party tab plugins.
 */
final class NetworkHomePage {

	/**
	 * Render the entire page: tabs row + active tab body. Sets the
	 * legacy `$GLOBALS['sd_active_tab']` for back-compat with the
	 * 1.4 extension API and fires `wpmus_network_home_tabs` /
	 * `wpmus_network_home_contents` so extensions can append.
	 */
	public function render(): void {
		$active_tab = $this->active_tab();

		// Legacy 1.4 set this global as the API for tab callbacks. Kept
		// in place so any external extension that hooked into the
		// `wpmus_network_home_tabs` / `_contents` actions and read
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
			 * Fires after the built-in network-home tabs are rendered.
			 *
			 * Extensions may hook into this action to append their own
			 * `<a class="nav-tab">` links. The currently selected tab
			 * slug is also exposed as `$GLOBALS['sd_active_tab']` for
			 * backwards compatibility with 1.4 extensions.
			 *
			 * @since 1.0.0
			 *
			 * @param string $active_tab Slug of the currently selected tab.
			 */
			do_action( 'wpmus_network_home_tabs', $active_tab );
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
		 * Fires after the built-in network-home tab body is rendered.
		 *
		 * Extensions may hook into this action to append additional
		 * content. They should check the `$active_tab` argument (or
		 * the `$GLOBALS['sd_active_tab']` legacy global) and only
		 * render their content when their own tab is selected.
		 *
		 * @since 1.0.0
		 *
		 * @param string $active_tab Slug of the currently selected tab.
		 */
		do_action( 'wpmus_network_home_contents', $active_tab );
	}

	/**
	 * Resolves the currently active tab from `$_GET['tab']`, defaulting
	 * to `welcome` and rejecting any value not in the allow-list.
	 */
	private function active_tab(): string {
		// The page is gated by `manage_network_options` (capability
		// check via add_menu_page); the value only picks which static
		// content block we render, from an allow-list.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( 'welcome', 'concepts', 'about' ), true ) ? $tab : 'welcome';
	}

	/**
	 * Renders a single tab link in the nav-tab-wrapper row.
	 */
	private function render_tab( string $slug, string $label, string $active_tab ): void {
		$url     = network_admin_url( 'admin.php?page=wpmus-networkhome&tab=' . $slug );
		$classes = 'nav-tab' . ( $slug === $active_tab ? ' nav-tab-active' : '' );
		printf(
			'<a class="%1$s" href="%2$s">%3$s</a>',
			esc_attr( $classes ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Body of the "Welcome" tab — quick-start links to the other
	 * pages.
	 */
	private function render_welcome(): void {
		$concepts_url = network_admin_url( 'admin.php?page=wpmus-networkhome&tab=concepts' );
		$actions_url  = network_admin_url( 'admin.php?page=wpmus-networksyncactions' );
		$options_url  = network_admin_url( 'admin.php?page=wpmus-networksyncoptions' );
		$about_url    = network_admin_url( 'admin.php?page=wpmus-networkhome&tab=about' );
		?>
		<h3><?php esc_html_e( 'Welcome to DiluxOne Multisite User Sync', 'wpm-user-sync' ); ?></h3>
		<p><?php esc_html_e( 'Thank you for choosing DiluxOne Multisite User Sync. Follow the next steps to get started synchronizing:', 'wpm-user-sync' ); ?></p>

		<a href="<?php echo esc_url( $concepts_url ); ?>" class="cuadrado"><?php esc_html_e( '1. Review basic concepts', 'wpm-user-sync' ); ?></a>
		<a href="<?php echo esc_url( $actions_url ); ?>" class="cuadrado"><?php esc_html_e( '2. Complete the initial Users Sync', 'wpm-user-sync' ); ?></a>
		<a href="<?php echo esc_url( $options_url ); ?>" class="cuadrado"><?php esc_html_e( '3. Check the network sync options', 'wpm-user-sync' ); ?></a>
		<a href="<?php echo esc_url( $about_url ); ?>" class="cuadrado"><?php esc_html_e( '4. Meet the Authors & Support Us', 'wpm-user-sync' ); ?></a>

		<p>
			<?php
			$site_link = '<a href="https://wordpress.org/support/plugin/wpm-user-sync/">' . esc_html__( 'support forum', 'wpm-user-sync' ) . '</a>';
			printf(
				/* translators: %s: link to the plugin's support forum on wordpress.org */
				esc_html__( 'Do you want online help? Check our %s.', 'wpm-user-sync' ),
				$site_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built from esc_html__ above.
			);
			?>
		</p>
		<?php
	}

	/**
	 * Body of the "Concepts" tab — long-form explanation of triggers
	 * and manual actions.
	 */
	private function render_concepts(): void {
		?>
		<h3><?php esc_html_e( 'User Sync Concepts', 'wpm-user-sync' ); ?></h3>
		<p><?php esc_html_e( 'DiluxOne Multisite User Sync has some simple but important concepts. Knowing all of them will help you get a better experience with the tool.', 'wpm-user-sync' ); ?></p>

		<h4><?php esc_html_e( 'What exactly does this plugin do?', 'wpm-user-sync' ); ?></h4>
		<p><?php esc_html_e( 'DiluxOne Multisite User Sync is a plugin that enables user synchronization in your WordPress Multisite — a type of WordPress installation that allows you to create and manage a network of multiple websites from a single WordPress dashboard.', 'wpm-user-sync' ); ?></p>
		<p><?php esc_html_e( 'Key concepts:', 'wpm-user-sync' ); ?></p>
		<ul>
			<li>- <?php esc_html_e( 'DiluxOne Multisite User Sync is a plugin, not a core feature of WordPress. It was built by external developers. However, it goes through a detailed testing process to ensure smooth operation as it interacts with core aspects of the CMS.', 'wpm-user-sync' ); ?></li>
			<li>- <?php esc_html_e( 'In an out-of-the-box WordPress multisite setup, when you create a new user, it is never synced to other sites in your network. Also, when you create a new site in your network, no users are synced to it. This means that you must manually register or associate users — a tedious manual process.', 'wpm-user-sync' ); ?></li>
			<li>- <?php esc_html_e( 'This plugin lets you automate all those scenarios, or do them manually. You decide.', 'wpm-user-sync' ); ?></li>
			<li>- <?php esc_html_e( 'When we talk about "user synchronization" we never duplicate user data. The user is one identity that is added (referenced) on multiple sites. If you use SUBDOMAIN_INSTALL and want a single-sign-on experience, configure the cookie domain in wp-config.php.', 'wpm-user-sync' ); ?></li>
		</ul>

		<h4><?php esc_html_e( 'What is a trigger? Which ones exist here?', 'wpm-user-sync' ); ?></h4>
		<p><?php esc_html_e( 'A trigger is procedural code that runs automatically in response to certain events. DiluxOne Multisite User Sync exposes three:', 'wpm-user-sync' ); ?></p>
		<ul>
			<li>- <strong><?php esc_html_e( 'New user creation', 'wpm-user-sync' ); ?></strong> — <?php esc_html_e( 'when a user registers on your site, or an admin creates a new one.', 'wpm-user-sync' ); ?></li>
			<li>- <strong><?php esc_html_e( 'New site creation', 'wpm-user-sync' ); ?></strong> — <?php esc_html_e( 'when an admin or authorized user creates a new site in your network.', 'wpm-user-sync' ); ?></li>
			<li>- <strong><?php esc_html_e( 'User role edited in one site', 'wpm-user-sync' ); ?></strong> — <?php esc_html_e( "when you edit a user's role on one of your network sites.", 'wpm-user-sync' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'All three triggers are configured from network-level options.', 'wpm-user-sync' ); ?></p>

		<h4><?php esc_html_e( 'What kind of options do I have at the network level?', 'wpm-user-sync' ); ?></h4>
		<p><?php esc_html_e( 'At network level you configure the three triggers described above:', 'wpm-user-sync' ); ?></p>
		<ul>
			<li>- <strong><?php esc_html_e( 'New Site Automatic Sync', 'wpm-user-sync' ); ?></strong> — <?php esc_html_e( 'when a new site is created, every user on the network is added to it with that site\'s default role (subscriber if no default is set). Super admins and people removed from a site are left out.', 'wpm-user-sync' ); ?></li>
			<li>- <strong><?php esc_html_e( 'New User Automatic Sync', 'wpm-user-sync' ); ?></strong> — <?php esc_html_e( 'when a new user is created, they are added to all sites with each site default role.', 'wpm-user-sync' ); ?></li>
			<li>- <strong><?php esc_html_e( 'Set User Role Automatic Sync', 'wpm-user-sync' ); ?></strong> — <?php esc_html_e( 'when a user role changes on one site, the change is copied to the other sites the user already belongs to that have that role. Administrator is never copied.', 'wpm-user-sync' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'You can also execute the following actions:', 'wpm-user-sync' ); ?></p>
		<ul>
			<li>- <strong><?php esc_html_e( 'Sync from scratch', 'wpm-user-sync' ); ?></strong> — <?php esc_html_e( 'add every network user to every site with each site default role. Existing memberships are not modified, and people removed from a site are only added back if you tick the box.', 'wpm-user-sync' ); ?></li>
			<li>- <strong><?php esc_html_e( 'Sync specific site', 'wpm-user-sync' ); ?></strong> — <?php esc_html_e( 'same as above but limited to the sites you check off in the form.', 'wpm-user-sync' ); ?></li>
		</ul>

		<h4><?php esc_html_e( 'What can a site administrator configure?', 'wpm-user-sync' ); ?></h4>
		<p><?php esc_html_e( "Nothing. Site administrators do not see the plugin. From a site's dashboard, a super admin can sync every network user into that one site. Existing memberships are not modified.", 'wpm-user-sync' ); ?></p>

		<h4><?php esc_html_e( 'Can I run only manual actions and avoid all triggers?', 'wpm-user-sync' ); ?></h4>
		<p><?php esc_html_e( 'Yes. Disable all triggers at network level and only manual actions will run.', 'wpm-user-sync' ); ?></p>
		<?php
	}

	/**
	 * Body of the "About" tab — author + project links.
	 */
	private function render_about(): void {
		?>
		<h3><?php esc_html_e( 'About DiluxOne Multisite User Sync', 'wpm-user-sync' ); ?></h3>
		<p><?php esc_html_e( 'This plugin was developed by Pablo Ariel Di Loreto:', 'wpm-user-sync' ); ?></p>
		<ul>
			<li>- <a href="https://www.linkedin.com/in/pablodiloreto/" target="_blank" rel="noopener"><?php esc_html_e( 'LinkedIn Contact', 'wpm-user-sync' ); ?></a>.</li>
			<li>- <a href="https://diluxone.com/plugins-wordpress" target="_blank" rel="noopener"><?php esc_html_e( 'DiluxOne plugins for WordPress', 'wpm-user-sync' ); ?></a>.</li>
			<li>- <a href="https://wordpress.org/plugins/wpm-user-sync/" target="_blank" rel="noopener"><?php esc_html_e( 'Plugin Homepage', 'wpm-user-sync' ); ?></a>.</li>
		</ul>
		<?php
	}
}
