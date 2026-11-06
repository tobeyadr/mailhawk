<?php

use MailHawk\Api\Postal\Postal;
use MailHawk\Classes\Email_Log_Item;
use MailHawk\Cron_Events;
use MailHawk\Plugin;
use MailHawk\Quarantine;
use function MailHawk\get_log_retention_days;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;

/**
 * The daily cleanup of the email log. It deletes for good, so what it keeps matters as much as what it deletes.
 */
class Test_Log_Trim extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Plugin::instance()->log->get_table_name() );
		Plugin::instance()->log->flush_cache();

		update_option( 'mailhawk_log_retention_in_days', 14 );
		wp_clear_scheduled_hook( 'mailhawk_trim_log_entries' );
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		parent::tear_down();
	}

	/**
	 * The cleanup, without the hooks the plugin already registered, and with the time it has under the test's control
	 *
	 * @param bool $out_of_time
	 *
	 * @return Cron_Events
	 */
	protected function cron( $out_of_time = false ) {
		return new class( $out_of_time ) extends Cron_Events {

			protected $out_of_time;

			public function __construct( $out_of_time ) {
				$this->out_of_time = $out_of_time;
			}

			protected function out_of_time( $batches ) {
				return $this->out_of_time;
			}
		};
	}

	/**
	 * Add a log row sent that many seconds ago, as the time of the site, which is how the log keeps it
	 *
	 * @param string $status
	 * @param int    $seconds_ago
	 * @param string $msg_id
	 *
	 * @return int
	 */
	protected function add_entry( $status, $seconds_ago, $msg_id = '' ) {

		$id = Plugin::instance()->log->add( [
			'msg_id'       => $msg_id,
			'recipients'   => [ 'someone@example.net' ],
			'from_address' => 'info@example.com',
			'subject'      => 'Test',
			'content'      => 'Some email content',
			'headers'      => [ [ 'Content-Type', 'text/plain' ], [ 'From', 'Test <info@example.com>' ] ],
			'status'       => $status,
			'date_sent'    => wp_date( 'Y-m-d H:i:s', time() - $seconds_ago ),
		] );

		$this->assertNotFalse( $id );

		return $id;
	}

	protected function exists( $id ) {
		return ! empty( Plugin::instance()->log->get( $id ) );
	}

	/**
	 * Answer the requests to the MTA as it does when an email is accepted, so nothing is sent
	 */
	protected function accept_email_as( $message_id ) {

		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );
		Postal::set_api_key( 'x' );

		add_filter( 'pre_http_request', function () use ( $message_id ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'status' => 'success', 'data' => [ 'message_id' => $message_id ] ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		} );
	}

	/**
	 * When the cleanup is scheduled to run again
	 *
	 * @return int[]
	 */
	protected function follow_ups() {

		$found = [];

		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			if ( isset( $hooks['mailhawk_trim_log_entries'] ) ) {
				$found[] = $time;
			}
		}

		return $found;
	}

	public function test_it_deletes_what_is_older_than_the_retention_and_keeps_the_rest() {

		$just_over  = $this->add_entry( 'sent', ( 14 * DAY_IN_SECONDS ) + HOUR_IN_SECONDS );
		$just_under = $this->add_entry( 'sent', ( 14 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS );
		$failed     = $this->add_entry( 'failed', 20 * DAY_IN_SECONDS );
		$recent     = $this->add_entry( 'sent', 60 );

		$this->cron()->trim_log_entries();

		$this->assertFalse( $this->exists( $just_over ) );
		$this->assertFalse( $this->exists( $failed ) );
		$this->assertTrue( $this->exists( $just_under ) );
		$this->assertTrue( $this->exists( $recent ) );
	}

	public function test_a_retention_that_is_not_a_number_of_days_does_not_empty_the_log() {

		$recent = $this->add_entry( 'sent', 60 );
		$older  = $this->add_entry( 'sent', 2 * DAY_IN_SECONDS );

		foreach ( [ 0, '0', '', 'abc', null ] as $value ) {

			update_option( 'mailhawk_log_retention_in_days', $value );

			$this->assertSame( 1, get_log_retention_days(), 'Retention of ' . var_export( $value, true ) );
		}

		$this->cron()->trim_log_entries();

		$this->assertTrue( $this->exists( $recent ), 'What was just sent has to survive' );
		$this->assertFalse( $this->exists( $older ), 'A day is the least it is kept' );
	}

	public function test_held_emails_are_not_deleted_early_on_a_site_behind_utc() {

		update_option( 'timezone_string', 'America/Los_Angeles' );

		// Held 4 hours short of the 30 days it is kept for
		$held = $this->add_entry( 'quarantine', ( 30 * DAY_IN_SECONDS ) - ( 4 * HOUR_IN_SECONDS ) );

		$this->cron()->trim_log_entries();

		$this->assertTrue( $this->exists( $held ) );
	}

	public function test_held_emails_are_not_kept_longer_on_a_site_ahead_of_utc() {

		update_option( 'timezone_string', 'Pacific/Auckland' );

		// Held 4 hours past the 30 days it is kept for
		$held = $this->add_entry( 'quarantine', ( 30 * DAY_IN_SECONDS ) + ( 4 * HOUR_IN_SECONDS ) );
		$sent = $this->add_entry( 'sent', ( 14 * DAY_IN_SECONDS ) + ( 4 * HOUR_IN_SECONDS ) );

		$this->cron()->trim_log_entries();

		$this->assertFalse( $this->exists( $held ) );
		$this->assertFalse( $this->exists( $sent ) );
	}

	public function test_the_quarantine_report_dates_follow_the_time_of_the_site() {

		update_option( 'timezone_string', 'America/Los_Angeles' );

		$this->add_entry( 'quarantine', 2 * DAY_IN_SECONDS );

		$report = Quarantine::get_report();
		$item   = $report['items'][0];

		$this->assertEqualsWithDelta( time() - ( 2 * DAY_IN_SECONDS ), $item['held_at'], 5 );
		$this->assertEqualsWithDelta( time() + ( 28 * DAY_IN_SECONDS ), $item['expires_at'], 5 );
	}

	public function test_it_deletes_in_batches_until_nothing_is_left() {

		add_filter( 'mailhawk/log_trim_batch_size', function () {
			return 2;
		} );

		$old    = [];
		$recent = $this->add_entry( 'sent', 60 );

		for ( $i = 0; $i < 7; $i ++ ) {
			$old[] = $this->add_entry( 'sent', ( 20 + $i ) * DAY_IN_SECONDS );
		}

		for ( $i = 0; $i < 3; $i ++ ) {
			$old[] = $this->add_entry( 'quarantine', ( 40 + $i ) * DAY_IN_SECONDS );
		}

		$this->cron()->trim_log_entries();

		foreach ( $old as $id ) {
			$this->assertFalse( $this->exists( $id ), 'Entry ' . $id );
		}

		$this->assertTrue( $this->exists( $recent ) );
		$this->assertSame( [], $this->follow_ups(), 'There was nothing left to come back for' );
	}

	public function test_it_carries_on_shortly_when_it_runs_out_of_time() {

		add_filter( 'mailhawk/log_trim_batch_size', function () {
			return 2;
		} );

		$old = [];

		for ( $i = 0; $i < 7; $i ++ ) {
			$old[] = $this->add_entry( 'sent', ( 20 + $i ) * DAY_IN_SECONDS );
		}

		$this->cron( true )->trim_log_entries();

		$left = array_filter( $old, [ $this, 'exists' ] );

		$this->assertCount( 5, $left, 'Only one batch fits in the time there was' );

		$follow_ups = $this->follow_ups();

		$this->assertCount( 1, $follow_ups );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $follow_ups[0], 5 );

		// And the next run finishes it
		$this->cron()->trim_log_entries();

		$this->assertEmpty( array_filter( $old, [ $this, 'exists' ] ) );
	}

	public function test_it_does_not_come_back_when_the_last_batch_was_exactly_full() {

		add_filter( 'mailhawk/log_trim_batch_size', function () {
			return 2;
		} );

		$old = [ $this->add_entry( 'sent', 20 * DAY_IN_SECONDS ), $this->add_entry( 'sent', 21 * DAY_IN_SECONDS ) ];

		$this->cron()->trim_log_entries();

		$this->assertEmpty( array_filter( $old, [ $this, 'exists' ] ) );
		$this->assertSame( [], $this->follow_ups(), 'A full batch that emptied it is not a reason to come back' );
	}

	public function test_what_the_log_remembers_is_not_stale_after_it_deletes() {

		$log = Plugin::instance()->log;

		$this->add_entry( 'trimtest', 20 * DAY_IN_SECONDS );

		$log->flush_cache();
		$this->assertSame( 1, absint( $log->count( [ 'status' => 'trimtest' ] ) ) );

		$this->cron()->trim_log_entries();

		$this->assertSame( 0, absint( $log->count( [ 'status' => 'trimtest' ] ) ) );
	}

	public function test_an_old_held_email_that_was_released_is_kept() {

		$this->accept_email_as( 'released-1@example.com' );

		// Held for 25 days, so it is still waiting, but it is older than the log is kept
		$id   = $this->add_entry( 'quarantine', 25 * DAY_IN_SECONDS );
		$item = new Email_Log_Item( $id );

		$this->assertTrue( $item->release() );

		$this->cron()->trim_log_entries();

		$this->assertTrue( $this->exists( $id ), 'Sent just now, it must not be deleted for having been held a while' );

		$entry = Plugin::instance()->log->get( $id );

		$this->assertSame( 'sent', $entry->status );
		$this->assertSame( 'released-1@example.com', $entry->msg_id, 'The ID later events are matched by' );
		$this->assertEqualsWithDelta( time(), ( new DateTime( $entry->date_sent, wp_timezone() ) )->getTimestamp(), 30 );
	}

	public function test_an_old_failed_email_that_was_retried_is_kept() {

		$this->accept_email_as( 'retried-1@example.com' );

		$id   = $this->add_entry( 'failed', 20 * DAY_IN_SECONDS );
		$item = new Email_Log_Item( $id );

		$this->assertTrue( $item->retry() );

		$this->cron()->trim_log_entries();

		$this->assertTrue( $this->exists( $id ) );
		$this->assertSame( 'sent', Plugin::instance()->log->get( $id )->status );
	}
}
