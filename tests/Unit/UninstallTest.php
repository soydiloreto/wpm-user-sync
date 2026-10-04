<?php
/**
 * Unit test for `uninstall.php`: deleting the plugin removes its
 * toggles, its queue, the queue's lock and cron event (on the main
 * site), and the removal record, and touches nothing else.
 *
 * The file runs top to bottom when required, so the test runs in its
 * own process with `WP_UNINSTALL_PLUGIN` defined, as WordPress does.
 *
 * @package WPMUS\Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey\Functions;
use Tests\TestCase;

final class UninstallTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_deletes_the_plugins_data_and_nothing_else(): void {
		$calls = array();
		$log   = static function ( string $name ) use ( &$calls ): callable {
			return static function ( ...$args ) use ( $name, &$calls ) {
				$calls[] = array_merge( array( $name ), $args );
				return true;
			};
		};
		foreach ( array( 'delete_site_option', 'switch_to_blog', 'wp_clear_scheduled_hook', 'restore_current_blog', 'delete_metadata' ) as $function ) {
			Functions\when( $function )->alias( $log( $function ) );
		}
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\expect( 'delete_user' )->never();
		Functions\expect( 'remove_user_from_blog' )->never();
		define( 'WP_UNINSTALL_PLUGIN', 'wpm-user-sync/wpm-user-sync.php' );

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame(
			array(
				array( 'delete_site_option', 'wpmus_newSiteSync' ),
				array( 'delete_site_option', 'wpmus_newUserSync' ),
				array( 'delete_site_option', 'wpmus_setUserRoleSync' ),
				array( 'delete_site_option', 'wpmus_sync_jobs' ),
				array( 'delete_site_option', 'wpmus_sync_lock' ),
				array( 'switch_to_blog', 1 ),
				array( 'wp_clear_scheduled_hook', 'wpmus_process_sync_queue' ),
				array( 'restore_current_blog' ),
				array( 'delete_metadata', 'user', 0, 'wpmus_removed_from_blogs', '', true ),
			),
			$calls
		);
	}
}
