<?php
/**
 * Thrown by the stubbed `wp_die()` (and a failing
 * `check_admin_referer()`) in the unit suite: the request ends there,
 * so the test ends there too, with what it was told.
 *
 * @package WPMUS\Tests\Stubs
 */

declare(strict_types=1);

namespace Tests\Stubs;

use RuntimeException;

final class Died extends RuntimeException {

	/** @var array<string,mixed> */
	public array $args;

	/**
	 * @param string              $message What wp_die() was asked to show.
	 * @param array<string,mixed> $args    Its arguments (`response`, …).
	 */
	public function __construct( string $message, array $args = array() ) {
		parent::__construct( $message );
		$this->args = $args;
	}
}
