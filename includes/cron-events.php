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
	 * How many log entries are deleted by one query. Entries hold the whole email, so one query for a big backlog would
	 * run long enough to be killed, and keep the table locked while it does.
	 *
	 * @return int
	 */
	protected function get_trim_batch_size() {
		return max( 1, absint( apply_filters( 'mailhawk/log_trim_batch_size', 1000 ) ) );
	}

	/**
	 * If there is no time left to go on with the cleanup in this request
	 *
	 * @param int $batches how many batches were deleted so far
	 *
	 * @return bool
	 */
	protected function out_of_time( $batches ) {
		return Limits::limits_exceeded( $batches );
	}

	/**
	 * Trim any old log entries
	 */
	public function trim_log_entries() {

		global $wpdb;

		$table      = Plugin::instance()->log->get_table_name();
		$batch_size = $this->get_trim_batch_size();

		// Quarantined emails are waiting on an admin, so they are kept longer
		$cleanups = [
			[ "`status` != 'quarantine'", get_log_cutoff( get_log_retention_days() ) ],
			[ "`status` = 'quarantine'", get_log_cutoff( get_quarantine_retention_days() ) ],
		];

		$batches = 0;
		$deleted = 0;
		$more    = false;

		Limits::start();

		foreach ( $cleanups as list( $status, $cutoff ) ) {

			do {

				$count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$status} AND `date_sent` <= %s LIMIT %d", $cutoff, $batch_size ) );

				// Nothing left, or the query failed. Either way it is not worth another try, and not "more" to come back for
				if ( ! $count ) {
					$more = false;
					break;
				}

				$deleted += $count;
				$batches ++;

				// A full batch means there is probably more of it
				$more = $count >= $batch_size;

				if ( $more && $this->out_of_time( $batches ) ) {
					break 2;
				}

			} while ( $more );
		}

		// The deletes above bypass the DB class, so what it remembered of the log is stale now
		if ( $deleted ) {
			Plugin::instance()->log->set_last_changed();
		}

		// Out of time with entries left: carry on shortly, instead of leaving them for tomorrow
		if ( $more ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mailhawk_trim_log_entries' );
		}
	}

	/**
	 * Retry any failed emails
	 */
	public function retry_failed_emails() {

		// check if retries are enabled
		if ( ! get_option( 'mailhawk_retry_failed_emails' ) || get_option( 'mailhawk_disable_email_logging' ) ) {
			return;
		}

		// The MTA asked the site to slow down: trying again now only gets the same answer
		if ( time() < mailhawk_throttled_until() ) {
			return;
		}

		$retry_attempts = get_email_retry_attempts();

		// Only the emails the MTA never took. One with a message ID was accepted, and then failed on its way to the
		// recipient: sending it again does not make the address any better, and for a dead one it hurts the reputation
		// of the sending IPs. An admin can still retry those one at a time.
		$items_to_retry = Plugin::instance()->log->query( [
			'where' => [
				'relationship' => 'AND',
				[ 'col' => 'retries', 'compare' => '<', 'val' => $retry_attempts ],
				[ 'col' => 'status', 'compare' => '=', 'val' => 'failed' ],
				[ 'col' => 'msg_id', 'compare' => '=', 'val' => '' ],
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

		} while ( ! empty( $items_to_retry ) && ! Limits::limits_exceeded( $completed ) && ! ( time() < mailhawk_throttled_until() ) );


	}
}
