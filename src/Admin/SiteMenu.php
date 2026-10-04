<?php
/**
 * Registers the per-site admin menu and its single subpage, and
 * delegates each page's rendering to the matching page class.
 *
 * @package WPMUS\Admin
 */

declare(strict_types=1);

namespace WPMUS\Admin;

use WPMUS\View\Header;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Per-site admin menu registration + page-render dispatcher.
 */
final class SiteMenu {

	private Header $header;
	private SiteHomePage $home;
	private SiteSyncActionsPage $actions;

	/**
	 * @param Header              $header  Shared page header.
	 * @param SiteHomePage        $home    Home (Welcome / Concepts / About).
	 * @param SiteSyncActionsPage $actions Single-button sync action.
	 */
	public function __construct( Header $header, SiteHomePage $home, SiteSyncActionsPage $actions ) {
		$this->header  = $header;
		$this->home    = $home;
		$this->actions = $actions;
	}

	/**
	 * Hooked on `admin_menu` from {@see \WPMUS\Plugin}. Registers the
	 * plugin's menu plus the Site Sync Actions submenu.
	 */
	public function register(): void {
		// Site administrators have nothing to configure or run here:
		// the one action pulls network accounts into the site, which is
		// the network's call. The menu shows to super admins only.
		add_menu_page(
			__( 'DiluxOne Multisite User Sync', 'wpm-user-sync' ),
			__( 'User Sync', 'wpm-user-sync' ),
			'manage_network_users',
			'wpmus-sitehome',
			array( $this, 'render_home' ),
			'dashicons-admin-generic',
			100
		);

		add_submenu_page(
			'wpmus-sitehome',
			__( 'Site Sync Actions', 'wpm-user-sync' ),
			__( 'Site Sync Actions', 'wpm-user-sync' ),
			'manage_network_users',
			'wpmus-sitesyncactions',
			array( $this, 'render_actions' )
		);
	}

	/**
	 * Page-render callback for `wpmus-sitehome`.
	 */
	public function render_home(): void {
		$this->header->render();
		$this->home->render();
	}

	/**
	 * Page-render callback for `wpmus-sitesyncactions`.
	 */
	public function render_actions(): void {
		$this->header->render();
		$this->actions->render();
	}
}
