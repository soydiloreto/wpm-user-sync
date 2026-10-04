<?php
/**
 * One sync job: which users go to which sites, and how far it got.
 *
 * Jobs that are too big for one request are stored in the queue
 * ({@see JobQueue}) as plain arrays and run in batches from WP-Cron;
 * the cursor lets each batch pick up where the previous one stopped.
 *
 * @package WPMUS\Sync
 */

declare(strict_types=1);

namespace WPMUS\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Value object for a sync job and its progress.
 */
final class SyncJob {

	/** Unique id, used to update or remove the stored job. */
	public string $id;

	/** Why the job exists: `new_site`, `new_user` or `manual`. */
	public string $context;

	/**
	 * Users to sync, or null for every user on the network.
	 *
	 * @var int[]|null
	 */
	public ?array $user_ids;

	/**
	 * Sites to sync into, or null for every active site.
	 *
	 * @var int[]|null
	 */
	public ?array $blog_ids;

	/** Also add back users who were removed from a site. */
	public bool $force;

	/** Cursor: how many users are fully processed. */
	public int $user_offset = 0;

	/** Cursor: the last site processed for the current user. */
	public int $last_blog_id = 0;

	/** User-site pairs processed so far. */
	public int $processed = 0;

	/** User-site pairs the job covers, estimated when it starts. */
	public int $total = 0;

	/** Unix time the job was created. */
	public int $created = 0;

	/** True once every pair is processed. Never stored. */
	public bool $done = false;

	/**
	 * @param string     $context  `new_site`, `new_user` or `manual`.
	 * @param int[]|null $user_ids Users, null for all.
	 * @param int[]|null $blog_ids Sites, null for all active ones.
	 * @param bool       $force    Also add back removed users.
	 */
	public function __construct( string $context, ?array $user_ids, ?array $blog_ids, bool $force ) {
		$this->id       = uniqid( 'wpmus', true );
		$this->context  = $context;
		$this->user_ids = null === $user_ids ? null : array_values( array_map( 'intval', $user_ids ) );
		$this->blog_ids = null === $blog_ids ? null : array_values( array_map( 'intval', $blog_ids ) );
		$this->force    = $force;
		$this->created  = time();
	}

	/**
	 * The array stored in the network option.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'context'      => $this->context,
			'user_ids'     => $this->user_ids,
			'blog_ids'     => $this->blog_ids,
			'force'        => $this->force,
			'user_offset'  => $this->user_offset,
			'last_blog_id' => $this->last_blog_id,
			'processed'    => $this->processed,
			'total'        => $this->total,
			'created'      => $this->created,
		);
	}

	/**
	 * Rebuilds a job from its stored array; null when the array is not
	 * a job (a corrupted or foreign value).
	 *
	 * @param mixed $data Stored value.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['id'], $data['context'] ) || ! is_string( $data['id'] ) || ! is_string( $data['context'] ) ) {
			return null;
		}
		$job               = new self(
			$data['context'],
			isset( $data['user_ids'] ) && is_array( $data['user_ids'] ) ? $data['user_ids'] : null,
			isset( $data['blog_ids'] ) && is_array( $data['blog_ids'] ) ? $data['blog_ids'] : null,
			! empty( $data['force'] )
		);
		$job->id           = $data['id'];
		$job->user_offset  = isset( $data['user_offset'] ) ? (int) $data['user_offset'] : 0;
		$job->last_blog_id = isset( $data['last_blog_id'] ) ? (int) $data['last_blog_id'] : 0;
		$job->processed    = isset( $data['processed'] ) ? (int) $data['processed'] : 0;
		$job->total        = isset( $data['total'] ) ? (int) $data['total'] : 0;
		$job->created      = isset( $data['created'] ) ? (int) $data['created'] : 0;
		return $job;
	}
}
