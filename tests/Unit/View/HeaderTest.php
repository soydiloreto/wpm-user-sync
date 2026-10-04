<?php
/**
 * Unit tests for {@see \WPMUS\View\Header}: the title and welcome line
 * at the top of every plugin screen.
 *
 * @package WPMUS\Tests\Unit\View
 */

declare(strict_types=1);

namespace Tests\Unit\View;

use Brain\Monkey\Functions;
use Tests\Stubs\AdminScreen;
use Tests\TestCase;
use WPMUS\View\Header;

final class HeaderTest extends TestCase {

	use AdminScreen;

	public function test_the_header_shows_the_plugin_name_and_allows_only_strong_in_the_welcome_line(): void {
		$this->stub_admin_screen();
		Functions\expect( 'wp_kses' )
			->once()
			->with( \Mockery::pattern( '/<strong>best free user synchronization solution<\/strong>/' ), array( 'strong' => array() ) )
			->andReturnFirstArg();

		$output = $this->output_of(
			static function (): void {
				( new Header() )->render();
			}
		);

		$this->assertStringContainsString( '<div class="wrap">', $output );
		$this->assertStringContainsString( '<h2>DiluxOne Multisite User Sync</h2>', $output );
		$this->assertStringContainsString( 'Welcome to the <strong>best free user synchronization solution</strong>', $output );
	}
}
