<?php
/**
 * Unit tests for {@see \WPMUS\Admin\SiteHomePage}: the tab chosen in
 * the query string (from an allow-list), the legacy `$sd_active_tab`
 * global and the two extension actions, with site admin links.
 *
 * @package WPMUS\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Brain\Monkey\Actions;
use Tests\Stubs\AdminScreen;
use Tests\TestCase;
use WPMUS\Admin\SiteHomePage;

final class SiteHomePageTest extends TestCase {

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
				( new SiteHomePage() )->render();
			}
		);
	}

	public function test_without_a_tab_the_welcome_tab_is_active_and_links_to_the_sites_screens(): void {
		$output = $this->render();

		$this->assertStringContainsString( '<a class="nav-tab nav-tab-active" href="https://example.test/sub/wp-admin/admin.php?page=wpmus-sitehome&#038;tab=welcome">Welcome</a>', $output );
		$this->assertStringContainsString( '<a class="nav-tab" href="https://example.test/sub/wp-admin/admin.php?page=wpmus-sitehome&#038;tab=concepts">Concepts</a>', $output );
		foreach ( array( 'wpmus-sitehome&#038;tab=concepts', 'wpmus-sitesyncactions', 'wpmus-sitehome&#038;tab=about' ) as $target ) {
			$this->assertStringContainsString( 'href="https://example.test/sub/wp-admin/admin.php?page=' . $target . '" class="cuadrado"', $output );
		}
		$this->assertStringNotContainsString( 'wp-admin/network/', $output );
		$this->assertSame( 'welcome', $GLOBALS['sd_active_tab'] );
	}

	public function test_the_concepts_tab_says_a_site_administrator_configures_nothing(): void {
		$_GET = array( 'tab' => 'concepts' );

		$output = $this->render();

		$this->assertStringContainsString( '<h3>Site User Sync Concepts</h3>', $output );
		$this->assertStringContainsString( 'What can a site administrator configure?', $output );
		$this->assertSame( 'concepts', $GLOBALS['sd_active_tab'] );
	}

	public function test_the_about_tab_links_to_the_author_and_the_plugin(): void {
		$_GET = array( 'tab' => 'about' );

		$output = $this->render();

		$this->assertStringContainsString( '<h3>About</h3>', $output );
		$this->assertStringContainsString( 'href="https://www.linkedin.com/in/pablodiloreto/" target="_blank" rel="noopener"', $output );
		$this->assertSame( 'about', $GLOBALS['sd_active_tab'] );
	}

	public function test_a_tab_outside_the_allow_list_falls_back_to_welcome(): void {
		$_GET = array( 'tab' => 'settings' );

		$output = $this->render();

		$this->assertStringContainsString( '<h3>Welcome to DiluxOne Multisite User Sync</h3>', $output );
		$this->assertSame( 'welcome', $GLOBALS['sd_active_tab'] );
	}

	public function test_extensions_add_a_tab_inside_the_tab_row_and_content_after_the_body(): void {
		$_GET = array( 'tab' => 'about' );
		Actions\expectDone( 'wpmus_site_home_tabs' )
			->once()
			->with( 'about' )
			->whenHappen(
				static function (): void {
					echo '<a class="nav-tab">EXTENSION TAB</a>';
				}
			);
		Actions\expectDone( 'wpmus_site_home_contents' )
			->once()
			->with( 'about' )
			->whenHappen(
				static function (): void {
					echo 'EXTENSION BODY';
				}
			);

		$output = $this->render();

		$this->assertLessThan( strpos( $output, '</h2>' ), strpos( $output, 'EXTENSION TAB' ) );
		$this->assertGreaterThan( strpos( $output, 'Plugin Homepage' ), strpos( $output, 'EXTENSION BODY' ) );
	}
}
