<?php
/**
 * Unit tests for {@see \WPMUS\Admin\NetworkSyncActionsPage}: the two
 * sync forms, the progress of background syncs, and the handlers that
 * check the nonce and `manage_network_users` before any sync, read
 * the ticked sites and the "add back" box, and say whether the sync
 * finished or was queued.
 *
 * The engine is the real one over mocked repositories and queue: a
 * sync whose sites are gone finishes at once, and a big one is
 * queued, where the job it was given can be inspected.
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
use WPMUS\Admin\NetworkSyncActionsPage;
use WPMUS\Config;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Repositories\UserRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;
use WPMUS\Sync\SyncJob;

final class NetworkSyncActionsPageTest extends TestCase {

	use AdminScreen;

	private const ACTIONS_PAGE = 'https://example.test/wp-admin/network/admin.php?page=wpmus-networksyncactions';

	/** @var SiteRepository&MockInterface */
	private $sites;
	/** @var UserRepository&MockInterface */
	private $users;
	/** @var JobQueue&MockInterface */
	private $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_admin_screen();
		$this->stub_redirect();
		$this->stub_wp_die();
		Functions\when( 'number_format_i18n' )->alias( 'number_format' );
		$this->sites = Mockery::mock( SiteRepository::class );
		$this->users = Mockery::mock( UserRepository::class );
		$this->queue = Mockery::mock( JobQueue::class );
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function page(): NetworkSyncActionsPage {
		/** @var SiteRepository $sites */
		$sites = $this->sites;
		/** @var UserRepository $users */
		$users = $this->users;
		/** @var JobQueue $queue */
		$queue = $this->queue;
		return new NetworkSyncActionsPage( $sites, new SyncEngine( Mockery::mock( Config::class ), $sites, $users, $queue ), $queue );
	}

	/**
	 * A nonce and capability that pass.
	 */
	private function allowed(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	/**
	 * A network too big to sync in the request: every sync is queued,
	 * and the queued job is handed to `$inspect`.
	 *
	 * @param callable(SyncJob):bool $inspect Checks the queued job.
	 */
	private function expect_queued( callable $inspect ): void {
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2, 3 ) );
		$this->users->shouldReceive( 'count_network_users' )->andReturn( SyncEngine::INLINE_LIMIT );
		$this->queue->shouldReceive( 'add' )->once()->with( Mockery::on( $inspect ) );
		$this->queue->shouldReceive( 'schedule' )->once();
	}

	/**
	 * Expects a bad nonce to end the request before the capability
	 * check or any sync.
	 */
	private function expect_nonce_checked_first(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( Config::NONCE_ACTION )
			->andReturnUsing(
				static function (): void {
					throw new Died( 'The link you followed has expired.' );
				}
			);
		Functions\expect( 'current_user_can' )->never();
		$this->sites->shouldNotReceive( 'all_blog_ids' );
		$this->expectException( Died::class );
	}

	// ---------------------------------------------------------------------
	// render
	// ---------------------------------------------------------------------

	public function test_both_forms_post_to_their_actions_with_a_nonce_and_the_add_back_box(): void {
		$this->sites->shouldReceive( 'all_sites' )->andReturn( array() );
		$this->queue->shouldReceive( 'all' )->andReturn( array() );

		$output = $this->output_of( array( $this->page(), 'render' ) );

		$this->assertStringContainsString( 'action="https://example.test/wp-admin/network/edit.php?action=wpmusSyncNetworkFromScratch"', $output );
		$this->assertStringContainsString( 'action="https://example.test/wp-admin/network/edit.php?action=wpmusSyncNetworkSiteFromScratch"', $output );
		$this->assertSame( 2, substr_count( $output, 'data-action="' . Config::NONCE_ACTION . '"' ) );
		$this->assertSame( 2, substr_count( $output, '<input type="checkbox" name="wpmus_force" value="yes" />' ) );
	}

	public function test_every_live_site_is_offered_with_its_id_and_escaped_address(): void {
		$this->sites->shouldReceive( 'all_sites' )->andReturn(
			array(
				new \WP_Site( 1, 'example.test', '/' ),
				new \WP_Site( 7, 'example.test', '/<b>shop</b>/' ),
			)
		);
		$this->queue->shouldReceive( 'all' )->andReturn( array() );

		$output = $this->output_of( array( $this->page(), 'render' ) );

		$this->assertStringContainsString( '<input type="checkbox" name="listSites[]" value="1" />', $output );
		$this->assertStringContainsString( '<input type="checkbox" name="listSites[]" value="7" />', $output );
		$this->assertStringContainsString( 'example.test/&lt;b&gt;shop&lt;/b&gt;/', $output );
		$this->assertStringNotContainsString( '<b>shop</b>', $output );
	}

	public function test_no_progress_table_while_the_queue_is_empty(): void {
		$this->sites->shouldReceive( 'all_sites' )->andReturn( array() );
		$this->queue->shouldReceive( 'all' )->andReturn( array() );

		$output = $this->output_of( array( $this->page(), 'render' ) );

		$this->assertStringNotContainsString( 'Syncs running in the background', $output );
	}

	/**
	 * A stored job with its counters.
	 */
	private function job( string $context, int $processed, int $total ): SyncJob {
		$job            = new SyncJob( $context, null, null, false );
		$job->processed = $processed;
		$job->total     = $total;
		$job->created   = 1700000000;
		return $job;
	}

	public function test_each_background_sync_shows_its_kind_start_and_progress(): void {
		$this->sites->shouldReceive( 'all_sites' )->andReturn( array() );
		$this->queue->shouldReceive( 'all' )->andReturn(
			array(
				$this->job( 'manual', 1250, 5000 ),
				$this->job( 'new_site', 0, 0 ),
				$this->job( 'new_user', 9, 6 ),
			)
		);
		Functions\when( 'get_site_option' )->alias(
			static function ( string $name, $fallback ) {
				return array(
					'date_format' => 'Y-m-d',
					'time_format' => 'H:i',
				)[ $name ] ?? $fallback;
			}
		);
		Functions\expect( 'wp_date' )->times( 3 )->with( 'Y-m-d H:i', 1700000000 )->andReturn( '2023-11-14 22:13' );

		$output = $this->output_of( array( $this->page(), 'render' ) );

		$this->assertStringContainsString( '<h4>Syncs running in the background</h4>', $output );
		$this->assertStringContainsString( '<td>Manual sync</td>', $output );
		$this->assertStringContainsString( '25% (1,250 of 5,000 user-site pairs)', $output );
		$this->assertStringContainsString( '<td>New site</td>', $output );
		$this->assertStringContainsString( '0% (0 of 0 user-site pairs)', $output, 'A job without a total shows 0%.' );
		$this->assertStringContainsString( '<td>New user</td>', $output );
		$this->assertStringContainsString( '100% (9 of 6 user-site pairs)', $output, 'Progress never goes past 100%.' );
		$this->assertSame( 3, substr_count( $output, '<td>2023-11-14 22:13</td>' ) );
	}

	public function test_a_sync_of_an_unknown_kind_shows_its_escaped_context_and_no_date_when_it_cannot_be_formatted(): void {
		$this->sites->shouldReceive( 'all_sites' )->andReturn( array() );
		$this->queue->shouldReceive( 'all' )->andReturn( array( $this->job( '<i>addon</i>', 1, 2 ) ) );
		Functions\when( 'get_site_option' )->returnArg( 2 );
		Functions\when( 'wp_date' )->justReturn( false );

		$output = $this->output_of( array( $this->page(), 'render' ) );

		$this->assertStringContainsString( '<td>&lt;i&gt;addon&lt;/i&gt;</td>', $output );
		$this->assertStringContainsString( '<td></td>', $output );
		$this->assertStringContainsString( '50% (1 of 2 user-site pairs)', $output );
	}

	// ---------------------------------------------------------------------
	// handle_sync_all
	// ---------------------------------------------------------------------

	public function test_sync_all_with_a_bad_nonce_stops_before_anything_else(): void {
		$this->expect_nonce_checked_first();

		$this->page()->handle_sync_all();
	}

	public function test_sync_all_without_manage_network_users_is_refused_with_403(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_network_users' )->andReturn( false );
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$died = $this->death_of( array( $this->page(), 'handle_sync_all' ) );

		$this->assertSame( 'You do not have permission to run a network-wide sync.', $died->getMessage() );
		$this->assertSame( array( 'response' => 403 ), $died->args );
	}

	public function test_sync_all_that_finishes_says_synced(): void {
		$this->allowed();
		$this->sites->shouldReceive( 'all_blog_ids' )->once()->andReturn( array() );
		$this->users->shouldReceive( 'count_network_users' )->andReturn( 3 );

		$this->assertSame( self::ACTIONS_PAGE . '&synced=true', $this->redirect_of( array( $this->page(), 'handle_sync_all' ) ) );
	}

	public function test_sync_all_that_is_queued_says_so_and_keeps_removals_by_default(): void {
		$this->allowed();
		$this->expect_queued(
			static function ( SyncJob $job ): bool {
				return 'manual' === $job->context && null === $job->blog_ids && false === $job->force;
			}
		);

		$this->assertSame( self::ACTIONS_PAGE . '&queued=true', $this->redirect_of( array( $this->page(), 'handle_sync_all' ) ) );
	}

	public function test_sync_all_adds_removed_people_back_only_when_the_box_is_ticked(): void {
		$this->allowed();
		$_POST = array( 'wpmus_force' => 'yes' );
		$this->expect_queued(
			static function ( SyncJob $job ): bool {
				return true === $job->force;
			}
		);

		$this->redirect_of( array( $this->page(), 'handle_sync_all' ) );
	}

	public function test_a_force_value_other_than_yes_does_not_add_removed_people_back(): void {
		$this->allowed();
		$_POST = array( 'wpmus_force' => 'on' );
		$this->expect_queued(
			static function ( SyncJob $job ): bool {
				return false === $job->force;
			}
		);

		$this->redirect_of( array( $this->page(), 'handle_sync_all' ) );
	}

	// ---------------------------------------------------------------------
	// handle_sync_selected
	// ---------------------------------------------------------------------

	public function test_sync_selected_with_a_bad_nonce_stops_before_anything_else(): void {
		$this->expect_nonce_checked_first();

		$this->page()->handle_sync_selected();
	}

	public function test_sync_selected_without_manage_network_users_is_refused_with_403(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_network_users' )->andReturn( false );
		$this->sites->shouldNotReceive( 'all_blog_ids' );
		$_POST = array( 'listSites' => array( '2' ) );

		$died = $this->death_of( array( $this->page(), 'handle_sync_selected' ) );

		$this->assertSame( 'You do not have permission to run a network sync.', $died->getMessage() );
		$this->assertSame( array( 'response' => 403 ), $died->args );
	}

	public function test_sync_selected_without_a_ticked_site_warns_and_syncs_nothing(): void {
		$this->allowed();
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$this->assertSame( self::ACTIONS_PAGE . '&nosynced=true', $this->redirect_of( array( $this->page(), 'handle_sync_selected' ) ) );
	}

	public function test_sync_selected_with_only_invalid_site_ids_warns_and_syncs_nothing(): void {
		$this->allowed();
		$this->sites->shouldNotReceive( 'all_blog_ids' );
		$_POST = array( 'listSites' => array( '0', 'abc', '' ) );

		$this->assertSame( self::ACTIONS_PAGE . '&nosynced=true', $this->redirect_of( array( $this->page(), 'handle_sync_selected' ) ) );
	}

	public function test_sync_selected_with_a_non_list_value_warns_and_syncs_nothing(): void {
		$this->allowed();
		$this->sites->shouldNotReceive( 'all_blog_ids' );
		$_POST = array( 'listSites' => '2' );

		$this->assertSame( self::ACTIONS_PAGE . '&nosynced=true', $this->redirect_of( array( $this->page(), 'handle_sync_selected' ) ) );
	}

	public function test_sync_selected_syncs_the_ticked_sites_as_ids_and_says_queued(): void {
		$this->allowed();
		$_POST = array(
			'listSites'   => array( '2', 'abc', '0', '3' ),
			'wpmus_force' => 'yes',
		);
		$this->expect_queued(
			static function ( SyncJob $job ): bool {
				return 'manual' === $job->context && array( 2, 3 ) === $job->blog_ids && true === $job->force;
			}
		);

		$this->assertSame( self::ACTIONS_PAGE . '&queued=true', $this->redirect_of( array( $this->page(), 'handle_sync_selected' ) ) );
	}

	public function test_sync_selected_that_finishes_says_synced(): void {
		$this->allowed();
		$_POST = array( 'listSites' => array( '9' ) );
		// Site 9 is not live: nothing to do, the sync is done.
		$this->sites->shouldReceive( 'all_blog_ids' )->once()->andReturn( array( 1, 2 ) );
		$this->users->shouldReceive( 'count_network_users' )->andReturn( 3 );

		$this->assertSame( self::ACTIONS_PAGE . '&synced=true', $this->redirect_of( array( $this->page(), 'handle_sync_selected' ) ) );
	}
}
