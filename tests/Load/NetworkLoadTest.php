<?php
/**
 * The plugin on a big network, with its real limits (no filter makes the
 * batches small): thousands of users, tens of sites. It measures the request
 * that starts a sync and every background run, and checks the memberships
 * at the end, none missing and none twice.
 *
 * Not part of `make coverage` or `make pre-pr`: it takes minutes. Run it with
 * `make test-load` (LOAD_USERS and LOAD_SITES set the size).
 *
 * @package WPMUS\Tests\Load
 */

declare(strict_types=1);

namespace Tests\Load;

use Tests\Integration\IntegrationTestCase;
use WPMUS\Config;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Repositories\UserRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;

final class NetworkLoadTest extends IntegrationTestCase {

	/** The request that starts a big sync only counts and queues it. */
	private const START_SECONDS = 5.0;

	/** A run stops taking batches after the time limit; one batch may run over. */
	private const RUN_SLACK_SECONDS = 15.0;

	/** What one run may add to the memory peak, whatever the network's size. */
	private const RUN_MEMORY_MB = 64;

	/** @var int[] */
	private array $load_user_ids = array();

	private function engine(): SyncEngine {
		return new SyncEngine(
			new Config( dirname( __DIR__, 2 ) . '/wpm-user-sync.php' ),
			new SiteRepository(),
			new UserRepository()
		);
	}

	private static function size( string $name, int $fallback ): int {
		$value = (int) getenv( $name );
		return $value > 0 ? $value : $fallback;
	}

	protected function setUp(): void {
		parent::setUp();
		( new JobQueue() )->clear();
	}

