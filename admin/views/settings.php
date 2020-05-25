<?php

use function MailHawk\build_site_email;
use function MailHawk\get_email_retry_attempts;
use function MailHawk\get_log_retention_days;

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
                <td><input type="email" name="default_from_email_address" class="regular-text"
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
            </tbody>
        </table>

		<?php submit_button(); ?>

    </div>

    <div class="mailhawk-content-box settings danger">

        <h3><?php _e( 'Danger Zone', 'mailhawk' ); ?></h3>

        <table class="form-table">
            <tbody>
            <tr>
                <th><?php _e( 'Disconnect', 'mailhawk' ); ?></th>
                <td>

                    <?php $url = \MailHawk\action_url( 'disconnect_mailhawk' ); ?>
                    <a class="button" href="<?php echo esc_attr( $url ); ?>"><?php _e( 'Disconnect MailHawk', 'mailhawk' ) ?></a>
                    <p class="description">
						<?php _e( "Do this if you no longer want this site connected to MailHawk. You will be able to reconnect later to the same account or a different account.", 'mailhawk' ); ?>
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

