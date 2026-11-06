<?php

use function MailHawk\get_groundhogg_details_url;
use function MailHawk\should_suggest_groundhogg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! should_suggest_groundhogg() ) {
	return;
}

// What Groundhogg does for the person who sends with MailHawk
$benefits = [
	__( 'Send newsletters and email campaigns', 'mailhawk' ),
	__( 'Automate follow-ups with visual flows', 'mailhawk' ),
	__( 'Keep every contact, tag and note in one CRM', 'mailhawk' ),
	__( 'See who opens, clicks and visits', 'mailhawk' ),
	__( 'Stop emailing addresses that bounce or unsubscribe', 'mailhawk' ),
	__( 'Capture leads with forms on your site', 'mailhawk' ),
];

?>
<div id="mailhawk-groundhogg-promo" class="mailhawk-content-box mailhawk-groundhogg-promo">
    <div class="mailhawk-groundhogg-pitch">
        <h2><?php _e( 'Do more with the email you send', 'mailhawk' ); ?></h2>
        <p><?php printf(
				__( "MailHawk gets your email delivered. %s is the free CRM and marketing automation plugin from the makers of MailHawk, and it sends through MailHawk, so what you build there lands in the inbox.", 'mailhawk' ),
				'<a href="https://wordpress.org/plugins/groundhogg/" target="_blank" rel="noopener">Groundhogg</a>'
			); ?></p>
        <div class="mailhawk-groundhogg-actions">
            <a class="button button-primary thickbox open-plugin-details-modal"
               href="<?php echo esc_url( get_groundhogg_details_url() ); ?>"
               aria-label="<?php esc_attr_e( 'More information about Groundhogg', 'mailhawk' ); ?>"
               data-title="Groundhogg"><?php _e( 'Learn more', 'mailhawk' ); ?></a>
            <button type="button" class="mailhawk-groundhogg-dismiss"><?php _e( "Don't show this again", 'mailhawk' ); ?></button>
        </div>
    </div>
    <ul class="mailhawk-groundhogg-benefits">
		<?php foreach ( $benefits as $benefit ): ?>
            <li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php echo esc_html( $benefit ); ?></li>
		<?php endforeach; ?>
    </ul>
</div>
