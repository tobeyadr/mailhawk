<?php

use MailHawk\Keys;
use function MailHawk\get_admin_mailhawk_uri;

$form_inputs = [
	'mailhawk_plugin_signup' => 'yes',
	'state'                  => Keys::instance()->state(),
	'redirect_uri'           => get_admin_mailhawk_uri(),
];

?>
<style>
    .wrap{
        max-width: 500px;
        margin: auto;
    }

    .mailhawk-header{
        text-align: center;
    }

</style>
<div class="wrap">
    <div class="mailhawk-header">
        <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
		<?php do_action( 'mailhawk_notices' ); ?>
    </div>
    <div class="mailhawk-content-box">
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
</div>
