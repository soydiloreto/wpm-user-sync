import { php, wp } from './cli';
import { DEV_URL } from './env';

/**
 * The network the suite builds its cases on, through WP-CLI.
 *
 * Everything the suite makes is named `e2e-…`, so the teardown (and the setup,
 * after an interrupted run) can find it and nothing else: the dev network is
 * somebody's, and whatever else is on it is left alone.
 */

export const PREFIX = 'e2e-';

/** The three toggles, under the option names the plugin stores. */
export const TOGGLES = {
	newSite: 'wpmus_newSiteSync',
	newUser: 'wpmus_newUserSync',
	role: 'wpmus_setUserRoleSync',
} as const;

export type Toggle = keyof typeof TOGGLES;

/** The knobs tests/e2e/mu-plugin/wpmus-e2e.php reads. */
export interface Knobs {
	inline_limit?: number;
	batch_size?: number;
	time_limit?: number;
	excluded_site_ids?: number[];
	replicate_administrator?: boolean;
	/** Web requests leave WP-Cron alone; only WP-CLI runs the queue. */
	hold_cron?: boolean;
	/** The first background batch waits, marked by batchPaused(), until releaseBatch() or this many seconds. */
	pause_first_batch?: number;
}

let counter = 0;

/** A name nobody else on the network has: the run, then a counter. */
export function unique(hint: string): string {
	counter += 1;

	return `${PREFIX}${hint}-${Date.now().toString(36)}${counter}`.toLowerCase();
}

/**
 * A login Network Admin's "Add New User" accepts: lowercase letters and
 * numbers only, so the suite's accounts made there start with `e2ex`.
 */
export function uniqueLogin(hint: string): string {
	return unique(hint).replace(/^e2e-/, 'e2ex').replace(/[^a-z0-9]/g, '');
}

