<?php

use MailHawk\Keys;
use function MailHawk\get_admin_mailhawk_uri;

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
            <a id="connect" class="button button-primary big-button" href="https://mailhawk.io/account/subscriptions/">
                <span class="dashicons dashicons-email-alt"></span>
				<?php _e( 'Reactivate My Account Now!', 'mailhawk' ); ?>
            </a>
        </div>
    </div>
</div>
