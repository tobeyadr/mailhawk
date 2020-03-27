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
<div class="mailhawk-content-box domains">
    <p><b><?php _e( 'Your Domains:', 'mailhawk' ); ?></b></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
        <tr>
            <th><?php _e( 'Domain', 'mailhawk' ); ?></th>
            <th><?php _e( 'SPF Verified', 'mailhawk' ); ?></th>
            <th><?php _e( 'DKIM Verified', 'mailhawk' ); ?></th>
            <th><?php _e( 'DNS', 'mailhawk' ); ?></th>
        </tr>
        </thead>
        <tbody>
		<?php foreach ( $domains as $domain ): ?>
            <tr>
				<td><?php esc_html_e( $domain->name ); ?></td>
				<td><?php echo $domain->spf->spf_status === 'OK' ? "<span class='yes'>" . __( 'Yes' ) . "</span>" : "<span class='no'>" . __( 'No' ) . "</span>" ; ?></td>
				<td><?php echo $domain->dkim->dkim_status === 'OK' ? "<span class='yes'>" . __( 'Yes' ) . "</span>" : "<span class='no'>" . __( 'No' ) . "</span>" ; ?></td>
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
	<p class="description"><?php _e( 'These are the domains which you have registered with MailHawk. You can only send emails from domains you have registered and verified.', 'mailhawk' ); ?></p>

	<p><b><?php _e( 'Register Domain:', 'mailhawk' ); ?></b></p>
	<form method="post">
		<?php wp_nonce_field( 'register_new_domain', '_mailhawk_nonce' ); ?>
		<input class="add-domain regular-text" type="url" name="domain" placeholder="https://example.com" required>
		<input class="button button-secondary" type="submit" value="<?php esc_attr_e( "Register" );?> ">
	</form>
    <p class="description"><?php _e( 'Register a new domain to be able to send email from it.', 'mailhawk' ); ?></p>
</div>
