<?php

?>
<div class="mailhawk-content-box domains">
	<p><b><?php _e( 'Send a test email:', 'mailhawk' ); ?></b></p>

    <form method="post">
	    <?php wp_nonce_field( 'send_test_email', '_mailhawk_nonce' ); ?>
        <p>
            <input type="text" name="subject" class="regular-text" placeholder="<?php esc_attr_e( 'Subject line...', 'mailhawk' ); ?>" value="<?php esc_attr_e( 'MailHawk is connected!', 'mailhawk' );?>" >
        </p>
        <p>
            <textarea name="content" class="regular-text" rows="4" placeholder="<?php esc_attr_e( 'Content...', 'mailhawk' ); ?>"><?php esc_html_e( 'Hello World!', 'mailhawk' ); ?></textarea>
        </p>
        <p>
            <input type="email" name="to_address" class="regular-text"
                   value="<?php esc_attr_e( wp_get_current_user()->user_email ); ?>">
            <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Send Test' ); ?>">
        </p>
    </form>
    <p class="description"><?php _e( 'Send a test email to yourself to ensure MailHawk is properly connected and your deliverability is good.', 'mailhawk' ); ?></p>

</div>
