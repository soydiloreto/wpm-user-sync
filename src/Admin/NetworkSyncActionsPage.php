<?php
/**
 * Network Sync Actions page — two manual sync forms (sync everything
 * vs sync selected sites) plus the matching save handlers.
 *
 * @package WPMUS\Admin
 */

declare(strict_types=1);

namespace WPMUS\Admin;

use WPMUS\Config;
use WPMUS\Repositories\SiteRepository;
use WPMUS\Sync\JobQueue;
use WPMUS\Sync\SyncEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Network Sync Actions page — two manual sync forms (sync everything,
 * or sync only selected sites) plus the matching save handlers.
 */
final class NetworkSyncActionsPage {

	private SiteRepository $sites;
	private SyncEngine $engine;
	private JobQueue $queue;

	/**
	 * @param SiteRepository $sites  For listing the sites in the
	 *                               "Sync specific sites" form.
	 * @param SyncEngine     $engine Runs the actual sync work.
	 * @param JobQueue       $queue  Background syncs, for their progress.
	 */
	public function __construct( SiteRepository $sites, SyncEngine $engine, JobQueue $queue ) {
		$this->sites  = $sites;
		$this->engine = $engine;
		$this->queue  = $queue;
	}

	/**
	 * Render both manual-sync forms. Output is HTML.
	 */
	public function render(): void {
		$all_sites = $this->sites->all_sites();
		?>
		<div class="wrap">
			<h3><?php esc_html_e( 'Network Sync Actions', 'wpm-user-sync' ); ?></h3>
			<p><?php esc_html_e( 'These actions let you sync users and sites with several options.', 'wpm-user-sync' ); ?></p>
			<?php $this->render_progress(); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Sync from scratch', 'wpm-user-sync' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=wpmusSyncNetworkFromScratch' ) ); ?>">
							<?php wp_nonce_field( Config::NONCE_ACTION ); ?>
							<p>
								<label>
									<input type="checkbox" name="wpmus_force" value="yes" />
									<?php esc_html_e( 'Also add back people who were removed from a site', 'wpm-user-sync' ); ?>
								</label>
							</p>
							<input type="submit" value="<?php esc_attr_e( 'Sync from scratch', 'wpm-user-sync' ); ?>" class="button" />
						</form>
						<p class="description"><?php esc_html_e( 'Sync every site with every user. Each site receives every user with the default site role. Existing memberships are not modified, and people removed from a site stay removed unless you tick the box.', 'wpm-user-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sync specific sites', 'wpm-user-sync' ); ?></th>
					<td>
						<p><?php esc_html_e( 'Select the sites you want to sync users into:', 'wpm-user-sync' ); ?></p>
						<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=wpmusSyncNetworkSiteFromScratch' ) ); ?>">
							<?php wp_nonce_field( Config::NONCE_ACTION ); ?>
							<?php foreach ( $all_sites as $site ) : ?>
								<label>
									<input type="checkbox" name="listSites[]" value="<?php echo esc_attr( (string) $site->blog_id ); ?>" />
									<?php echo esc_html( $site->domain . $site->path ); ?>
								</label><br />
							<?php endforeach; ?>
							<p>
								<label>
									<input type="checkbox" name="wpmus_force" value="yes" />
									<?php esc_html_e( 'Also add back people who were removed from a site', 'wpm-user-sync' ); ?>
								</label>
							</p>
							<input type="submit" value="<?php esc_attr_e( 'Sync selected sites', 'wpm-user-sync' ); ?>" class="button" />
						</form>
						<p class="description"><?php esc_html_e( 'All selected sites will receive every user with the default site role. Existing memberships are not modified, and people removed from a site stay removed unless you tick the box.', 'wpm-user-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Hooked on `network_admin_edit_wpmusSyncNetworkFromScratch`.
	 */
	public function handle_sync_all(): void {
		check_admin_referer( Config::NONCE_ACTION );

		if ( ! current_user_can( 'manage_network_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to run a network-wide sync.', 'wpm-user-sync' ), '', array( 'response' => 403 ) );
		}

		$finished = $this->engine->sync_all_users_to_all_sites( $this->force_requested() );
		$this->redirect_after_sync( $finished );
	}

	/**
	 * Hooked on `network_admin_edit_wpmusSyncNetworkSiteFromScratch`.
	 */
	public function handle_sync_selected(): void {
		check_admin_referer( Config::NONCE_ACTION );

		if ( ! current_user_can( 'manage_network_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to run a network sync.', 'wpm-user-sync' ), '', array( 'response' => 403 ) );
		}

		$list_sites_raw = isset( $_POST['listSites'] ) && is_array( $_POST['listSites'] )
			? array_map( 'absint', wp_unslash( $_POST['listSites'] ) )
			: array();
		/** @var int[] $blog_ids */
		$blog_ids = array_values( array_filter( $list_sites_raw ) );

		if ( array() === $blog_ids ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'wpmus-networksyncactions',
						'nosynced' => 'true',
					),
					network_admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$finished = $this->engine->sync_all_users_to_sites( $blog_ids, $this->force_requested() );
		$this->redirect_after_sync( $finished );
	}

	/**
	 * True when the administrator ticked "also add back people who
	 * were removed". Only called after the nonce check.
	 */
	private function force_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() in the calling handler.
		return isset( $_POST['wpmus_force'] ) && 'yes' === sanitize_key( wp_unslash( (string) $_POST['wpmus_force'] ) );
	}

	/**
	 * Back to the page, with the notice for a finished or queued sync.
	 */
	private function redirect_after_sync( bool $finished ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                              => 'wpmus-networksyncactions',
					( $finished ? 'synced' : 'queued' ) => 'true',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Lists the syncs running in the background and how far each got.
	 * Nothing when the queue is empty.
	 */
	private function render_progress(): void {
		$jobs = $this->queue->all();
		if ( array() === $jobs ) {
			return;
		}
		$labels = array(
			'new_site' => __( 'New site', 'wpm-user-sync' ),
			'new_user' => __( 'New user', 'wpm-user-sync' ),
			'manual'   => __( 'Manual sync', 'wpm-user-sync' ),
		);
		?>
		<h4><?php esc_html_e( 'Syncs running in the background', 'wpm-user-sync' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Large syncs run in batches through WP-Cron, a few seconds at a time, whenever the main site gets a visit. They are listed here until they finish.', 'wpm-user-sync' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Sync', 'wpm-user-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Started', 'wpm-user-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Progress', 'wpm-user-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $jobs as $job ) : ?>
					<?php $percent = $job->total > 0 ? min( 100, (int) floor( $job->processed * 100 / $job->total ) ) : 0; ?>
					<tr>
						<td><?php echo esc_html( $labels[ $job->context ] ?? $job->context ); ?></td>
						<?php $started = wp_date( (string) get_site_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_site_option( 'time_format', 'H:i' ), $job->created ); ?>
						<td><?php echo esc_html( false === $started ? '' : $started ); ?></td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: percentage done, 2: user-site pairs processed, 3: user-site pairs in total */
									__( '%1$d%% (%2$s of %3$s user-site pairs)', 'wpm-user-sync' ),
									$percent,
									number_format_i18n( $job->processed ),
									number_format_i18n( $job->total )
								)
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
