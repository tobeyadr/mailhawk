<?php

use MailHawk\Keys;
use function MailHawk\get_mailhawk_suspension;
use function MailHawk\mailhawk_account_url;
use function MailHawk\mailhawk_admin_page;
use function MailHawk\mailhawk_suspended_since;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$form_inputs = [
	'mailhawk_plugin_reactivate' => 'yes',
	'state'                      => Keys::instance()->state(),
	'redirect_uri'               => mailhawk_admin_page(),
];

// What the MTA said about it. Older MTA builds say nothing, then the usual causes are listed instead.
$suspension = get_mailhawk_suspension();
$since      = mailhawk_suspended_since();

?>
<div class="wrap mailhawk-connect">
    <div class="mailhawk-header">
        <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
		<?php do_action( 'mailhawk_notices' ); ?>
    </div>
    <div class="mailhawk-content-box mailhawk-connect-box">
        <h2><?php _e( 'Your MailHawk account is suspended', 'mailhawk' ); ?></h2>
		<?php if ( $suspension['reason'] ) : ?>
            <p class="mailhawk-lead"><?php _e( 'Email from this site is not being sent. MailHawk gave this reason:', 'mailhawk' ); ?></p>
            <blockquote class="mailhawk-suspension-reason"><p><?php echo esc_html( $suspension['reason'] ); ?></p></blockquote>
		<?php else : ?>
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
		<?php endif; ?>
		<?php if ( $since ) : ?>
            <p><?php
				/* translators: %s: date and time the account was suspended */
				printf( esc_html__( 'Suspended since %s.', 'mailhawk' ), esc_html( $since ) );
				?></p>
		<?php endif; ?>
		<?php if ( 'organization' === $suspension['scope'] ) : ?>
            <p><?php _e( 'The whole MailHawk account is suspended, so none of its sites can send email.', 'mailhawk' ); ?></p>
		<?php endif; ?>
        <p><?php _e( 'Your MailHawk account page says what to do next, for example, renewing your subscription or sending an incident report.', 'mailhawk' ); ?></p>
        <a class="button button-primary big-button mailhawk-cta" href="<?php echo esc_url( mailhawk_account_url() ); ?>" target="_blank" rel="noopener">
            <span class="dashicons dashicons-external" aria-hidden="true"></span>
            <span class="mailhawk-connect-label"><?php _e( 'See what to do next', 'mailhawk' ); ?></span>
        </a>
        <p class="mailhawk-connect-note">
            <a href="<?php echo esc_url( add_query_arg( urlencode_deep( $form_inputs ), MAILHAWK_LICENSE_SERVER_URL ) ); ?>"><?php _e( 'Reactivate my account', 'mailhawk' ); ?></a>
            |
            <a class="danger" style="color:#d63638"
               href="<?php echo esc_url( \MailHawk\action_url( 'disconnect_mailhawk' ) ); ?>"><?php _e( 'Disconnect MailHawk', 'mailhawk' ) ?></a>
        </p>
    </div>
</div>
