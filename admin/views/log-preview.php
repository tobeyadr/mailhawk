<?php

use MailHawk\Classes\Email_Log_Item;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_request_var;

$log_item_id = absint( get_request_var( 'preview' ) );

$log_item = new Email_Log_Item( $log_item_id );

?>
<div class="mailhawk-log-preview">
    <div class="mailhawk-content-box">
        <div class="close">
            <span class="dashicons dashicons-dismiss"></span>
        </div>

		<?php if ( $log_item->exists() ): ?>

            <div id="subject">
				<?php esc_html_e( $log_item->subject ); ?>
            </div>

			<?php if ( $log_item->error_code || $log_item->error_message ): ?>

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

            <div id="recipients">

				<?php

				$links = [];

				foreach ( $log_item->recipients as $recipient ) {

					if ( ! is_email( $recipient ) ) {
						continue;
					}

					$links[] = sprintf( '<a href="mailto:%1$s">%1$s</a>', $recipient );

				}

				?>

				<?php printf( __( 'To: %s', 'mailhawk' ), implode( ', ', $links ) ); ?>
            </div>
            <div id="from">
				<?php printf( __( 'From: %s', 'mailhawk' ), esc_html( $log_item->from_address ) ); ?>
            </div>
            <div id="content">
                <iframe id="body-iframe"
                        src="<?php echo wp_nonce_url( get_admin_mailhawk_uri( [ 'preview' => $log_item->get_id() ] ), 'preview_email', '_mailhawk_nonce' ); ?>"></iframe>
            </div>
            <div id="headers">
                <h2><?php _e( 'Headers', 'mailhawk' ); ?></h2>

				<?php
				$headers = "";
				foreach ( $log_item->headers as $header ):
					$headers .= sprintf( "%s: %s\n", $header[0], $header[1] );
				endforeach; ?>

                <textarea class="code" readonly rows="4"><?php esc_html_e( $headers ); ?></textarea>

            </div>
            <div id="raw">
                <h2><?php _e( 'Raw/Original', 'mailhawk' ); ?></h2>

                <textarea class="code" readonly rows="8"
                          onfocus="this.select()"><?php esc_html_e( $log_item->raw ); ?></textarea>

            </div>

		<?php endif; ?>

    </div>
</div>
