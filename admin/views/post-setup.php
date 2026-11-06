<?php

use MailHawk\Api\Postal\Domains;
use function MailHawk\get_domains_needing_dns;
use function MailHawk\get_setup_domain_names;
use function MailHawk\mailhawk_admin_page;

$setup_names = get_setup_domain_names();
$all_domains = $setup_names ? Domains::query_all() : [];
$to_configure = get_domains_needing_dns( $setup_names, $all_domains );

// Everything that was registered is authenticated already, or the domains could not be looked up
$all_verified = $setup_names && is_array( $all_domains ) && empty( $to_configure );

$finish_url = apply_filters( 'mailhawk/finish_url', mailhawk_admin_page() );

if ( ! empty( $to_configure ) ) {
	$dns_url = mailhawk_admin_page( [ 'view' => 'domains', 'domain' => $to_configure[0]->name ] );
	$dns_label = 1 === count( $to_configure )
		? sprintf( __( 'Set up DNS for %s', 'mailhawk' ), $to_configure[0]->name )
		: __( 'Set up DNS records', 'mailhawk' );
} else {
	$dns_url   = mailhawk_admin_page( [ 'view' => 'domains', 'notice' => 'instructions' ] );
	$dns_label = __( 'Set up DNS records', 'mailhawk' );
}

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
<div class="wrap mailhawk-connect mailhawk-complete">
    <div class="mailhawk-header">
        <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
		<?php do_action( 'mailhawk_notices' ); ?>
    </div>
    <div class="mailhawk-content-box mailhawk-connect-box">

		<?php
		$mailhawk_step = $all_verified ? 4 : 3;
		include __DIR__ . '/setup-steps.php';
		?>

		<?php if ( $all_verified ) : ?>

            <h2><?php _e( "You're all set!", 'mailhawk' ); ?></h2>
            <p class="mailhawk-lead"><?php _e( 'MailHawk is connected and your domains are authenticated. Your email is now sent through MailHawk.', 'mailhawk' ); ?></p>
            <a class="button button-primary big-button mailhawk-cta" href="<?php echo esc_url( $finish_url ); ?>">
                <span class="mailhawk-connect-label"><?php _e( 'Go to the Overview', 'mailhawk' ); ?></span>
            </a>

		<?php else : ?>

            <h2><?php _e( 'Last step: authenticate your domains', 'mailhawk' ); ?></h2>
            <p class="mailhawk-lead"><?php _e( "You're connected and your domains are registered. To finish, add a few DNS records at your domain provider. They let inboxes confirm that your email really comes from you.", 'mailhawk' ); ?></p>
            <p><?php _e( 'Without them your email is more likely to land in spam or be rejected, so this is the one thing worth doing now.', 'mailhawk' ); ?></p>

			<?php if ( ! empty( $to_configure ) ) : ?>
                <ul class="mailhawk-domain-list">
					<?php foreach ( $to_configure as $domain ) : ?>
                        <li>
                            <code><?php echo esc_html( $domain->name ); ?></code>
                            <span class="mailhawk-domain-status">
                                <span class="mailhawk-chip <?php echo ( $domain->spf->spf_status ?? '' ) === 'OK' ? 'ok' : 'todo'; ?>">SPF</span>
                                <span class="mailhawk-chip <?php echo ( $domain->dkim->dkim_status ?? '' ) === 'OK' ? 'ok' : 'todo'; ?>">DKIM</span>
                            </span>
                            <a class="button button-secondary"
                               href="<?php echo esc_url( mailhawk_admin_page( [ 'view' => 'domains', 'domain' => $domain->name ] ) ); ?>"><?php _e( 'Configure', 'mailhawk' ); ?></a>
                        </li>
					<?php endforeach; ?>
                </ul>
			<?php endif; ?>

            <a class="button button-primary big-button mailhawk-cta" href="<?php echo esc_url( $dns_url ); ?>">
                <span class="dashicons dashicons-admin-site" aria-hidden="true"></span>
                <span class="mailhawk-connect-label"><?php echo esc_html( $dns_label ); ?></span>
            </a>
            <p class="mailhawk-connect-note"><?php _e( 'It takes a few minutes. New records usually verify within an hour, but can take up to 24 hours.', 'mailhawk' ); ?>
                <a href="https://mailhawk.io/configure-dns/" target="_blank" rel="noopener"><?php _e( 'Not sure how? Read the DNS guide', 'mailhawk' ); ?></a>
            </p>
            <p class="mailhawk-skip">
                <a href="<?php echo esc_url( $finish_url ); ?>"><?php _e( "I'll do this later, take me to the Overview", 'mailhawk' ); ?></a>
            </p>

		<?php endif; ?>

    </div>
</div>
