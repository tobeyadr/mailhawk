<?php

namespace MailHawk;

use MailHawk\Api\Postal\Messages;
use MailHawk\Api\Webhook_Listener;
use MailHawk\Utils\Limits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catches the email log up on emails the MTA has news about that the webhooks did not bring.
 *
 * The webhooks stay the way the log learns what happened to an email, they are quick and the MTA does the work. They are
 * not the whole story: an event can be lost, and the delivered event is not subscribed to because one request per email
 * would be too much for a site after a big send. So every so often the emails that are still waiting at "sent" or
 * "delayed" are looked up in the MTA, in batches, at the pace of this site. Nothing here is on a page load.
 *
 * An MTA that can not do it (yet) is left alone for a good while, quietly, and the webhooks carry on as before.
 */
class Delivery_Sync {

	const HOOK = 'mailhawk_sync_delivery_status';

	/**
	 * A transient that is there while the MTA should be left alone, with why
	 */
	const PAUSE = 'mailhawk_delivery_sync_pause';

	/**
	 * The ID of the last log entry that was looked up, so the next run carries on from there and the entries that are
	 * not going anywhere (still queued, held) do not keep the newer ones from being looked at
	 */
	const CURSOR = 'mailhawk_delivery_sync_cursor';

	/**
	 * What the MTA says, as the status it becomes in the log, and how far along it is: the furthest along is the one
	 * that counts for an email with more than one recipient
	 *
	 * @var int[]
	 */
	const MTA_RANK = [
		'queued'    => 0,
		'held'      => 0,
		'delayed'   => 1,
		'delivered' => 2,
		'failed'    => 3,
		'bounced'   => 4,
	];

	public function __construct() {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
		add_action( 'init', [ $this, 'register_event' ] );
		add_action( self::HOOK, [ $this, 'sync' ] );
	}

	/**
	 * @param array $schedules
	 *
	 * @return array
	 */
	public function add_schedule( $schedules ) {

		$schedules['mailhawk_fifteen_minutes'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'mailhawk' ),
		];

