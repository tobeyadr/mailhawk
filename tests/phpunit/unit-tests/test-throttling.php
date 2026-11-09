<?php

use MailHawk\Api\Postal\Postal;
use MailHawk\Api\Postal\Reporting;
use MailHawk\Api\Postal\Send;
use MailHawk\Classes\Email_Log_Item;
use MailHawk\Cron_Events;
use MailHawk\Plugin;
use function MailHawk\mailhawk_is_throttled;
use function MailHawk\mailhawk_throttled_until;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;
use function MailHawk\set_mailhawk_is_throttled;

/**
 * The MTA throttles a site that used up its plan, with a 429, instead of failing every email. The MTA is answered by the
 * tests.
 */
class Test_Throttling extends WP_UnitTestCase {

	/**
	 * What the MTA was asked, in order
	 *
	 * @var string[]
	 */
	protected $requests = [];

	public function set_up() {
		parent::set_up();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Plugin::instance()->log->get_table_name() );
		Plugin::instance()->log->flush_cache();

		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );
		Postal::set_api_key( 'secret-key' );
		delete_transient( 'mailhawk_is_throttled' );

		$this->requests = [];
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		delete_transient( 'mailhawk_is_throttled' );
		delete_option( 'mailhawk_retry_failed_emails' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Be the MTA, answering every request the same way
	 *
	 * @param int   $http
	 * @param array $headers
	 * @param mixed $body
	 */
	protected function mta( $http, $headers = [], $body = '' ) {

		remove_all_filters( 'pre_http_request' );

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $http, $headers, $body ) {

			$this->requests[] = substr( $url, strpos( $url, '/api/v1/' ) + 8 );

			return [
				'headers'  => $headers,
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'response' => [ 'code' => $http, 'message' => '' ],
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );
	}

	protected function mta_accepts() {
		$this->mta( 200, [], [ 'status' => 'success', 'data' => [ 'message_id' => 'accepted@mta' ] ] );
	}

	protected function add_failed_email( $recipient = 'bob@example.net' ) {

		$id = Plugin::instance()->log->add( [
			'msg_id'       => '',
			'recipients'   => [ $recipient ],
			'from_address' => 'info@example.com',
			'subject'      => 'Your order',
			'content'      => 'Thanks for your order',
			'headers'      => [ [ 'Content-Type', 'text/plain' ] ],
			'status'       => 'failed',
			'date_sent'    => current_time( 'mysql' ),
		] );

		$this->assertNotFalse( $id );

		return $id;
	}

	protected function entry( $id ) {
		Plugin::instance()->log->flush_cache();

		return Plugin::instance()->log->get( $id );
	}

	public function test_a_429_to_a_send_is_an_error_that_says_how_long_to_wait() {

		$this->mta( 429, [ 'retry-after' => '120' ] );

		$result = Send::raw( 'info@example.com', [ 'bob@example.net' ], 'The email' );

		$this->assertWPError( $result );
		$this->assertSame( 'mailhawk_rate_limited', $result->get_error_code() );
		$this->assertSame( 120, $result->get_error_data()['retry_after'] );
		$this->assertStringContainsString( 'slowing down', $result->get_error_message() );

		$this->assertTrue( mailhawk_is_throttled() );
		$this->assertEqualsWithDelta( time() + 120, mailhawk_throttled_until(), 5 );
	}

	public function test_the_wait_comes_from_the_body_when_there_is_no_header_and_defaults_to_five_minutes() {

		$this->mta( 429, [], [ 'status' => 'error', 'data' => [ 'code' => 'RateLimitExceeded', 'retry_after' => 45 ] ] );

		$this->assertSame( 45, Send::raw( 'info@example.com', [ 'bob@example.net' ], 'The email' )->get_error_data()['retry_after'] );

		$this->mta( 429 );

		$this->assertSame( 300, Send::raw( 'info@example.com', [ 'bob@example.net' ], 'The email' )->get_error_data()['retry_after'] );
		$this->assertEqualsWithDelta( time() + 300, mailhawk_throttled_until(), 5 );
	}

	public function test_a_wait_longer_than_a_day_is_not_believed() {

		$this->mta( 429, [ 'retry-after' => (string) ( 30 * DAY_IN_SECONDS ) ] );

		Send::raw( 'info@example.com', [ 'bob@example.net' ], 'The email' );

		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, mailhawk_throttled_until(), 5 );
	}

	public function test_a_429_to_something_that_is_not_a_send_does_not_say_email_is_throttled() {

		$this->mta( 429, [ 'retry-after' => '60' ] );

		$result = Reporting::limits();

		$this->assertSame( 'mailhawk_rate_limited', $result->get_error_code() );
		$this->assertFalse( mailhawk_is_throttled() );
	}

	public function test_an_email_that_goes_through_ends_it() {

		set_mailhawk_is_throttled( 600 );
		$this->mta_accepts();

		$this->assertSame( 'accepted@mta', Send::raw( 'info@example.com', [ 'bob@example.net' ], 'The email' ) );
		$this->assertFalse( mailhawk_is_throttled() );
	}

	public function test_a_retry_the_mta_turns_away_does_not_use_up_an_attempt() {

		$id = $this->add_failed_email();
		$this->mta( 429, [ 'retry-after' => '60' ] );

		$item = new Email_Log_Item( $id );

		$this->assertFalse( $item->retry() );
		$this->assertSame( 0, (int) $this->entry( $id )->retries );
		$this->assertStringContainsString( 'slowing down', $item->error->get_error_message() );
	}

	public function test_a_retry_that_fails_for_another_reason_still_counts() {

		$id = $this->add_failed_email();
		$this->mta( 500, [], 'Nope' );

		$this->assertFalse( ( new Email_Log_Item( $id ) )->retry() );
		$this->assertSame( 1, (int) $this->entry( $id )->retries );
	}

	public function test_failed_emails_are_not_retried_while_the_mta_says_to_wait() {

		update_option( 'mailhawk_retry_failed_emails', 1 );
		$id = $this->add_failed_email();
		$this->mta_accepts();
		set_mailhawk_is_throttled( 600 );

		( new Cron_Events() )->retry_failed_emails();

		$this->assertSame( [], $this->requests, 'The MTA is left alone' );
		$this->assertSame( 'failed', $this->entry( $id )->status );
		$this->assertSame( 0, (int) $this->entry( $id )->retries );
	}

	public function test_failed_emails_are_retried_when_the_wait_is_over() {

		update_option( 'mailhawk_retry_failed_emails', 1 );
		$id = $this->add_failed_email();
		$this->mta_accepts();

		// Still remembered, but past
		set_transient( 'mailhawk_is_throttled', time() - 10, DAY_IN_SECONDS );

		( new Cron_Events() )->retry_failed_emails();

		$this->assertSame( [ 'send/raw' ], $this->requests );
		$this->assertSame( 'sent', $this->entry( $id )->status );
		$this->assertFalse( mailhawk_is_throttled() );
	}

	public function test_the_retries_stop_at_the_first_email_the_mta_turns_away() {

		update_option( 'mailhawk_retry_failed_emails', 1 );
		$ids = [ $this->add_failed_email( 'a@example.net' ), $this->add_failed_email( 'b@example.net' ), $this->add_failed_email( 'c@example.net' ) ];
		$this->mta( 429, [ 'retry-after' => '600' ] );

		( new Cron_Events() )->retry_failed_emails();

		$this->assertSame( [ 'send/raw' ], $this->requests, 'One was enough to know' );

		foreach ( $ids as $id ) {
			$this->assertSame( 'failed', $this->entry( $id )->status );
			$this->assertSame( 0, (int) $this->entry( $id )->retries );
		}
	}
}
