<?php
/**
 * Minimal WordPress class stubs for the unit-test suite.
 *
 * The plugin's production code declares return types of `\WP_User` and
 * `\WP_Site` on the repository classes. PHP enforces those types at
 * runtime even when the value is being returned from a Mockery mock,
 * so test data needs to be a real object that satisfies the type.
 *
 * Pulling in the full WordPress runtime to provide one class each is
 * disproportionate; the integration suite (PR 4) does that. For the
 * unit suite, these stubs supply enough surface for the repositories
 * and SyncEngine to compile against. They are NOT representative of
 * the real WordPress classes.
 *
 * @package WPMUS\Tests\Stubs
 */

if ( ! class_exists( 'WP_User', false ) ) {
	/**
	 * Stub of the WordPress core class used by `UserRepository`.
	 *
	 * @property int    $ID
	 * @property array  $roles
	 */
	final class WP_User {

		public int $ID = 0;

		/** @var string[] */
		public array $roles = array();

		public function __construct( int $id = 0, array $roles = array() ) {
			$this->ID    = $id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$this->roles = $roles;
		}
	}
}

if ( ! class_exists( 'WP_Site', false ) ) {
	/**
	 * Stub of the WordPress core class used by `SiteRepository`.
	 *
	 * @property int    $blog_id
	 * @property string $domain
	 * @property string $path
	 */
	final class WP_Site {

		public int $blog_id = 0;
		public string $domain = '';
		public string $path = '';

		public function __construct( int $blog_id = 0, string $domain = '', string $path = '/' ) {
			$this->blog_id = $blog_id;
			$this->domain  = $domain;
			$this->path    = $path;
		}
	}
}

if ( ! class_exists( 'WP_User_Query', false ) ) {
	/**
	 * Stub of the WordPress core class used by
	 * `UserRepository::count_network_users()`. It records the query it
	 * was built with and reports the total a test set.
	 */
	final class WP_User_Query {

		/** @var array<string,mixed>|null The last query built. */
		public static ?array $last_query = null;

		/** The total every query reports. */
		public static int $total = 0;

		/** @param array<string,mixed> $query Query arguments. */
		public function __construct( array $query = array() ) {
			self::$last_query = $query;
		}

		public function get_total(): int {
			return self::$total;
		}
	}
}
