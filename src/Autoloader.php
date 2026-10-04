<?php
/**
 * Tiny PSR-4 autoloader so the plugin's runtime doesn't need Composer's
 * vendor/ directory. The same `WPMUS\` namespace mapping is also declared
 * in composer.json's "autoload" so tests can load classes via Composer.
 *
 * @package WPMUS
 */

declare(strict_types=1);

namespace WPMUS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WPMUS\\';
		if ( strncmp( $class_name, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
