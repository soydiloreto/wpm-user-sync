<?php
/**
 * Unit tests for {@see \WPMUS\Notices}: the notice that follows each
 * redirect, shown on the plugin's own screens only.
 *
 * @package WPMUS\Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Stubs\AdminScreen;
use Tests\TestCase;
use WPMUS\Notices;

final class NoticesTest extends TestCase {

	use AdminScreen;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_admin_screen();
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function render(): string {
		return $this->output_of(
			static function (): void {
				( new Notices() )->render();
			}
		);
	}

	public function test_nothing_is_shown_without_a_page(): void {
		$_GET = array( 'updated' => 'true' );

		$this->assertSame( '', $this->render() );
	}

	public function test_nothing_is_shown_on_another_plugins_screen(): void {
		$_GET = array(
			'page'    => 'other-plugin',
			'updated' => 'true',
			'synced'  => 'true',
		);

		$this->assertSame( '', $this->render() );
	}

	public function test_nothing_is_shown_on_a_plugin_screen_without_a_flag(): void {
		$_GET = array( 'page' => 'wpmus-networksyncoptions' );

		$this->assertSame( '', $this->render() );
	}

	public function test_saved_settings_show_a_success_notice_escaped_once(): void {
		$_GET = array(
			'page'    => 'wpmus-networksyncoptions',
			'updated' => 'true',
		);

		$output = $this->render();

		$this->assertStringContainsString( '<div id="message" class="updated notice is-dismissible">', $output );
		$this->assertStringContainsString( '<p>Settings updated. You&#039;re the best!</p>', $output );
		$this->assertStringContainsString( '<span class="screen-reader-text">Dismiss this notice.</span>', $output );
		$this->assertSame( 1, substr_count( $output, 'id="message"' ) );
	}

	public function test_a_finished_sync_shows_a_success_notice(): void {
		$_GET = array(
			'page'   => 'wpmus-networksyncactions',
			'synced' => 'true',
		);

		$output = $this->render();

		$this->assertStringContainsString( 'class="updated notice is-dismissible"', $output );
		$this->assertStringContainsString( 'Sync done. You&#039;re a champion!', $output );
	}

	public function test_a_queued_sync_shows_an_info_notice(): void {
		$_GET = array(
			'page'   => 'wpmus-sitesyncactions',
			'queued' => 'true',
		);

		$output = $this->render();

		$this->assertStringContainsString( 'class="notice-info notice is-dismissible"', $output );
		$this->assertStringContainsString( 'runs in the background in batches', $output );
	}

	public function test_a_sync_without_sites_shows_a_warning(): void {
		$_GET = array(
			'page'     => 'wpmus-networksyncactions',
			'nosynced' => 'true',
		);

		$output = $this->render();

		$this->assertStringContainsString( 'class="notice-warning notice is-dismissible"', $output );
		$this->assertStringContainsString( 'You must select at least one site!', $output );
	}

	public function test_the_page_slug_is_sanitized_before_it_is_matched(): void {
		$_GET = array(
			'page'   => 'WPMUS-Network<Sync>Actions',
			'synced' => 'true',
		);

		$this->assertStringContainsString( 'Sync done.', $this->render() );
	}

	public function test_every_flag_present_shows_its_own_notice(): void {
		$_GET = array(
			'page'     => 'wpmus-networksyncactions',
			'updated'  => 'true',
			'synced'   => 'true',
			'queued'   => 'true',
			'nosynced' => 'true',
		);

		$this->assertSame( 4, substr_count( $this->render(), 'id="message"' ) );
	}
}
