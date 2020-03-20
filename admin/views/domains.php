<?php

// Todo get domains from API
use function MailHawk\get_admin_mailhawk_uri;

$domains = [

	[
		'domain'   => 'mailhawkwp.com',
		'verified' => false,
		'dns'      => [
			// todo DNS structure
		]
	],
	[
		'domain'   => 'groundhogg.io',
		'verified' => true,
		'dns'      => [
			// todo DNS structure
		]
	],


];

?>
<div class="mailhawk-connect domains">
    <p><b><?php _e( 'Your Domains:', 'mailhawk' ); ?></b></p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
        <tr>
            <th><?php _e( 'Domain', 'mailhawk' ); ?></th>
            <th><?php _e( 'Verified', 'mailhawk' ); ?></th>
            <th><?php _e( 'DNS', 'mailhawk' ); ?></th>
        </tr>
        </thead>
        <tbody>
		<?php foreach ( $domains as $domain ): ?>
            <tr>
				<td><?php esc_html_e( $domain['domain'] ); ?></td>
				<td><?php echo $domain[ 'verified' ] ? "<span class='yes'>" . __( 'Yes' ) . "</span>" : "<span class='no'>" . __( 'No' ) . "</span>" ; ?></td>
				<td><a href="<?php echo esc_url( get_admin_mailhawk_uri( [ 'action' => 'dns-single', 'domain' => $domain[ 'domain' ] ] ) ); ?>" class="button button-secondary"><?php _e( 'Configure', 'mailhawk' ); ?></a></td>
            </tr>
		<?php endforeach; ?>
        </tbody>
    </table>
	<p class="description"><?php _e( 'These are the domains which you have registered with MailHawk. You can only send emails from domains you have registered and verified.', 'mailhawk' ); ?></p>

	<p><b><?php _e( 'Register Domain:', 'mailhawk' ); ?></b></p>
	<form method="post">
		<?php wp_nonce_field( 'register_new_domain', '_mailhawk_nonce' ); ?>
		<input class="add-domain regular-text" type="url" name="domain" placeholder="https://example.com">
		<input class="button button-secondary alignright" type="submit" value="<?php esc_attr_e( "Register" );?> ">
	</form>
    <p class="description"><?php _e( 'Register a new domain to be able to send email from it.', 'mailhawk' ); ?></p>
</div>
