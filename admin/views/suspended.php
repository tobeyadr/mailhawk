<?php

use MailHawk\Keys;
use function MailHawk\mailhawk_admin_page;

$form_inputs = [
	'mailhawk_plugin_reactivate' => 'yes',
	'state'                      => Keys::instance()->state(),
	'redirect_uri'               => mailhawk_admin_page(),
];

?>
<div class="wrap mailhawk-connect">
    <div class="mailhawk-header">
        <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
		<?php do_action( 'mailhawk_notices' ); ?>
    </div>
    <div class="mailhawk-content-box mailhawk-connect-box">
        <h2><?php _e( 'Your MailHawk account is suspended', 'mailhawk' ); ?></h2>
        <p class="mailhawk-lead"><?php _e( 'To continue sending email you must reactivate it. This may have happened because:', 'mailhawk' ); ?></p>
        <ul class="mailhawk-connect-points">
            <li>
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <span><?php _e( 'Your subscription was cancelled.', 'mailhawk' ); ?></span>
            </li>
            <li>
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <span><?php _e( 'Your last payment failed.', 'mailhawk' ); ?></span>
            </li>
        </ul>
        <a id="connect" class="button button-primary big-button"
           href="<?php echo esc_url( add_query_arg( urlencode_deep( $form_inputs ), MAILHAWK_LICENSE_SERVER_URL ) ); ?>">
            <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
            <span class="mailhawk-connect-label"><?php _e( 'Reactivate my account', 'mailhawk' ); ?></span>
        </a>
        <p class="mailhawk-connect-note">
            <a class="danger" style="color:#d63638"
               href="<?php echo esc_url( \MailHawk\action_url( 'disconnect_mailhawk' ) ); ?>"><?php _e( 'Disconnect MailHawk', 'mailhawk' ) ?></a>
        </p>
    </div>
</div>
