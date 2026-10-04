<?php
/**
 * Unit tests for {@see \WPMUS\Sync\JobQueue}: the jobs stored in a
 * network option, the lock that keeps two cron runs apart (taken, held,
 * and taken over once older than LOCK_TTL), and the cron event, always
 * on the main site.
 *
 * The network options live in an array here; Brain Monkey routes the
 * `*_site_option()` calls to it. The object cache is another array, so a
 * test can see what the queue drops from it before each read.
 *
 * @package WPMUS\Tests\Unit\Sync
 */

declare(strict_types=1);

namespace Tests\Unit\Sync;

use Brain\Monkey\Functions;
use RuntimeException;
use Tests\TestCase;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncJob;

final class JobQueueTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var string[] Blog switches, in order: `to:N` and `restore`. */
	private array $switches = array();

	/** @var array<string,mixed> The `site-options` cache group, by key. */
	private array $cache = array();

	protected function setUp(): void {
		parent::setUp();
		$options  = &$this->options;
		$switches = &$this->switches;
		$cache    = &$this->cache;
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'wp_cache_get' )->alias(
			static function ( string $key, string $group ) use ( &$cache ) {
				return 'site-options' === $group && array_key_exists( $key, $cache ) ? $cache[ $key ] : false;
			}
		);
		Functions\when( 'wp_cache_set' )->alias(
			static function ( string $key, $value, string $group ) use ( &$cache ): bool {
				$cache[ $key ] = $value;
				return 'site-options' === $group;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( string $key, string $group ) use ( &$cache ): bool {
				unset( $cache[ $key ] );
				return 'site-options' === $group;
			}
		);
		Functions\when( 'get_site_option' )->alias(
			static function ( string $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_site_option' )->alias(
			static function ( string $name, $value ) use ( &$options ): bool {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_site_option' )->alias(
			static function ( string $name, $value ) use ( &$options ): bool {
				if ( array_key_exists( $name, $options ) ) {
					return false;
				}
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->alias(
			static function ( string $name ) use ( &$options ): bool {
				unset( $options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'switch_to_blog' )->alias(
			static function ( int $blog_id ) use ( &$switches ): bool {
				$switches[] = 'to:' . $blog_id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			static function () use ( &$switches ): bool {
				$switches[] = 'restore';
				return true;
			}
		);
	}

	private function job( string $context = 'manual' ): SyncJob {
		return new SyncJob( $context, null, null, false );
	}

	// ---------------------------------------------------------------------
	// The stored jobs
	// ---------------------------------------------------------------------

	public function test_an_empty_queue_has_no_jobs_and_no_first(): void {
		$queue = new JobQueue();

		$this->assertSame( array(), $queue->all() );
		$this->assertNull( $queue->first() );
	}

	public function test_a_stored_value_that_is_not_a_list_reads_as_an_empty_queue(): void {
		$this->options[ JobQueue::OPTION_JOBS ] = 'corrupted';

		$this->assertSame( array(), ( new JobQueue() )->all() );
	}

	public function test_entries_that_are_not_jobs_are_skipped(): void {
		$job                                    = $this->job();
		$this->options[ JobQueue::OPTION_JOBS ] = array( 'junk', $job->to_array(), array( 'id' => 5 ) );

		$jobs = ( new JobQueue() )->all();

		$this->assertCount( 1, $jobs );
		$this->assertSame( $job->id, $jobs[0]->id );
	}

	public function test_added_jobs_are_stored_as_arrays_oldest_first(): void {
		$queue  = new JobQueue();
		$first  = $this->job( 'new_site' );
		$second = $this->job( 'new_user' );

		$queue->add( $first );
		$queue->add( $second );

		$this->assertSame( array( $first->to_array(), $second->to_array() ), $this->options[ JobQueue::OPTION_JOBS ] );
		$this->assertSame( $first->id, $queue->first()->id );
	}

	public function test_every_read_drops_the_requests_cached_copy_of_the_queue(): void {
		$this->cache['1:' . JobQueue::OPTION_JOBS] = array( 'stale' );
		$this->cache['1:notoptions']               = array(
			JobQueue::OPTION_JOBS => true,
			'another_option'      => true,
		);

		( new JobQueue() )->all();

		$this->assertArrayNotHasKey( '1:' . JobQueue::OPTION_JOBS, $this->cache );
		$this->assertSame( array( 'another_option' => true ), $this->cache['1:notoptions'], 'Only the queue leaves the "not there" record.' );
	}

	public function test_a_not_there_record_without_the_queue_is_left_alone(): void {
		$this->cache['1:notoptions'] = array( 'another_option' => true );

		( new JobQueue() )->all();

		$this->assertSame( array( 'another_option' => true ), $this->cache['1:notoptions'] );
	}

	public function test_update_stores_the_progress_of_that_job_only(): void {
		$queue = new JobQueue();
		$one   = $this->job();
		$two   = $this->job();
		$queue->add( $one );
		$queue->add( $two );

		$two->processed    = 40;
		$two->user_offset  = 3;
		$two->last_blog_id = 7;
		$queue->update( $two );

		$jobs = $queue->all();
		$this->assertSame( 0, $jobs[0]->processed );
		$this->assertSame( 40, $jobs[1]->processed );
		$this->assertSame( 3, $jobs[1]->user_offset );
		$this->assertSame( 7, $jobs[1]->last_blog_id );
	}

	public function test_remove_drops_that_job_and_keeps_the_rest_in_order(): void {
		$queue = new JobQueue();
		$one   = $this->job();
		$two   = $this->job();
		$three = $this->job();
		$queue->add( $one );
		$queue->add( $two );
		$queue->add( $three );

		$queue->remove( $two->id );

		$this->assertSame( array( $one->to_array(), $three->to_array() ), $this->options[ JobQueue::OPTION_JOBS ] );
	}

	public function test_removing_the_last_job_deletes_the_option(): void {
		$queue = new JobQueue();
		$job   = $this->job();
		$queue->add( $job );

		$queue->remove( $job->id );

		$this->assertArrayNotHasKey( JobQueue::OPTION_JOBS, $this->options );
	}

	public function test_clear_empties_the_queue_its_lock_and_the_cron_event_on_the_main_site(): void {
		$this->options[ JobQueue::OPTION_JOBS ] = array( $this->job()->to_array() );
		$this->options[ JobQueue::OPTION_LOCK ] = time();
		$switches                               = &$this->switches;
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( JobQueue::CRON_HOOK )
			->andReturnUsing(
				static function () use ( &$switches ): int {
					$switches[] = 'clear';
					return 1;
				}
			);

		( new JobQueue() )->clear();

		$this->assertSame( array(), $this->options );
		$this->assertSame( array( 'to:1', 'clear', 'restore' ), $this->switches );
	}

	public function test_unschedule_removes_the_pending_run_on_the_main_site_and_keeps_the_jobs(): void {
		$this->options[ JobQueue::OPTION_JOBS ] = array( $this->job()->to_array() );
		$switches                               = &$this->switches;
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( JobQueue::CRON_HOOK )
			->andReturnUsing(
				static function () use ( &$switches ): int {
					$switches[] = 'clear';
					return 1;
				}
			);

		( new JobQueue() )->unschedule();

		$this->assertArrayHasKey( JobQueue::OPTION_JOBS, $this->options );
		$this->assertSame( array( 'to:1', 'clear', 'restore' ), $this->switches );
	}

	// ---------------------------------------------------------------------
	// The lock
	// ---------------------------------------------------------------------

	public function test_a_free_lock_is_taken_and_stamped_with_the_time(): void {
		$before = time();

		$this->assertTrue( ( new JobQueue() )->acquire_lock() );
		$this->assertGreaterThanOrEqual( $before, $this->options[ JobQueue::OPTION_LOCK ] );
	}

	public function test_a_lock_another_run_holds_is_refused_and_left_alone(): void {
		$held                                   = time() - JobQueue::LOCK_TTL + 60;
		$this->options[ JobQueue::OPTION_LOCK ] = $held;

		$this->assertFalse( ( new JobQueue() )->acquire_lock() );
		$this->assertSame( $held, $this->options[ JobQueue::OPTION_LOCK ] );
	}

	public function test_a_lock_older_than_its_ttl_is_taken_over(): void {
		$this->options[ JobQueue::OPTION_LOCK ] = time() - JobQueue::LOCK_TTL - 1;
		$before                                 = time();

		$this->assertTrue( ( new JobQueue() )->acquire_lock() );
		$this->assertGreaterThanOrEqual( $before, $this->options[ JobQueue::OPTION_LOCK ] );
	}

	public function test_the_second_of_two_runs_does_not_get_the_lock(): void {
		$queue = new JobQueue();

		$this->assertTrue( $queue->acquire_lock() );
		$this->assertFalse( $queue->acquire_lock() );
	}

	public function test_a_held_lock_goes_stale_its_ttl_after_it_was_taken(): void {
		$this->options[ JobQueue::OPTION_LOCK ] = 1700000000;

		$this->assertSame( 1700000000 + JobQueue::LOCK_TTL, ( new JobQueue() )->lock_stale_at() );
	}

	public function test_a_lock_released_meanwhile_is_stale_now(): void {
		$before = time();
		$stale  = ( new JobQueue() )->lock_stale_at();

		$this->assertGreaterThanOrEqual( $before, $stale );
		$this->assertLessThanOrEqual( time(), $stale );
	}

	public function test_a_released_lock_can_be_taken_again(): void {
		$queue = new JobQueue();
		$queue->acquire_lock();

		$queue->release_lock();

		$this->assertArrayNotHasKey( JobQueue::OPTION_LOCK, $this->options );
		$this->assertTrue( $queue->acquire_lock() );
	}

	// ---------------------------------------------------------------------
	// The cron event
	// ---------------------------------------------------------------------

	public function test_schedule_adds_a_run_on_the_main_site_when_none_is_pending(): void {
		Functions\expect( 'wp_next_scheduled' )->once()->with( JobQueue::CRON_HOOK )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), JobQueue::CRON_HOOK );

		( new JobQueue() )->schedule();

		$this->assertSame( array( 'to:1', 'restore' ), $this->switches );
	}

	public function test_schedule_does_not_add_a_second_run_when_one_is_due(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() - 5 );
		Functions\expect( 'wp_unschedule_event' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();

		( new JobQueue() )->schedule();

		$this->assertSame( array( 'to:1', 'restore' ), $this->switches );
	}

	public function test_schedule_brings_a_later_retry_forward(): void {
		$later = time() + 300;
		Functions\when( 'wp_next_scheduled' )->justReturn( $later );
		Functions\expect( 'wp_unschedule_event' )->once()->with( $later, JobQueue::CRON_HOOK );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::on( static fn ( $at ) => $at <= time() ), JobQueue::CRON_HOOK );

		( new JobQueue() )->schedule();

		$this->assertSame( array( 'to:1', 'restore' ), $this->switches );
	}

	public function test_schedule_at_adds_a_run_at_that_time_when_none_is_pending(): void {
		Functions\expect( 'wp_next_scheduled' )->once()->with( JobQueue::CRON_HOOK )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( 1700000600, JobQueue::CRON_HOOK );

		( new JobQueue() )->schedule_at( 1700000600 );

		$this->assertSame( array( 'to:1', 'restore' ), $this->switches );
	}

	public function test_schedule_at_leaves_a_pending_run_alone(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() );
		Functions\expect( 'wp_schedule_single_event' )->never();

		( new JobQueue() )->schedule_at( time() + 600 );

		$this->assertSame( array( 'to:1', 'restore' ), $this->switches );
	}

	public function test_is_scheduled_reads_the_main_sites_cron(): void {
		Functions\expect( 'wp_next_scheduled' )->twice()->with( JobQueue::CRON_HOOK )->andReturn( 1700000000, false );
		$queue = new JobQueue();

		$this->assertTrue( $queue->is_scheduled() );
		$this->assertFalse( $queue->is_scheduled() );
		$this->assertSame( array( 'to:1', 'restore', 'to:1', 'restore' ), $this->switches );
	}

	public function test_the_main_site_is_restored_even_when_the_cron_call_fails(): void {
		Functions\when( 'wp_next_scheduled' )->alias(
			static function (): void {
				throw new RuntimeException( 'cron failed' );
			}
		);

		try {
			( new JobQueue() )->schedule();
			$this->fail( 'The failure must reach the caller.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'cron failed', $e->getMessage() );
		}

		$this->assertSame( array( 'to:1', 'restore' ), $this->switches );
	}
}
