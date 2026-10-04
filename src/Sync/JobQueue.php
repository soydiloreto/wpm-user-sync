<?php
/**
 * The queue of sync jobs too big for one request, and the WP-Cron
 * event that works through it.
 *
 * Jobs live in a network option, so every site of the network sees
 * the same queue. The cron event is scheduled on the main site:
 * WP-Cron stores events per site, and the main site is the one that
 * reliably gets traffic.
 *
 * @package WPMUS\Sync
 */

declare(strict_types=1);

namespace WPMUS\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Note: not declared `final` so Mockery can subclass it for unit
 * tests. The class is still treated as a leaf in production.
 */
class JobQueue {

	public const OPTION_JOBS = 'wpmus_sync_jobs';
	public const OPTION_LOCK = 'wpmus_sync_lock';
	public const CRON_HOOK   = 'wpmus_process_sync_queue';

	/**
	 * A lock older than this is considered abandoned (a run that
	 * died), in seconds.
	 */
	public const LOCK_TTL = 600;

	/**
	 * Every queued job, oldest first.
	 *
	 * @return SyncJob[]
	 */
	public function all(): array {
		$this->forget_cached_jobs();
		$stored = get_site_option( self::OPTION_JOBS, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$jobs = array();
		foreach ( $stored as $data ) {
			$job = SyncJob::from_array( $data );
			if ( null !== $job ) {
				$jobs[] = $job;
			}
		}
		return $jobs;
	}

	/**
	 * The job to work on next, or null when the queue is empty.
	 */
	public function first(): ?SyncJob {
		$jobs = $this->all();
		return array() === $jobs ? null : $jobs[0];
	}

	/**
	 * Appends a job.
	 */
	public function add( SyncJob $job ): void {
		$jobs   = $this->all();
		$jobs[] = $job;
		$this->save( $jobs );
	}

	/**
	 * Stores a job's progress.
	 */
	public function update( SyncJob $job ): void {
		$jobs = $this->all();
		foreach ( $jobs as $i => $stored ) {
			if ( $stored->id === $job->id ) {
				$jobs[ $i ] = $job;
			}
		}
		$this->save( $jobs );
	}

	/**
	 * Drops a job.
	 */
	public function remove( string $id ): void {
		$jobs = array_filter(
			$this->all(),
			static function ( SyncJob $job ) use ( $id ): bool {
				return $job->id !== $id;
			}
		);
		$this->save( array_values( $jobs ) );
	}

	/**
	 * Empties the queue and removes its cron event.
	 */
	public function clear(): void {
		delete_site_option( self::OPTION_JOBS );
		delete_site_option( self::OPTION_LOCK );
		$this->on_main_site(
			static function (): void {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
		);
	}

	/**
	 * Takes the lock that keeps two cron runs from working the same
	 * job. False when another run holds it.
	 */
	public function acquire_lock(): bool {
		$now = time();
		if ( add_site_option( self::OPTION_LOCK, $now ) ) {
			return true;
		}
		$held_since = (int) get_site_option( self::OPTION_LOCK, 0 );
		if ( $now - $held_since < self::LOCK_TTL ) {
			return false;
		}
		update_site_option( self::OPTION_LOCK, $now );
		return true;
	}

	/**
	 * When the lock another run holds counts as abandoned: the moment a
	 * run may take it over. Now, when it was released in the meantime.
	 */
	public function lock_stale_at(): int {
		$held_since = get_site_option( self::OPTION_LOCK, false );
		return false === $held_since ? time() : (int) $held_since + self::LOCK_TTL;
	}

	/**
	 * Releases the lock.
	 */
	public function release_lock(): void {
		delete_site_option( self::OPTION_LOCK );
	}

	/**
	 * Schedules the next run on the main site now. A run pending for
	 * later (a retry) is brought forward.
	 */
	public function schedule(): void {
		$this->on_main_site(
			static function (): void {
				$now  = time();
				$next = wp_next_scheduled( self::CRON_HOOK );
				if ( false !== $next && $next <= $now ) {
					return;
				}
				if ( false !== $next ) {
					wp_unschedule_event( $next, self::CRON_HOOK );
				}
				wp_schedule_single_event( $now, self::CRON_HOOK );
			}
		);
	}

	/**
	 * Schedules a run on the main site at a given time, unless one is
	 * already pending.
	 *
	 * @param int $timestamp When to run (Unix time).
	 */
	public function schedule_at( int $timestamp ): void {
		$this->on_main_site(
			static function () use ( $timestamp ): void {
				if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_single_event( $timestamp, self::CRON_HOOK );
				}
			}
		);
	}

	/**
	 * Removes a pending run from the main site: once the queue is empty,
	 * a retry left for a lock that was held has nothing to do.
	 */
	public function unschedule(): void {
		$this->on_main_site(
			static function (): void {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
		);
	}

	/**
	 * True when a run is scheduled on the main site.
	 */
	public function is_scheduled(): bool {
		$scheduled = false;
		$this->on_main_site(
			static function () use ( &$scheduled ): void {
				$scheduled = false !== wp_next_scheduled( self::CRON_HOOK );
			}
		);
		return $scheduled;
	}

	/**
	 * Drops this request's cached copy of the job list, so the next read
	 * comes from the database. Every request shares the queue: a cron run
	 * working a batch, a new site or user queueing its sync, Network Admin
	 * emptying it. A request that kept its first read would write that old
	 * copy back over whatever the others changed meanwhile, losing a job
	 * queued during a batch or bringing back an emptied one.
	 *
	 * The keys are core's own for a network option (`get_network_option()`:
	 * `{network}:{option}` and `{network}:notoptions` in `site-options`);
	 * the integration tests fail if core ever changes them. The price is one
	 * small query per read: a cron run reads the queue a few times per batch,
	 * the progress table once per page load.
	 */
	private function forget_cached_jobs(): void {
		$network_id = get_current_network_id();
		wp_cache_delete( $network_id . ':' . self::OPTION_JOBS, 'site-options' );
		$missing = wp_cache_get( $network_id . ':notoptions', 'site-options' );
		if ( is_array( $missing ) && isset( $missing[ self::OPTION_JOBS ] ) ) {
			unset( $missing[ self::OPTION_JOBS ] );
			wp_cache_set( $network_id . ':notoptions', $missing, 'site-options' );
		}
	}

	/**
	 * Stores the job list; an empty list deletes the option.
	 *
	 * @param SyncJob[] $jobs Jobs to store.
	 */
	private function save( array $jobs ): void {
		if ( array() === $jobs ) {
			delete_site_option( self::OPTION_JOBS );
			return;
		}
		update_site_option(
			self::OPTION_JOBS,
			array_map(
				static function ( SyncJob $job ): array {
					return $job->to_array();
				},
				$jobs
			)
		);
	}

	/**
	 * Runs `$callback` switched to the network's main site.
	 *
	 * @param callable():void $callback What to run.
	 */
	private function on_main_site( callable $callback ): void {
		switch_to_blog( get_main_site_id() );
		try {
			$callback();
		} finally {
			restore_current_blog();
		}
	}
}
