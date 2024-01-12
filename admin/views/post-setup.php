<?php

// Todo get domains from API
use MailHawk\Api\Postal\Domains;
use function MailHawk\mailhawk_admin_page;

?>
<style>
    #wpcontent {
        margin: 0;
        padding: 0;
    }

    #adminmenumain, #wpfooter, #wpadminbar {
        display: none !important;
    }

    .wrap {
        max-width: 600px;
        margin: auto;
    }

    .mailhawk-header {
        text-align: center;
    }

    .next-step p {
        padding-right: 150px;
    }

    .next-step .button {
        float: right;
        margin: 10px 0 10px 10px !important;
    }

    .groundhogg-ad {
        max-width: 100%;
        border-radius: 5px;
        cursor: pointer;
        margin-top: 20px;
    }

</style>
<div class="wrap">
    <div class="mailhawk-header">
        <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
		<?php do_action( 'mailhawk_notices' ); ?>
    </div>
    <div class="mailhawk-content-box setup">

        <h1><strong><?php _e( "Initial Setup Complete!", 'mailhawk' ); ?></strong></h1>
        <p><?php _e( 'Congratulations! MailHawk is now connected to your site. Below are some next steps that will help you improve deliverability and keep you up to date on product news.', 'mailhawk' ); ?></p>

        <div class="next-step">
            <a href="<?php echo esc_url( mailhawk_admin_page( [ 'view' => 'domains', 'notice' => 'instructions' ] ) ); ?>" target="_blank"
               class="button big-button"><?php _e( 'Configure Now!' ); ?></a>
            <h2><?php _e( 'Configure your DNS!', 'mailhawk' ); ?></h2>
            <p><?php _e( 'Improve your email deliverability by configuring <code>SPF</code> and <code>DKIM</code> for the domains you registered.', 'mailhawk' ); ?></p>
        </div>
        <div class="next-step">
            <a href="https://twitter.com/mailhawkwp" target="_blank"
               class="button big-button"><?php _e( 'Follow Us!' ); ?></a>
            <h2><?php _e( 'Follow us on Twitter!', 'mailhawk' ); ?></h2>
            <p><?php _e( 'Get notified about product updates and useful email deliverabilty tips by following us on twitter. <code>@mailhawkwp</code>', 'mailhawk' ); ?></p>
        </div>
        <div class="next-step">
            <a href="https://wordpress.org/support/plugin/mailhawk/reviews/#new-post" target="_blank"
               class="button big-button"><?php _e( 'Rate ' ); ?>&#x2B50;&#x2B50;&#x2B50;&#x2B50;&#x2B50;!</a>
            <h2><?php _e( 'Leave a Review!', 'mailhawk' ); ?></h2>
            <p><?php _e( 'Like the service? Let others know by leaving a five star review on WordPress.org!', 'mailhawk' ); ?></p>
        </div>

        <!-- Groundhogg Ad -->
		<?php if ( ! \MailHawk\is_groundhogg_active() ): ?>
            <img class="groundhogg-ad" id="groundhogg-connect"
                 title="<?php esc_attr_e( 'Install & Connect Groundhogg!', 'mailhawk' ); ?>"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/groundhogg-ad.png' ); ?>">
			<?php \MailHawk\Groundhogg::instance()->output_js(); ?>
		<?php endif; ?>

		<?php $finish_url = apply_filters( 'mailhawk/finish_url', mailhawk_admin_page() ); ?>

        <a class="button big-button button-primary"
           href="<?php echo esc_url( $finish_url ); ?>"><b>&larr; <?php _e( 'Finish Setup!', 'mailhawk' ); ?></b></a>

    </div>
</div>
