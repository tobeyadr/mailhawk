<?php

// Todo get domains from API
use MailHawk\Api\Postal\Domains;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_spf_record;
use function MailHawk\get_url_var;
use function MailHawk\mailhawk_spf_is_set;

$domain = Domains::query( sanitize_text_field( get_url_var( 'domain' ) ) );

if ( is_wp_error( $domain ) ) {
	wp_die( $domain );
}

if ( get_url_var( 'action' ) === 'is_verified' ) {
	if ( $domain->spf->spf_status === 'OK' && $domain->dkim->dkim_status === 'OK' ) {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Your domain has been verified successfully!', 'mailhawk' ); ?></p>
        </div>
		<?php
	} else {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( "We were unable to verify your domain. It can take up to 24 hours for records to propogate, so check again in an hour or so.", 'mailhawk' ); ?></p>
        </div>
		<?php
	}
}

?>
<div class="mailhawk-content-box setup domains domain-single">

    <a href="<?php echo wp_nonce_url( get_admin_mailhawk_uri( [ 'domain' => $domain->name ] ), 'mailhawk_check_domain', '_mailhawk_nonce' ); ?>"
       class="button big-button button-primary verify-button"><?php _e( 'Verify', 'mailhawk' ); ?></a>

    <h1><?php printf( __( "Configure your DNS for <b>%s</b>!", 'mailhawk' ), esc_html( $domain->name ) ); ?></h1>

    <!-- If both records are verified, output success message! -->
	<?php if ( $domain->dkim->dkim_status === 'OK' && $domain->spf->spf_status === 'OK' ): ?>
        <p><?php _e( 'Your domain has been fully verified! No more action is required.', 'mailhawk' ); ?></p>
	<?php else: ?>
        <div class="domain-unverified">
            <p><?php printf( __( 'To improve your email deliverability, you should configure your DNS records for <code>%s</code>.', 'mailhawk' ), esc_attr( $domain->name ) ) ?></p>
        </div>
	<?php endif; ?>

    <table class="wp-list-table widefat fixed striped dns-list">
        <thead>
        <tr>
            <th class="type"><?php _e( 'Type', 'mailhawk' ) ?></th>
            <th class="name"><?php _e( 'Name', 'mailhawk' ) ?></th>
            <th class="ttl"><?php _e( 'TTL', 'mailhawk' ) ?></th>
            <th class="value"><?php _e( 'Value', 'mailhawk' ) ?></th>
            <th class="status"><?php _e( 'Status', 'mailhawk' ) ?></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><?php _e( '<code>TXT</code> (SPF)' ) ?></td>
            <td><input class="code" onfocus="this.select()" type="text"
                       value="@"
                       readonly></td>
            <td><input class="code" onfocus="this.select()" type="text"
                       value="3600"
                       readonly></td>
            <td>

				<?php

				// Get the actual spf record
				$spf_record = get_spf_record( $domain->name );
				// Use the customers current SPF record to add the mailhawk information
				if ( $domain->spf->spf_status !== 'OK' && ! empty( $spf_record ) ):
					preg_match( '/include:[^ ]+/', $domain->spf->spf_record, $matches );
					$to_include = apply_filters( 'mailhawk/spf_include', $matches[0] );
					$spf_record = preg_replace( '/([^ ]+$)/', "{$to_include} $1", $spf_record );
				else:
                    $spf_record = $domain->spf->spf_record;
				endif; ?>

                <input class="code" onfocus="this.select()" type="text"
                       value="<?php esc_attr_e( $spf_record ); ?>"
                       readonly></td>
            <td><?php echo $domain->spf->spf_status === 'OK' ? "<span class='tag yes'>" . __( 'Verified', 'mailhawk' ) . "</span>" : "<span class='tag no'>" . __( 'Unverified' ) . "</span>"; ?>
            </td>
        </tr>
        <tr>
            <td><?php _e( '<code>TXT</code> (DKIM)' ) ?></td>
            <td><input class="code" onfocus="this.select()" type="text"
                       value="<?php esc_attr_e( $domain->dkim->dkim_record_name ); ?>"
                       readonly></td>
            <td><input class="code" onfocus="this.select()" type="text"
                       value="3600"
                       readonly></td>
            <td><input class="code" onfocus="this.select()" type="text"
                       value="<?php esc_attr_e( $domain->dkim->dkim_record ); ?>"
                       readonly></td>
            <td><?php echo $domain->dkim->dkim_status === 'OK' ? "<span class='tag yes'>" . __( 'Verified', 'mailhawk' ) . "</span>" : "<span class='tag no'>" . __( 'Unverified' ) . "</span>"; ?>
            </td>
        </tr>
        </tbody>
    </table>
    <div class="not-sure-dns">
        <p>
            <span class="dashicons dashicons-sos"></span> <?php _e( "Don't know how to configure DNS records?", 'mailhawk' ) ?>
            <a href="https://mailhawk.io/configure-dns/"
               target="_blank"><?php _e( 'Learn how to configure your DNS', 'mailhawk' ); ?></a></p>
    </div>
    <p>
        <a href="<?php echo esc_url( get_admin_mailhawk_uri( [ 'view' => 'domains' ] ) ); ?>">&larr; <?php _e( 'Back', 'mailhawk' ); ?></a>
        <a href="<?php echo wp_nonce_url( get_admin_mailhawk_uri( [ 'domain' => $domain->name ] ), 'mailhawk_delete_domain', '_mailhawk_nonce' ); ?>"
           class="button delete alignright"><?php _e( 'Delete', 'mailhawk' ); ?></a>
    </p>

</div>

