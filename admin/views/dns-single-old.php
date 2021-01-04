<?php

// Todo get domains from API
use MailHawk\Api\Postal\Domains;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_url_var;

$domain = Domains::query( sanitize_text_field( get_url_var( 'domain' ) ) );

if ( is_wp_error( $domain ) ){
	wp_die( $domain );
}

//var_dump( $domain->dkim, $domain->spf );

?>
<div class="mailhawk-content-box setup domains">

	<h1><?php printf( __( "Configure your DNS for <b>%s</b>!", 'mailhawk' ), esc_html( $domain->name ) ); ?></h1>
	<p>Before you can start sending email, you must configure your DNS records for <code><?php esc_html_e( $domain->name ); ?></code>.</p>
	<p><a class="button button-secondary" href="https://mailhawk.io/configure-dns/" target="_blank"><span
				class="dashicons dashicons-text-page"></span><?php _e( 'Instructions', 'mailhawk' ); ?></a></p>

	<h3><?php _e( 'Status' ); ?></h3>
	<p>
		<?php $verified = $domain->spf->spf_status === 'OK' ? "<span class='yes'>" . __( 'verified' ) . "</span>" : "<span class='no'>" . __( 'unverified' ) . "</span>" ; ?>
		<?php printf( __( "<code>SPF</code> is currently %s.", 'mailhawk' ), $verified ); ?>
	</p>
	<p>
		<?php $verified = $domain->dkim->dkim_status === 'OK' ? "<span class='yes'>" . __( 'verified' ) . "</span>" : "<span class='no'>" . __( 'unverified' ) . "</span>" ; ?>
		<?php printf( __( "<code>DKIM</code> is currently %s.", 'mailhawk' ), $verified ); ?>
	</p>

	<a href="<?php echo wp_nonce_url( get_admin_mailhawk_uri( [ 'domain' => $domain->name ] ), 'mailhawk_check_domain', '_mailhawk_nonce' ); ?>" class="button"><?php _e( 'Refresh', 'mailhawk' ); ?></a>

	<h3><?php _e( 'SPF Record' ); ?></h3>

	<?php $short_spf = preg_match( "/include:[^ ]+/", $domain->spf->spf_record, $matches ); ?>
	<p class="description"><?php printf( __( 'You need to add a TXT record at the apex/root of your doman (@) with the following content. If you already send mail from another service, you may just need to add <code>%s</code> to your existing record.', 'mailhawk' ), $matches[0] ); ?></p>
	<input class="code" onfocus="this.select()" type="text" value="<?php esc_attr_e( $domain->spf->spf_record ); ?>"
	       readonly>
	<h3><?php _e( 'DKIM Record' ); ?></h3>
	<p class="description"><?php printf( __( 'You need to add a new TXT record with the name <code>%s</code> with the following content.', 'mailhawk' ), strtolower( $domain->dkim->dkim_record_name ) ); ?></p>
	<input class="code" onfocus="this.select()" type="text"
	       value="<?php esc_attr_e( $domain->dkim->dkim_record ); ?>"
	       readonly>

	<p>
		<a href="<?php echo esc_url( get_admin_mailhawk_uri( [ 'view' => 'domains' ] ) ); ?>">&larr; <?php _e( 'Back', 'mailhawk' ); ?></a>
		<a href="<?php echo wp_nonce_url( get_admin_mailhawk_uri( [ 'domain' => $domain->name ] ), 'mailhawk_delete_domain', '_mailhawk_nonce' ); ?>" class="button delete alignright"><?php _e( 'Delete', 'mailhawk' ); ?></a>
	</p>

</div>

