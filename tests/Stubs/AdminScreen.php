<?php
/**
 * Brain Monkey stubs for the WordPress functions the plugin's admin
 * screens and handlers call, shared by the unit tests of `src/Admin`,
 * `Notices` and `Header`.
 *
 * Translation and escaping go through Brain Monkey's defaults
 * (`htmlspecialchars` for the `esc_*` family), so a test can feed
 * markup in and assert it comes out escaped. `wp_safe_redirect()` and
 * `wp_die()` throw {@see Redirected} and {@see Died}: the request ends
 * there, and the `exit` after a redirect is never reached. `wp_kses()`
 * is left to each test (the header test expects it).
 *
 * @package WPMUS\Tests\Stubs
 */

declare(strict_types=1);

namespace Tests\Stubs;

use Brain\Monkey\Functions;

trait AdminScreen {

	/** Network admin base URL the stubbed `network_admin_url()` uses. */
	protected string $network_admin = 'https://example.test/wp-admin/network/';

	/** Site admin base URL the stubbed `admin_url()` uses. */
	protected string $site_admin = 'https://example.test/sub/wp-admin/';

	/**
	 * Stubs translation, escaping, URLs, sanitizing and the form helpers.
	 */
	protected function stub_admin_screen(): void {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		// Like core, a bare `&` in a URL is output as `&#038;`.
		Functions\when( 'esc_url' )->alias(
			static function ( string $url ): string {
				return str_replace( array( '&', "'" ), array( '&#038;', '&#039;' ), $url );
			}
		);
		$network_admin = $this->network_admin;
		$site_admin    = $this->site_admin;
		Functions\when( 'network_admin_url' )->alias(
			static function ( string $path = '' ) use ( $network_admin ): string {
				return $network_admin . $path;
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ) use ( $site_admin ): string {
				return $site_admin . $path;
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			static function ( $value ) {
				return is_array( $value ) ? array_map( 'stripslashes', $value ) : stripslashes( (string) $value );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action ): void {
				echo '<input type="hidden" name="_wpnonce" data-action="' . $action . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current ): void {
				echo $checked === $current ? " checked='checked'" : '';
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function (): void {
				echo '<input type="submit" class="button button-primary" />';
			}
		);
	}

	/**
	 * Makes `wp_safe_redirect()` end the test with {@see Redirected}.
	 */
	protected function stub_redirect(): void {
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $location ): void {
				throw new Redirected( $location );
			}
		);
	}

	/**
	 * Makes `wp_die()` end the test with {@see Died}.
	 */
	protected function stub_wp_die(): void {
		Functions\when( 'wp_die' )->alias(
			static function ( $message = '', $title = '', $args = array() ): void {
				throw new Died( (string) $message, is_array( $args ) ? $args : array() );
			}
		);
	}

	/**
	 * Runs `$callback` and returns where it redirected to; fails the
	 * test when it did not redirect.
	 *
	 * @param callable():void $callback The handler call.
	 */
	protected function redirect_of( callable $callback ): string {
		try {
			$callback();
		} catch ( Redirected $redirect ) {
			return $redirect->location;
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * Runs `$callback` and returns the wp_die() it ended in; fails the
	 * test when it did not die.
	 *
	 * @param callable():void $callback The handler call.
	 */
	protected function death_of( callable $callback ): Died {
		try {
			$callback();
		} catch ( Died $died ) {
			return $died;
		}
		$this->fail( 'Expected wp_die().' );
	}

	/**
	 * The output `$callback` prints.
	 *
	 * @param callable():void $callback The render call.
	 */
	protected function output_of( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}
}
