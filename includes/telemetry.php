<?php

namespace MailHawk;

use MailHawk\Api\Licensing;

class Telemetry {

	public function __construct() {
		add_action( 'mailhawk/wp_mail/sent', [ $this, 'increment_sent_this_month' ], 10 );
		add_action( 'init', [ $this, 'schedule_reset_monthly' ] );
		add_action( 'mailhawk/monthly', [ $this, 'reset_monthly' ] );
	}

	protected $sent = 0;
	protected $batch = 0;

	/**
	 * Increase monthly sent count
	 *
	 * @return void
	 */
	public function increment_sent_this_month( $result ) {

		if ( $result !== true ) {
			return;
		}

		if ( ! $this->sent ) {
			$this->sent = absint( get_core_option( 'mailhawk_sent_this_month' ) );
		}

		if ( ! $this->batch ) {
			$limit       = absint( get_core_option( 'mailhawk_monthly_email_limit' ) ) ?: 1000;
			$this->batch = floor( $limit * 0.05 );
		}

		$this->sent ++;

		update_core_option( 'mailhawk_sent_this_month', $this->sent );

		if ( $this->sent % $this->batch === 0 ) {
			$this->maybe_notify();
		}
	}



	/**
	 * Reset monthly stats
	 *
	 * @return void
	 */
	public function reset_monthly() {
		update_core_option( 'mailhawk_sent_this_month', 0 );
		update_core_option( 'mailhawk_warnings_this_month', [] );
	}

	/**
	 * Schedule monthly counts reset
	 *
	 * @throws \Exception
	 * @return void
	 */
	public function schedule_reset_monthly() {

		if ( ! mailhawk_is_connected() || wp_next_scheduled( 'mailhawk/monthly' ) ) {
			return;
		}

		$dateTime = new \DateTime( 'now', wp_timezone() );
		$dateTime->modify( 'first day of next month 00:00:00' );

		wp_schedule_single_event( $dateTime->getTimestamp(), 'mailhawk/monthly' );
	}

	/**
	 * Check to see if usage has hit a specific threshold
	 *
	 * @return void
	 */
	public function maybe_notify() {

		$usage = get_email_usage();

		if ( ! $usage ) {
			return;
		}

		$percent_usage = $usage['percent'];

		$already_received = get_core_option( 'mailhawk_warnings_this_month', [] );

		$thresholds = [
			50,
			75,
			90,
			95,
			100,
		];

		foreach ( $thresholds as $threshold ) {
			if ( $percent_usage >= $threshold && ! in_array( $threshold, $already_received ) ) {

				$this->notify();

				$already_received[] = $threshold;

				update_core_option( 'mailhawk_warnings_this_month', $already_received );
				break;
			}
		}

	}

	/**
	 * Send telemetry of current usage
	 *
	 * @return void
	 */
	public function notify() {
		$result = Licensing::instance()->request( '/wp-json/gh/v4/webhooks/60-telemetry?token=njXRHzg', [
			'usage' => get_email_usage(),
			'url'   => home_url(),
		] );
	}

}
