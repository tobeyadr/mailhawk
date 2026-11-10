<?php

use MailHawk\Api\Postal\Domains;
use function MailHawk\get_array_var;
use function MailHawk\get_default_from_email_address;
use function MailHawk\get_test_email_faq;
use function MailHawk\get_test_from_addresses;
use function MailHawk\mailhawk_admin_page;

// The domains MailHawk knows, to suggest addresses to send from and to warn about ones that are not authenticated
$domains = Domains::query_all();
$from    = get_test_from_addresses( is_wp_error( $domains ) ? [] : $domains );

// What was sent last stays, the default from address the first time
$from_address = get_array_var( $this->test_data, 'from' ) ?: strtolower( (string) get_default_from_email_address() );

?>
<div class="mailhawk-content-box domains mailhawk-test-email">
	<p><b><?php _e( 'Send a test email:', 'mailhawk' ); ?></b></p>

    <form method="post">
	    <?php wp_nonce_field( 'send_test_email', '_mailhawk_nonce' ); ?>
		<?php

		// The default first, then the addresses on verified domains, then the rest
		$groups = [ 'default' => [], 'verified' => [], 'unverified' => [] ];

		foreach ( $from['addresses'] as $address ) {
			$groups[ $address['default'] ? 'default' : ( $address['authenticated'] ? 'verified' : 'unverified' ) ][] = $address['email'];
		}

		$suggested = in_array( $from_address, wp_list_pluck( $from['addresses'], 'email' ), true );

		?>
        <p>
            <label for="mailhawk-test-from-select"><?php _e( 'From', 'mailhawk' ); ?></label><br>
            <select id="mailhawk-test-from-select" class="regular-text" hidden>
				<?php foreach ( $groups['default'] as $email ) : ?>
                    <option value="<?php echo esc_attr( $email ); ?>" <?php selected( $email, $from_address ); ?>><?php
						/* translators: %s: the default from email address */
						echo esc_html( sprintf( __( '%s (default)', 'mailhawk' ), $email ) );
						?></option>
				<?php endforeach; ?>
				<?php if ( $groups['verified'] ) : ?>
                    <optgroup label="<?php esc_attr_e( 'Verified domains', 'mailhawk' ); ?>">
						<?php foreach ( $groups['verified'] as $email ) : ?>
                            <option value="<?php echo esc_attr( $email ); ?>" <?php selected( $email, $from_address ); ?>><?php echo esc_html( $email ); ?></option>
						<?php endforeach; ?>
                    </optgroup>
				<?php endif; ?>
				<?php if ( $groups['unverified'] ) : ?>
                    <optgroup label="<?php esc_attr_e( 'Domains that are not verified', 'mailhawk' ); ?>">
						<?php foreach ( $groups['unverified'] as $email ) : ?>
                            <option value="<?php echo esc_attr( $email ); ?>" <?php selected( $email, $from_address ); ?>><?php echo esc_html( $email ); ?></option>
						<?php endforeach; ?>
                    </optgroup>
				<?php endif; ?>
                <option value="" <?php selected( ! $suggested ); ?>><?php _e( 'Other address&hellip;', 'mailhawk' ); ?></option>
            </select>
			<?php // What is sent. Without JavaScript it is the field, with it the select fills it in and it is only shown for another address. ?>
            <input type="email" name="from_address" id="mailhawk-test-from" class="regular-text"
                   aria-label="<?php esc_attr_e( 'From address', 'mailhawk' ); ?>"
                   placeholder="<?php esc_attr_e( 'name@yourdomain.com', 'mailhawk' ); ?>"
                   value="<?php echo esc_attr( $from_address ); ?>"
                   data-domains="<?php echo esc_attr( wp_json_encode( (object) $from['domains'] ) ); ?>">
        </p>
        <div class="mailhawk-test-from-warning notice notice-warning inline" hidden
             data-unverified="<?php
				/* translators: %s: the domain of the from address */
				echo esc_attr__( 'The DNS records of %s are not all verified, so this email may go to spam or be refused.', 'mailhawk' );
				?>"
             data-unknown="<?php
				/* translators: %s: the domain of the from address */
				echo esc_attr__( '%s is not one of your MailHawk domains, so this email can not be authenticated and may go to spam or be refused.', 'mailhawk' );
				?>"
             data-dns-url="<?php echo esc_attr( mailhawk_admin_page( [ 'view' => 'domains', 'domain' => '%s' ] ) ); ?>"
             data-domains-url="<?php echo esc_attr( mailhawk_admin_page( [ 'view' => 'domains' ] ) ); ?>">
            <p>
                <span class="mailhawk-test-from-warning-text"></span>
                <a class="mailhawk-test-from-warning-link" href="#"
                   data-unverified="<?php esc_attr_e( 'Check the DNS records', 'mailhawk' ); ?>"
                   data-unknown="<?php esc_attr_e( 'Add the domain', 'mailhawk' ); ?>"></a>
            </p>
        </div>
        <p>
            <label for="mailhawk-test-to"><?php _e( 'To', 'mailhawk' ); ?></label><br>
            <input type="email" name="to_address" id="mailhawk-test-to" class="regular-text"
                   value="<?php esc_attr_e( wp_get_current_user()->user_email ); ?>">
            <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Send Test' ); ?>">
        </p>
    </form>
    <p class="description"><?php _e( 'MailHawk sends a short email that confirms the site can send, with tips on what it means for where it lands. Send it to an address of your own at Gmail, Outlook or wherever your customers are, and see where it ends up.', 'mailhawk' ); ?></p>

</div>
<div class="mailhawk-content-box mailhawk-test-faq">
    <p><b><?php _e( 'Where did it land?', 'mailhawk' ); ?></b></p>
	<?php foreach ( get_test_email_faq( true ) as $item ) : ?>
        <details>
            <summary><?php echo esc_html( $item['question'] ); ?></summary>
            <p><?php echo wp_kses( $item['answer'], [ 'a' => [ 'href' => true ], 'b' => [], 'i' => [] ] ); ?></p>
        </details>
	<?php endforeach; ?>
</div>
