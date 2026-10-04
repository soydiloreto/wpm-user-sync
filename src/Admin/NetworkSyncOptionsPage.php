<?php
/**
 * Network Sync Options page — three checkboxes that toggle the
 * automatic sync triggers, plus the matching save handler.
 *
 * @package WPMUS\Admin
 */

declare(strict_types=1);

namespace WPMUS\Admin;

use WPMUS\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Network Sync Options page — three checkboxes that toggle the
 * automatic sync triggers, plus the matching save handler.
 */
final class NetworkSyncOptionsPage {

	private Config $config;

	/**
	 * @param Config $config Toggle accessors used by the form view
	 *                       and the save handler.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Render the options form. Output is HTML; no return value.
	 */
	public function render(): void {
		$new_site_sync = $this->config->is_new_site_sync_enabled();
		$new_user_sync = $this->config->is_new_user_sync_enabled();
		$set_role_sync = $this->config->is_set_user_role_sync_enabled();
		?>
		<div class="wrap">
			<h3><?php esc_html_e( 'Network Configuration', 'wpm-user-sync' ); ?></h3>
			<p><?php esc_html_e( 'These settings let you customize the sync behavior.', 'wpm-user-sync' ); ?></p>
			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=wpmusSaveGlobalConfig' ) ); ?>">
				<?php wp_nonce_field( Config::NONCE_ACTION ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'New Site Automatic Sync', 'wpm-user-sync' ); ?></th>
						<td>
							<label>
								<input name="wpmus_newSiteSync" type="checkbox" value="yes" <?php checked( true, $new_site_sync ); ?> />
								<?php esc_html_e( 'Sync new site with all users', 'wpm-user-sync' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When a new site is created in the network, all users in the database will be added to this new site with the default site role. If no default role is configured, "subscriber" is used.', 'wpm-user-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New User Automatic Sync', 'wpm-user-sync' ); ?></th>
						<td>
							<label>
								<input name="wpmus_newUserSync" type="checkbox" value="yes" <?php checked( true, $new_user_sync ); ?> />
								<?php esc_html_e( 'Sync new users with all sites', 'wpm-user-sync' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When a new user is created in the network, they will be added to all sites with each default site role. If no default role is configured, "subscriber" is used.', 'wpm-user-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Set User Role Automatic Sync', 'wpm-user-sync' ); ?></th>
						<td>
							<label>
								<input name="wpmus_setUserRoleSync" type="checkbox" value="yes" <?php checked( true, $set_role_sync ); ?> />
								<?php esc_html_e( 'Sync new user roles to all sites', 'wpm-user-sync' ); ?>
							</label>
							<p class="description"><?php esc_html_e( "When a user's role changes on any site (e.g. promoted to editor on one site), the change is replicated to every other site where the user is already a member and that defines the role. Administrator is never replicated, and super admins are left alone.", 'wpm-user-sync' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Hooked on `network_admin_edit_wpmusSaveGlobalConfig`. The
	 * nonce + capability gate runs before any state mutation.
	 */
	public function handle_save(): void {
		check_admin_referer( Config::NONCE_ACTION );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change network sync options.', 'wpm-user-sync' ), '', array( 'response' => 403 ) );
		}

		$this->config->save_toggles(
			isset( $_POST['wpmus_newSiteSync'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wpmus_newSiteSync'] ) ) : '',
			isset( $_POST['wpmus_newUserSync'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wpmus_newUserSync'] ) ) : '',
			isset( $_POST['wpmus_setUserRoleSync'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wpmus_setUserRoleSync'] ) ) : ''
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wpmus-networksyncoptions',
					'updated' => 'true',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
