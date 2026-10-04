<?php
/**
 * Unit tests for {@see \WPMUS\Sync\SyncEngine}.
 *
 * SyncEngine has zero direct WordPress coupling — every interaction
 * goes through Config / SiteRepository / UserRepository. The test
 * suite mocks those collaborators with Mockery and asserts the
 * call shape: which methods fire, how many times, with what args.
 *
 * The most important test in the file is
 * {@see test_role_change_during_inner_add_to_blog_does_not_recurse}
 * which is the regression test for the re-entrancy bug Copilot
 * caught on PR #18 review: `add_user_to_blog()` synchronously fires
 * WordPress's `set_user_role` action, which would re-enter
 * `on_role_changed()` and cascade roles across the network if not
 * guarded.
 *
 * @package WPMUS\Tests\Unit\Sync
 */

declare(strict_types=1);

namespace Tests\Unit\Sync;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use WPMUS\Config;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Repositories\UserRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;
use WPMUS\Sync\SyncJob;
use WPMUS\Sync\WriteGroups;

final class SyncEngineTest extends TestCase {

	/** @var Config&MockInterface */
	private $config;
	/** @var SiteRepository&MockInterface */
	private $sites;
	/** @var UserRepository&MockInterface */
	private $users;
	/** @var JobQueue&MockInterface */
	private $queue;
	/** @var WriteGroups&MockInterface */
	private $groups;

	protected function setUp(): void {
		parent::setUp();
		$this->config = Mockery::mock( Config::class );
		$this->sites  = Mockery::mock( SiteRepository::class );
		$this->users  = Mockery::mock( UserRepository::class );
		$this->users->shouldReceive( 'removed_blog_ids' )->andReturn( array() )->byDefault();
		$this->users->shouldReceive( 'super_admin_ids' )->andReturn( array() )->byDefault();
		$this->queue  = Mockery::mock( JobQueue::class );
		$this->queue->shouldReceive( 'unschedule' )->byDefault();
		$this->groups = Mockery::mock( WriteGroups::class );
		$this->groups->shouldReceive( 'begin' )->andReturn( false )->byDefault();
		$this->groups->shouldReceive( 'checkpoint' )->andReturn( false )->byDefault();
		$this->groups->shouldReceive( 'end' )->andReturn( true )->byDefault();
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	private function engine(): SyncEngine {
		/** @var Config $config */
		$config = $this->config;
		/** @var SiteRepository $sites */
		$sites = $this->sites;
		/** @var UserRepository $users */
		$users = $this->users;
		/** @var JobQueue $queue */
		$queue = $this->queue;

		/** @var WriteGroups $groups */
		$groups = $this->groups;

		return new SyncEngine( $config, $sites, $users, $queue, $groups );
	}

	/**
	 * Stubs the network's users: their count and their id pages.
	 *
	 * @param int[] $ids User ids, oldest first.
	 */
	private function network_users( array $ids ): void {
		$this->users->shouldReceive( 'count_network_users' )->andReturn( count( $ids ) );
		$this->users->shouldReceive( 'network_user_ids' )->andReturnUsing(
			static function ( int $offset, int $limit ) use ( $ids ): array {
				return array_slice( $ids, $offset, $limit );
			}
		);
	}

	// ---------------------------------------------------------------------
	// on_new_site
	// ---------------------------------------------------------------------

	public function test_on_new_site_returns_early_when_toggle_off(): void {
		$this->config->shouldReceive( 'is_new_site_sync_enabled' )->once()->andReturn( false );
		$this->users->shouldNotReceive( 'network_user_ids' );
		$this->users->shouldNotReceive( 'add_to_blog' );

		$this->engine()->on_new_site( 7 );
	}

	public function test_on_new_site_gives_the_sites_default_role_whatever_role_the_user_has_elsewhere(): void {
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 7 ) );
		// The engine only ever sees user ids: whatever role users 5 and
		// 6 hold elsewhere, the new site's default role is used.
		$this->config->shouldReceive( 'is_new_site_sync_enabled' )->andReturn( true );
		$this->network_users( array( 5, 6 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->with( 7 )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 7, 5, 'subscriber' )->andReturn( true );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 7, 6, 'subscriber' )->andReturn( true );