/** PHP string literal. */
const q = (value: string): string => `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

export interface Site {
	id: number;
	slug: string;
	/** The site's home, e.g. http://localhost:8898/e2e-alpha-x1/ */
	url: string;
	/** Its dashboard. */
	admin: string;
}

export interface SiteOptions {
	/** The role new members get (the site's default_role option). */
	defaultRole?: string;
	archived?: boolean;
	spam?: boolean;
}

/**
 * A site of the network, made the way WordPress makes one, with its default
 * role and its status set. Triggers fire as they would; a spec that does not
 * want them turns them off first.
 */
export function createSite(hint: string, options: SiteOptions = {}): Site {
	const slug = unique(hint);
	const id = php<number>(`
		$network = get_network();
		$id = wpmu_create_blog( $network->domain, '/${slug}/', ${q(slug)}, 1 );
		if ( is_wp_error( $id ) ) { return 0; }
		${options.defaultRole ? `update_blog_option( $id, 'default_role', ${q(options.defaultRole)} );` : ''}
		${options.archived ? `update_blog_status( $id, 'archived', 1 );` : ''}
		${options.spam ? `update_blog_status( $id, 'spam', 1 );` : ''}
		return (int) $id;
	`);

	if (!id) {
		throw new Error(`could not create the site ${slug}`);
	}

	return { id, slug, url: `${DEV_URL}/${slug}/`, admin: `${DEV_URL}/${slug}/wp-admin/` };
}

export interface User {
	id: number;
	login: string;
	email: string;
	password: string;
}

/**
 * An account of the network with no site at all, the way Network Admin's
 * "Add New User" leaves one, plus the memberships asked for.
 */
export function createUser(hint: string, memberships: Record<number, string> = {}, superAdmin = false): User {
	const login = unique(hint);
	const email = `${login}@example.com`;
	const password = `pw-${login}`;
	const adds = Object.entries(memberships)
		.map(([blog, role]) => `add_user_to_blog( ${Number(blog)}, $id, ${q(role)} );`)
		.join('\n');
	const id = php<number>(`
		$id = wpmu_create_user( ${q(login)}, ${q(password)}, ${q(email)} );
		if ( ! $id ) { return 0; }
		${adds}
		${superAdmin ? 'grant_super_admin( $id );' : ''}
		return (int) $id;
	`);

	if (!id) {
		throw new Error(`could not create the user ${login}`);
	}

	return { id, login, email, password };
}

/** The user's role on every site they belong to, by site id. */
export function memberships(userId: number): Record<number, string> {
	return php<Record<number, string>>(`
		$out = array();
		foreach ( get_blogs_of_user( ${userId}, true ) as $blog ) {
			switch_to_blog( $blog->userblog_id );
			$user = get_userdata( ${userId} );
			$out[ $blog->userblog_id ] = $user && $user->roles ? implode( ',', $user->roles ) : '';
			restore_current_blog();
		}
		return (object) $out;
	`);
}

/** The ids of the members of a site. */
export function membersOf(blogId: number): number[] {
	return php<number[]>(`return array_map( 'intval', get_users( array( 'blog_id' => ${blogId}, 'fields' => 'ID' ) ) );`);
}

export function setToggles(values: Partial<Record<Toggle, boolean>>): void {
	const lines = Object.entries(values)
		.map(([key, on]) => `update_site_option( '${TOGGLES[key as Toggle]}', ${on ? "'yes'" : "''"} );`)
		.join('\n');

	php(`${lines} return true;`);
}

export function toggles(): Record<Toggle, boolean> {
	return php<Record<Toggle, boolean>>(`
		return array(
			'newSite' => 'yes' === get_site_option( 'wpmus_newSiteSync' ),
			'newUser' => 'yes' === get_site_option( 'wpmus_newUserSync' ),
			'role'    => 'yes' === get_site_option( 'wpmus_setUserRoleSync' ),
		);
	`);
}

export function setKnobs(knobs: Knobs): void {
	php(`update_site_option( 'wpmus_e2e_knobs', json_decode( ${q(JSON.stringify(knobs))}, true ) ); return true;`);
}

export function clearKnobs(): void {
	php(`delete_site_option( 'wpmus_e2e_knobs' ); delete_site_option( 'wpmus_e2e_batch_paused' ); delete_site_option( 'wpmus_e2e_batch_release' ); return true;`);
}

/** Lets the batch paused by pause_first_batch go on. */
export function releaseBatch(): void {
	php(`update_site_option( 'wpmus_e2e_batch_release', time() ); return true;`);
}

/** Whether a background batch has started its pause_first_batch wait. */
export function batchPaused(): boolean {
	return php<boolean>(`return false !== get_site_option( 'wpmus_e2e_batch_paused' );`);
}

export interface QueuedJob {
	context: string;
	processed: number;
	total: number;
}

/** The syncs waiting in the background, oldest first. */
export function queue(): QueuedJob[] {
	return php<QueuedJob[]>(`
		$jobs = get_site_option( 'wpmus_sync_jobs', array() );
		return array_values( array_map( function ( $job ) {
			return array( 'context' => $job['context'], 'processed' => (int) $job['processed'], 'total' => (int) $job['total'] );
		}, is_array( $jobs ) ? $jobs : array() ) );
	`);
}

/**
 * Empties the queue and its cron event. A spec that queues a sync calls it
 * after each test, so the next one starts from an empty queue whatever the
 * last one left.
 */
export function clearQueue(): void {
	php(`
		delete_site_option( 'wpmus_sync_jobs' );
		delete_site_option( 'wpmus_sync_lock' );
		switch_to_blog( get_main_site_id() );
		wp_clear_scheduled_hook( 'wpmus_process_sync_queue' );
		restore_current_blog();
		return true;
	`);
}

/** Whether the queue's WP-Cron event is scheduled on the main site. */
export function cronScheduled(): boolean {
	return php<boolean>(`
		switch_to_blog( get_main_site_id() );
		$next = wp_next_scheduled( 'wpmus_process_sync_queue' );
		restore_current_blog();
		return false !== $next;
	`);
}

/**
 * Runs the queue's WP-Cron event the way WP-Cron does, through WP-CLI's cron
 * runner on the main site. It only runs when the plugin scheduled it, which is
 * half of what a test of the background path wants to know.
 */
export function runCron(): string {
	return wp(['cron', 'event', 'run', 'wpmus_process_sync_queue']);
}

/** Runs the cron event until the queue is empty, and says how many runs it took. */
export function drainQueue(max = 50): number {
	for (let run = 1; run <= max; run++) {
		runCron();

		if (queue().length === 0) {
			return run;
		}
	}

	throw new Error(`the queue was not empty after ${max} cron runs`);
}

/** The sites the removal record keeps a user off. */
export function removedFrom(userId: number): number[] {
	return php<number[]>(`
		$ids = get_user_meta( ${userId}, 'wpmus_removed_from_blogs', true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	`);
}

/**
 * Deletes every user and site the suite ever made on this network, the
 * queue's jobs included. Everything else stays.
 */
export function sweep(): void {
	php(`
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		foreach ( get_sites( array( 'number' => 0, 'path__not_in' => array( '/' ) ) ) as $site ) {
			if ( 0 === strpos( trim( $site->path, '/' ), '${PREFIX}' ) ) {
				wpmu_delete_blog( (int) $site->blog_id, true );
			}
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->signups} WHERE user_login LIKE %s", 'e2ex%' ) );
		foreach ( get_users( array( 'blog_id' => 0, 'search' => 'e2e*', 'search_columns' => array( 'user_login' ), 'fields' => array( 'ID', 'user_login' ) ) ) as $user ) {
			if ( 0 === strpos( $user->user_login, '${PREFIX}' ) || 0 === strpos( $user->user_login, 'e2ex' ) ) {
				revoke_super_admin( (int) $user->ID );
				wpmu_delete_user( (int) $user->ID );
			}
		}
		return true;
	`);
}
