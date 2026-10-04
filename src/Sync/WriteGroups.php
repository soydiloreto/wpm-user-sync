<?php
/**
 * Groups a background run's database writes into transactions of about
 * a second.
 *
 * Core's `add_user_to_blog()` makes several writes per membership, and
 * the database commits each one on its own, waiting for the disk every
 * time; that wait, not the work, is most of what a big sync costs. In a
 * transaction the writes of a whole second are committed together.
 *
 * A COMMIT that succeeds does not prove a group survived: a deadlock
 * rolls a transaction back and the statements after it commit on their
 * own, and a connection WordPress reopens has lost its transaction. So
 * the engine checks, in the database itself, that every membership a
 * group wrote is there before it stores the batch's progress.
 *
 * Only the queue's WP-Cron run groups its writes: that request is the
 * plugin's own, so no transaction of anyone else's can be open in it (a
 * sync that runs inside another request, a new user's or a new site's,
 * writes one by one as before). A group is short, so other requests are
 * never kept waiting long. When a run dies inside one, the database
 * drops that group's memberships and the next run adds them again (the
 * cursor is stored after the batch, so it never moves past them):
 * nothing is lost or doubled, but whatever other plugins did on
 * `add_user_to_blog` for that group happens twice. The
 * `wpmus_sync_group_writes` filter turns grouping off for a network
 * where that matters.
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
class WriteGroups {

	/**
	 * Seconds of writes one group holds before it is committed.
	 */
	public const SECONDS = 1.0;

	private bool $open = false;

	private bool $lost = false;

	private float $since = 0.0;

	/**
	 * Opens a group, unless grouping is filtered off or the database does
	 * not allow it. True when a group is open.
	 */
	public function begin(): bool {
		$this->lost = false;
		if ( $this->open ) {
			return true;
		}
		/**
		 * Filters whether a background run groups its writes into
		 * transactions of about a second. Off, every write commits on
		 * its own, as core does by itself: slower, and no group to redo
		 * if a run dies.
		 *
		 * @param bool $group Default true.
		 */
		if ( ! apply_filters( 'wpmus_sync_group_writes', true ) || ! $this->database_allows_it() ) {
			return false;
		}
		$this->open  = $this->start();
		$this->since = microtime( true );
		return $this->open;
	}

	/**
	 * Commits the open group once it holds a second of writes, and opens
	 * the next. A commit that fails ends grouping for the batch: the
	 * writes that follow commit one by one, and {@see WriteGroups::end()}
	 * reports the group lost. True when a group was committed just now.
	 */
	public function checkpoint(): bool {
		if ( ! $this->open || microtime( true ) - $this->since < self::SECONDS ) {
			return false;
		}
		if ( ! $this->commit() ) {
			$this->open = false;
			return false;
		}
		$this->open  = $this->start();
		$this->since = microtime( true );
		return true;
	}

	/**
	 * Commits the open group, if any. False when a group of this batch
	 * did not commit: its memberships are not stored, and the caller
	 * must not move the job's cursor past them.
	 */
	public function end(): bool {
		if ( $this->open ) {
			$this->open = false;
			$this->commit();
		}
		return ! $this->lost;
	}

	/**
	 * Commits the open group. When the database refuses, the group is
	 * rolled back on purpose, so no transaction is left open to swallow
	 * the writes that follow (the queue's lock, its next run), and the
	 * group is recorded lost.
	 */
	private function commit(): bool {
		if ( $this->query( 'COMMIT' ) ) {
			return true;
		}
		$this->query( 'ROLLBACK' );
		$this->lost = true;
		return false;
	}

	/**
	 * False when the database logs statements for replication
	 * (`binlog_format` STATEMENT): there, a transaction at READ COMMITTED
	 * refuses InnoDB writes, so grouping would lose them. A database that
	 * does not answer counts as one that does not allow it.
	 */
	private function database_allows_it(): bool {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		$format     = $wpdb->get_var( 'SELECT @@SESSION.binlog_format' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppressed );
		return is_string( $format ) && '' !== $format && 'STATEMENT' !== strtoupper( $format );
	}

	/**
	 * Starts a transaction that reads what is committed now, as a write
	 * outside any transaction would: the default level reads a snapshot
	 * taken at its first read, and for up to a second the sync would not
	 * see a membership another request had just added, and add it twice.
	 */
	private function start(): bool {
		return $this->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ) && $this->query( 'START TRANSACTION' );
	}

	/**
	 * Sends one of the fixed transaction statements. True when the
	 * database took it.
	 */
	private function query( string $statement ): bool {
		global $wpdb;
		return false !== $wpdb->query( $statement ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- a fixed statement, no input.
	}
}
