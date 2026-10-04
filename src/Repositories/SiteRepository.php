<?php
/**
 * Wraps WordPress multisite-site lookups so the rest of the codebase
 * can talk to a thin, mockable abstraction instead of `$wpdb` and
 * `get_sites()` directly.
 *
 * @package WPMUS\Repositories
 */

declare(strict_types=1);

namespace WPMUS\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Note: not declared `final` so Mockery can subclass it for unit
 * tests. The class is still treated as a leaf in production.
 */
class SiteRepository {

	/**
	 * Every active blog ID in the current network: archived, spam and
	 * deleted sites are left out, and so are the sites of any other
	 * network on the install. Returns int[] regardless of how WP
	 * encodes them internally.
	 *
	 * @return int[]
	 */
	public function all_blog_ids(): array {
		$args           = $this->active_site_query();
		$args['fields'] = 'ids';
		/** @var int[] $sites */
		$sites = get_sites( $args );
		return array_map( 'intval', $sites );
	}

	/**
	 * Active site rows for UI rendering (domain + path + blog_id).
	 *
	 * @return \WP_Site[]
	 */
	public function all_sites(): array {
		$sites = get_sites( $this->active_site_query() );
		/** @var \WP_Site[] $sites */
		return $sites;
	}

	/**
	 * The site the current request runs on.
	 */
	public function current_blog_id(): int {
		return get_current_blog_id();
	}

	/**
	 * `get_sites()` arguments shared by every lookup: this network's
	 * sites that are not archived, spam or deleted.
	 *
	 * @return array{network_id:int,archived:int,spam:int,deleted:int,number:int,orderby:string,order:string,update_site_meta_cache:bool}
	 */
	private function active_site_query(): array {
		return array(
			'network_id'             => get_current_network_id(),
			'archived'               => 0,
			'spam'                   => 0,
			'deleted'                => 0,
			'number'                 => 0,
			'orderby'                => 'id',
			'order'                  => 'ASC',
			'update_site_meta_cache' => false,
		);
	}

	/**
	 * Resolves the default user role configured for `$blog_id`, read
	 * on that site: its `default_role` option when the site has that
	 * role, `subscriber` otherwise (no option, an empty one, or a role
	 * the site does not define).
	 */
	public function default_role_for_blog( int $blog_id ): string {
		$role = get_blog_option( $blog_id, 'default_role', 'subscriber' );
		if ( is_string( $role ) && '' !== $role && $this->role_exists_on_blog( $blog_id, $role ) ) {
			return $role;
		}
		return 'subscriber';
	}

	/**
	 * True when `$role` is defined on `$blog_id`. Roles live in each
	 * site's own options, so the check runs switched to that site.
	 */
	public function role_exists_on_blog( int $blog_id, string $role ): bool {
		switch_to_blog( $blog_id );
		try {
			return wp_roles()->is_role( $role );
		} finally {
			restore_current_blog();
		}
	}
}