	protected function tearDown(): void {
		global $wpdb;
		( new JobQueue() )->clear();
		foreach ( array_chunk( $this->load_user_ids, 1000 ) as $chunk ) {
			$ids = implode( ',', array_map( 'intval', $chunk ) );
			$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id IN ($ids)" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$wpdb->users} WHERE ID IN ($ids)" ); // phpcs:ignore WordPress.DB
		}
		$this->load_user_ids = array();
		parent::tearDown();
	}

	/**
	 * Accounts written straight to the users table, a thousand per query:
	 * an import, not sign-ups, so no trigger runs and the setup takes
	 * seconds.
	 *
	 * @return int[]
	 */
	private function make_users_in_bulk( int $count ): array {
		global $wpdb;
		$run = substr( str_replace( '.', '', (string) microtime( true ) ), -8 );
		for ( $first = 0; $first < $count; $first += 1000 ) {
			$rows = array();
			for ( $i = $first; $i < min( $count, $first + 1000 ); $i++ ) {
				$login  = "load{$run}u{$i}";
				$rows[] = $wpdb->prepare( '(%s, %s, %s, %s, %s, %s)', $login, '*', $login, "{$login}@load.test", '2026-01-01 00:00:00', $login );
			}
			$wpdb->query( "INSERT INTO {$wpdb->users} (user_login, user_pass, user_nicename, user_email, user_registered, display_name) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB
		}
		$ids                 = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s", "load{$run}u%" ) ) );
		$this->load_user_ids = array_merge( $this->load_user_ids, $ids );
		return $ids;
	}

	/**
	 * Members of a site: rows of its capabilities meta, so a membership
	 * written twice would count twice.
	 */
	private function membership_rows( int $blog_id, array $user_ids ): int {
		global $wpdb;
		$key  = $wpdb->get_blog_prefix( $blog_id ) . 'capabilities';
		$rows = 0;
		foreach ( array_chunk( $user_ids, 1000 ) as $chunk ) {
			$ids   = implode( ',', array_map( 'intval', $chunk ) );
			$rows += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ($ids)", $key ) ); // phpcs:ignore WordPress.DB
		}
		return $rows;
	}

	/**
	 * Runs the queue the way WP-Cron does, one run after another, and
	 * measures each.
	 *
	 * @return array{runs:int, seconds:float, slowest:float, memory_mb:float}
	 */
	private function drain( SyncEngine $engine ): array {
		$queue   = new JobQueue();
		$stats   = array(
			'runs'      => 0,
			'seconds'   => 0.0,
			'slowest'   => 0.0,
			'memory_mb' => 0.0,
		);
		$started = microtime( true );
		while ( null !== $queue->first() ) {
			$this->assertLessThan( 10000, $stats['runs'], 'The queue drains.' );
			memory_reset_peak_usage();
			$before = memory_get_usage();
			$run    = microtime( true );
			$engine->process_queue();
			$took = microtime( true ) - $run;
			++$stats['runs'];
			$stats['slowest']   = max( $stats['slowest'], $took );
			$stats['memory_mb'] = max( $stats['memory_mb'], ( memory_get_peak_usage() - $before ) / 1048576 );
			// Each cron run is a request of its own: nothing it cached outlives it.
			wp_cache_flush_runtime();
		}
		$stats['seconds'] = microtime( true ) - $started;
		return $stats;
	}

	private function report( string $what, int $pairs, float $start, array $stats ): void {
		fwrite(
			STDOUT,
			sprintf(
				"\n%s: %d user-site pairs. Start %.2fs; %d background runs in %.1fs (%.0f pairs/s); slowest run %.1fs; most memory one run added %.1f MB.\n",
				$what,
				$pairs,
				$start,
				$stats['runs'],
				$stats['seconds'],
				$pairs / max( 0.001, $stats['seconds'] ),
				$stats['slowest'],
				$stats['memory_mb']
			)
		);
	}

	private function assert_runs_are_bounded( array $stats ): void {
		$this->assertLessThan( SyncEngine::TIME_LIMIT + self::RUN_SLACK_SECONDS, $stats['slowest'], 'A run stops near its time limit.' );
		$this->assertLessThan( self::RUN_MEMORY_MB, $stats['memory_mb'], 'A run stays within its memory, whatever the network size.' );
	}

	public function test_a_manual_sync_of_a_big_network_runs_in_bounded_batches_and_misses_nobody(): void {
		$users = self::size( 'LOAD_USERS', 3000 );
		$sites = self::size( 'LOAD_SITES', 10 );

		$user_ids = $this->make_users_in_bulk( $users );
		$blog_ids = array();
		for ( $i = 0; $i < $sites; $i++ ) {
			$blog_ids[] = $this->make_site( 'load-' . $i . '-' . substr( (string) microtime( true ), -5 ) );
		}
		$engine = $this->engine();

		$run = microtime( true );
		$this->assertFalse( $engine->sync_all_users_to_sites( $blog_ids ), 'A sync this big goes to the background.' );
		$start = microtime( true ) - $run;
		$this->assertLessThan( self::START_SECONDS, $start, 'The request that starts it only counts and queues.' );

		$stats = $this->drain( $engine );
		$this->report( 'Manual sync', $users * $sites, $start, $stats );
		$this->assert_runs_are_bounded( $stats );
		foreach ( $blog_ids as $blog_id ) {
			$this->assertSame( $users, $this->membership_rows( $blog_id, $user_ids ), "Site $blog_id has every user, once." );
		}

		// A second pass finds everyone in place and adds nothing.
		$engine->sync_all_users_to_sites( $blog_ids );
		$this->drain( $engine );
		foreach ( $blog_ids as $blog_id ) {
			$this->assertSame( $users, $this->membership_rows( $blog_id, $user_ids ), "Site $blog_id gains no duplicate." );
		}
	}

	public function test_a_new_site_on_a_big_network_is_filled_in_the_background(): void {
		$users    = self::size( 'LOAD_USERS', 3000 );
		$user_ids = $this->make_users_in_bulk( $users );
		update_site_option( Config::OPTION_NEW_SITE_SYNC, 'yes' );

		$run     = microtime( true );
		$blog_id = $this->make_site( 'load-new-' . substr( (string) microtime( true ), -5 ) );
		$start   = microtime( true ) - $run;
		$this->assertLessThan( self::START_SECONDS, $start, 'Creating the site is not held up by the sync.' );
		$this->assertNotNull( ( new JobQueue() )->first(), 'The new site\'s sync is queued.' );

		$stats = $this->drain( $this->engine() );
		$this->report( 'New site', $users, $start, $stats );
		$this->assert_runs_are_bounded( $stats );
		$this->assertSame( $users, $this->membership_rows( $blog_id, $user_ids ) );
	}
}
