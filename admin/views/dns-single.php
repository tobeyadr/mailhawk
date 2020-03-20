<?php

// Todo get domains from API
use function MailHawk\get_admin_mailhawk_uri;

$domain = [
	'domain'   => 'groundhogg.io',
	'verified' => true,
	'dns'      => [
		'spf'        => 'v=spf1 a mx include:spf.mta01.mailhawk.io ~all',
		'dkim_name'  => 'postal-YqaxqG._domainkey',
		'dkim_value' => 'v=DKIM1; t=s; h=sha256; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC4pIyo/8TgM5oZHK1yw6XlAGTQH5SDjcotvfo2Peo9L5f20J5kyB7Hfvh3EBg17/3o9H6C7wNfDyi3KnOAL5tIoLjb7msWI1Lzeh8dYKmOyL9zFNa3Rv5QCENkQjmdQ/V0OWZ9BK0yF2EPdvlKBUIxaLBvha2UR1FBOi3eeqjPeQIDAQAB;',
		'rp_name'    => 'psrp.mailhawkwp.com',
		'rp_value'   => 'rp.mta01.mailhawk.io'
	]
];

?>
<div class="mailhawk-connect setup">

    <h1><?php _e( "Configure your DNS!", 'mailhawk' ); ?></h1>
    <p>Before you can start sending email, you must configure your DNS records for this domain.</p>
    <p><a class="button button-secondary" href="#"><span
                    class="dashicons dashicons-video-alt3"></span><?php _e( 'Video Tutorial', 'mailhawk' ); ?></a></p>

    <h2><span class="dashicons dashicons-admin-site-alt2"></span><?php esc_html_e( $domain['domain'] ); ?></h2>

    <h3><?php _e( 'SPF Record' ); ?></h3>
    <p class="description"><?php _e( 'You need to add a TXT record at the apex/root of your domain (@) with the following content. If you already send mail from another service, you may just need to add <code>include:spf.mta01.mailhawk.io</code> to your existing record.', 'mailhawk' ); ?></p>
    <input class="code" onfocus="this.select()" type="text" value="<?php esc_attr_e( $domain['dns']['spf'] ); ?>"
           readonly>
    <h3><?php _e( 'DKIM Record' ); ?></h3>
    <p class="description"><?php printf( __( 'You need to add a new TXT record with the name %s with the following content.', 'mailhawk' ), "<code>{$domain['dns']['dkim_name']}</code>" ); ?></p>
    <input class="code" onfocus="this.select()" type="text"
           value="<?php esc_attr_e( $domain['dns']['dkim_value'] ); ?>"
           readonly>
    <h3><?php _e( 'Return Path' ); ?></h3>
    <p class="description"><?php printf( __( 'This is optional but we recommend adding this to improve deliverability. You should add a CNAME record at %s to point to the hostname below.', 'mailhawk' ), "<code>{$domain['dns']['rp_name']}</code>" ); ?></p>
    <input class="code" onfocus="this.select()" type="text"
           value="<?php esc_attr_e( $domain['dns']['rp_value'] ); ?>"
           readonly>

    <p>
        <a href="<?php echo esc_url( get_admin_mailhawk_uri() ); ?>">&larr; <?php _e( 'Back', 'mailhawk' ); ?></a>
        <a href="<?php echo wp_nonce_url( get_admin_mailhawk_uri( [ 'domain' => $domain[ 'domain' ] ] ), 'mailhawk_delete_domain', '_mailhawk_nonce' ); ?>" class="button delete alignright"><?php _e( 'Delete', 'mailhawk' ); ?></a>
    </p>

</div>