		$this->engine()->on_new_site( 7 );
	}

	public function test_on_new_site_skips_users_already_member(): void {
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 7 ) );
		$this->config->shouldReceive( 'is_new_site_sync_enabled' )->andReturn( true );
		$this->network_users( array( 5 ) );
		$this->users->shouldReceive( 'is_member_of' )->with( 5, 7 )->andReturn( true );
		$this->sites->shouldReceive( 'default_role_for_blog' )->with( 7 )->andReturn( 'subscriber' );
		$this->users->shouldNotReceive( 'add_to_blog' );

		$this->engine()->on_new_site( 7 );
	}

	// ---------------------------------------------------------------------
	// on_new_user
	// ---------------------------------------------------------------------

	public function test_on_new_user_returns_early_when_toggle_off(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->once()->andReturn( false );
		$this->sites->shouldNotReceive( 'all_blog_ids' );
		$this->users->shouldNotReceive( 'add_to_blog' );

		$this->engine()->on_new_user( 5 );
	}

	public function test_on_new_user_adds_user_to_every_missing_site(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( true );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2, 3 ) );

		// User is already on blog 1, missing from 2 and 3.
		$this->users->shouldReceive( 'is_member_of' )->with( 5, 1 )->andReturn( true );
		$this->users->shouldReceive( 'is_member_of' )->with( 5, 2 )->andReturn( false );
		$this->users->shouldReceive( 'is_member_of' )->with( 5, 3 )->andReturn( false );

		$this->sites->shouldReceive( 'default_role_for_blog' )->with( 2 )->andReturn( 'subscriber' );
		$this->sites->shouldReceive( 'default_role_for_blog' )->with( 3 )->andReturn( 'editor' );

		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 2, 5, 'subscriber' )->andReturn( true );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 3, 5, 'editor' )->andReturn( true );

		$this->engine()->on_new_user( 5 );
	}

	public function test_on_new_site_accepts_the_wp_site_from_wp_initialize_site(): void {
		$this->config->shouldReceive( 'is_new_site_sync_enabled' )->andReturn( true );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 7 ) );
		$this->network_users( array( 5 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->with( 7 )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 7, 5, 'subscriber' )->andReturn( true );

		$this->engine()->on_new_site( new \WP_Site( 7, 'localhost', '/seven/' ) );
	}

	public function test_a_new_user_is_synced_once_whichever_hooks_fire(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( true );
		$this->sites->shouldReceive( 'all_blog_ids' )->once()->andReturn( array( 2 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 2, 5, 'subscriber' )->andReturn( true );

		// wpmu_create_user(): user_register, then wpmu_new_user, then shutdown.
		$engine = $this->engine();
		$engine->on_user_registered( 5 );
		$engine->on_new_user( 5 );
		$engine->flush_registered_users();
	}

	public function test_a_user_made_with_wp_insert_user_alone_is_synced_at_shutdown(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( true );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 2 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );

		$added = array();
		$this->users->shouldReceive( 'add_to_blog' )->andReturnUsing(
			static function ( int $blog_id, int $user_id, string $role ) use ( &$added ): bool {
				$added[] = array( $blog_id, $user_id, $role );
				return true;
			}
		);

		$engine = $this->engine();
		$engine->on_user_registered( 6 );
		$this->assertSame( array(), $added, 'Nothing happens at user_register.' );

		$engine->flush_registered_users();
		$this->assertSame( array( array( 2, 6, 'subscriber' ) ), $added );
	}

	// ---------------------------------------------------------------------
	// Removals
	// ---------------------------------------------------------------------

	public function test_a_removal_is_recorded(): void {
		Functions\when( 'doing_action' )->justReturn( false );
		$this->users->shouldReceive( 'record_removal' )->once()->with( 5, 3 );

		$this->engine()->on_user_removed_from_blog( 5, 3 );
	}

	public function test_core_housekeeping_removals_are_not_recorded(): void {
		Functions\when( 'doing_action' )->alias(
			static function ( string $hook ): bool {
				return 'wp_uninitialize_site' === $hook;
			}
		);
		$this->users->shouldNotReceive( 'record_removal' );

		$this->engine()->on_user_removed_from_blog( 5, 3 );
	}

	public function test_an_activated_invitee_gets_the_new_user_sync_again(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( true );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->users->shouldReceive( 'is_member_of' )->with( 5, 1 )->andReturn( false );
		$this->users->shouldReceive( 'is_member_of' )->with( 5, 2 )->andReturn( true );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		// Once from wpmu_new_user, and again after core took the invitee
		// off site 1: the once-per-request guard does not apply here.
		$this->users->shouldReceive( 'add_to_blog' )->twice()->with( 1, 5, 'subscriber' )->andReturn( true );

		$engine = $this->engine();
		$engine->on_new_user( 5 );
		$engine->on_user_activated( 5 );
	}

	public function test_someone_else_adding_the_user_back_forgets_the_removal(): void {
		$this->users->shouldReceive( 'forget_removal' )->once()->with( 5, 3 );

		$this->engine()->on_user_added_to_blog( 5, 'subscriber', 3 );
	}

	public function test_new_user_trigger_skips_sites_the_user_was_removed_from(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( true );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 2, 3 ) );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->users->shouldReceive( 'removed_blog_ids' )->with( 5 )->andReturn( array( 3 ) );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 2, 5, 'subscriber' )->andReturn( true );

		$this->engine()->on_new_user( 5 );
	}

	public function test_forced_manual_sync_adds_a_removed_user_back_and_forgets_the_removal(): void {
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 3 ) );
		$this->network_users( array( 5 ) );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->users->shouldReceive( 'removed_blog_ids' )->with( 5 )->andReturn( array( 3 ) );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 3, 5, 'subscriber' )->andReturn( true );
		$this->users->shouldReceive( 'forget_removal' )->once()->with( 5, 3 );

		$this->engine()->sync_all_users_to_sites( array( 3 ), true );
	}

	// ---------------------------------------------------------------------
	// on_role_changed
	// ---------------------------------------------------------------------

	public function test_on_role_changed_returns_early_when_toggle_off(): void {
		$this->config->shouldReceive( 'is_set_user_role_sync_enabled' )->once()->andReturn( false );
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$this->engine()->on_role_changed( 5, 'editor' );
	}

	/**
	 * Role sync on, the change made on site 1, the user a member of
	 * sites 1, 2 and 3, every site active and defining every role.
	 */
	private function role_change_on_site_one(): void {
		$this->config->shouldReceive( 'is_set_user_role_sync_enabled' )->andReturn( true );
		$this->sites->shouldReceive( 'current_blog_id' )->andReturn( 1 );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2, 3, 4 ) );
		$this->users->shouldReceive( 'blog_ids_of_user' )->with( 5 )->andReturn( array( 1, 2, 3 ) );
		$this->sites->shouldReceive( 'role_exists_on_blog' )->andReturn( true )->byDefault();
	}

	public function test_on_role_changed_copies_the_role_to_the_users_other_sites(): void {
		$this->role_change_on_site_one();
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 2, 5, 'editor' )->andReturn( true );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 3, 5, 'editor' )->andReturn( true );

		$this->engine()->on_role_changed( 5, 'editor', array( 'subscriber' ) );
	}

	public function test_on_role_changed_skips_a_site_without_that_role(): void {
		$this->role_change_on_site_one();
		$this->sites->shouldReceive( 'role_exists_on_blog' )->with( 2, 'shop_manager' )->andReturn( false );
		$this->sites->shouldReceive( 'role_exists_on_blog' )->with( 3, 'shop_manager' )->andReturn( true );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 3, 5, 'shop_manager' )->andReturn( true );

		$this->engine()->on_role_changed( 5, 'shop_manager', array( 'subscriber' ) );
	}

	public function test_on_role_changed_does_not_copy_administrator(): void {
		$this->role_change_on_site_one();
		$this->users->shouldNotReceive( 'add_to_blog' );

		$this->engine()->on_role_changed( 5, 'administrator', array( 'subscriber' ) );
	}

	public function test_on_role_changed_copies_administrator_when_the_filter_allows_it(): void {
		$this->role_change_on_site_one();
		\Brain\Monkey\Filters\expectApplied( 'wpmus_replicate_role' )->once()->with( false, 'administrator', 5 )->andReturn( true );
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );

		$this->engine()->on_role_changed( 5, 'administrator', array( 'subscriber' ) );
	}

	public function test_on_role_changed_ignores_an_unchanged_or_empty_role(): void {
		$this->config->shouldReceive( 'is_set_user_role_sync_enabled' )->andReturn( true );
		$this->users->shouldNotReceive( 'blog_ids_of_user' );
		$this->users->shouldNotReceive( 'add_to_blog' );

		$this->engine()->on_role_changed( 5, 'editor', array( 'editor' ) );
		$this->engine()->on_role_changed( 5, '', array( 'editor' ) );
	}

	public function test_on_role_changed_leaves_super_admins_alone(): void {
		$this->config->shouldReceive( 'is_set_user_role_sync_enabled' )->andReturn( true );
		$this->users->shouldReceive( 'super_admin_ids' )->andReturn( array( 5 ) );
		$this->users->shouldNotReceive( 'add_to_blog' );

		$this->engine()->on_role_changed( 5, 'editor', array( 'subscriber' ) );
	}

	// ---------------------------------------------------------------------
	// sync_all_users_to_all_sites
	// ---------------------------------------------------------------------

	public function test_sync_all_users_to_all_sites_runs_unconditionally(): void {
		// No call to is_*_enabled — manual actions are gated by the
		// admin handler's nonce + capability check, not by the
		// trigger toggles.
		$this->config->shouldNotReceive( 'is_new_site_sync_enabled' );
		$this->config->shouldNotReceive( 'is_new_user_sync_enabled' );
		$this->config->shouldNotReceive( 'is_set_user_role_sync_enabled' );

		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->network_users( array( 5 ) );

		$this->users->shouldReceive( 'is_member_of' )->with( 5, 1 )->andReturn( true );
		$this->users->shouldReceive( 'is_member_of' )->with( 5, 2 )->andReturn( false );

		$this->sites->shouldReceive( 'default_role_for_blog' )->with( 2 )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 2, 5, 'subscriber' )->andReturn( true );

		$this->engine()->sync_all_users_to_all_sites();
	}

	// ---------------------------------------------------------------------
	// sync_all_users_to_sites — perf regression: users fetched once
	// ---------------------------------------------------------------------

	public function test_users_are_read_a_page_of_ids_at_a_time(): void {
		// Users are never all loaded at once: ids only, a page at a time.
		// One user and five sites fit in a single, short page.
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2, 3, 4, 5 ) );
		$this->users->shouldReceive( 'count_network_users' )->andReturn( 1 );
		$this->users->shouldReceive( 'network_user_ids' )
			->once() // <-- the assertion
			->with( 0, 500 )
			->andReturn( array( 5 ) );

		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->times( 5 )->andReturn( true );

		$this->assertTrue( $this->engine()->sync_all_users_to_sites( array( 1, 2, 3, 4, 5 ) ) );
	}

	public function test_a_job_over_the_inline_limit_is_queued_not_run(): void {
		\Brain\Monkey\Filters\expectApplied( 'wpmus_sync_inline_limit' )->once()->with( SyncEngine::INLINE_LIMIT, 'manual' )->andReturn( 1 );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->users->shouldReceive( 'count_network_users' )->andReturn( 3 );
		$this->users->shouldNotReceive( 'add_to_blog' );
		$this->queue->shouldReceive( 'add' )->once()->with(
			Mockery::on(
				static function ( SyncJob $job ): bool {
					return 'manual' === $job->context && 6 === $job->total && 0 === $job->processed;
				}
			)
		);
		$this->queue->shouldReceive( 'schedule' )->once();

		$this->assertFalse( $this->engine()->sync_all_users_to_all_sites() );
	}

	public function test_a_queued_job_advances_one_batch_per_run_and_resumes_where_it_stopped(): void {
		// Two users, two sites, batches of three pairs: the first run
		// does u5@1, u5@2, u6@1; the second does u6@2 and finishes.
		\Brain\Monkey\Filters\expectApplied( 'wpmus_sync_batch_size' )->andReturn( 3 );
		\Brain\Monkey\Filters\expectApplied( 'wpmus_sync_time_limit' )->andReturn( 0 );
		$job = new SyncJob( 'manual', null, null, false );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->network_users( array( 5, 6 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$added = array();
		$this->users->shouldReceive( 'add_to_blog' )->andReturnUsing(
			static function ( int $blog_id, int $user_id ) use ( &$added ): bool {
				$added[] = $user_id . '@' . $blog_id;
				return true;
			}
		);
		$this->queue->shouldReceive( 'acquire_lock' )->andReturn( true );
		$this->queue->shouldReceive( 'release_lock' );
		$this->queue->shouldReceive( 'first' )->andReturn( $job, null, null, $job, null, null );
		$this->queue->shouldReceive( 'update' )->once()->with( $job );
		$this->queue->shouldReceive( 'schedule' )->never();
		$this->queue->shouldReceive( 'remove' )->once()->with( $job->id );

		$engine = $this->engine();
		$engine->process_queue();
		$this->assertSame( array( '5@1', '5@2', '6@1' ), $added );
		$this->assertSame( 3, $job->processed );
		$this->assertFalse( $job->done );

		$engine->process_queue();
		$this->assertSame( array( '5@1', '5@2', '6@1', '6@2' ), $added );
		$this->assertTrue( $job->done );
	}

	public function test_a_run_that_empties_the_queue_removes_any_pending_retry(): void {
		$job = $this->one_queued_job();
		$this->queue->shouldReceive( 'first' )->andReturn( $job, null );
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );
		$this->queue->shouldReceive( 'remove' )->once();
		$this->queue->shouldNotReceive( 'schedule' );
		$this->queue->shouldReceive( 'unschedule' )->once();

		$this->engine()->process_queue();
	}

	public function test_a_committed_group_cleans_its_users_cache_right_away(): void {
		$job = $this->one_queued_job();
		$this->queue->shouldReceive( 'first' )->andReturn( $job, null );
		$this->queue->shouldReceive( 'remove' )->once();
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );
		$this->users->shouldReceive( 'stored_member_count' )->andReturn( 1 );
		$this->groups->shouldReceive( 'begin' )->andReturn( true );
		$this->groups->shouldReceive( 'checkpoint' )->andReturn( true, false );
		$this->users->shouldReceive( 'forget_cached' )->once()->with( array( 5 ) )->ordered();
		$this->users->shouldReceive( 'forget_cached' )->once()->with( array( 5 ) )->ordered();

		$this->engine()->process_queue();
	}

	public function test_a_job_queued_while_the_run_emptied_the_queue_keeps_its_run(): void {
		$job = $this->one_queued_job();
		$new = new SyncJob( 'new_site', null, array( 9 ), false );
		$this->queue->shouldReceive( 'first' )->andReturn( $job, null, $new );
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );
		$this->queue->shouldReceive( 'remove' )->once();
		$this->queue->shouldReceive( 'unschedule' )->once()->ordered();
		$this->queue->shouldReceive( 'schedule' )->once()->ordered();

		$this->engine()->process_queue();
	}

	public function test_a_run_that_leaves_work_schedules_the_next_one(): void {
		\Brain\Monkey\Filters\expectApplied( 'wpmus_sync_batch_size' )->andReturn( 1 );
		\Brain\Monkey\Filters\expectApplied( 'wpmus_sync_time_limit' )->andReturn( 0 );
		$job = new SyncJob( 'new_user', array( 5 ), null, false );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 1, 5, 'subscriber' )->andReturn( true );
		$this->queue->shouldReceive( 'acquire_lock' )->andReturn( true );
		$this->queue->shouldReceive( 'release_lock' );
		$this->queue->shouldReceive( 'first' )->andReturn( $job );
		$this->queue->shouldReceive( 'update' )->once();
		$this->queue->shouldReceive( 'schedule' )->once();

		$this->engine()->process_queue();
	}

	/**
	 * A queued job of user 5 on sites 2 and 3, one batch per run.
	 */
	private function one_queued_job(): SyncJob {
		\Brain\Monkey\Filters\expectApplied( 'wpmus_sync_time_limit' )->andReturn( 0 );
		$job = new SyncJob( 'new_user', array( 5 ), null, false );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 2, 3 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->queue->shouldReceive( 'acquire_lock' )->andReturn( true );
		$this->queue->shouldReceive( 'release_lock' );
		return $job;
	}

	public function test_a_background_batch_opens_a_write_group_checkpoints_after_each_pair_and_ends_it(): void {
		$job = $this->one_queued_job();
		$this->queue->shouldReceive( 'first' )->andReturn( $job, null );
		$this->queue->shouldReceive( 'remove' )->once();
		$order = array();
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturnUsing(
			static function () use ( &$order ): bool {
				$order[] = 'add';
				return true;
			}
		);
		foreach ( array( 'begin', 'checkpoint', 'end' ) as $step ) {
			$this->groups->shouldReceive( $step )->andReturnUsing(
				static function () use ( &$order, $step ): bool {
					$order[] = $step;
					return 'end' === $step;
				}
			);
		}

		$this->engine()->process_queue();

		$this->assertSame( array( 'begin', 'add', 'checkpoint', 'add', 'checkpoint', 'end' ), $order );
	}

	public function test_a_batch_whose_group_did_not_commit_is_left_for_the_next_run(): void {
		$job = $this->one_queued_job();
		$this->queue->shouldReceive( 'first' )->andReturn( $job );
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );
		$this->groups->shouldReceive( 'end' )->once()->andReturn( false );
		$this->queue->shouldNotReceive( 'update' );
		$this->queue->shouldNotReceive( 'remove' );
		$this->queue->shouldNotReceive( 'schedule' );
		$this->queue->shouldReceive( 'schedule_at' )->once()->with(
			Mockery::on(
				static function ( int $at ): bool {
					return abs( $at - ( time() + SyncEngine::LOST_GROUP_RETRY ) ) <= 2;
				}
			)
		);

		$this->engine()->process_queue();
	}

	public function test_a_grouped_batch_is_stored_once_the_database_holds_every_membership_it_wrote(): void {
		$job = $this->one_queued_job();
		$this->queue->shouldReceive( 'first' )->andReturn( $job, null );
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );
		$this->groups->shouldReceive( 'begin' )->andReturn( true );
		$this->users->shouldReceive( 'stored_member_count' )->once()->with( 2, array( 5 ) )->andReturn( 1 );
		$this->users->shouldReceive( 'stored_member_count' )->once()->with( 3, array( 5 ) )->andReturn( 1 );
		// Core cleaned their cache before the commit; it is cleaned again after.
		$this->users->shouldReceive( 'forget_cached' )->once()->with( array( 5, 5 ) );
		$this->queue->shouldReceive( 'remove' )->once();

		$this->engine()->process_queue();
	}

	public function test_a_grouped_batch_the_database_lost_is_read_afresh_and_left_for_the_next_run(): void {
		$job = $this->one_queued_job();
		$this->queue->shouldReceive( 'first' )->andReturn( $job );
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );
		$this->groups->shouldReceive( 'begin' )->andReturn( true );
		// The COMMIT went through, but a deadlock had rolled the group back.
		$this->users->shouldReceive( 'stored_member_count' )->andReturn( 0 );
		// Once after the commit, once more for the redo.
		$this->users->shouldReceive( 'forget_cached' )->twice()->with( array( 5, 5 ) );
		$this->queue->shouldNotReceive( 'update' );
		$this->queue->shouldNotReceive( 'remove' );
		$this->queue->shouldReceive( 'schedule_at' )->once();

		$this->engine()->process_queue();
	}

	public function test_an_ungrouped_batch_is_not_checked(): void {
		$job = $this->one_queued_job();
		$this->queue->shouldReceive( 'first' )->andReturn( $job, null );
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );
		$this->users->shouldNotReceive( 'stored_member_count' );
		$this->queue->shouldReceive( 'remove' )->once();

		$this->engine()->process_queue();
	}

	public function test_the_write_group_ends_even_when_a_write_fails(): void {
		$job = $this->one_queued_job();
		$this->queue->shouldReceive( 'first' )->andReturn( $job );
		$this->users->shouldReceive( 'add_to_blog' )->andThrow( new \RuntimeException( 'database gone' ) );
		$this->groups->shouldReceive( 'begin' )->once();
		$this->groups->shouldReceive( 'end' )->once()->andReturn( true );

		$this->expectException( \RuntimeException::class );
		$this->engine()->process_queue();
	}

	public function test_a_sync_in_the_request_that_starts_it_opens_no_write_group(): void {
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 2 ) );
		$this->network_users( array( 5 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->andReturn( true );
		$this->groups->shouldNotReceive( 'begin' );

		$this->assertTrue( $this->engine()->sync_all_users_to_sites( array( 2 ) ) );
	}

	public function test_a_run_does_nothing_while_another_holds_the_lock_but_leaves_a_retry_for_when_it_goes_stale(): void {
		$this->queue->shouldReceive( 'acquire_lock' )->andReturn( false );
		$this->queue->shouldReceive( 'lock_stale_at' )->andReturn( 1700000600 );
		$this->queue->shouldReceive( 'schedule_at' )->once()->with( 1700000600 );
		$this->queue->shouldNotReceive( 'first' );
		$this->queue->shouldNotReceive( 'release_lock' );

		$this->engine()->process_queue();
	}

	// ---------------------------------------------------------------------
	// Exclusions
	// ---------------------------------------------------------------------

	public function test_super_admins_are_never_added(): void {
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 3 ) );
		$this->network_users( array( 1, 5 ) );
		$this->users->shouldReceive( 'super_admin_ids' )->andReturn( array( 1 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 3, 5, 'subscriber' )->andReturn( true );

		$this->engine()->sync_all_users_to_all_sites();
	}

	public function test_requested_sites_that_are_not_active_are_left_alone(): void {
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->network_users( array( 5 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 2, 5, 'subscriber' )->andReturn( true );

		$this->engine()->sync_all_users_to_sites( array( 2, 9 ) );
	}

	public function test_the_site_filter_excludes_sites(): void {
		\Brain\Monkey\Filters\expectApplied( 'wpmus_excluded_site_ids' )->once()->with( array(), 'manual' )->andReturn( array( 2 ) );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->network_users( array( 5 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 1, 5, 'subscriber' )->andReturn( true );

		$this->engine()->sync_all_users_to_all_sites();
	}

	public function test_the_user_filter_excludes_a_user_from_a_site(): void {
		\Brain\Monkey\Filters\expectApplied( 'wpmus_should_sync_user' )->twice()->andReturnUsing(
			static function ( bool $sync, int $user_id, int $blog_id ): bool {
				return 2 !== $blog_id;
			}
		);
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->network_users( array( 5 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 1, 5, 'subscriber' )->andReturn( true );

		$this->engine()->sync_all_users_to_all_sites();
	}

	// ---------------------------------------------------------------------
	// Re-entrancy guard — the regression test for the Copilot finding
	// ---------------------------------------------------------------------

	public function test_role_change_during_inner_add_to_blog_does_not_recurse(): void {
		// Scenario: role-sync is ON. The admin runs a manual `Sync from
		// scratch`, which calls add_to_blog → WP fires set_user_role →
		// on_role_changed gets invoked. Without the in_sync guard the
		// inner on_role_changed would re-iterate every blog and call
		// add_to_blog again, which fires set_user_role again, etc.
		//
		// We simulate the WP-side dispatch here: the add_to_blog mock
		// invokes on_role_changed during its execution. The assertion
		// is that all_blog_ids is called EXACTLY ONCE (from
		// sync_all_users_to_all_sites). If the guard fails, the inner
		// on_role_changed call would also call all_blog_ids for its
		// own iteration, producing >1 calls.
		$engine = $this->engine();

		$this->config->shouldReceive( 'is_set_user_role_sync_enabled' )->andReturn( true );

		// One call from the outer sync_all_users_to_all_sites.
		$this->sites->shouldReceive( 'all_blog_ids' )->once()->andReturn( array( 1, 2 ) );

		$this->network_users( array( 5 ) );

		// User missing on both sites — both will be add_to_blog'd.
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );

		// Simulate WP firing set_user_role during add_user_to_blog.
		$this->users->shouldReceive( 'add_to_blog' )->andReturnUsing(
			static function ( int $blog_id, int $user_id, string $role ) use ( $engine ) {
				$engine->on_role_changed( $user_id, $role, array() );
				return true;
			}
		);

		$engine->sync_all_users_to_all_sites();
		// Mockery's expectations on call counts (the `->once()` on
		// all_blog_ids) provide the assertion. Without a guard,
		// Mockery would fail the test with a too-many-invocations
		// error.
		$this->assertTrue( true ); // satisfy PHPUnit risky-test detection.
	}

	public function test_role_change_outside_a_sync_method_runs_normally(): void {
		// Sanity check: the in_sync flag is reset between calls, so a
		// real role-change event after a manual sync still propagates
		// across existing memberships.
		$this->role_change_on_site_one();
		$this->users->shouldReceive( 'add_to_blog' )->twice()->andReturn( true );

		$this->engine()->on_role_changed( 5, 'editor', array( 'subscriber' ) );
	}

	// ---------------------------------------------------------------------
	// Early returns
	// ---------------------------------------------------------------------

	public function test_on_new_site_ignores_an_invalid_site_id(): void {
		$this->config->shouldReceive( 'is_new_site_sync_enabled' )->andReturn( true );
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$this->engine()->on_new_site( 0 );
	}

	public function test_on_new_user_ignores_an_invalid_user_id(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( true );
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$this->engine()->on_new_user( 0 );
	}

	public function test_on_new_user_runs_once_per_user_in_a_request(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( true );
		$this->sites->shouldReceive( 'all_blog_ids' )->once()->andReturn( array( 2 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->with( 2, 5, 'subscriber' )->andReturn( true );

		$engine = $this->engine();
		$engine->on_new_user( 5 );
		$engine->on_new_user( 5 );
	}

	public function test_a_registration_with_the_toggle_off_is_not_synced_at_shutdown(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( false );
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$engine = $this->engine();
		$engine->on_user_registered( 5 );
		$engine->flush_registered_users();
	}

	public function test_a_registration_with_an_invalid_user_id_is_ignored(): void {
		$this->config->shouldNotReceive( 'is_new_user_sync_enabled' );

		$engine = $this->engine();
		$engine->on_user_registered( 0 );
		$engine->flush_registered_users();
	}

	public function test_a_removal_with_an_invalid_id_is_not_recorded(): void {
		$this->users->shouldNotReceive( 'record_removal' );
		Functions\expect( 'doing_action' )->never();

		$engine = $this->engine();
		$engine->on_user_removed_from_blog( 0, 3 );
		$engine->on_user_removed_from_blog( 5, 0 );
	}

	public function test_an_activation_with_the_toggle_off_syncs_nothing(): void {
		$this->config->shouldReceive( 'is_new_user_sync_enabled' )->andReturn( false );
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$this->engine()->on_user_activated( 5 );
	}

	public function test_an_activation_with_an_invalid_user_id_syncs_nothing(): void {
		$this->config->shouldNotReceive( 'is_new_user_sync_enabled' );
		$this->sites->shouldNotReceive( 'all_blog_ids' );

		$this->engine()->on_user_activated( 0 );
	}

	public function test_the_engines_own_writes_do_not_forget_a_removal(): void {
		// A forced sync adds a removed user back: the add_user_to_blog
		// hook it fires must not clear anything by itself; the engine
		// forgets the removal once, after the write.
		$engine = $this->engine();
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 3 ) );
		$this->network_users( array( 5 ) );
		$this->users->shouldReceive( 'is_member_of' )->andReturn( false );
		$this->users->shouldReceive( 'removed_blog_ids' )->with( 5 )->andReturn( array( 3 ) );
		$this->sites->shouldReceive( 'default_role_for_blog' )->andReturn( 'subscriber' );
		$this->users->shouldReceive( 'add_to_blog' )->once()->andReturnUsing(
			static function ( int $blog_id, int $user_id, string $role ) use ( $engine ): bool {
				$engine->on_user_added_to_blog( $user_id, $role, $blog_id );
				return true;
			}
		);
		$this->users->shouldReceive( 'forget_removal' )->once()->with( 5, 3 );

		$this->assertTrue( $engine->sync_all_users_to_all_sites( true ) );
	}

	public function test_a_sync_with_no_user_site_pairs_finishes_without_writing(): void {
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->users->shouldReceive( 'count_network_users' )->andReturn( 0 );
		$this->users->shouldNotReceive( 'network_user_ids' );
		$this->queue->shouldNotReceive( 'add' );

		$this->assertTrue( $this->engine()->sync_all_users_to_all_sites() );
	}

	public function test_a_run_with_an_empty_queue_releases_the_lock_and_schedules_nothing(): void {
		$this->queue->shouldReceive( 'acquire_lock' )->once()->andReturn( true );
		$this->queue->shouldReceive( 'first' )->andReturn( null );
		$this->queue->shouldReceive( 'release_lock' )->once();
		$this->queue->shouldNotReceive( 'schedule' );

		$this->engine()->process_queue();
	}

	public function test_a_queued_job_whose_sites_are_gone_is_finished_and_removed(): void {
		$job = new SyncJob( 'manual', null, array( 9 ), false );
		$this->sites->shouldReceive( 'all_blog_ids' )->andReturn( array( 1, 2 ) );
		$this->users->shouldNotReceive( 'network_user_ids' );
		$this->queue->shouldReceive( 'acquire_lock' )->andReturn( true );
		$this->queue->shouldReceive( 'first' )->andReturn( $job, null );
		$this->queue->shouldReceive( 'remove' )->once()->with( $job->id );
		$this->queue->shouldReceive( 'release_lock' )->once();
		$this->queue->shouldNotReceive( 'schedule' );

		$this->engine()->process_queue();

		$this->assertTrue( $job->done );
	}

	public function test_the_lock_is_released_when_a_batch_fails(): void {
		$job = new SyncJob( 'manual', null, null, false );
		$this->sites->shouldReceive( 'all_blog_ids' )->andThrow( new \RuntimeException( 'database gone' ) );
		$this->queue->shouldReceive( 'acquire_lock' )->andReturn( true );
		$this->queue->shouldReceive( 'first' )->andReturn( $job );
		$this->queue->shouldReceive( 'release_lock' )->once();

		$this->expectException( \RuntimeException::class );

		$this->engine()->process_queue();
	}
}
