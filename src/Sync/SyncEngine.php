<?php
/**
 * Core synchronisation logic.
 *
 * Three trigger entry points (called from {@see \WPMUS\Plugin} hook
 * registrations) plus three manual entry points (called from network
 * and site admin actions). All of them read the toggle state from
 * {@see \WPMUS\Config} and delegate the multisite-side work to
 * {@see \WPMUS\Repositories\SiteRepository} and
 * {@see \WPMUS\Repositories\UserRepository}.
 *
 * The class deliberately does NOT reach into `$wpdb` or call WP user
 * functions directly — every interaction goes through the repositories,
 * so unit tests can mock them.
 *
 * ## Re-entrancy
 *
 * `add_user_to_blog()` fires WordPress's `set_user_role` action as a
 * side effect of every membership write. With the role-sync trigger
 * enabled, that re-enters {@see SyncEngine::on_role_changed()} and can
 * cascade across the network — propagating a destination site's role
 * back onto every other membership the user already has, recursively.
 *
 * To prevent that without temporarily detaching/re-attaching WP hooks
 * (which interacts badly with priority and unrelated subscribers), the
 * class carries a private `$in_sync` flag. Every method that calls
 * `add_to_blog()` does so via {@see SyncEngine::add_to_blog_guarded()},
 * which sets the flag for the duration of the call. `on_role_changed()`
 * early-returns when the flag is set, breaking the recursion.
 *
 * @package WPMUS\Sync
 */

declare(strict_types=1);

namespace WPMUS\Sync;

use WPMUS\Config;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Repositories\UserRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Multisite user-synchronisation engine. Centralises every membership
 * write triggered by the plugin — both the WP hook callbacks and the
 * manual admin actions go through this class.
 */
final class SyncEngine {

	/**
	 * A job of at most this many user-site pairs runs in the request
	 * that starts it; a bigger one goes to the background queue.
	 * Filter: `wpmus_sync_inline_limit`.
	 */
	public const INLINE_LIMIT = 500;

	/**
	 * User-site pairs per background batch. Filter:
	 * `wpmus_sync_batch_size`.
	 */
	public const BATCH_SIZE = 500;

	/**
	 * Seconds one cron run keeps taking batches. Filter:
	 * `wpmus_sync_time_limit`.
	 */
	public const TIME_LIMIT = 20;

	/**
	 * Seconds before a run retries a batch whose writes the database did
	 * not commit.
	 */
	public const LOST_GROUP_RETRY = 60;

	/**
	 * Most users fetched per query, however large the batch.
	 */
	private const USER_PAGE_MAX = 500;

	private Config $config;
	private SiteRepository $sites;
	private UserRepository $users;
	private JobQueue $queue;
	private WriteGroups $groups;

	/**
	 * Re-entrancy guard. Set to `true` while any sync method is in the
	 * middle of writing a membership, so the `set_user_role` hook fired
	 * as a side effect of `add_user_to_blog()` does not recurse back
	 * into {@see on_role_changed()}.
	 */
	private bool $in_sync = false;

	/**
	 * The memberships the open write group added, by site: user ids.
	 * Null while no group is open.
	 *
	 * @var array<int, int[]>|null
	 */
	private ?array $written = null;

	/**
	 * Users whose memberships the open write group added since its last
	 * commit: core cleans their cache before the commit, and a request
	 * reading them meanwhile can cache what was there before, so they are
	 * cleaned again once the group is committed.
	 *
	 * @var int[]
	 */
	private array $uncommitted = array();

	/**
	 * Default role per blog id, resolved once per sync run: reading it
	 * switches to the site.
	 *
	 * @var array<int,string>
	 */
	private array $default_roles = array();

	/**
	 * Super admin user ids, as keys, loaded once per run. Super admins
	 * reach every site already and are never added as members.
	 *
	 * @var array<int,bool>|null
	 */
	private ?array $super_admins = null;

	/**
	 * Users registered in this request whose new-user sync is waiting
	 * for `wpmu_new_user` or shutdown, as keys.
	 *
	 * @var array<int,bool>
	 */
	private array $registered = array();

