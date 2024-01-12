<?php

namespace MailHawk;

use MailHawk\Classes\Email_Log_Item;
use MailHawk\Utils\Limits;

class Cron_Events {

	public function __construct() {
		add_action( 'init', [ $this, 'register_events' ] );

		add_action( 'mailhawk_trim_log_entries', [ $this, 'trim_log_entries' ] );
		add_action( 'mailhawk_trim_blacklist_entries', [ $this, 'trim_blacklist_entries' ] );
		add_action( 'mailhawk_retry_failed_emails', [ $this, 'retry_failed_emails' ] );
	}

	/**
	 * Register a recurring cron event.
	 *
	 * @param $hook
	 * @param $interval
	 */
	public function register_event( $hook, $interval = 'daily' ) {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $interval, $hook );
		}
	}

	/**
	 * Register the cron events...
	 */
	public function register_events() {
		$this->register_event( 'mailhawk_trim_log_entries', 'daily' );
		$this->register_event( 'mailhawk_retry_failed_emails', 'hourly' );
	}

	/**
	 * Trim any old log entries
	 */
	public function trim_log_entries() {

		global $wpdb;

		$retention_in_days = get_log_retention_days();
		$log               = Plugin::instance()->log;
		$compare_date      = date( 'Y-m-d H:i:s', strtotime( $retention_in_days . ' days ago' ) );

		$wpdb->query( "DELETE from {$log->get_table_name()} WHERE `date_sent` <= '{$compare_date}'" );
	}

	/**
	 * Trim any old blacklist entries
	 */
	public function trim_blacklist_entries() {

	}

	/**
	 * Retry any failed emails
	 */
	public function retry_failed_emails() {

		// check if retries are enabled
		if ( ! get_option( 'mailhawk_retry_failed_emails' ) || get_option( 'mailhawk_disable_email_logging' ) ) {
			return;
		}

		$retry_attempts = get_email_retry_attempts();

		$items_to_retry = Plugin::instance()->log->query( [
			'where' => [
				'relationship' => 'AND',
				[ 'col' => 'retries', 'compare' => '<', 'val' => $retry_attempts ],
				[ 'col' => 'status', 'compare' => '=', 'val' => 'failed' ],
			]
		] );

		if ( empty( $items_to_retry ) ) {
			return;
		}

		$completed = 0;

		Limits::start();

		do {

			$item = array_pop( $items_to_retry );

			$item = new Email_Log_Item( absint( $item->ID ) );

			if ( $item->exists() ) {
				$item->retry();
			}

			$completed ++;

		} while ( ! empty( $items_to_retry ) && ! Limits::limits_exceeded( $completed ) );


	}
}
