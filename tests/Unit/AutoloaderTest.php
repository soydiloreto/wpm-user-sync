<?php
/**
 * Unit tests for `src/Autoloader.php`, the plugin's own PSR-4 loader
 * (the plugin ships without Composer). Each test runs in its own
 * process, where the class it loads is not loaded yet.
 *
 * @package WPMUS\Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AutoloaderTest extends TestCase {

	/**
	 * Registers the plugin's autoloader and returns it.
	 */
	private function autoloader(): callable {
		require dirname( __DIR__, 2 ) . '/src/Autoloader.php';
		$loaders = spl_autoload_functions();
		$loader  = end( $loaders );
		$this->assertIsCallable( $loader );
		return $loader;
	}

	public function test_a_plugin_class_is_loaded_from_its_psr4_path(): void {
		$loader = $this->autoloader();
		$this->assertFalse( class_exists( \WPMUS\View\Header::class, false ) );

		$loader( \WPMUS\View\Header::class );

		$this->assertTrue( class_exists( \WPMUS\View\Header::class, false ) );
	}

	public function test_a_class_outside_the_plugins_namespace_is_left_to_other_loaders(): void {
		$loader = $this->autoloader();

		$loader( 'WPMUSX\\View\\Header' );
		$loader( 'Other\\Plugin' );

		$this->assertFalse( class_exists( \WPMUS\View\Header::class, false ) );
	}

	public function test_a_plugin_class_without_a_file_is_not_loaded(): void {
		$loader = $this->autoloader();

		$loader( 'WPMUS\\DoesNotExist' );

		$this->assertFalse( class_exists( 'WPMUS\\DoesNotExist', false ) );
	}
}