	/**
	 * Users the new-user sync already ran for in this request, as keys.
	 *
	 * @var array<int,bool>
	 */
	private array $synced_new_users = array();

	/**
	 * @param Config           $config Toggle accessors + plugin metadata.
	 * @param SiteRepository   $sites  Wraps `get_sites()` / `get_blog_option()`.
	 * @param UserRepository   $users  Wraps `get_users()` / `add_user_to_blog()`
	 *                                 / `is_user_member_of_blog()`.
	 * @param JobQueue|null    $queue  Background jobs; a fresh one when null.
	 * @param WriteGroups|null $groups Transactions around a background
	 *                                 batch's writes; a fresh one when null.
	 */
	public function __construct( Config $config, SiteRepository $sites, UserRepository $users, ?JobQueue $queue = null, ?WriteGroups $groups = null ) {
		$this->config = $config;
		$this->sites  = $sites;
		$this->users  = $users;
		$this->queue  = $queue ?? new JobQueue();
		$this->groups = $groups ?? new WriteGroups();
	}

	/**
	 * Trigger callback for `wp_initialize_site` — populates a
	 * freshly-created site with every existing network user. No-op when
	 * the `New Site Sync` toggle is off.
	 *
	 * Every new membership gets the new site's own default role. The
	 * role a user holds on the site the request runs on is never
	 * copied: doing so made every editor or administrator of the main
	 * site an editor or administrator of each new site.
	 *
	 * @param \WP_Site|int $site The new site (`wp_initialize_site`), or
	 *                           its id (the deprecated wrapper).
	 */
	public function on_new_site( $site ): void {
		if ( ! $this->config->is_new_site_sync_enabled() ) {
			return;
		}
		$blog_id = $site instanceof \WP_Site ? (int) $site->blog_id : (int) $site;
		if ( $blog_id <= 0 ) {
			return;
		}

		$this->start( new SyncJob( 'new_site', null, array( $blog_id ), false ) );
	}

	/**
	 * Trigger callback for `wpmu_new_user` — adds a brand-new user to
	 * every active site with each site's default role. No-op when the
	 * `New User Sync` toggle is off, and runs once per user.
	 */
	public function on_new_user( int $user_id ): void {
		unset( $this->registered[ $user_id ] );
		if ( ! $this->config->is_new_user_sync_enabled() ) {
			return;
		}
		if ( $user_id <= 0 || isset( $this->synced_new_users[ $user_id ] ) ) {
			return;
		}
		$this->synced_new_users[ $user_id ] = true;
		$this->start( new SyncJob( 'new_user', array( $user_id ), null, false ) );
	}

	/**
	 * Callback for `user_register`. Inside wpmu_create_user() it fires
	 * before core strips the account's default membership, so the work
	 * waits: `wpmu_new_user` runs it right after, and accounts made
	 * with wp_insert_user() alone, where `wpmu_new_user` never fires,
	 * are handled by {@see flush_registered_users()} at shutdown.
	 */
	public function on_user_registered( int $user_id ): void {
		if ( $user_id <= 0 || ! $this->config->is_new_user_sync_enabled() ) {
			return;
		}
		$this->registered[ $user_id ] = true;
	}

	/**
	 * Callback for `shutdown`: runs the new-user sync for accounts
	 * registered in this request that `wpmu_new_user` did not cover.
	 */
	public function flush_registered_users(): void {
		foreach ( array_keys( $this->registered ) as $user_id ) {
			$this->on_new_user( $user_id );
		}
	}

