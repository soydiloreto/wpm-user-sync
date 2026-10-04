<?php
/**
 * Thrown by the stubbed `wp_safe_redirect()` in the unit suite, so a
 * handler that redirects and then calls `exit` stops at the redirect
 * and the test can assert where it was sent.
 *
 * @package WPMUS\Tests\Stubs
 */

declare(strict_types=1);

namespace Tests\Stubs;

use RuntimeException;

final class Redirected extends RuntimeException {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'Redirected to ' . $location );
		$this->location = $location;
	}
}
