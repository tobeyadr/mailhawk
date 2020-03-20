<?php

use MailHawk\Keys;
use function MailHawk\get_admin_mailhawk_uri;

$form_inputs = [
	'mailhawk_plugin_signup' => 'yes',
	'state'                  => Keys::instance()->state(),
	'redirect_uri'           => get_admin_mailhawk_uri(),
];

?>
<div class="mailhawk-connect">
    <p><?php _e( 'Connect to <b>MailHawk</b> and instantly solve your WordPress email delivery problems. Starts at just <b>$14.97</b>/m.', 'mailhawk' ); ?></p>
    <form method="post" action="<?php echo esc_url( trailingslashit( MAILHAWK_LICENSE_SERVER_URL ) ); ?>">
		<?php

		foreach ( $form_inputs as $input => $value ) {
			?><input type="hidden" name="<?php esc_attr_e( $input ); ?>"
                     value="<?php esc_attr_e( $value ); ?>"><?php
		}

		?>
        <button id="connect" class="button button-primary big-button" type="submit" value="connect">
            <span class="dashicons dashicons-email-alt"></span>
			<?php _e( 'Connect MailHawk Now!', 'mailhawk' ); ?>
        </button>
    </form>
</div>
