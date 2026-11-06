<?php

namespace MailHawk;

use MailHawk\Classes\Email_Log_Item;
use MailHawk\Utils\Limits;

class Cron_Events {

	public function __construct() {
		add_action( 'init', [ $this, 'register_events' ] );

		add_action( 'mailhawk_trim_log_entries', [ $this, 'trim_log_entries' ] );
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

		$table                = Plugin::instance()->log->get_table_name();
		$compare_date         = date( 'Y-m-d H:i:s', strtotime( get_log_retention_days() . ' days ago' ) );
		$quarantine_threshold = date( 'Y-m-d H:i:s', strtotime( get_quarantine_retention_days() . ' days ago' ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE `status` != 'quarantine' AND `date_sent` <= %s", $compare_date ) );

		// Quarantined emails are waiting on an admin, so they are kept longer
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE `status` = 'quarantine' AND `date_sent` <= %s", $quarantine_threshold ) );
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
