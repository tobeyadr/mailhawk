<?php

use MailHawk\Quarantine;
use function MailHawk\mailhawk_admin_page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$report = Quarantine::get_report();

$log_url        = mailhawk_admin_page( [ 'view' => 'log', 'status' => 'quarantine' ] );
$recipients_url = mailhawk_admin_page( [ 'view' => 'recipients' ] );

// Each thing the administrator should act on, with where to do it
$actions = [];

if ( $report['waiting'] > 0 ) {
	$actions[] = [
		'level' => 'warning',
		'text'  => sprintf( _n( '%s email is waiting for your review. It is not sent until you release it.', '%s emails are waiting for your review. They are not sent until you release them.', $report['waiting'], 'mailhawk' ), '<b>' . number_format_i18n( $report['waiting'] ) . '</b>' ),
		'url'   => $log_url,
		'label' => __( 'Review emails', 'mailhawk' ),
	];
}

if ( $report['expiring'] > 0 ) {
	$actions[] = [
		'level' => 'error',
		'text'  => sprintf( _n( '%1$s email will be rejected automatically within %2$s days.', '%1$s emails will be rejected automatically within %2$s days.', $report['expiring'], 'mailhawk' ), '<b>' . number_format_i18n( $report['expiring'] ) . '</b>', number_format_i18n( $report['expiring_days'] ) ),
		'url'   => $log_url,
		'label' => __( 'Review before they expire', 'mailhawk' ),
	];
}

if ( $report['blocked'] > 0 ) {
	$actions[] = [
		'level' => 'error',
		'text'  => sprintf( _n( '%s address reported your email as spam. Email to it is always held.', '%s addresses reported your email as spam. Email to them is always held.', $report['blocked'], 'mailhawk' ), '<b>' . number_format_i18n( $report['blocked'] ) . '</b>' ),
		'url'   => $recipients_url,
		'label' => __( 'See the addresses', 'mailhawk' ),
	];
}

if ( $report['bouncing'] > 0 ) {
	$actions[] = [
		'level' => 'warning',
		'text'  => sprintf( _n( '%s address has bounced recently. More bounces and email to it is held.', '%s addresses have bounced recently. More bounces and email to them is held.', $report['bouncing'], 'mailhawk' ), '<b>' . number_format_i18n( $report['bouncing'] ) . '</b>' ),
		'url'   => $recipients_url,
		'label' => __( 'See the addresses', 'mailhawk' ),
	];
}

// With nothing to act on there is nothing to show, the section only appears when it has something to say
if ( empty( $actions ) ) {
	return;
}

?>
<div class="mailhawk-content-box mailhawk-quarantine-report">
    <h2><span class="quarantine-alert" aria-hidden="true">&#128680;</span> <?php _e( 'Quarantine', 'mailhawk' ); ?></h2>

    <ul class="quarantine-actions">
		<?php foreach ( $actions as $action ): ?>
            <li class="<?php echo esc_attr( $action['level'] ); ?>">
                <span class="dashicons dashicons-<?php echo 'error' === $action['level'] ? 'warning' : 'flag'; ?>"></span>
                <span class="action-text"><?php echo wp_kses( $action['text'], [ 'b' => [] ] ); ?></span>
                <a class="button button-secondary" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
            </li>
		<?php endforeach; ?>
    </ul>

	<?php if ( ! empty( $report['items'] ) ): ?>
        <h3><?php _e( 'Closest to being rejected', 'mailhawk' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
            <tr>
                <th><?php _e( 'Subject', 'mailhawk' ); ?></th>
                <th><?php _e( 'To', 'mailhawk' ); ?></th>
                <th><?php _e( 'Held', 'mailhawk' ); ?></th>
                <th><?php _e( 'Rejected in', 'mailhawk' ); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
			<?php foreach ( $report['items'] as $item ):

				$to = array_slice( $item['recipients'], 0, 2 );
				$more = count( $item['recipients'] ) - count( $to );

				$preview_url = mailhawk_admin_page( [
					'view'    => 'log',
					'status'  => 'quarantine',
					'preview' => $item['id'],
				] );

				?>
                <tr>
                    <td><?php echo esc_html( $item['subject'] ); ?></td>
                    <td>
						<?php
						echo esc_html( implode( ', ', $to ) );

						if ( $more > 0 ) {
							echo ' ' . esc_html( sprintf( __( '+%s more', 'mailhawk' ), number_format_i18n( $more ) ) );
						}
						?>
                    </td>
                    <td><?php echo esc_html( sprintf( __( '%s ago', 'mailhawk' ), human_time_diff( $item['held_at'] ) ) ); ?></td>
                    <td><?php echo $item['expires_at'] > time() ? esc_html( human_time_diff( $item['expires_at'] ) ) : esc_html__( 'Soon', 'mailhawk' ); ?></td>
                    <td><a class="button button-secondary" href="<?php echo esc_url( $preview_url ); ?>"><?php _e( 'Review', 'mailhawk' ); ?></a></td>
                </tr>
			<?php endforeach; ?>
            </tbody>
        </table>
	<?php endif; ?>

    <p class="quarantine-links">
        <a href="<?php echo esc_url( $log_url ); ?>"><?php _e( 'All quarantined emails', 'mailhawk' ); ?></a>
        &middot;
        <a href="<?php echo esc_url( $recipients_url ); ?>"><?php printf( _n( 'Recipients (%s trusted)', 'Recipients (%s trusted)', $report['trusted'], 'mailhawk' ), number_format_i18n( $report['trusted'] ) ); ?></a>
        &middot;
        <a href="https://mailhawk.io/documentation/email-quarantine/" target="_blank" rel="noopener"><?php _e( 'How quarantine works', 'mailhawk' ); ?></a>
    </p>
</div>
<div class="wp-clearfix"></div>
