<?php
/**
 * Unit tests for {@see \WPMUS\Repositories\SiteRepository}.
 *
 * The repository is a thin wrapper over `get_sites()` and
 * `get_blog_option()`. Brain Monkey stubs both so the tests verify
 * that the wrapper passes the right arguments and normalises the
 * return values consistently.
 *
 * @package WPMUS\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use Tests\TestCase;
use WPMUS\Repositories\SiteRepository;

final class SiteRepositoryTest extends TestCase {

	/**
	 * @return array<string,int|bool|string>
	 */
	private function active_query(): array {
		return array(
			'network_id'             => 1,
			'archived'               => 0,
			'spam'                   => 0,
			'deleted'                => 0,
			'number'                 => 0,
			'orderby'                => 'id',
			'order'                  => 'ASC',
			'update_site_meta_cache' => false,
		);
	}

	public function test_all_blog_ids_asks_for_this_networks_active_sites_and_casts_to_int(): void {
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\expect( 'get_sites' )
			->once()
			->with( array( 'fields' => 'ids' ) + $this->active_query() )
			->andReturn( array( '1', 2, '3' ) );

		$ids = ( new SiteRepository() )->all_blog_ids();

		$this->assertSame( array( 1, 2, 3 ), $ids );
	}

	public function test_all_blog_ids_returns_empty_array_when_no_sites(): void {
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'get_sites' )->justReturn( array() );

		$this->assertSame( array(), ( new SiteRepository() )->all_blog_ids() );
	}

	public function test_all_sites_returns_full_objects(): void {
		$sites = array(
			new \WP_Site( 1, 'localhost', '/' ),
			new \WP_Site( 2, 'localhost', '/sitio01/' ),
		);
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\expect( 'get_sites' )
			->once()
			->with( $this->active_query() )
			->andReturn( $sites );

		$result = ( new SiteRepository() )->all_sites();

		$this->assertCount( 2, $result );
		$this->assertSame( $sites, $result );
	}

	/**
	 * Stubs the switch to a site and its role registry: every role
	 * exists except the ones listed.
	 *
	 * @param string[] $missing Roles the site does not define.
	 */
	private function roles_on_site( array $missing = array() ): void {
		Functions\when( 'switch_to_blog' )->justReturn( true );
		Functions\when( 'restore_current_blog' )->justReturn( true );
		Functions\when( 'wp_roles' )->justReturn(
			new class( $missing ) {
				/** @var string[] */
				private array $missing;

				/** @param string[] $missing */
				public function __construct( array $missing ) {
					$this->missing = $missing;
				}

				public function is_role( string $role ): bool {
					return ! in_array( $role, $this->missing, true );
				}
			}
		);
	}

	public function test_default_role_for_blog_returns_option_value(): void {
		$this->roles_on_site();
		Functions\expect( 'get_blog_option' )
			->once()
			->with( 5, 'default_role', 'subscriber' )
			->andReturn( 'editor' );

		$this->assertSame( 'editor', ( new SiteRepository() )->default_role_for_blog( 5 ) );
	}

	public function test_default_role_for_blog_falls_back_when_option_returns_empty_string(): void {
		// Some installs persist `default_role => ''`; treat as unset
		// and use the documented fallback.
		$this->roles_on_site();
		Functions\when( 'get_blog_option' )->justReturn( '' );

		$this->assertSame( 'subscriber', ( new SiteRepository() )->default_role_for_blog( 9 ) );
	}

	public function test_default_role_for_blog_falls_back_when_option_returns_non_string(): void {
		// `get_blog_option` can return false on missing options; the
		// repository must coerce non-strings to the documented default.
		$this->roles_on_site();
		Functions\when( 'get_blog_option' )->justReturn( false );

		$this->assertSame( 'subscriber', ( new SiteRepository() )->default_role_for_blog( 7 ) );
	}

	public function test_default_role_for_blog_falls_back_when_the_site_lacks_that_role(): void {
		$this->roles_on_site( array( 'shop_manager' ) );
		Functions\when( 'get_blog_option' )->justReturn( 'shop_manager' );

		$this->assertSame( 'subscriber', ( new SiteRepository() )->default_role_for_blog( 7 ) );
	}

	public function test_role_exists_on_blog_restores_the_site_it_switched_from(): void {
		Functions\expect( 'switch_to_blog' )->once()->with( 4 )->andReturn( true );
		Functions\expect( 'restore_current_blog' )->once()->andReturn( true );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function is_role( string $role ): bool {
					return 'editor' === $role;
				}
			}
		);

		$this->assertTrue( ( new SiteRepository() )->role_exists_on_blog( 4, 'editor' ) );
	}

	public function test_current_blog_id_is_the_site_of_the_request(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 6 );

		$this->assertSame( 6, ( new SiteRepository() )->current_blog_id() );
	}
}
