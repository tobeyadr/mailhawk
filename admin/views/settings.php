<?php

use MailHawk\Api\Licensing;
use MailHawk\Api\Postal\Domains;
use function MailHawk\build_site_email;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_authenticated_sender_domain;
use function MailHawk\get_email_retry_attempts;
use function MailHawk\get_log_retention_days;
use function MailHawk\get_mailhawk_api_key;
use function MailHawk\maybe_get_from_transient;

if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	require_once ABSPATH . '/wp-admin/includes/plugin.php';
}

$account_data = maybe_get_from_transient( 'mailhawk_account_info', function () {
	$info = Licensing::instance()->get_account();

	if ( is_wp_error( $info ) ) {
		return null;
	}

	return $info;
} );

?>

<form method="post">

	<?php wp_nonce_field( 'save_settings', '_mailhawk_nonce' ); ?>

    <div class="mailhawk-content-box settings">

        <h2><?php _e( 'Defaults', 'mailhawk' ); ?></h2>

        <table class="form-table">

            <tbody>
            <tr>
                <th><?php _e( 'Default from name', 'mailhawk' ); ?></th>
                <td>
                    <input type="text" name="default_from_name" class="regular-text"
                           value="<?php esc_attr_e( get_option( 'mailhawk_default_from_name', get_bloginfo( 'name' ) ) ); ?>">
                    <p class="description">
						<?php _e( 'Override the default wp_mail from name. Only has an effect if the from name is not specified in the email headers.', 'mailhawk' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php _e( 'Default from email address', 'mailhawk' ); ?></th>
                <td>
                    <input type="email" name="default_from_email_address" class="regular-text"
                           value="<?php esc_attr_e( get_option( 'mailhawk_default_from_email_address', build_site_email( 'wordpress' ) ) ); ?>">
                    <p class="description">
						<?php _e( 'Override the default wp_mail from email. Only has an effect if the from email is not specified in the email headers.', 'mailhawk' ); ?>
                    </p>
                </td>
            </tr>
            </tbody>


        </table>

		<?php submit_button(); ?>

    </div>

    <div class="mailhawk-content-box settings">

        <h3><?php _e( 'Email Log', 'mailhawk' ); ?></h3>

        <table class="form-table">
            <tbody>
            <tr>
                <th><?php _e( 'Disable email logging', 'mailhawk' ); ?></th>
                <td>
                    <label><input type="checkbox" name="disable_email_logging"
                                  value="1" <?php checked( 1, get_option( 'mailhawk_disable_email_logging' ) ); ?>> <?php _e( 'Disable', 'mailhawk' ); ?>
                    </label>
                    <p class="description">
						<?php printf( __( 'Disables the built-in MailHawk email log.', 'mailhawk' ), get_log_retention_days() ); ?>
                    </p>
                </td>
            </tr>
			<?php if ( ! get_option( 'mailhawk_disable_email_logging' ) ): ?>
                <tr>
                    <th><?php _e( 'Log retention', 'mailhawk' ); ?></th>
                    <td>
                        <input type="number" name="log_retention_in_days"
                               value="<?php echo get_log_retention_days(); ?>">
                        <p class="description">
							<?php printf( __( 'The number of days to retain log entries for. Log entries older than <code>%s</code> days will be automatically deleted.', 'mailhawk' ), get_log_retention_days() ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e( 'Retry failed emails?', 'mailhawk' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="retry_failed_emails"
                                      value="1" <?php checked( 1, get_option( 'mailhawk_retry_failed_emails' ) ); ?>> <?php _e( 'Enable', 'mailhawk' ); ?>
                        </label>
                        <p class="description">
							<?php printf( __( 'When enabled MailHawk will attempt to resend any failed emails.', 'mailhawk' ), get_log_retention_days() ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e( 'Number of retries', 'mailhawk' ); ?></th>
                    <td>
                        <input type="number" name="number_of_retries"
                               value="<?php echo get_email_retry_attempts(); ?>">
                        <p class="description">
							<?php printf( __( 'How many times a failed email is automatically retried before failing permanently. Emails will be retried <code>%s</code> times.', 'mailhawk' ), get_email_retry_attempts() ); ?>
                        </p>
                    </td>
                </tr>
			<?php endif; ?>
            </tbody>
        </table>

		<?php submit_button(); ?>

    </div>

	<?php if ( is_multisite() && is_main_site() ): ?>
        <div class="mailhawk-content-box settings">

            <h3><?php _e( 'Multisite Settings', 'mailhawk' ); ?></h3>

			<?php if ( ! is_plugin_active_for_network( 'mailhawk/mailhawk.php' ) ): ?>
                <div class="notice notice-warning">
                    <p><?php _e( "To use MailHawk for all subsites, you must network activate it!", 'mailhawk' ); ?></p>
                </div>
			<?php endif; ?>

            <table class="form-table">

                <tbody>
                <tr>
                    <th><?php _e( 'Network Sender Domain', 'mailhawk' ); ?></th>
                    <td>

						<?php $domains = Domains::get_verified(); ?>
						<?php if ( ! $domains ): ?>
                            <p><a href="<?php echo esc_url( get_admin_mailhawk_uri( [ 'view' => 'domains' ] ) ); ?>">You
                                    must verify a domain first.</a></p>
						<?php else: ?>
                            <select name="sender_domain" class="regular-text">
								<?php foreach ( $domains as $domain ): ?>
                                    <option
                                            value="<?php esc_attr_e( $domain->name ); ?>" <?php selected( get_authenticated_sender_domain(), $domain->name ); ?>><?php esc_attr_e( $domain->name ); ?></option>
								<?php endforeach; ?>
                            </select>
						<?php endif; ?>
                        <p class="description">
							<?php printf( __( "When sub sites send email using their own domain, it will show as sent <code>via %s</code>.", 'mailhawk' ), get_authenticated_sender_domain() ); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>

			<?php submit_button(); ?>

        </div>
	<?php endif; ?>

    <div class="mailhawk-content-box settings danger">
        <h3><?php _e( 'Danger Zone', 'mailhawk' ); ?></h3>
        <table class="form-table">
            <tbody>
            <tr>
                <th><?php _e( 'Email API key', 'mailhawk' ); ?></th>
                <td>
                    <input type="password" name="mailhawk_mta_credential_key"
                           value="<?php esc_attr_e( get_mailhawk_api_key() ); ?>">
                    <p class="description">
						<?php _e( "This is what authorizes your site to connect to the MailHawk service. Do not change this unless instructed by support.", 'mailhawk' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php _e( 'License Token', 'mailhawk' ); ?></th>
                <td>
                    <input type="password" name="mailhawk_license_server_token"
                           value="<?php esc_attr_e( get_option( 'mailhawk_license_server_token' ) ); ?>">
                    <p class="description">
						<?php _e( "Allows you to connect to the licensing server.", 'mailhawk' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php _e( 'Disconnect', 'mailhawk' ); ?></th>
                <td>

					<?php $url = \MailHawk\action_url( 'disconnect_mailhawk' ); ?>
                    <p>
                        <a class="button"
                           href="<?php echo esc_attr( $url ); ?>"><?php _e( 'Disconnect MailHawk', 'mailhawk' ) ?></a>
                        &nbsp;<span><?php printf( __( 'Currently connected to %s', 'mailhawk' ), '<code>' . ( $account_data ? $account_data->email : '' ) . '</code>' ) ?></span>
                    </p>
                    <p class="description">
						<?php _e( "Do this if you no longer want this site connected to MailHawk. You will be able to reconnect later to the same account or a different account. <b>This will not cancel your subscription.</b>", 'mailhawk' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php _e( 'Delete all data when uninstalling?', 'mailhawk' ); ?></th>
                <td>
                    <label><input type="checkbox" name="delete_all_data"
                                  value="1" <?php checked( 1, get_option( 'mailhawk_delete_all_data' ) ); ?>> <?php _e( 'Enable', 'mailhawk' ); ?>
                    </label>
                    <p class="description">
						<?php _e( 'If you uninstall MailHawk would you like to delete all associated data?', 'mailhawk' ); ?>
                    </p>
                </td>
            </tr>
            </tbody>
        </table>
		<?php submit_button(); ?>
    </div>
</form>

