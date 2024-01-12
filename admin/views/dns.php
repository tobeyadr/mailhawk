<?php

// Todo get domains from API
use MailHawk\Api\Postal\Domains;
use function MailHawk\mailhawk_admin_page;

$domains = Domains::query_all();

// If there was an error, show no domains.
if ( is_wp_error( $domains ) || empty( $domains ) ) {
	$domains = [];
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

    .wrap {
        max-width: 700px;
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

        <h1><?php _e( "Configure your DNS!", 'mailhawk' ); ?></h1>
        <p><?php _e( 'Before you can start sending email, you must configure your DNS records for the emails you just registered.', 'mailhawk' ); ?></p>
        <p><a class="button button-secondary" href="https://mailhawk.io/configure-dns/" target="_blank"><span
                        class="dashicons dashicons-text-page"></span><?php _e( 'Instructions', 'mailhawk' ); ?></a></p>
        </p>
		<?php foreach ( $domains as $domain ): ?>

            <h2><span class="dashicons dashicons-admin-site-alt2"></span><?php esc_html_e( $domain->name ); ?></h2>

            <h3><?php _e( 'SPF Record' ); ?></h3>

			<?php $short_spf = preg_match( "/include:[^ ]+/", $domain->spf->spf_record, $matches ); ?>
            <p class="description"><?php printf( __( 'You need to add a TXT record at the apex/root of your domain (@) with the following content. If you already send mail from another service, you may just need to add <code>%s</code> to your existing record.', 'mailhawk' ), $matches[0] ); ?></p>
            <input class="code" onfocus="this.select()" type="text"
                   value="<?php esc_attr_e( $domain->spf->spf_record ); ?>"
                   readonly>
            <h3><?php _e( 'DKIM Record' ); ?></h3>
            <p class="description"><?php printf( __( 'You need to add a new TXT record with the name <code>%s</code> with the following content.', 'mailhawk' ), strtolower( $domain->dkim->dkim_record_name ) ); ?></p>
            <input class="code" onfocus="this.select()" type="text"
                   value="<?php esc_attr_e( $domain->dkim->dkim_record ); ?>"
                   readonly>

		<?php endforeach; ?>

        <?php $finish_url = apply_filters( 'mailhawk/finish_url', mailhawk_admin_page() ); ?>

        <a class="button big-button button-primary"
           href="<?php echo esc_url( $finish_url ); ?>"><b>&larr; <?php _e( 'Finish Setup!', 'mailhawk' ); ?></b></a>

    </div>
</div>
