<?php

use MailHawk\Classes\Email_Log_Item;
use function MailHawk\get_request_var;
use function MailHawk\mailhawk_admin_page;

$log_item_id = absint( get_request_var( 'preview' ) );

$log_item = new Email_Log_Item( $log_item_id );

$recipients = [];

foreach ( $log_item->recipients as $recipient ) {

	if ( ! is_email( $recipient ) ) {
		continue;
	}

	$recipients[] = sprintf( '<a class="recipient-link" href="%2$s">%1$s</a>', $recipient, mailhawk_admin_page( [
		's'             => $recipient,
		'search_column' => 'recipients',
		'view'          => 'log'
	] ) );
}

$release_url = wp_nonce_url( mailhawk_admin_page( [
	'view'    => 'log',
	'status' => 'quarantine',
	'id'      => $log_item_id
] ), 'retry_email', '_mailhawk_nonce' );

$reject_url = wp_nonce_url( mailhawk_admin_page( [
	'view'    => 'log',
	'status' => 'quarantine',
	'id'      => $log_item_id
] ), 'reject_email', '_mailhawk_nonce' );

?>
<div class="mailhawk-log-preview">
    <div class="mailhawk-content-box">
        <div class="close">
            <span class="dashicons dashicons-dismiss"></span>
        </div>

		<?php if ( $log_item->exists() ): ?>

            <p><?php printf( __( 'Sent to %s on %s.', 'mailhawk' ), \MailHawk\andList( $recipients ), '<abbr title="' . $log_item->get_date_sent()->format( 'Y-m-d H:i:s' ) . '">' .  $log_item->get_date_sent()->format( \MailHawk\get_date_time_format() ) . '</abbr>' ) ?></p>

			<?php if ( $log_item->is_quarantined() ): ?>

                <div class="mailhawk-warning">
                    <p><?php _e( 'This email was quarantined because sending it might damage your sender reputation.', 'mailhawk' ); ?></p>
                    <p><?php _e( 'MailHawk evaluated the recipient as <b>high risk</b>, meaning a bounce or complaint is likely.', 'mailhawk' ); ?></p>
                    <p><?php printf( __( 'You can <b><a href="%1$s">release it</a></b> and it will send now, or <b><a href="%2$s">reject it</a></b> so it won\'t send.', 'mailhawk' ), $release_url, $reject_url ); ?></p>
                </div>

			<?php elseif ( $log_item->error_code || $log_item->error_message ): ?>

                <div class="mailhawk-error">
                    <div class="error-explanation">
						<?php _e( 'There was a problem sending this email:', 'mailhawk' ); ?>
                    </div>
                    <div class="mailhawk-error-details">
                        <span class="mailhawk-error-code"><?php echo $log_item->error_code; ?>:</span>
                        <span class="mailhawk-error-message"><?php echo $log_item->error_message; ?></span>
                    </div>
                </div>

			<?php endif; ?>

            <div class="mh-email-preview">
                <div class="mh-email-preview-header">
					<?php echo get_avatar( $log_item->from_address, 47 ); ?>
                    <div>
                        <h3 class="mh-email-preview-subject"><?php esc_html_e( $log_item->subject ); ?></h3>
                        <p class="mh-email-preview-from"><?php esc_html_e( $log_item->get_from_header() ); ?></p>
                    </div>
                </div>
                <iframe id="email-body-iframe" height="500"></iframe>
                <script>
                  ( () => {
                    let email = <?php echo wp_json_encode( [ 'content' => $log_item->get_preview_content() ] ); ?>;
                    let blob = new Blob([email.content], { type: 'text/html; charset=utf-8' })
                    let src = URL.createObjectURL(blob)
                    document.getElementById('email-body-iframe').src = src
                  } )()
                </script>
            </div>
            <div id="headers">
                <h3><?php _e( 'Headers', 'mailhawk' ); ?></h3>

				<?php
				$headers = "";
				foreach ( $log_item->headers as $header ):
					$headers .= sprintf( "%s: %s\n", $header[0], $header[1] );
				endforeach; ?>
                <pre class="code"><?php esc_html_e( $headers ); ?></pre>
            </div>
		<?php endif; ?>
    </div>
</div>
