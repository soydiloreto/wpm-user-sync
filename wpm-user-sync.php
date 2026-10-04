<?php
/**
 * Plugin Name: DiluxOne Multisite User Sync
 * Plugin URI: https://wordpress.org/plugins/wpm-user-sync/
 * Description: Configures & automates user synchronization between WordPress sites in a multi-site setup.
 * Version: 1.5.0
 * Author: Pablo Ariel Di Loreto
 * Author URI: https://diluxone.com/plugins-wordpress
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpm-user-sync
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Network: true
 *
 * @package WPMUS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

define( 'WPMUS_VERSION', '1.5.0' );

require_once __DIR__ . '/src/Autoloader.php';
require_once __DIR__ . '/legacy-deprecated.php';

( new \WPMUS\Plugin( __FILE__ ) )->register();
