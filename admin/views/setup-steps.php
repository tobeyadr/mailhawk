<?php
/**
 * The progress through connecting, shared by the setup screens.
 *
 * @var int $mailhawk_step the step the customer is on, 2 or 3, or 4 when every step is done
 */

$steps = [
	1 => [ __( 'Connected', 'mailhawk' ), __( 'Connect', 'mailhawk' ) ],
	2 => [ __( 'Domains registered', 'mailhawk' ), __( 'Register your domains', 'mailhawk' ) ],
	3 => [ __( 'Domains authenticated', 'mailhawk' ), __( 'Authenticate your domains', 'mailhawk' ) ],
];

?>
<ol class="mailhawk-steps">
	<?php foreach ( $steps as $number => $labels ) : ?>
		<?php if ( $number < $mailhawk_step ) : ?>
            <li class="done"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html( $labels[0] ); ?></li>
		<?php else : ?>
            <li class="<?php echo $number === $mailhawk_step ? 'current' : ''; ?>"><span class="mailhawk-step-number" aria-hidden="true"><?php echo (int) $number; ?></span><?php echo esc_html( $labels[1] ); ?></li>
		<?php endif; ?>
	<?php endforeach; ?>
</ol>
