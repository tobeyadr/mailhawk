<?php

// Todo get domains from API
use MailHawk\Api\Postal\Domains;
use function MailHawk\mailhawk_admin_page;
use function MailHawk\get_dns_instructions;
use function MailHawk\get_dns_record_names;
use function MailHawk\is_maybe_subdomain;
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

    <a href="<?php echo wp_nonce_url( mailhawk_admin_page( [ 'domain' => $domain->name ] ), 'mailhawk_check_domain', '_mailhawk_nonce' ); ?>"
       class="button big-button button-primary verify-button"><?php _e( 'Verify', 'mailhawk' ); ?></a>

    <h1><?php printf( __( "Configure your DNS for <b>%s</b>!", 'mailhawk' ), esc_html( $domain->name ) ); ?></h1>

    <!-- If both records are verified, output success message! -->
	<?php if ( $domain->dkim->dkim_status === 'OK' && $domain->spf->spf_status === 'OK' ): ?>
        <p><?php _e( 'Your domain has been fully verified! No more action is required.', 'mailhawk' ); ?></p>
	<?php else: ?>
        <div class="domain-unverified">
            <p><?php printf( __( 'To improve your email deliverability, you should configure your DNS records for <code>%s</code>.', 'mailhawk' ), esc_html( $domain->name ) ) ?></p>
        </div>
	<?php endif; ?>

	<?php

	// The same pair for every record: what goes in the Host or Name field, and the hostname it ends up as
	$dkim_names        = get_dns_record_names( $domain->dkim->dkim_record_name, $domain->name );
	$return_path_names = get_dns_record_names( $domain->return_path->return_path_domain, $domain->name );
	$spf_names         = get_dns_record_names( '@', $domain->name );

	/**
	 * The full hostname in one pill, with the host inside it as a pill of its own. The host copies just the host, the
	 * rest of it copies the full hostname.
	 *
	 * @param array $names
	 */
	$name_fields = function ( $names ) use ( $domain ) {

		// The domain itself has no part before the domain, so it is shown after the @
		$rest = '@' === $names['host'] ? strtolower( $domain->name ) : substr( $names['hostname'], strlen( $names['host'] ) );

		?>
        <div class="mailhawk-dns-name code" role="button" tabindex="0"
             data-copy="<?php echo esc_attr( $names['hostname'] ); ?>"
             title="<?php esc_attr_e( 'Copy the full hostname', 'mailhawk' ); ?>"
             aria-label="<?php echo esc_attr( sprintf(
				 /* translators: %s: the full hostname of a DNS record */
				 __( 'Copy the full hostname %s', 'mailhawk' ), $names['hostname'] ) ); ?>">
            <button type="button" class="mailhawk-dns-host"
                    data-copy="<?php echo esc_attr( $names['host'] ); ?>"
                    title="<?php esc_attr_e( 'Copy the host', 'mailhawk' ); ?>"
                    aria-label="<?php echo esc_attr( sprintf(
						/* translators: %s: the host of a DNS record, relative to the domain */
						__( 'Copy the host %s', 'mailhawk' ), $names['host'] ) ); ?>"><?php echo esc_html( $names['host'] ); ?></button>
            <span class="mailhawk-dns-rest<?php echo '@' === $names['host'] ? ' is-domain' : ''; ?>"><?php echo esc_html( $rest ); ?></span>
            <span class="mailhawk-dns-copied" aria-live="polite"></span>
            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
        </div>
		<?php
	};

	/**
	 * A pill like the name, without a part of its own: the whole of it copies the value
	 *
	 * @param string $value
	 * @param string $label what the value is, for a screen reader
	 */
	$copy_field = function ( $value, $label ) {
		?>
        <div class="mailhawk-dns-name mailhawk-copy-value code" role="button" tabindex="0"
             data-copy="<?php echo esc_attr( $value ); ?>"
             data-copied="<?php esc_attr_e( 'Copied', 'mailhawk' ); ?>"
             title="<?php echo esc_attr( $value ); ?>"
             aria-label="<?php echo esc_attr( sprintf(
				 /* translators: 1: what is copied, like TTL or value, 2: the text that is copied */
				 __( 'Copy the %1$s %2$s', 'mailhawk' ), $label, $value ) ); ?>">
            <span class="mailhawk-copy-text"><?php echo esc_html( $value ); ?></span>
            <span class="mailhawk-dns-copied" aria-live="polite"></span>
            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
        </div>
		<?php
	};

	$ttl_label   = __( 'TTL', 'mailhawk' );
	$value_label = __( 'value', 'mailhawk' );

	?>
    <p class="description">
		<?php _e( 'Click the highlighted <b>host</b> to copy just that part, which is what most DNS providers ask for, or anywhere else on the name to copy the <b>full hostname</b>.', 'mailhawk' ); ?>
		<?php if ( is_maybe_subdomain( $domain->name ) ) : ?>
			<?php _e( 'If the records of a subdomain go in the DNS of its parent domain, add the subdomain to the host: for <code>academy.example.com</code> in the DNS of <code>example.com</code>, <code>postal-abc._domainkey</code> becomes <code>postal-abc._domainkey.academy</code>.', 'mailhawk' ); ?>
		<?php endif; ?>
    </p>

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
            <td><?php _e( '<code>TXT</code> (DKIM)' ) ?></td>
            <td><?php $name_fields( $dkim_names ); ?></td>
            <td><?php $copy_field( '3600', $ttl_label ); ?></td>
            <td><?php $copy_field( (string) $domain->dkim->dkim_record, $value_label ); ?></td>
            <td><?php echo $domain->dkim->dkim_status === 'OK' ? "<span class='tag yes'>" . __( 'Verified', 'mailhawk' ) . "</span>" : "<span class='tag no'>" . __( 'Unverified' ) . "</span>"; ?>
            </td>
        </tr>
        <tr>
            <td><?php _e( '<code>CNAME</code>' ) ?></td>
            <td><?php $name_fields( $return_path_names ); ?></td>
            <td><?php $copy_field( '3600', $ttl_label ); ?></td>
            <td><?php $copy_field( 'rp.mta01.mailhawk.io', $value_label ); ?></td>
            <td><?php echo $domain->return_path->return_path_status === 'OK' ? "<span class='tag yes'>" . __( 'Verified', 'mailhawk' ) . "</span>" : "<span class='tag no'>" . __( 'Unverified' ) . "</span>"; ?>
            </td>
        </tr>
        <tr>
            <td><?php _e( '<code>TXT</code> (SPF)' ) ?></td>
            <td><?php $name_fields( $spf_names ); ?></td>
            <td><?php $copy_field( '3600', $ttl_label ); ?></td>
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

				<?php $copy_field( (string) $spf_record, $value_label ); ?></td>
            <td><?php echo $domain->spf->spf_status === 'OK' ? "<span class='tag yes'>" . __( 'Verified', 'mailhawk' ) . "</span>" : "<span class='tag no'>" . __( 'Unverified' ) . "</span>"; ?>
            </td>
        </tr>
		<?php
		// Recommended, not needed to verify the domain. The MTA checks it since November 2026.
		$dmarc = \MailHawk\Abilities\Formatter::dmarc_record( $domain );
		if ( $dmarc ) : ?>
            <tr>
                <td><?php _e( '<code>TXT</code> (DMARC)', 'mailhawk' ) ?></td>
                <td><?php $name_fields( get_dns_record_names( $dmarc['hostname'], $domain->name ) ); ?></td>
                <td><?php $copy_field( '3600', $ttl_label ); ?></td>
                <td><?php $copy_field( $dmarc['value'], $value_label ); ?></td>
                <td><?php echo $dmarc['verified'] ? "<span class='tag yes'>" . __( 'Verified', 'mailhawk' ) . "</span>" : "<span class='tag no'>" . __( 'Recommended', 'mailhawk' ) . "</span>"; ?>
                </td>
            </tr>
		<?php endif; ?>
        </tbody>
    </table>
	<?php if ( isset( $dmarc ) && $dmarc && ! $dmarc['verified'] ) : ?>
        <p class="description">
			<?php _e( '<b>DMARC is recommended.</b> Your domain is verified without it, but Apple, Gmail and Yahoo expect it, and Apple turns away most email from a domain that has none. <code>p=none</code> only asks for reports, it never stops any email. A domain can only have one DMARC record: if a TXT record starting with <code>v=DMARC1</code> is there already, keep it.', 'mailhawk' ); ?>
        </p>
	<?php endif; ?>
    <div class="not-sure-dns">
        <p>
            <span class="dashicons dashicons-sos"></span> <?php _e( "Don't know how to configure DNS records?", 'mailhawk' ) ?>
            <a href="https://mailhawk.io/configure-dns/"
               target="_blank"><?php _e( 'Learn how to configure your DNS', 'mailhawk' ); ?></a></p>
        <p class="mailhawk-dns-handoff">
			<?php _e( 'Someone else manages your DNS, like an agency, a developer or an AI assistant?', 'mailhawk' ); ?>
            <button type="button" class="button button-secondary mailhawk-copy-button"
                    data-copy="<?php echo esc_attr( get_dns_instructions( $domain ) ); ?>"
                    data-copied="<?php esc_attr_e( 'Instructions copied', 'mailhawk' ); ?>">
                <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                <span class="mailhawk-copy-button-label"><?php _e( 'Copy instructions', 'mailhawk' ); ?></span>
            </button>
        </p>
    </div>
    <p>
        <a href="<?php echo esc_url( mailhawk_admin_page( [ 'view' => 'domains' ] ) ); ?>">&larr; <?php _e( 'Back', 'mailhawk' ); ?></a>
        <a href="<?php echo wp_nonce_url( mailhawk_admin_page( [ 'domain' => $domain->name ] ), 'mailhawk_delete_domain', '_mailhawk_nonce' ); ?>"
           class="button delete alignright"><?php _e( 'Delete', 'mailhawk' ); ?></a>
    </p>

</div>

