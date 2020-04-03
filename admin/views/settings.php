<?php

use function MailHawk\build_site_email;
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
			</tbody>
		</table>

		<?php submit_button(); ?>

	</div>

</form>