	/**
	 * Callback for `remove_user_from_blog`. Records the removal so no
	 * automatic sync adds the user back to that site; runs whatever
	 * the toggles say, so a trigger turned on later still respects it.
	 *
	 * Removals core makes as housekeeping are not someone's decision
	 * and are not recorded: emptying a site that is being deleted
	 * (`wp_uninitialize_site`), and taking a newly activated invitee
	 * off the main site (`wpmu_activate_user`).
	 */
	public function on_user_removed_from_blog( int $user_id, int $blog_id ): void {
		if ( $user_id <= 0 || $blog_id <= 0 ) {
			return;
		}
		if ( doing_action( 'wp_uninitialize_site' ) || doing_action( 'wpmu_activate_user' ) ) {
			return;
		}
		$this->users->record_removal( $user_id, $blog_id );
	}

	/**
	 * Callback for `wpmu_activate_user`, after core's own handler: an
	 * invitee activated from a signup was just taken off the main site
	 * and put on the inviting site, so the new-user sync runs again for
	 * them (it only adds what is missing).
	 */
	public function on_user_activated( int $user_id ): void {
		if ( $user_id <= 0 || ! $this->config->is_new_user_sync_enabled() ) {
			return;
		}
		$this->start( new SyncJob( 'new_user', array( $user_id ), null, false ) );
	}

	/**
	 * Callback for `add_user_to_blog`. When someone other than this
	 * plugin adds the user to a site (an administrator, another
	 * plugin), a removal recorded for that site no longer applies.
	 */
	public function on_user_added_to_blog( int $user_id, string $role, int $blog_id ): void {
		if ( $this->in_sync ) {
			return;
		}
		$this->users->forget_removal( $user_id, $blog_id );
	}

	/**
	 * Trigger callback for `set_user_role`. When a user's role is
	 * changed on one site, copy it to the other sites where the user is
	 * already a member. New memberships are NOT created here.
	 *
	 * The role is copied only to a site that defines it, never when it
	 * is `administrator` unless the `wpmus_replicate_role` filter says
	 * so, never for a super admin, and not when nothing changed (the
	 * role is empty, or equals the only role the user had).
	 *
	 * Returns immediately when {@see $in_sync} is set: the current
	 * `add_user_to_blog` call is part of another sync method's loop,
	 * not a real role change driven by an admin.
	 *
	 * @param int      $user_id   The user whose role changed.
	 * @param string   $role      The new role on the current site.
	 * @param string[] $old_roles The roles the user had there before.
	 */
	public function on_role_changed( int $user_id, string $role, array $old_roles = array() ): void {
		if ( $this->in_sync ) {
			return;
		}
		if ( ! $this->config->is_set_user_role_sync_enabled() ) {
			return;
		}
		if ( '' === $role || array( $role ) === array_values( $old_roles ) ) {
			return;
		}
		if ( in_array( $user_id, $this->users->super_admin_ids(), true ) ) {
			return;
		}

		/**
		 * Filters whether a role change on one site is copied to the
		 * user's other sites. `administrator` is not copied by default.
		 *
		 * @param bool   $replicate True to copy the role.
		 * @param string $role      The new role.
		 * @param int    $user_id   The user.
		 */
		if ( ! apply_filters( 'wpmus_replicate_role', 'administrator' !== $role, $role, $user_id ) ) {
			return;
		}

		$this->begin_run();
		$source = $this->sites->current_blog_id();
		foreach ( $this->target_blog_ids( $this->users->blog_ids_of_user( $user_id ), 'role_change' ) as $blog_id ) {
			if ( $blog_id === $source || ! $this->sites->role_exists_on_blog( $blog_id, $role ) ) {
				continue;
			}
			$this->add_to_blog_guarded( $blog_id, $user_id, $role );
		}
	}

	/**
	 * Manual action: sync every network user to every site. Existing
	 * memberships are not modified — only missing memberships are
	 * created with the destination site's default role. A user removed
	 * from a site is only added back when `$force` is true.
	 *
	 * @return bool True when the sync finished in this request, false
	 *              when it was queued to run in the background.
	 */
	public function sync_all_users_to_all_sites( bool $force = false ): bool {
		return $this->start( new SyncJob( 'manual', null, null, $force ) );
	}

