<?php
/**
 * Writes a stand-in for `wp-admin/includes/plugin.php` under the test
 * ABSPATH, for the code paths that load it when the admin plugin API
 * is not loaded yet (a front-end or cron request). Its functions say
 * where they came from, so a test can tell the file was loaded.
 *
 * Use it only in a test that runs in a separate process: the file
 * defines global functions for the rest of that process.
 *
 * @package WPMUS\Tests\Stubs
 */

declare(strict_types=1);

namespace Tests\Stubs;

final class AdminIncludes {

	public const NAME = 'Loaded from wp-admin/includes/plugin.php';

	/**
	 * Creates ABSPATH/wp-admin/includes/plugin.php.
	 */
	public static function install(): void {
		$dir = ABSPATH . 'wp-admin/includes';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents(
			$dir . '/plugin.php',
			'<?php
function get_plugin_data( $plugin_file ) {
	return array( "Name" => "' . self::NAME . '", "RequiresWP" => "6.6", "File" => $plugin_file );
}
function is_plugin_active( $plugin ) {
	return false;
}
'
		);
	}
}
