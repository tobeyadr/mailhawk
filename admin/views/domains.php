<?php

use MailHawk\Api\Postal\Domains;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_url_var;

$domains = Domains::query_all();

// If there was an error, show no domains.
if ( is_wp_error( $domains ) || empty( $domains ) ){
    $domains = [];
}

if ( get_url_var( 'notice' ) === 'instructions' ):
	?>

	<div class="notice notice-info">
		<h2><b><?php _e( 'Next Steps...', 'mailhawk' ); ?></b></h2>
		<p><?php _e( 'Great! Your next step is to configure your DNS so we can verify <code>SPF</code> and <code>DKIM</code>.', 'mailhawk' ); ?></p>
		<p><?php _e( 'This is important because it will improve your email deliverability.', 'mailhawk' ); ?></p>
		<p><?php _e( "If you've never managed your DNS records before, please read this article:", 'mailhawk' ); ?>
			<a href="https://mailhawk.io/configure-dns/"
			   target="_blank"><?php _e( 'Learn how to configure your DNS', 'mailhawk' ); ?></a>
		</p>
		<p><?php _e( "If you know what you're doing, click <b>Configure</b> on any of the domains listed below to view the appropriate DNS records to edit.", 'mailhawk' ); ?></p>
	</div>
	<?php
endif;

?>
<div class="mailhawk-content-box domains">
    <h2><b><?php _e( 'Your Domains:', 'mailhawk' ); ?></b></h2>
    <p><?php _e( 'These are the domains which you have registered with MailHawk. You can improve your email deliverability by verifying <code>SPF</code> and <code>DKIM</code> for each domain.', 'mailhawk' ); ?></p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
        <tr>
            <th><?php _e( 'Domain', 'mailhawk' ); ?></th>
            <th><?php _e( 'SPF', 'mailhawk' ); ?></th>
            <th><?php _e( 'DKIM', 'mailhawk' ); ?></th>
            <th><?php _e( 'DNS', 'mailhawk' ); ?></th>
        </tr>
        </thead>
        <tbody>
		<?php foreach ( $domains as $domain ): ?>
            <tr>
	            <td><a href="<?php echo esc_url( get_admin_mailhawk_uri( [ 'view' => 'domains', 'domain' => $domain->name ] ) ); ?>"><code><?php esc_html_e( $domain->name ); ?></code></a></td>
				<td><?php echo $domain->spf->spf_status === 'OK' ? "<span class='tag yes'>" . __( 'Verified' ) . "</span>" : "<span class='tag no'>" . __( 'Unverified' ) . "</span>" ; ?></td>
				<td><?php echo $domain->dkim->dkim_status === 'OK' ? "<span class='tag yes'>" . __( 'Verified' ) . "</span>" : "<span class='tag no'>" . __( 'Unverified' ) . "</span>" ; ?></td>
				<td><a href="<?php echo esc_url( get_admin_mailhawk_uri( [ 'view' => 'domains', 'domain' => $domain->name ] ) ); ?>" class="button button-secondary"><?php _e( 'Configure', 'mailhawk' ); ?></a></td>
            </tr>
		<?php endforeach; ?>
        <?php if ( empty( $domains ) ): ?>
        <tr>
            <td colspan="4"><?php _e( 'You do not have any registered domains.', 'mailhawk' ); ?></td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="not-sure-dns">
        <p>
            <span class="dashicons dashicons-sos"></span> <?php _e( "Don't know how to configure DNS records?", 'mailhawk' ) ?>
            <a href="https://mailhawk.io/configure-dns/"
               target="_blank"><?php _e( 'Learn how to configure your DNS', 'mailhawk' ); ?></a></p>
    </div>

	<p><b><?php _e( 'Register New Domain:', 'mailhawk' ); ?></b></p>
	<form method="post">
		<?php wp_nonce_field( 'register_new_domain', '_mailhawk_nonce' ); ?>
		<input class="add-domain regular-text" type="url" name="domain" placeholder="https://example.com" required>
		<input class="button button-secondary" type="submit" value="<?php esc_attr_e( "Register" );?> ">
	</form>
    <p class="description"><?php _e( 'Register a new domain to be able to send email from it.', 'mailhawk' ); ?></p>
</div>