	/**
	 * Manual action: sync every network user to a subset of sites
	 * (typically chosen via the network-admin UI checkboxes).
	 *
	 * @param int[] $blog_ids Sites to populate. Anything outside this
	 *                        list, or not active, is left untouched.
	 * @param bool  $force    Also add back users who were removed.
	 * @return bool True when the sync finished in this request, false
	 *              when it was queued to run in the background.
	 */
	public function sync_all_users_to_sites( array $blog_ids, bool $force = false ): bool {
		return $this->start( new SyncJob( 'manual', null, $blog_ids, $force ) );
	}

	/**
	 * WP-Cron callback (`wpmus_process_sync_queue`): works through the
	 * queued jobs, one batch at a time, until the queue is empty or the
	 * run's time is up, and schedules another run while jobs remain.
	 * Each batch stores its progress, so a run that dies loses at most
	 * one batch, which the next run redoes harmlessly. A batch's writes
	 * are committed in groups of about a second ({@see WriteGroups}); a
	 * batch whose group did not commit ends the run without storing its
	 * progress, and a run a minute later redoes it.
	 */
	public function process_queue(): void {
		if ( ! $this->queue->acquire_lock() ) {
			// Another run holds the queue. Should it die, no run would be
			// left to take the lock over: come back when the lock goes
			// stale. A run that ends first brings the next one forward.
			$this->queue->schedule_at( $this->queue->lock_stale_at() );
			return;
		}
		/**
		 * Filters how many seconds one cron run keeps taking batches.
		 * With 0, a run takes a single batch.
		 *
		 * @param int $seconds Default {@see SyncEngine::TIME_LIMIT}.
		 */
		$deadline = microtime( true ) + (float) apply_filters( 'wpmus_sync_time_limit', self::TIME_LIMIT );
		$lost     = false;
		try {
			do {
				$job = $this->queue->first();
				if ( null === $job ) {
					break;
				}
				$size          = $this->batch_size();
				$this->written = $this->groups->begin() ? array() : null;
				$ended         = false;
				$written       = null;
				try {
					$this->run_batch( $job, $size );
				} finally {
					$ended = $this->groups->end();
					$this->forget_uncommitted();
					$written = $this->written;
					// Whatever happened, nothing later in the request is
					// part of this group.
					$this->written = null;
				}
				// Checked once the batch returned, so a failing check never
				// hides the batch's own exception.
				if ( ! $ended || ! $this->is_stored( $written ) ) {
					// Not all the batch's memberships are stored: the
					// stored cursor stays before them, their users are
					// read afresh, and a later run redoes the batch.
					if ( ! empty( $written ) ) {
						$this->users->forget_cached( array_merge( ...array_values( $written ) ) );
					}
					$lost = true;
					break;
				}
				if ( $job->done ) {
					$this->queue->remove( $job->id );
				} else {
					$this->queue->update( $job );
				}
			} while ( microtime( true ) < $deadline );
		} finally {
			$this->queue->release_lock();
		}
		if ( $lost ) {
			// Not at once: a database refusing commits gets a minute.
			$this->queue->schedule_at( time() + self::LOST_GROUP_RETRY );
		} elseif ( null !== $this->queue->first() ) {
			$this->queue->schedule();
		} else {
			$this->queue->unschedule();
			if ( null !== $this->queue->first() ) {
				// A job queued in the meantime keeps its run.
				$this->queue->schedule();
			}
		}
	}

	/**
	 * Runs a job now when it is small, or queues it for WP-Cron.
	 *
	 * @return bool True when it finished now, false when queued.
	 */
	private function start( SyncJob $job ): bool {
		$this->begin_run();
		$sites = $this->target_blog_ids( $job->blog_ids, $job->context );
		$users = null === $job->user_ids ? $this->users->count_network_users() : count( $job->user_ids );

		$job->total = count( $sites ) * $users;
		if ( 0 === $job->total ) {
			return true;
		}

		/**
		 * Filters the largest job, in user-site pairs, that runs in the
		 * request that starts it. Bigger jobs run in the background.
		 *
		 * @param int    $limit   Default {@see SyncEngine::INLINE_LIMIT}.
		 * @param string $context `new_site`, `new_user` or `manual`.
		 */
		$limit = (int) apply_filters( 'wpmus_sync_inline_limit', self::INLINE_LIMIT, $job->context );
		if ( $job->total <= $limit ) {
			$this->run_batch( $job, PHP_INT_MAX, $sites );
			return true;
		}

		$this->queue->add( $job );
		$this->queue->schedule();
		return false;
	}

