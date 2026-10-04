<?php
/**
 * Unit tests for {@see \WPMUS\Admin\NetworkHomePage}: the tab chosen in
 * the query string (from an allow-list), the legacy `$sd_active_tab`
 * global and the two extension actions.
 *
 * @package WPMUS\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Brain\Monkey\Actions;
use Tests\Stubs\AdminScreen;
use Tests\TestCase;
use WPMUS\Admin\NetworkHomePage;

final class NetworkHomePageTest extends TestCase {

	use AdminScreen;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_admin_screen();
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['sd_active_tab'] );
		parent::tearDown();
	}

	private function render(): string {
		return $this->output_of(
			static function (): void {
				( new NetworkHomePage() )->render();
			}
		);
	}

	public function test_without_a_tab_the_welcome_tab_is_active(): void {
		$output = $this->render();

		$this->assertStringContainsString( '<a class="nav-tab nav-tab-active" href="https://example.test/wp-admin/network/admin.php?page=wpmus-networkhome&#038;tab=welcome">Welcome</a>', $output );
		$this->assertStringContainsString( '<a class="nav-tab" href="https://example.test/wp-admin/network/admin.php?page=wpmus-networkhome&#038;tab=concepts">Concepts</a>', $output );
		$this->assertStringContainsString( '<a class="nav-tab" href="https://example.test/wp-admin/network/admin.php?page=wpmus-networkhome&#038;tab=about">About</a>', $output );
		$this->assertStringContainsString( '<h3>Welcome to DiluxOne Multisite User Sync</h3>', $output );
		$this->assertSame( 'welcome', $GLOBALS['sd_active_tab'] );
	}

	public function test_the_welcome_tab_links_to_every_screen_and_the_support_forum(): void {
		$_GET = array( 'tab' => 'welcome' );

		$output = $this->render();

		foreach ( array( 'wpmus-networkhome&#038;tab=concepts', 'wpmus-networksyncactions', 'wpmus-networksyncoptions', 'wpmus-networkhome&#038;tab=about' ) as $target ) {
			$this->assertStringContainsString( 'href="https://example.test/wp-admin/network/admin.php?page=' . $target . '" class="cuadrado"', $output );
		}
		$this->assertStringContainsString( 'Check our <a href="https://wordpress.org/support/plugin/wpm-user-sync/">support forum</a>.', $output );
	}

	public function test_the_concepts_tab_explains_the_triggers(): void {
		$_GET = array( 'tab' => 'concepts' );

		$output = $this->render();

		$this->assertStringContainsString( 'nav-tab nav-tab-active" href="https://example.test/wp-admin/network/admin.php?page=wpmus-networkhome&#038;tab=concepts"', $output );
		$this->assertStringContainsString( '<h3>User Sync Concepts</h3>', $output );
		$this->assertStringContainsString( 'What is a trigger?', $output );
		$this->assertStringNotContainsString( '<h3>Welcome to', $output );
		$this->assertSame( 'concepts', $GLOBALS['sd_active_tab'] );
	}

	public function test_the_about_tab_links_to_the_author_and_the_plugin(): void {
		$_GET = array( 'tab' => 'about' );

		$output = $this->render();

		$this->assertStringContainsString( '<h3>About DiluxOne Multisite User Sync</h3>', $output );
		$this->assertStringContainsString( 'href="https://wordpress.org/plugins/wpm-user-sync/" target="_blank" rel="noopener"', $output );
		$this->assertSame( 'about', $GLOBALS['sd_active_tab'] );
	}

	public function test_a_tab_outside_the_allow_list_falls_back_to_welcome(): void {
		$_GET = array( 'tab' => '"><script>alert(1)</script>' );

		$output = $this->render();

		$this->assertStringContainsString( '<h3>Welcome to DiluxOne Multisite User Sync</h3>', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertSame( 'welcome', $GLOBALS['sd_active_tab'] );
	}

	public function test_the_tab_is_sanitized_before_it_is_matched(): void {
		$_GET = array( 'tab' => 'About' );

		$this->render();

		$this->assertSame( 'about', $GLOBALS['sd_active_tab'] );
	}

	public function test_extensions_add_a_tab_inside_the_tab_row_and_content_after_the_body(): void {
		$_GET = array( 'tab' => 'concepts' );
		Actions\expectDone( 'wpmus_network_home_tabs' )
			->once()
			->with( 'concepts' )
			->whenHappen(
				static function (): void {
					echo '<a class="nav-tab">EXTENSION TAB</a>';
				}
			);
		Actions\expectDone( 'wpmus_network_home_contents' )
			->once()
			->with( 'concepts' )
			->whenHappen(
				static function (): void {
					echo 'EXTENSION BODY';
				}
			);

		$output = $this->render();

		$this->assertLessThan( strpos( $output, '</h2>' ), strpos( $output, 'EXTENSION TAB' ) );
		$this->assertGreaterThan( strpos( $output, 'Can I run only manual actions' ), strpos( $output, 'EXTENSION BODY' ) );
	}
}
