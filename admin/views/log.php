<?php


use MailHawk\Admin\Email_Log_Table;
use MailHawk\Classes\Email_Log_Item;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_url_var;


$table = new Email_Log_Table();

$table->prepare_items();

?>
<div id="mailhawk-overlay" <?php if ( get_url_var( 'preview' ) ) echo 'style="display:block;"'; ?>></div>
<div id="mailhawk-modal">
	<?php if ( get_url_var( 'preview' ) ) : ?>
		<?php include __DIR__ . '/log-preview.php'; ?>
	<?php endif; ?>
</div>
<div class="mailhawk-log-wrap">
    <div class="mailhawk-table-wrap">

        <?php $table->views(); ?>
        <form method="get" class="search-form">
            <input type="hidden" name="page" value="mailhawk">
            <input type="hidden" name="view" value="log">
            <p class="search-box">
                <label class="screen-reader-text" for="post-search-input"><?php _e( 'Search Log', 'mailhawk' ); ?>
                    :</label>
                <input type="search" id="post-search-input" name="s"
                       value="<?php esc_attr_e( get_url_var( 's' ) ); ?>">
                <input type="submit" id="search-submit" class="button"
                       value="<?php esc_attr_e( 'Search', 'mailhawk' ); ?>">
            </p>
        </form>
        <form method="post">
			<?php $table->display(); ?>
        </form>
    </div>
</div>