	/**
	 * Processes up to `$budget` user-site pairs of a job, moving its
	 * cursor, and sets `$job->done` once nothing is left. Users are read
	 * a page at a time (ids only); sites are walked in id order so the
	 * cursor survives sites created in the meantime. In a background
	 * run its writes are committed in groups of about a second
	 * ({@see WriteGroups}); elsewhere no group is open and each write
	 * commits on its own.
	 *
	 * @param SyncJob    $job    The job; its cursor and counters move.
	 * @param int        $budget Most pairs to process.
	 * @param int[]|null $sites  Target sites when the caller just
	 *                           resolved them in this run.
	 */
	private function run_batch( SyncJob $job, int $budget, ?array $sites = null ): void {
		if ( null === $sites ) {
			$this->begin_run();
			$sites = $this->target_blog_ids( $job->blog_ids, $job->context );
		}
		$count = count( $sites );
		if ( 0 === $count ) {
			$job->done = true;
			return;
		}
		$page_size = $budget >= self::USER_PAGE_MAX * $count
			? self::USER_PAGE_MAX
			: max( 1, intdiv( $budget + $count - 1, $count ) );

		while ( true ) {
			$user_ids = $this->user_page( $job, $page_size );
			foreach ( $user_ids as $user_id ) {
				foreach ( $sites as $blog_id ) {
					if ( $blog_id <= $job->last_blog_id ) {
						continue;
					}
					if ( $budget <= 0 ) {
						return;
					}
					$this->add_if_missing( $user_id, $blog_id, $job->force, $job->context );
					if ( $this->groups->checkpoint() ) {
						$this->forget_uncommitted();
					}
					$job->last_blog_id = $blog_id;
					++$job->processed;
					--$budget;
				}
				++$job->user_offset;
				$job->last_blog_id = 0;
			}
			if ( count( $user_ids ) < $page_size ) {
				$job->done = true;
				return;
			}
		}
	}

	/**
	 * The next page of the job's users, from its cursor.
	 *
	 * @return int[]
	 */
	private function user_page( SyncJob $job, int $limit ): array {
		if ( null !== $job->user_ids ) {
			return array_slice( $job->user_ids, $job->user_offset, $limit );
		}
		return $this->users->network_user_ids( $job->user_offset, $limit );
	}

	/**
	 * Pairs per background batch, from the `wpmus_sync_batch_size`
	 * filter; at least 1.
	 */
	private function batch_size(): int {
		/**
		 * Filters how many user-site pairs one background batch takes.
		 *
		 * @param int $size Default {@see SyncEngine::BATCH_SIZE}.
		 */
		return max( 1, (int) apply_filters( 'wpmus_sync_batch_size', self::BATCH_SIZE ) );
	}

	/**
	 * Resets what a run caches: default roles and super admins can
	 * change between runs.
	 */
	private function begin_run(): void {
		$this->default_roles = array();
		$this->super_admins  = null;
	}

	/**
	 * The sites a run may write to: the requested ones (or all) that
	 * are active on this network (not archived, spam or deleted),
	 * minus the ones the `wpmus_excluded_site_ids` filter lists.
	 *
	 * @param int[]|null $requested Requested sites, null for all.
	 * @param string     $context   new_site, new_user, manual or role_change.
	 * @return int[]
	 */
	private function target_blog_ids( ?array $requested, string $context ): array {
		$active  = $this->sites->all_blog_ids();
		$targets = null === $requested ? $active : array_values( array_intersect( $requested, $active ) );

		/**
		 * Filters the sites the sync never writes to.
		 *
		 * @param int[]  $excluded Blog ids to leave alone. Default empty.
		 * @param string $context  `new_site`, `new_user`, `manual` or `role_change`.
		 */
		$excluded = apply_filters( 'wpmus_excluded_site_ids', array(), $context );
		if ( is_array( $excluded ) && array() !== $excluded ) {
			$targets = array_values( array_diff( $targets, array_map( 'intval', $excluded ) ) );
		}
		sort( $targets );
		return $targets;
	}

