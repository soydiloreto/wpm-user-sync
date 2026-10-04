<?php
/**
 * Per-site Sync Actions page — a single button that pulls every
 * network user into the current site, plus the save handler.
 *
 * @package WPMUS\Admin
 */

declare(strict_types=1);

namespace WPMUS\Admin;

use WPMUS\Config;
use WPMUS\Sync\SyncEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Per-site Sync Actions page — a single "Sync from scratch" button
 * that pulls every network user into the current site, plus the
 * matching save handler. Only for people who manage the network's
 * users (super admins), never for a site administrator.
 */
final class SiteSyncActionsPage {

	private SyncEngine $engine;

	/**
	 * @param SyncEngine $engine Runs the actual sync work.
	 */
	public function __construct( SyncEngine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Render the single-button form. Output is HTML.
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h3><?php esc_html_e( 'Site Sync Actions', 'wpm-user-sync' ); ?></h3>
			<p><?php esc_html_e( 'These actions let you sync every network user into this site.', 'wpm-user-sync' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Sync from scratch', 'wpm-user-sync' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=wpmusSyncSiteSiteFromScratch' ) ); ?>">
							<?php wp_nonce_field( Config::NONCE_ACTION ); ?>
							<input type="hidden" name="wpmus_blogid" value="<?php echo esc_attr( (string) get_current_blog_id() ); ?>" />
							<input type="submit" value="<?php esc_attr_e( 'Sync from scratch', 'wpm-user-sync' ); ?>" class="button" />
						</form>
						<p class="description"><?php esc_html_e( 'Adds every network user to this site with the default site role. If no default role is configured, "subscriber" is used. Existing memberships are not modified.', 'wpm-user-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Hooked on `admin_action_wpmusSyncSiteSiteFromScratch`. Always
	 * acts on the CURRENT blog id from the WP runtime.
	 */
	public function handle_sync_current_site(): void {
		check_admin_referer( Config::NONCE_ACTION );

		// Pulling every account on the network into a site is a network
		// decision: a site administrator (manage_options) cannot do it.
		if ( ! current_user_can( 'manage_network_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to run a site sync.', 'wpm-user-sync' ), '', array( 'response' => 403 ) );
		}

		$finished = $this->engine->sync_all_users_to_sites( array( get_current_blog_id() ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                              => 'wpmus-sitesyncactions',
					( $finished ? 'synced' : 'queued' ) => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
