<?php

use MailHawk\Keys;
use function MailHawk\mailhawk_admin_page;

$form_inputs = [
	'mailhawk_plugin_signup' => 'yes',
	'state'                  => Keys::instance()->state(),
	'redirect_uri'           => mailhawk_admin_page(),
];

?>
<div class="wrap mailhawk-connect">
    <div class="mailhawk-header">
        <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
		<?php do_action( 'mailhawk_notices' ); ?>
    </div>
    <div class="mailhawk-content-box mailhawk-connect-box">
        <h2><?php _e( 'Send your WordPress email with MailHawk', 'mailhawk' ); ?></h2>
        <p class="mailhawk-lead"><?php _e( 'MailHawk is a mail service built for delivery. Connect this site and your email stops depending on your web host.', 'mailhawk' ); ?></p>
        <ul class="mailhawk-connect-points">
            <li>
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <span><strong><?php _e( 'Authenticated email', 'mailhawk' ); ?></strong> <?php _e( 'sent from your own domain, so it reaches the inbox.', 'mailhawk' ); ?></span>
            </li>
            <li>
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <span><strong><?php _e( 'Protection from bounces and spam complaints.', 'mailhawk' ); ?></strong> <?php _e( 'Risky recipients are held back before they hurt your reputation.', 'mailhawk' ); ?></span>
            </li>
            <li>
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <span><strong><?php _e( 'A complete log.', 'mailhawk' ); ?></strong> <?php _e( 'See every email that was sent, and resend it.', 'mailhawk' ); ?></span>
            </li>
        </ul>
        <a id="connect" class="button button-primary big-button"
           href="<?php echo esc_url( add_query_arg( urlencode_deep( $form_inputs ), MAILHAWK_LICENSE_SERVER_URL ) ); ?>">
            <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
            <span class="mailhawk-connect-label"><?php _e( 'Connect MailHawk', 'mailhawk' ); ?></span>
        </a>
        <p class="mailhawk-connect-note"><?php _e( 'You will sign in or create a MailHawk account, then come straight back here. Until you connect, your site keeps using the default WordPress mailer.', 'mailhawk' ); ?></p>
    </div>
</div>
