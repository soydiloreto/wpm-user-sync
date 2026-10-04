<?php
/**
 * Registers the network-admin menu and its three subpages, and
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
 * Network-admin menu registration + page-render dispatcher.
 */
final class NetworkMenu {

	private Header $header;
	private NetworkHomePage $home;
	private NetworkSyncOptionsPage $options;
	private NetworkSyncActionsPage $actions;

	/**
	 * @param Header                 $header  Shared page header.
	 * @param NetworkHomePage        $home    Home (Welcome / Concepts / About).
	 * @param NetworkSyncOptionsPage $options Trigger toggles form.
	 * @param NetworkSyncActionsPage $actions Manual sync actions.
	 */
	public function __construct( Header $header, NetworkHomePage $home, NetworkSyncOptionsPage $options, NetworkSyncActionsPage $actions ) {
		$this->header  = $header;
		$this->home    = $home;
		$this->options = $options;
		$this->actions = $actions;
	}

	/**
	 * Hooked on `network_admin_menu` from {@see \WPMUS\Plugin}.
	 * Registers the plugin's menu plus its two submenus.
	 */
	public function register(): void {
		add_menu_page(
			__( 'DiluxOne Multisite User Sync', 'wpm-user-sync' ),
			__( 'User Sync', 'wpm-user-sync' ),
			'manage_network_options',
			'wpmus-networkhome',
			array( $this, 'render_home' ),
			'dashicons-admin-generic',
			100
		);

		add_submenu_page(
			'wpmus-networkhome',
			__( 'Network Sync Options', 'wpm-user-sync' ),
			__( 'Network Sync Options', 'wpm-user-sync' ),
			'manage_network_options',
			'wpmus-networksyncoptions',
			array( $this, 'render_options' )
		);

		add_submenu_page(
			'wpmus-networkhome',
			__( 'Network Sync Actions', 'wpm-user-sync' ),
			__( 'Network Sync Actions', 'wpm-user-sync' ),
			'manage_network_users',
			'wpmus-networksyncactions',
			array( $this, 'render_actions' )
		);
	}

	/**
	 * Page-render callback for `wpmus-networkhome`.
	 */
	public function render_home(): void {
		$this->header->render();
		$this->home->render();
	}

	/**
	 * Page-render callback for `wpmus-networksyncoptions`.
	 */
	public function render_options(): void {
		$this->header->render();
		$this->options->render();
	}

	/**
	 * Page-render callback for `wpmus-networksyncactions`.
	 */
	public function render_actions(): void {
		$this->header->render();
		$this->actions->render();
	}
}
