<?php

use MailHawk\DB\Recipients;
use MailHawk\Plugin;
use function MailHawk\get_groundhogg_details_url;
use function MailHawk\mailhawk_admin_page;
use function MailHawk\should_suggest_groundhogg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$recipients_db = Plugin::instance()->recipients;
$rows          = $recipients_db->is_ready() ? $recipients_db->query( [ 'LIMIT' => 200, 'ORDER_BY' => 'date_updated' ], '', false ) : [];

?>
<div class="mailhawk-content-box">
    <p><b><?php _e( 'Trust an email address:', 'mailhawk' ); ?></b></p>
    <form method="post">
		<?php wp_nonce_field( 'trust_recipient', '_mailhawk_nonce' ); ?>
        <input class="regular-text" type="email" name="address" placeholder="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
        <input class="button button-secondary" type="submit" value="<?php esc_attr_e( 'Trust', 'mailhawk' ); ?>">
    </form>
    <p class="description"><?php _e( 'Emails to a trusted address are never quarantined. You can also trust the recipients of a quarantined email when you release it. An address that has reported your email as spam can not be trusted.', 'mailhawk' ); ?></p>
</div>
<?php

// Only worth a mention when there is trouble on the list: an address that bounces or reported spam
$has_trouble = false;

foreach ( $rows as $row ) {
	if ( Recipients::TRUSTED !== $row->status ) {
		$has_trouble = true;
		break;
	}
}

?>
<?php if ( $has_trouble && should_suggest_groundhogg() ) : ?>
    <div id="mailhawk-groundhogg-card" class="mailhawk-content-box mailhawk-groundhogg-card">
        <div class="mailhawk-groundhogg-text">
            <p><b><?php _e( 'Bouncing addresses are a list problem', 'mailhawk' ); ?></b></p>
            <p class="description"><?php printf(
					__( 'MailHawk holds email to addresses that keep bouncing, but they stay on your list. %s, the free CRM from the makers of MailHawk, keeps track of bounces, spam reports and unsubscribes for each contact, and stops emailing the ones who should not get it.', 'mailhawk' ),
					'<a href="https://wordpress.org/plugins/groundhogg/" target="_blank" rel="noopener">Groundhogg</a>'
				); ?></p>
        </div>
        <div class="mailhawk-groundhogg-actions">
            <a id="groundhogg-learn-more" class="button button-secondary thickbox open-plugin-details-modal"
               href="<?php echo esc_url( get_groundhogg_details_url() ); ?>"
               aria-label="<?php esc_attr_e( 'More information about Groundhogg', 'mailhawk' ); ?>"
               data-title="Groundhogg"><?php _e( 'Learn more', 'mailhawk' ); ?></a>
            <button type="button" class="mailhawk-groundhogg-dismiss"><?php _e( "Don't show this again", 'mailhawk' ); ?></button>
        </div>
    </div>
<?php endif; ?>
<div class="mailhawk-content-box recipients">
    <p><b><?php _e( 'What MailHawk has learned about your recipients', 'mailhawk' ); ?></b></p>
    <p class="description"><?php _e( 'Addresses appear here when you trust them, or when they bounce or report your email as spam. Emails to an address that keeps bouncing are quarantined, and emails to an address that reported spam are always quarantined.', 'mailhawk' ); ?></p>

	<?php if ( ! $recipients_db->is_ready() ): ?>
        <p><?php _e( 'The recipients table has not been created yet. Run the database update from the notice on your Plugins or Dashboard screen.', 'mailhawk' ); ?></p>
	<?php elseif ( empty( $rows ) ): ?>
        <p><?php _e( 'Nothing to show yet.', 'mailhawk' ); ?></p>
	<?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
            <tr>
                <th><?php _e( 'Address', 'mailhawk' ); ?></th>
                <th><?php _e( 'Status', 'mailhawk' ); ?></th>
                <th><?php _e( 'Bounces', 'mailhawk' ); ?></th>
                <th><?php _e( 'Spam reports', 'mailhawk' ); ?></th>
                <th><?php _e( 'Updated', 'mailhawk' ); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
			<?php foreach ( $rows as $row ):

				switch ( $row->status ) {
					case Recipients::TRUSTED:
						$status = __( 'Trusted', 'mailhawk' );
						$tag    = 'yes';
						break;
					case Recipients::BLOCKED:
						$status = __( 'Reported spam', 'mailhawk' );
						$tag    = 'no';
						break;
					default:
						$status = __( 'Watching', 'mailhawk' );
						$tag    = 'watching';
						break;
				}

				$forget_url = wp_nonce_url( mailhawk_admin_page( [
					'view'      => 'recipients',
					'recipient' => $row->ID,
				] ), 'forget_recipient_' . $row->ID, '_mailhawk_nonce' );

				?>
                <tr>
                    <td><?php echo esc_html( $row->email ); ?></td>
                    <td><span class="tag <?php echo esc_attr( $tag ); ?>"><?php echo esc_html( $status ); ?></span></td>
                    <td><?php echo esc_html( number_format_i18n( absint( $row->bounces ) ) ); ?></td>
                    <td><?php echo esc_html( number_format_i18n( absint( $row->complaints ) ) ); ?></td>
                    <td><?php echo esc_html( $row->date_updated ); ?></td>
                    <td>
						<?php if ( ! $recipients_db->has_reported_spam( $row ) ): ?>
                            <a class="button button-secondary" href="<?php echo esc_url( $forget_url ); ?>"><?php _e( 'Remove', 'mailhawk' ); ?></a>
						<?php endif; ?>
                    </td>
                </tr>
			<?php endforeach; ?>
            </tbody>
        </table>
	<?php endif; ?>
</div>
