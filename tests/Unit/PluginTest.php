<?php
/**
 * Unit tests for {@see \WPMUS\Plugin::register()}: what is hooked on a
 * network, and that outside one only the requirements check is.
 *
 * @package WPMUS\Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey\Functions;
use Tests\TestCase;
use WPMUS\Assets;
use WPMUS\Plugin;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;

final class PluginTest extends TestCase {

	public function test_outside_a_network_only_the_requirements_check_is_hooked(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'register_activation_hook' )->never();

		( new Plugin( '/path/to/wpm-user-sync.php' ) )->register();

		$this->assertNotFalse( has_action( 'admin_init' ) );
		foreach ( array( 'init', 'network_admin_menu', 'admin_menu', 'wp_initialize_site', 'wpmu_new_user', 'user_register', 'set_user_role', 'remove_user_from_blog', JobQueue::CRON_HOOK ) as $hook ) {
			$this->assertFalse( has_action( $hook ), "{$hook} must not be hooked on a single site" );
		}
	}

	public function test_on_a_network_every_trigger_menu_and_the_queue_are_hooked(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'register_activation_hook' )->once();

		( new Plugin( '/path/to/wpm-user-sync.php' ) )->register();

		foreach ( array( 'admin_init', 'init', 'network_admin_menu', 'admin_menu', 'wp_initialize_site', 'wpmu_new_user', 'user_register', 'wpmu_activate_user', 'set_user_role', 'remove_user_from_blog', 'add_user_to_blog', JobQueue::CRON_HOOK ) as $hook ) {
			$this->assertNotFalse( has_action( $hook ), "{$hook} must be hooked on a network" );
		}
	}

	public function test_the_plugin_does_not_load_its_own_translations(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\expect( 'load_plugin_textdomain' )->never();

		( new Plugin( '/path/to/wpm-user-sync.php' ) )->register();

		$this->assertFalse( has_action( 'plugins_loaded' ) );
	}

	public function test_init_hooks_the_stylesheet_loader(): void {
		$plugin = new Plugin( '/path/to/wpm-user-sync.php' );

		$plugin->on_init();

		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', Assets::class . '->enqueue_admin_styles()' ) );
	}

	public function test_activation_changes_nothing(): void {
		Functions\expect( 'update_site_option' )->never();
		Functions\expect( 'add_site_option' )->never();

		( new Plugin( '/path/to/wpm-user-sync.php' ) )->on_activate();
	}

	public function test_the_last_plugin_built_is_the_instance_and_exposes_its_engine(): void {
		$plugin = new Plugin( '/path/to/wpm-user-sync.php' );

		$this->assertSame( $plugin, Plugin::instance() );
		$this->assertInstanceOf( SyncEngine::class, $plugin->engine() );
		$this->assertSame( $plugin->engine(), Plugin::instance()->engine() );
	}
}
