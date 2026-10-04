<?php
/**
 * Unit tests for {@see \WPMUS\Repositories\UserRepository}.
 *
 * Brain Monkey stubs the WP user-management functions used by the
 * repository so we can verify the right arguments are passed and the
 * right return-value coercions are applied.
 *
 * @package WPMUS\Tests\Unit\Repositories
 */

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use Tests\TestCase;
use WPMUS\Repositories\UserRepository;

final class UserRepositoryTest extends TestCase {

	public function test_network_user_ids_reads_one_page_of_ids(): void {
		// `blog_id => 0` asks WordPress for every user across the
		// network; only ids are loaded, oldest first, one page at a time.
		Functions\expect( 'get_users' )
			->once()
			->with(
				array(
					'blog_id'     => 0,
					'fields'      => 'ID',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'number'      => 50,
					'offset'      => 100,
					'count_total' => false,
				)
			)
			->andReturn( array( '7', 9 ) );

		$this->assertSame( array( 7, 9 ), ( new UserRepository() )->network_user_ids( 100, 50 ) );
	}

	public function test_super_admin_ids_resolves_logins(): void {
		Functions\when( 'get_super_admins' )->justReturn( array( 'admin', 'gone' ) );
		Functions\when( 'get_user_by' )->alias(
			static function ( string $field, string $login ) {
				return 'admin' === $login ? new \WP_User( 1 ) : false;
			}
		);

		$this->assertSame( array( 1 ), ( new UserRepository() )->super_admin_ids() );
	}

	public function test_is_member_of_returns_bool(): void {
		Functions\expect( 'is_user_member_of_blog' )
			->once()
			->with( 5, 7 )
			->andReturn( true );

		$this->assertTrue( ( new UserRepository() )->is_member_of( 5, 7 ) );
	}

	public function test_is_member_of_coerces_non_bool_truthy_to_true(): void {
		// `is_user_member_of_blog` historically returned 1/0 in some WP
		// versions. The repository's bool cast must normalise that.
		Functions\when( 'is_user_member_of_blog' )->justReturn( 1 );

		$this->assertTrue( ( new UserRepository() )->is_member_of( 5, 7 ) );
	}

	public function test_add_to_blog_passes_args_through(): void {
		Functions\expect( 'add_user_to_blog' )
			->once()
			->with( 7, 5, 'editor' )
			->andReturn( true );

		$result = ( new UserRepository() )->add_to_blog( 7, 5, 'editor' );

		$this->assertTrue( $result );
	}

	public function test_removed_blog_ids_normalises_the_stored_list(): void {
		Functions\expect( 'get_user_meta' )
			->once()
			->with( 5, UserRepository::META_REMOVED_FROM, true )
			->andReturn( array( '3', 3, 0, 'x', 7 ) );

		$this->assertSame( array( 3, 7 ), ( new UserRepository() )->removed_blog_ids( 5 ) );
	}

	public function test_removed_blog_ids_is_empty_without_a_record(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$this->assertSame( array(), ( new UserRepository() )->removed_blog_ids( 5 ) );
	}

	public function test_record_removal_appends_once(): void {
		Functions\when( 'get_user_meta' )->justReturn( array( 2 ) );
		Functions\expect( 'update_user_meta' )->once()->with( 5, UserRepository::META_REMOVED_FROM, array( 2, 3 ) );

		( new UserRepository() )->record_removal( 5, 3 );
		( new UserRepository() )->record_removal( 5, 2 );
	}

	public function test_forget_removal_deletes_the_record_when_it_empties(): void {
		Functions\when( 'get_user_meta' )->justReturn( array( 3 ) );
		Functions\expect( 'delete_user_meta' )->once()->with( 5, UserRepository::META_REMOVED_FROM );

		( new UserRepository() )->forget_removal( 5, 3 );
	}

	public function test_stored_member_count_reads_the_database_a_chunk_at_a_time(): void {
		$queries         = array();
		$GLOBALS['wpdb'] = new class( $queries ) {
			public string $usermeta = 'wp_usermeta';
			/** @var string[] */
			private array $queries;

			public function __construct( array &$queries ) {
				$this->queries = &$queries;
			}

			public function get_blog_prefix( int $blog_id ): string {
				return 'wp_' . $blog_id . '_';
			}

			public function prepare( string $query, array $args ): string {
				return $query . ' ' . implode( ',', $args );
			}

			public function get_var( string $query ): string {
				$this->queries[] = $query;
				return '3';
			}
		};

		$count = ( new UserRepository() )->stored_member_count( 7, array_merge( range( 1, 600 ), array( 1, 2 ) ) );
		unset( $GLOBALS['wpdb'] );

		$this->assertSame( 6, $count, 'Two chunks of distinct ids, 3 found in each.' );
		$this->assertCount( 2, $queries );
		$this->assertStringContainsString( 'wp_7_capabilities', $queries[0] );
	}

	public function test_forget_cached_cleans_each_user_once(): void {
		Functions\expect( 'clean_user_cache' )->twice();

		( new UserRepository() )->forget_cached( array( 5, 6, 5 ) );
	}

	public function test_count_network_users_counts_every_account_loading_one_id_at_most(): void {
		\WP_User_Query::$total = 1234;

		$this->assertSame( 1234, ( new UserRepository() )->count_network_users() );
		$this->assertSame(
			array(
				'blog_id'     => 0,
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			),
			\WP_User_Query::$last_query
		);
	}

	public function test_blog_ids_of_user_are_the_keys_of_their_sites(): void {
		Functions\expect( 'get_blogs_of_user' )
			->once()
			->with( 5 )
			->andReturn(
				array(
					'1' => (object) array( 'userblog_id' => 1 ),
					4   => (object) array( 'userblog_id' => 4 ),
				)
			);

		$this->assertSame( array( 1, 4 ), ( new UserRepository() )->blog_ids_of_user( 5 ) );
	}

	public function test_is_member_of_coerces_falsy_to_false(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( 0 );

		$this->assertFalse( ( new UserRepository() )->is_member_of( 5, 7 ) );
	}

	public function test_forget_removal_of_a_site_not_recorded_writes_nothing(): void {
		Functions\when( 'get_user_meta' )->justReturn( array( 2 ) );
		Functions\expect( 'update_user_meta' )->never();
		Functions\expect( 'delete_user_meta' )->never();

		( new UserRepository() )->forget_removal( 5, 3 );
	}

	public function test_forget_removal_keeps_the_other_sites_recorded(): void {
		Functions\when( 'get_user_meta' )->justReturn( array( 2, 3, 4 ) );
		Functions\expect( 'update_user_meta' )->once()->with( 5, UserRepository::META_REMOVED_FROM, array( 2, 4 ) );
		Functions\expect( 'delete_user_meta' )->never();

		( new UserRepository() )->forget_removal( 5, 3 );
	}
}
