<?php


use MailHawk\Admin\Email_Log_Table;
use function MailHawk\get_url_var;


$table = new Email_Log_Table();

$table->prepare_items();

?>

<div class="mailhawk-log-wrap">
    <div class="mailhawk-log-right">
        <div class="mailhawk-table-wrap">
            <form method="get" class="search-form">
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
    <div class="mailhawk-log-left">
        <div class="mailhawk-content-box">

        </div>
    </div>
</div>
