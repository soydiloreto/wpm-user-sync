<?php
/**
 * Unit tests for {@see \WPMUS\Admin\SiteSyncActionsPage}: the one-button
 * form, and the handler that checks the nonce and
 * `manage_network_users` (a site administrator is refused) before it
 * syncs the current site.
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
use WPMUS\Admin\SiteSyncActionsPage;
use WPMUS\Config;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Repositories\UserRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;
use WPMUS\Sync\SyncJob;

final class SiteSyncActionsPageTest extends TestCase {

	use AdminScreen;

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
		$this->sites = Mockery::mock( SiteRepository::class );
		$this->users = Mockery::mock( UserRepository::class );
		$this->queue = Mockery::mock( JobQueue::class );
	}

	private function page(): SiteSyncActionsPage {
		/** @var SiteRepository $sites */
		$sites = $this->sites;
		/** @var UserRepository $users */
		$users = $this->users;
		/** @var JobQueue $queue */
		$queue = $this->queue;
		return new SiteSyncActionsPage( new SyncEngine( Mockery::mock( Config::class ), $sites, $users, $queue ) );
	}

	/**
	 * A nonce and capability that pass, on site `$blog_id`.
	 */
	private function allowed_on( int $blog_id ): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( $blog_id );
	}

	public function test_the_form_posts_the_current_site_to_the_sync_action_with_a_nonce(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 4 );

		$output = $this->output_of( array( $this->page(), 'render' ) );

		$this->assertStringContainsString( '<form method="post" action="https://example.test/sub/wp-admin/admin.php?action=wpmusSyncSiteSiteFromScratch">', $output );
		$this->assertStringContainsString( '<input type="hidden" name="_wpnonce" data-action="' . Config::NONCE_ACTION . '" />', $output );
		$this->assertStringContainsString( '<input type="hidden" name="wpmus_blogid" value="4" />', $output );
		$this->assertStringContainsString( '<input type="submit" value="Sync from scratch" class="button" />', $output );
	}

	public function test_a_bad_nonce_stops_before_anything_else(): void {
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

		$this->page()->handle_sync_current_site();
	}

	public function test_a_site_administrator_is_refused_with_403(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_network_users' )->andReturn( false );
		Functions\expect( 'get_current_blog_id' )->never();
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$died = $this->death_of( array( $this->page(), 'handle_sync_current_site' ) );

		$this->assertSame( 'You do not have permission to run a site sync.', $died->getMessage() );
		$this->assertSame( array( 'response' => 403 ), $died->args );
	}

	public function test_a_sync_that_finishes_says_synced(): void {
		$this->allowed_on( 3 );
		// Site 3 is no longer live, so the job has nothing to do.
		$this->sites->shouldReceive( 'all_blog_ids' )->once()->andReturn( array( 1, 2 ) );
		$this->users->shouldReceive( 'count_network_users' )->andReturn( 10 );

		$location = $this->redirect_of( array( $this->page(), 'handle_sync_current_site' ) );

		$this->assertSame( 'https://example.test/sub/wp-admin/admin.php?page=wpmus-sitesyncactions&synced=true', $location );
	}

	public function test_a_big_sync_of_the_current_site_is_queued_and_says_so(): void {
		$this->allowed_on( 3 );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2, 3 ) );
		$this->users->shouldReceive( 'count_network_users' )->andReturn( SyncEngine::INLINE_LIMIT + 1 );
		$this->queue->shouldReceive( 'add' )->once()->with(
			Mockery::on(
				static function ( SyncJob $job ): bool {
					return 'manual' === $job->context && array( 3 ) === $job->blog_ids && null === $job->user_ids && false === $job->force;
				}
			)
		);
		$this->queue->shouldReceive( 'schedule' )->once();

		$location = $this->redirect_of( array( $this->page(), 'handle_sync_current_site' ) );

		$this->assertSame( 'https://example.test/sub/wp-admin/admin.php?page=wpmus-sitesyncactions&queued=true', $location );
	}
}
