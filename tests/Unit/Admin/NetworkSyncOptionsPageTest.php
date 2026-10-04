<?php
/**
 * Unit tests for {@see \WPMUS\Admin\NetworkSyncOptionsPage}: the form
 * shows the stored toggles, and saving checks the nonce and
 * `manage_network_options` before anything is written.
 *
 * @package WPMUS\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Tests\Stubs\AdminScreen;
use Tests\Stubs\Died;
use Tests\TestCase;
use WPMUS\Admin\NetworkSyncOptionsPage;
use WPMUS\Config;

final class NetworkSyncOptionsPageTest extends TestCase {

	use AdminScreen;

	/** @var Config&MockInterface */
	private $config;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_admin_screen();
		$this->stub_redirect();
		$this->stub_wp_die();
		$this->config = Mockery::mock( Config::class );
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function page(): NetworkSyncOptionsPage {
		/** @var Config $config */
		$config = $this->config;
		return new NetworkSyncOptionsPage( $config );
	}

	public function test_the_form_posts_to_the_save_action_with_a_nonce(): void {
		$this->config->shouldReceive( 'is_new_site_sync_enabled', 'is_new_user_sync_enabled', 'is_set_user_role_sync_enabled' )->andReturn( false );

		$output = $this->output_of( array( $this->page(), 'render' ) );

		$this->assertStringContainsString( '<form method="post" action="https://example.test/wp-admin/network/edit.php?action=wpmusSaveGlobalConfig">', $output );
		$this->assertSame( 1, substr_count( $output, '<input type="hidden" name="_wpnonce" data-action="' . Config::NONCE_ACTION . '" />' ) );
		$this->assertStringContainsString( '<input type="submit"', $output );
	}

	public function test_the_form_ticks_exactly_the_toggles_that_are_on(): void {
		$this->config->shouldReceive( 'is_new_site_sync_enabled' )->andReturn( true );
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( false );
		$this->config->shouldReceive( 'is_set_user_role_sync_enabled' )->andReturn( true );

		$output = $this->output_of( array( $this->page(), 'render' ) );

		$this->assertStringContainsString( 'name="wpmus_newSiteSync" type="checkbox" value="yes"  checked=\'checked\'', $output );
		$this->assertStringContainsString( 'name="wpmus_newUserSync" type="checkbox" value="yes"  />', $output );
		$this->assertStringContainsString( 'name="wpmus_setUserRoleSync" type="checkbox" value="yes"  checked=\'checked\'', $output );
	}

	public function test_saving_with_a_bad_nonce_stops_before_anything_else(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( Config::NONCE_ACTION )
			->andReturnUsing(
				static function (): void {
					throw new Died( 'The link you followed has expired.' );
				}
			);
		Functions\expect( 'current_user_can' )->never();
		$this->config->shouldNotReceive( 'save_toggles' );

		$this->expectException( Died::class );

		$this->page()->handle_save();
	}

	public function test_saving_without_manage_network_options_is_refused_with_403(): void {
		Functions\expect( 'check_admin_referer' )->once()->with( Config::NONCE_ACTION )->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_network_options' )->andReturn( false );
		$this->config->shouldNotReceive( 'save_toggles' );
		$_POST = array( 'wpmus_newSiteSync' => 'yes' );

		$died = $this->death_of( array( $this->page(), 'handle_save' ) );

		$this->assertSame( 'You do not have permission to change network sync options.', $died->getMessage() );
		$this->assertSame( array( 'response' => 403 ), $died->args );
	}

	public function test_saving_stores_the_ticked_toggles_and_returns_to_the_form(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		// Slashed and padded the way a request delivers them; an unticked
		// box is not posted at all.
		$_POST = array(
			'wpmus_newSiteSync'     => ' yes ',
			'wpmus_setUserRoleSync' => 'y\\es',
		);
		$this->config->shouldReceive( 'save_toggles' )->once()->with( 'yes', '', 'yes' );

		$location = $this->redirect_of( array( $this->page(), 'handle_save' ) );

		$this->assertSame( 'https://example.test/wp-admin/network/admin.php?page=wpmus-networksyncoptions&updated=true', $location );
	}
}
