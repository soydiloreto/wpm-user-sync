<?php
/**
 * Plugin Name: WPMUS end-to-end knobs
 * Description: Test-only. Lets the end-to-end suite turn the plugin's public filters from a network option, so a browser test can make a sync big enough to queue, exclude a site or allow the administrator role to be copied. Its folder is mapped as mu-plugins by .wp-env.json and the Plugin Check environment; never shipped (tests/ is in .distignore).
 *
 * @package WPMUS\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

/**
 * The knobs the suite set, or an empty array. Read on every call so a change
 * made by WP-CLI between two requests takes effect on the next one.
 *
 * @return array<string, mixed>
 */
function wpmus_e2e_knobs(): array {
	$knobs = get_site_option( 'wpmus_e2e_knobs', array() );

	return is_array( $knobs ) ? $knobs : array();
}

add_filter(
	'wpmus_sync_inline_limit',
	static function ( $limit ) {
		$knobs = wpmus_e2e_knobs();

		return isset( $knobs['inline_limit'] ) ? (int) $knobs['inline_limit'] : $limit;
	}
);

add_filter(
	'wpmus_sync_batch_size',
	static function ( $size ) {
		$knobs = wpmus_e2e_knobs();

		return isset( $knobs['batch_size'] ) ? (int) $knobs['batch_size'] : $size;
	}
);

add_filter(
	'wpmus_sync_time_limit',
	static function ( $seconds ) {
		$knobs = wpmus_e2e_knobs();

		return isset( $knobs['time_limit'] ) ? (int) $knobs['time_limit'] : $seconds;
	}
);

add_filter(
	'wpmus_excluded_site_ids',
	static function ( $ids ) {
		$knobs = wpmus_e2e_knobs();

		return isset( $knobs['excluded_site_ids'] ) ? array_merge( (array) $ids, array_map( 'intval', (array) $knobs['excluded_site_ids'] ) ) : $ids;
	}
);

add_filter(
	'wpmus_replicate_role',
	static function ( $replicate, $role ) {
		$knobs = wpmus_e2e_knobs();

		return 'administrator' === $role && ! empty( $knobs['replicate_administrator'] ) ? true : $replicate;
	},
	10,
	2
);

/*
 * Pauses the first background batch after the knob is set, and marks it in
 * the network option `wpmus_e2e_batch_paused`, so a test can change the queue
 * from another request while a run works on it. The batch goes on when the
 * test sets `wpmus_e2e_batch_release`, or after the knob's seconds at most.
 * The release is read from the database: this request's option cache would
 * never see another request set it.
 */
add_filter(
	'wpmus_sync_batch_size',
	static function ( $size ) {
		$knobs = wpmus_e2e_knobs();
		if ( ! empty( $knobs['pause_first_batch'] ) && add_site_option( 'wpmus_e2e_batch_paused', time() ) ) {
			global $wpdb;
			$until = time() + (int) $knobs['pause_first_batch'];
			while ( time() < $until && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->sitemeta} WHERE meta_key = %s", 'wpmus_e2e_batch_release' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				usleep( 200000 );
			}
		}

		return $size;
	},
	20
);

/*
 * Holds WP-Cron for web requests, so a test can look at a queued sync before
 * anything works on it; WP-CLI's `wp cron event run` still runs the event,
 * which is how the test moves it on, one run at a time.
 */
add_filter(
	'pre_get_ready_cron_jobs',
	static function ( $pre ) {
		$knobs = wpmus_e2e_knobs();

		return ! empty( $knobs['hold_cron'] ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ? array() : $pre;
	}
);
