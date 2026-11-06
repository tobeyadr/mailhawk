<?php

use function MailHawk\build_site_email;
use function MailHawk\get_post_var;

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

// Free inbox providers like gmail.com can't be authenticated, nobody can add DNS records to them
$free_provider_emails = array_filter( $emails, 'MailHawk\is_free_email_provider' );
$emails               = array_filter( array_diff( $emails, $free_provider_emails ), 'is_email' );

// When the form was sent back with a problem, keep what the customer entered so they can fix it
$submitted = ! empty( get_post_var( '_mailhawk_setup_nonce' ) );

$from_name  = $submitted ? sanitize_text_field( get_post_var( 'default_from_name' ) ) : get_option( 'mailhawk_default_from_name', get_bloginfo( 'name' ) );
$from_email = $submitted ? sanitize_text_field( get_post_var( 'default_from_email_address' ) ) : get_option( 'mailhawk_default_from_email_address', build_site_email( site_url(), 'wordpress' ) );
$selected   = $submitted ? array_map( 'sanitize_email', (array) get_post_var( 'emails', [] ) ) : $emails;

$mailhawk_step = 2;

?>
<style>
    #wpcontent {
        margin: 0;
        padding: 0;
    }

    #adminmenumain, #wpfooter, #wpadminbar {
        display: none !important;
    }
</style>
<div class="wrap mailhawk-connect mailhawk-setup">
    <div class="mailhawk-header">
        <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
		<?php do_action( 'mailhawk_notices' ); ?>
    </div>
    <div class="mailhawk-content-box mailhawk-connect-box">

		<?php include __DIR__ . '/setup-steps.php'; ?>

        <h2><?php _e( 'Choose where your email comes from', 'mailhawk' ); ?></h2>
        <p class="mailhawk-lead"><?php _e( 'MailHawk sends email for the domains you own. Choose how your email should appear, and which addresses you send from.', 'mailhawk' ); ?></p>

        <form method="post">

			<?php wp_nonce_field( 'mailhawk_register_domains', '_mailhawk_setup_nonce' ); ?>

            <div class="mailhawk-field">
                <label for="default_from_name"><?php _e( 'From name', 'mailhawk' ); ?></label>
                <input type="text" id="default_from_name" name="default_from_name"
                       value="<?php echo esc_attr( $from_name ); ?>">
                <p class="description"><?php _e( 'The name people see on your emails.', 'mailhawk' ); ?></p>
            </div>

            <div class="mailhawk-field">
                <label for="default_from_email_address"><?php _e( 'From email address', 'mailhawk' ); ?></label>
                <input type="email" id="default_from_email_address" name="default_from_email_address"
                       value="<?php echo esc_attr( $from_email ); ?>">
                <p class="description"><?php _e( 'The address your email is sent from. It must be on a domain you own.', 'mailhawk' ); ?></p>
            </div>

            <fieldset class="mailhawk-field">
                <legend><?php _e( 'Addresses to send from', 'mailhawk' ); ?></legend>
                <p class="description"><?php _e( 'We found these addresses on your site. MailHawk registers the domain of each one you select.', 'mailhawk' ); ?></p>

				<?php foreach ( $emails as $email ): ?>
                    <label class="mailhawk-option">
                        <input type="checkbox" name="emails[]" value="<?php echo esc_attr( $email ); ?>"
							<?php checked( in_array( $email, $selected, true ) ); ?>>
                        <span><?php echo esc_html( $email ); ?></span>
                    </label>
				<?php endforeach; ?>

				<?php if ( ! empty( $free_provider_emails ) ): ?>
                    <p class="description"><?php printf( __( 'Left out because they are on a free email provider that can not be authenticated: %s', 'mailhawk' ), '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $free_provider_emails ) ) . '</code>' ); ?></p>
				<?php endif; ?>
            </fieldset>

            <button type="submit" class="button button-primary big-button mailhawk-cta">
                <span class="mailhawk-connect-label"><?php esc_html_e( 'Register domains', 'mailhawk' ); ?></span>
                <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
            </button>
        </form>
        <p class="mailhawk-connect-note">
            <?php _e( 'Next you will add a few DNS records so inboxes trust your email.', 'mailhawk' ); ?>
        </p>
        <p class="mailhawk-skip">
            <a href="<?php echo esc_url( \MailHawk\mailhawk_admin_page() ); ?>"><?php esc_html_e( 'Skip for now, I will finish setting up later', 'mailhawk' ); ?></a>
        </p>
    </div>
</div>
