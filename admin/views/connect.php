<?php

use MailHawk\Keys;
use function MailHawk\mailhawk_admin_page;

$form_inputs = [
	'mailhawk_plugin_signup' => 'yes',
	'state'                  => Keys::instance()->state(),
	'redirect_uri'           => mailhawk_admin_page(),
];

?>
<style>
    .wrap {
        max-width: 500px;
        margin: auto;
    }

    .mailhawk-header {
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
        <div style="text-align: center">
            <a id="connect" class="button button-primary big-button"
               href="<?php echo esc_url( add_query_arg( urlencode_deep( $form_inputs ), MAILHAWK_LICENSE_SERVER_URL ) ); ?>">
                <span class="dashicons dashicons-email-alt"></span>
				<?php _e( 'Connect MailHawk Now!', 'mailhawk' ); ?>
            </a>
        </div>
    </div>
</div>
