<?php

namespace MailHawk\Admin\Views;

use function MailHawk\is_notice_dismissed;
use function MailHawk\is_review_eligible;
use function MailHawk\mailhawk_admin_page;
use function MailHawk\get_url_var;

$menu_items = [
	[
		'view' => 'overview',
		'text' => __( 'Overview' ),
		'icon' => 'dashboard',
	],
	[
		'view' => 'domains',
		'text' => __( 'Domains' ),
		'icon' => 'admin-site',
	],
	[
		'view' => 'log',
		'text' => __( 'Email Log' ),
		'icon' => 'email',
	],
	[
		'view' => 'recipients',
		'text' => __( 'Recipients' ),
		'icon' => 'groups',
	],
	[
		'view' => 'test',
		'text' => __( 'Test' ),
		'icon' => 'admin-tools',
	],
	[
		'view' => 'settings',
		'text' => __( 'Settings' ),
		'icon' => 'admin-settings',
	]

];

// Asking for a review stays out of the way: a button, nothing once the customer says not to ask again, and nothing
// for a site that is not sending well
$show_review = current_user_can( 'manage_options' ) && ! is_notice_dismissed( 'review' ) && is_review_eligible();

?>
<div id="mailhawk-menu">
    <ul>
		<?php foreach ( $menu_items as $item ) : ?>

			<?php $is_active = get_url_var( 'view', 'overview' ) === $item['view']; ?>

            <li><a class="<?php echo $is_active ? 'active' : ''; ?>"
                   href="<?php echo esc_url( mailhawk_admin_page( [ 'view' => $item['view'] ] ) ); ?>"><span
                            class="dashicons dashicons-<?php esc_attr_e( $item['icon'] ); ?>"></span><?php esc_html_e( $item['text'] ); ?>
                </a>
            </li>

		<?php endforeach; ?>
    </ul>
	<?php if ( $show_review ) : ?>
        <button type="button" id="mailhawk-review-button" class="mailhawk-review-button"
                aria-haspopup="dialog" aria-controls="mailhawk-review-modal"
                aria-label="<?php esc_attr_e( 'Leave a review', 'mailhawk' ); ?>">
            <span class="mailhawk-stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><span aria-hidden="true">&#128591;</span>
        </button>
	<?php endif; ?>
</div>
<?php if ( $show_review ) : ?>
    <div id="mailhawk-review-modal" class="mailhawk-modal" role="dialog" aria-modal="true"
         aria-labelledby="mailhawk-review-title" hidden>
        <div class="mailhawk-modal-dialog">
            <div class="mailhawk-modal-header">
                <span class="mailhawk-stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><span aria-hidden="true">&#128591;</span>
                <button type="button" class="mailhawk-modal-close" aria-label="<?php esc_attr_e( 'Close', 'mailhawk' ); ?>">&times;</button>
            </div>
            <div class="mailhawk-modal-body">
                <h2 id="mailhawk-review-title"><?php _e( 'Enjoying MailHawk?', 'mailhawk' ); ?></h2>
                <p><?php _e( "MailHawk grows almost entirely through word of mouth. If we've kept your email out of the spam folder, or saved you time, we'd really appreciate a quick review.", 'mailhawk' ); ?></p>
                <p><?php printf(
					__( 'Leave a review on our %s to help spread the word!', 'mailhawk' ),
					'<a href="https://wordpress.org/support/plugin/mailhawk/reviews/#new-post" target="_blank" rel="noopener">' . esc_html__( 'WordPress.org plugin page', 'mailhawk' ) . '</a>'
				); ?></p>
                <div class="mailhawk-modal-actions">
                    <button type="button" class="mailhawk-review-never"><?php _e( "Don't ask again.", 'mailhawk' ); ?></button>
                    <button type="button" class="mailhawk-review-later"><?php _e( 'Maybe later…', 'mailhawk' ); ?></button>
                    <a class="mailhawk-review-now" href="https://wordpress.org/support/plugin/mailhawk/reviews/#new-post"
                       target="_blank" rel="noopener"><?php _e( 'Review Now!', 'mailhawk' ); ?></a>
                </div>
                <p class="mailhawk-modal-thanks"><?php _e( 'Your support is appreciated!', 'mailhawk' ); ?></p>
                <p class="mailhawk-modal-signature">&mdash; Adrian Tobey</p>
            </div>
        </div>
    </div>
<?php endif; ?>
