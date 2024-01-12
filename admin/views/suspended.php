<?php

use MailHawk\Api\Postal\Reporting;
use MailHawk\Keys;
use function MailHawk\mailhawk_admin_page;
use function MailHawk\set_mailhawk_is_suspended;

$form_inputs = [
	'mailhawk_plugin_reactivate' => 'yes',
	'state'                      => Keys::instance()->state(),
	'redirect_uri'               => mailhawk_admin_page(),
];

$limits = Reporting::limits();

if ( ! is_wp_error( $limits ) ){
    set_mailhawk_is_suspended( false );
	wp_die( "<script>location.reload();</script>" );
}

?>
<style>
    .wrap {
        max-width: 500px;
        margin: auto;
    }

    .mailhawk-header {
        text-align: center;
    }

    .suspension-reasons {
        list-style: disc;
        padding-left: 20px;
    }

    #connect {
        font-weight: 700;
    }

</style>
<div class="wrap">
    <div class="mailhawk-header">
        <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
		<?php do_action( 'mailhawk_notices' ); ?>
    </div>
    <div class="mailhawk-content-box">
        <p><?php _e( 'Your account has been suspended. This may have happened because:', 'mailhawk' ); ?></p>
        <ul class="suspension-reasons">
            <li><?php _e( 'Your subscription was cancelled.', 'mailhawk' ); ?></li>
            <li><?php _e( 'Your last payment failed.', 'mailhawk' ); ?></li>
        </ul>
        <p><?php _e( 'To continue using MailHawk you must reactivate you account.', 'mailhawk' ); ?></p>
        <div style="text-align: center">
            <a id="connect" class="button button-primary big-button" href="<?php echo esc_url( add_query_arg( urlencode_deep( $form_inputs ), MAILHAWK_LICENSE_SERVER_URL ) ); ?>">
                <span class="dashicons dashicons-email-alt"></span>
                <?php _e( 'Reactivate My Account Now!', 'mailhawk' ); ?>
            </a>
	        <?php $url = \MailHawk\action_url( 'disconnect_mailhawk' ); ?>
        </div>
        <p style="text-align: center">
            <a class="danger" style="color:red"
               href="<?php echo esc_attr( $url ); ?>"><?php _e( 'Disconnect MailHawk', 'mailhawk' ) ?></a>
        </p>
    </div>
</div>
