<?php

namespace MailHawk\Admin\Views;

use function MailHawk\get_admin_mailhawk_uri;
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
//	[
//		'view' => 'blacklist',
//		'text' => __( 'Blacklist' ),
//		'icon' => 'shield',
//	],
	[
		'view' => 'log',
		'text' => __( 'Email Log' ),
		'icon' => 'email',
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

?>
<div id="mailhawk-menu">
    <ul>
		<?php foreach ( $menu_items as $item ) : ?>

			<?php $is_active = get_url_var( 'view' ) === $item['view']; ?>

            <li><a class="<?php echo $is_active ? 'active' : ''; ?>"
                   href="<?php echo esc_url( get_admin_mailhawk_uri( [ 'view' => $item['view'] ] ) ); ?>"><span
                            class="dashicons dashicons-<?php esc_attr_e( $item['icon'] ); ?>"></span><?php esc_html_e( $item['text'] ); ?>
                </a>
            </li>

		<?php endforeach; ?>
    </ul>
</div>
