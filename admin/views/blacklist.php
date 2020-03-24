<?php

?>
<div class="mailhawk-connect domains">
    <p><b><?php _e( 'Add email address to blacklist:', 'mailhawk' ); ?></b></p>
    <form method="post">
		<?php wp_nonce_field( 'add_to_blacklist', '_mailhawk_nonce' ); ?>
        <input class="add-email regular-text" type="email" name="address" placeholder="spam@spammy.com" required>
        <input class="button button-secondary alignright" type="submit" value="<?php esc_attr_e( "Add" ); ?> ">
    </form>
    <p class="description"><?php _e( 'You can add new email addresses here (stored locally) and all your email will be prevented from sending to anyone on the blacklist. You can enter <code>*@domain.com</code> to blacklist all email addresses belonging to <code>domain.com</code>.', 'mailhawk' ); ?></p>
    <p><b><?php _e( 'Whitelist email address:', 'mailhawk' ); ?></b></p>
    <form method="post">
		<?php wp_nonce_field( 'add_to_whitelist', '_mailhawk_nonce' ); ?>
        <input class="remove-email regular-text" type="email" name="address"
               placeholder="<?php esc_attr_e( wp_get_current_user()->user_email ); ?>" required>
        <input class="button button-secondary alignright" type="submit" value="<?php esc_attr_e( "Add" ); ?> ">
    </form>
    <p class="description"><?php _e( 'You can whitelist an email address to prevent it from being added to the blacklist. If it is already on the blacklist it will be removed.', 'mailhawk' ); ?></p>
</div>
