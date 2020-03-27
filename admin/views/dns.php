<?php

// Todo get domains from API
use MailHawk\Api\Postal\Domains;
use function MailHawk\get_admin_mailhawk_uri;

$domains = Domains::query_all();

// If there was an error, show no domains.
if ( is_wp_error( $domains ) || empty( $domains ) ){
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
</style>
<div class="mailhawk-content-box setup">

    <h1><?php _e( "Configure your DNS!", 'mailhawk' ); ?></h1>
    <p><?php _e( 'Before you can start sending email, you must configure your DNS records for the emails you just registered.', 'mailhawk' ); ?></p>
	<p><a class="button button-secondary" href="#"><span class="dashicons dashicons-video-alt3"></span><?php _e( 'Video Tutorial', 'mailhawk' ); ?></a></p>
	<?php foreach ( $domains as $domain ): ?>

        <h2><span class="dashicons dashicons-admin-site-alt2"></span><?php esc_html_e( $domain->name ); ?></h2>

        <h3><?php _e( 'SPF Record' ); ?></h3>

		<?php $short_spf = preg_match( "/include:[^ ]+/", $domain->spf->spf_record, $matches ); ?>
        <p class="description"><?php printf( __( 'You need to add a TXT record at the apex/root of your doman (@) with the following content. If you already send mail from another service, you may just need to add <code>%s</code> to your existing record.', 'mailhawk' ), $matches[0] ); ?></p>
        <input class="code" onfocus="this.select()" type="text" value="<?php esc_attr_e( $domain->spf->spf_record ); ?>"
               readonly>
        <h3><?php _e( 'DKIM Record' ); ?></h3>
        <p class="description"><?php printf( __( 'You need to add a new TXT record with the name %s with the following content.', 'mailhawk' ), "<code>{$domain->dkim->dkim_record_name}</code>" ); ?></p>
        <input class="code" onfocus="this.select()" type="text"
               value="<?php esc_attr_e( $domain->dkim->dkim_record ); ?>"
               readonly>

	<?php endforeach; ?>

    <a class="button big-button button-primary" href="<?php echo esc_url( get_admin_mailhawk_uri() ); ?>"><b>&larr; <?php _e( 'Finish Setup!', 'mailhawk' ); ?></b></a>

</div>

