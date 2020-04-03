<?php

$emails = [];

// The admin email
$emails[] = get_option( 'admin_email' );

// include admin emails
$admins = get_users( [ 'role__in' => [ 'administrator' ] ] );
foreach ( $admins as $admin ) {
	$emails[] = $admin->user_email;
}

// Regular WP email
$emails[] = sprintf( 'wordpress@%s', str_replace( 'www.', '', wp_parse_url( site_url(), PHP_URL_HOST ) ) );

$emails = array_unique( $emails );

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
    <div class="mailhawk-content-box setup">

        <h1><?php _e( "Let's get you set up!", 'mailhawk' ); ?></h1>
        <p><?php _e( 'We have detected the following email addresses in use, please select the ones you wish to send email from.', 'mailhawk' ); ?></p>

        <form method="post">

			<?php wp_nonce_field( 'mailhawk_register_domains', '_mailhawk_setup_nonce' ); ?>

			<?php foreach ( $emails as $email ): ?>

				<?php if ( ! is_email( $email ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
                <div class="email-option">
                    <label><input type="checkbox" name="emails[]" value="<?php esc_attr_e( $email ); ?>"
                                  checked> <?php esc_html_e( $email ); ?></label>
                </div>

			<?php endforeach; ?>

            <button class="big-button button button-primary"><?php esc_html_e( 'Next', 'mailhawk' ); ?> &rarr;</button>
        </form>
    </div>
</div>

