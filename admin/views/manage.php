<?php

?>
<div class="mailhawk-connect">

    <p><?php _e( 'You are connected to MailHawk! We are ensuring the safe delivery of your email to your customers\'s inbox.', 'mailhawk' ); ?></p>
    <p><a href="#" class="button button-secondary"><?php _e( 'My Account', 'mailhawk' ); ?></a></p>

    <p><b><?php _e( 'Status:', 'mailhawk' ); ?></b></p>
    <p><textarea name="status" id="mailhawk-status" class="code" onfocus="this.select()"
                 readonly><?php esc_html_e( $this->get_status() ); ?></textarea></p>

</div>