	/**
	 * Adds one user to one site, with that site's default role, unless
	 * they are already a member or were removed from it. `$force`
	 * overrides the removal and clears its record.
	 */
	private function add_if_missing( int $user_id, int $blog_id, bool $force, string $context ): void {
		if ( null === $this->super_admins ) {
			$this->super_admins = array_fill_keys( $this->users->super_admin_ids(), true );
		}
		if ( isset( $this->super_admins[ $user_id ] ) ) {
			return;
		}
		if ( $this->users->is_member_of( $user_id, $blog_id ) ) {
			return;
		}
		$removed = in_array( $blog_id, $this->users->removed_blog_ids( $user_id ), true );
		if ( $removed && ! $force ) {
			return;
		}
		/**
		 * Filters whether the sync adds a user to a site.
		 *
		 * Runs only for a membership the sync is about to create: super
		 * admins, existing members and removed users are already out.
		 *
		 * @param bool   $sync    True to add the user. Default true.
		 * @param int    $user_id The user.
		 * @param int    $blog_id The site.
		 * @param string $context `new_site`, `new_user` or `manual`.
		 */
		if ( ! apply_filters( 'wpmus_should_sync_user', true, $user_id, $blog_id, $context ) ) {
			return;
		}
		if ( ! isset( $this->default_roles[ $blog_id ] ) ) {
			$this->default_roles[ $blog_id ] = $this->sites->default_role_for_blog( $blog_id );
		}
		$this->add_to_blog_guarded( $blog_id, $user_id, $this->default_roles[ $blog_id ] );
		if ( $removed ) {
			$this->users->forget_removal( $user_id, $blog_id );
		}
	}

	/**
	 * Wraps `UserRepository::add_to_blog()` with the {@see $in_sync}
	 * re-entrancy guard. Every membership write inside this class
	 * MUST go through this helper rather than calling the repository
	 * directly, otherwise the `set_user_role` cascade described in
	 * the class-level docblock kicks in.
	 *
	 * The previous flag value is preserved + restored so the helper
	 * is safe under nested calls (extension code is unlikely to nest,
	 * but the bookkeeping costs nothing).
	 */
	private function add_to_blog_guarded( int $blog_id, int $user_id, string $role ): void {
		$previously_in_sync = $this->in_sync;
		$this->in_sync      = true;
		try {
			$added = $this->users->add_to_blog( $blog_id, $user_id, $role );
		} finally {
			$this->in_sync = $previously_in_sync;
		}
		if ( null !== $this->written && true === $added ) {
			$this->written[ $blog_id ][] = $user_id;
			$this->uncommitted[]         = $user_id;
		}
	}

	/**
	 * Cleans the cache of the users the write group added since its last
	 * commit (see {@see SyncEngine::$uncommitted}).
	 */
	private function forget_uncommitted(): void {
		if ( array() !== $this->uncommitted ) {
			$this->users->forget_cached( $this->uncommitted );
			$this->uncommitted = array();
		}
	}

	/**
	 * True when every membership a write group added is in the database:
	 * a COMMIT that succeeded does not prove it (see {@see WriteGroups}).
	 * Always true when no group was open.
	 *
	 * @param array<int, int[]>|null $written The group's memberships, by site.
	 */
	private function is_stored( ?array $written ): bool {
		foreach ( $written ?? array() as $blog_id => $user_ids ) {
			if ( $this->users->stored_member_count( $blog_id, $user_ids ) < count( array_unique( $user_ids ) ) ) {
				return false;
			}
		}
		return true;
	}
}