		return $schedules;
	}

	public function register_event() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'mailhawk_fifteen_minutes', self::HOOK );
		}
	}

	/**
	 * Whether to ask the MTA at all right now
	 *
	 * @return bool
	 */
	protected function can_run() {
		return mailhawk_is_connected()
		       && ! mailhawk_is_suspended()
		       && ! get_option( 'mailhawk_disable_email_logging' )
		       && false === get_transient( self::PAUSE );
	}

	/**
	 * If there is no time left to go on in this request
	 *
	 * @param int $requests how many requests were made so far
	 *
	 * @return bool
	 */
	protected function out_of_time( $requests ) {
		return Limits::limits_exceeded( $requests );
	}

	/**
	 * Leave the MTA alone for a while, and say nothing about it to anyone: this is a catch up, so the site is fine
	 * without it. A site that wants to know can listen for the action.
	 *
	 * @param \WP_Error $error
	 */
	protected function back_off( \WP_Error $error ) {

		switch ( $error->get_error_code() ) {

			// For as long as the MTA says
			case 'mailhawk_rate_limited':
				$data = (array) $error->get_error_data();
				$wait = min( max( 1, absint( $data['retry_after'] ?? 0 ) ), HOUR_IN_SECONDS );
				break;

			// Until the MTA is upgraded, which does not need an update of this plugin
			case 'mailhawk_unsupported':
				$wait = max( MINUTE_IN_SECONDS, absint( apply_filters( 'mailhawk/delivery_sync_unsupported_ttl', 6 * HOUR_IN_SECONDS ) ) );
				break;

			default:
				$wait = 30 * MINUTE_IN_SECONDS;
		}

		set_transient( self::PAUSE, $error->get_error_code(), $wait );

		/**
		 * The sync of delivery statuses with the MTA could not go on
		 *
		 * @param \WP_Error $error
		 * @param int       $wait seconds until it tries again
		 */
		do_action( 'mailhawk/delivery_sync_failed', $error, $wait );
	}

	/**
	 * The log entries to look up: sent and delayed ones the MTA gave an ID, not so new that the webhooks may still be on
	 * their way, and not so old that the MTA has probably forgotten them.
	 *
	 * @param int $after only entries with an ID above this
	 * @param int $limit
	 *
	 * @return object[]
	 */
	protected function get_rows( $after, $limit ) {

		global $wpdb;

		$table  = Plugin::instance()->log->get_table_name();
		$newest = wp_date( 'Y-m-d H:i:s', time() - absint( apply_filters( 'mailhawk/delivery_sync_min_age', 15 * MINUTE_IN_SECONDS ) ) );
		$oldest = wp_date( 'Y-m-d H:i:s', time() - absint( apply_filters( 'mailhawk/delivery_sync_max_age', 7 * DAY_IN_SECONDS ) ) );

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT `ID`, `msg_id`, `recipients`, `status` FROM {$table}
			WHERE `status` IN ( 'sent', 'softfail' ) AND `msg_id` != '' AND `ID` > %d AND `date_sent` <= %s AND `date_sent` >= %s
			ORDER BY `ID` ASC LIMIT %d",
			absint( $after ), $newest, $oldest, absint( $limit )
		) );
	}

	/**
	 * Look up the emails that are still waiting, a batch at a time, for as many batches as there is room for in a run.
	 *
	 * @return array what was done: requests, checked, updated
	 */
	public function sync() {

		$done = [ 'requests' => 0, 'checked' => 0, 'updated' => 0 ];

		if ( ! $this->can_run() ) {
			return $done;
		}

		Limits::start();

		$cursor       = absint( get_option( self::CURSOR, 0 ) );
		$max_requests = max( 1, absint( apply_filters( 'mailhawk/delivery_sync_max_requests', 10 ) ) );
		$more         = false;

		while ( $done['requests'] < $max_requests ) {

			$rows = $this->get_rows( $cursor, Messages::MAX_IDS );

			// Everything was looked at, start from the top next time
			if ( empty( $rows ) ) {
				$cursor = 0;
				$more   = false;
				break;
			}

			$statuses = Messages::statuses( array_values( array_unique( wp_list_pluck( $rows, 'msg_id' ) ) ) );

			$done['requests'] ++;

			if ( is_wp_error( $statuses ) ) {
				$this->back_off( $statuses );
				$more = false;
				break;
			}

			$done['checked'] += count( $rows );
			$done['updated'] += $this->apply( $rows, $statuses );

			$cursor = absint( end( $rows )->ID );

			// A short batch was the last of them
			if ( count( $rows ) < Messages::MAX_IDS ) {
				$cursor = 0;
				$more   = false;
				break;
			}

			$more = true;

			if ( $this->out_of_time( $done['requests'] ) ) {
				break;
			}
		}


		update_option( self::CURSOR, $cursor, false );

		// There is more, carry on shortly instead of waiting for the next run
		if ( $more ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}

		return $done;
	}

	/**
	 * Put what the MTA said in the log
	 *
	 * @param object[] $rows
	 * @param array    $statuses from Messages::statuses()
	 *
	 * @return int how many entries were changed
	 */
	protected function apply( array $rows, array $statuses ) {

		$updated = 0;

		foreach ( $rows as $row ) {

			$msg_id = (string) $row->msg_id;

			// The MTA has no answer, or does not have the email any more: leave the entry as it is
			if ( empty( $statuses[ $msg_id ]['found'] ) ) {
				continue;
			}

			$outcome = self::summarize( self::get_recipients( $row ), $statuses[ $msg_id ]['recipients'] );

			if ( ! $outcome ) {
				continue;
			}

			// Never takes an email back from where it got to, a spam report only ever comes from a webhook
			if ( true !== Webhook_Listener::advance_delivery( $msg_id, $outcome['status'], $outcome['code'], $outcome['message'] ) ) {
				continue;
			}

			$updated ++;

			// What the webhook would have said about an address that does not take email, for the risk of sending to it.
			// Only for the one that moved the entry on: the entry is what tells whether it was said already, so a webhook
			// that got there first has said it, and one that comes after will find the entry moved and stay quiet.
			foreach ( $outcome['hard'] as $address ) {
				/** This action is documented in Webhook_Listener::handle_event() */
				do_action( 'mailhawk/bounced', $address, $msg_id );
			}
		}

		return $updated;
	}

	/**
	 * Who the email in the log went to
	 *
	 * @param object $row
	 *
	 * @return string[]
	 */
	protected static function get_recipients( $row ) {

		$recipients = maybe_unserialize( $row->recipients );

		if ( ! is_array( $recipients ) ) {
			$recipients = [ $recipients ];
		}

		$recipients = array_filter( $recipients, 'is_string' );

		return array_values( array_unique( array_map( function ( $address ) {
			return strtolower( trim( $address ) );
		}, $recipients ) ) );
	}

	/**
	 * What the MTA said about the recipients of an email adds up to one status for the entry in the log.
	 *
	 * Recipients are matched by address. An address the log does not have is not this entry's, and one the MTA said
	 * nothing about is not done yet. Any that did not get the email decides it, the worst of them first. It is only
	 * delivered when it was to everyone, and anything else is not news.
	 *
	 * @param string[] $recipients who the entry is for, lower case
	 * @param array[]  $entries    what the MTA said, one for each recipient ( rcpt_to, status, time, details )
	 *
	 * @return array|null status, code and message to put in the log, and the addresses whose server would not take the
	 *                    email, or null when there is nothing to put in the log
	 */
	public static function summarize( array $recipients, array $entries ) {

		$by_address = [];

		foreach ( $entries as $entry ) {

			$address = $entry['rcpt_to'];

			if ( ! isset( $by_address[ $address ] ) || self::MTA_RANK[ $entry['status'] ] > self::MTA_RANK[ $by_address[ $address ]['status'] ] ) {
				$by_address[ $address ] = $entry;
			}
		}

		$matched = [];

		foreach ( $recipients as $address ) {
			if ( isset( $by_address[ $address ] ) ) {
				$matched[ $address ] = $by_address[ $address ];
			}
		}

		if ( empty( $matched ) ) {
			return null;
		}

		$with = function ( $status ) use ( $matched ) {
			return array_filter( $matched, function ( $entry ) use ( $status ) {
				return $entry['status'] === $status;
			} );
		};

		$bounced = $with( 'bounced' );
		$failed  = $with( 'failed' );
		$delayed = $with( 'delayed' );

		// Failed also covers what the MTA gave up on itself, like a cancelled hold, which says nothing about the address
		$rejected = array_filter( $failed, function ( $entry ) {
			return Webhook_Listener::is_rejected_by_recipient( $entry['details'] );
		} );

		if ( $bounced ) {
			return [
				'status'  => 'bounced',
				'code'    => 'Bounced',
				'message' => __( 'This email could not be delivered and bounced back.', 'mailhawk' ),
				'hard'    => array_keys( $bounced + $rejected ),
			];
		}

		if ( $failed ) {
			return [
				'status'  => 'failed',
				'code'    => 'MessageDeliveryFailed',
				'message' => reset( $failed )['details'],
				'hard'    => array_keys( $rejected ),
			];
		}

		if ( $delayed ) {
			return [
				'status'  => 'softfail',
				'code'    => 'MessageDelayed',
				'message' => reset( $delayed )['details'],
				'hard'    => [],
			];
		}

		if ( count( $with( 'delivered' ) ) === count( $recipients ) ) {
			return [
				'status'  => 'delivered',
				'code'    => '',
				'message' => '',
				'hard'    => [],
			];
		}

		return null;
	}
}
