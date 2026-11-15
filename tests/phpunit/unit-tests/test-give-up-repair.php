<?php

use MailHawk\Api\Postal\Messages;
use MailHawk\Api\Postal\Postal;
use MailHawk\Give_Up_Repair;
use MailHawk\Plugin;
use function MailHawk\is_groundhogg_active;
use function MailHawk\set_mailhawk_is_connected;

/**
 * The bounces earlier versions recorded for emails the MTA gave up on for reasons of its own are undone, once. The MTA
 * is answered by the tests.
 */
class Test_Give_Up_Repair extends WP_UnitTestCase {

	/**
	 * @var array[] what the MTA was asked: [ url, body ]
	 */
	protected $requests = [];

	public function set_up() {
		parent::set_up();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Plugin::instance()->recipients->get_table_name() );
		Plugin::instance()->recipients->flush_cache();

		set_mailhawk_is_connected( true );
		Postal::set_api_key( 'secret-key' );
		delete_option( Give_Up_Repair::OPTION );
		wp_clear_scheduled_hook( Give_Up_Repair::HOOK );

		$this->requests = [];
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		delete_option( Give_Up_Repair::OPTION );
		wp_clear_scheduled_hook( Give_Up_Repair::HOOK );
		parent::tear_down();
	}

	/**
	 * Be the MTA: answer messages/given_up with this envelope data, or with the HTTP status
	 *
	 * @param array|null $given_up
	 * @param int        $http
	 */
	protected function mta( $given_up, $http = 200 ) {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $given_up, $http ) {

			if ( false === strpos( $url, '/api/v1/messages/given_up' ) ) {
				return new WP_Error( 'blocked', 'Unexpected request to ' . $url );
			}

			$this->requests[] = [ 'url' => $url, 'body' => json_decode( $args['body'], true ), 'args' => $args ];

			return [
				'headers'  => [],
				'body'     => 200 === $http ? wp_json_encode( [ 'status' => 'success', 'time' => 0.01, 'flags' => new stdClass(), 'data' => [ 'given_up' => $given_up ] ] ) : 'Not found',
				'response' => [ 'code' => $http, 'message' => '' ],
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );
	}

	protected function bounced( $email, $bounces ) {
		Plugin::instance()->recipients->add( [
			'email'       => $email,
			'status'      => '',
			'source'      => 'webhook',
			'bounces'     => $bounces,
			'last_bounce' => current_time( 'mysql' ),
		] );
	}

	public function test_given_up_asks_with_the_key_and_reads_the_answer() {
		$this->mta( [
			[ 'rcpt_to' => 'Someone@Gmail.com', 'message_id' => 'abc@site.test', 'time' => 1795000000 ],
			[ 'rcpt_to' => 'not an address', 'message_id' => 'x', 'time' => 1 ],
		] );

		$result = Messages::given_up( 1794000000 );

		$this->assertSame( [ [ 'rcpt_to' => 'someone@gmail.com', 'message_id' => 'abc@site.test', 'time' => 1795000000 ] ], $result );
		$this->assertSame( 1794000000, $this->requests[0]['body']['since'] );
		$this->assertSame( 'secret-key', $this->requests[0]['args']['headers']['X-Server-API-Key'] );
	}

	public function test_an_mta_without_the_list_is_unsupported() {
		$this->mta( null, 404 );

		$result = Messages::given_up( 1794000000 );

		$this->assertWPError( $result );
		$this->assertSame( 'mailhawk_unsupported', $result->get_error_code() );
	}

	public function test_takes_the_give_up_bounces_off_and_only_those() {
		$this->bounced( 'twice@example.com', 3 );
		$this->bounced( 'once@example.com', 1 );
		$this->bounced( 'other@example.com', 2 );

		$now = time();
		$this->mta( [
			[ 'rcpt_to' => 'twice@example.com', 'message_id' => 'a', 'time' => $now - 100 ],
			[ 'rcpt_to' => 'twice@example.com', 'message_id' => 'b', 'time' => $now - 50 ],
			[ 'rcpt_to' => 'once@example.com', 'message_id' => 'c', 'time' => $now - 10 ],
			[ 'rcpt_to' => 'never-bounced@example.com', 'message_id' => 'd', 'time' => $now ],
		] );

		( new Give_Up_Repair() )->run();

		$recipients = Plugin::instance()->recipients;
		$recipients->flush_cache();

		$this->assertSame( 1, absint( $recipients->get_by_email( 'twice@example.com' )->bounces ) );
		$this->assertSame( 0, absint( $recipients->get_by_email( 'once@example.com' )->bounces ) );
		$this->assertSame( 0, $recipients->get_bounce_risk( $recipients->get_by_email( 'once@example.com' ) ) );
		$this->assertSame( 2, absint( $recipients->get_by_email( 'other@example.com' )->bounces ) );

		$result = Give_Up_Repair::get_result();
		$this->assertNotEmpty( $result['done'] );
		$this->assertSame( 3, $result['addresses'] );
		$this->assertSame( 3, $result['bounces_removed'] );
	}

	public function test_runs_once() {
		$this->mta( [] );

		( new Give_Up_Repair() )->run();
		( new Give_Up_Repair() )->run();

		$this->assertCount( 1, $this->requests );
	}

	public function test_tries_again_later_while_the_mta_does_not_have_the_list() {
		$this->mta( null, 404 );

		( new Give_Up_Repair() )->run();

		$result = Give_Up_Repair::get_result();
		$this->assertSame( 0, $result['done'] );
		$this->assertSame( 1, $result['tries'] );
		$this->assertGreaterThan( time() + 5 * HOUR_IN_SECONDS, wp_next_scheduled( Give_Up_Repair::HOOK ) );
	}

	public function test_stops_trying_after_a_while() {
		update_option( Give_Up_Repair::OPTION, [ 'done' => 0, 'tries' => Give_Up_Repair::MAX_TRIES - 1 ] );
		$this->mta( null, 404 );

		( new Give_Up_Repair() )->run();

		$this->assertNotEmpty( Give_Up_Repair::get_result()['done'] );
		$this->assertFalse( wp_next_scheduled( Give_Up_Repair::HOOK ) );
	}

	public function test_a_site_that_is_not_connected_has_nothing_to_repair() {
		set_mailhawk_is_connected( false );
		$this->mta( [] );

		( new Give_Up_Repair() )->run();

		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'not connected', Give_Up_Repair::get_result()['skipped'] );
	}

	public function test_it_is_scheduled_until_it_has_run() {
		( new Give_Up_Repair() )->maybe_schedule();
		$this->assertNotFalse( wp_next_scheduled( Give_Up_Repair::HOOK ) );

		wp_clear_scheduled_hook( Give_Up_Repair::HOOK );
		update_option( Give_Up_Repair::OPTION, [ 'done' => time() ] );
		( new Give_Up_Repair() )->maybe_schedule();
		$this->assertFalse( wp_next_scheduled( Give_Up_Repair::HOOK ) );
	}

	public function test_only_a_change_around_a_give_up_counts() {
		$repair = new Give_Up_Repair();
		$time   = 1795000000;

		$this->assertTrue( $repair->caused_by_give_up( $time + 5, [ $time ] ) );
		$this->assertTrue( $repair->caused_by_give_up( $time + 5 * HOUR_IN_SECONDS, [ $time ] ) );
		$this->assertTrue( $repair->caused_by_give_up( $time - 10 * MINUTE_IN_SECONDS, [ $time ] ) );
		$this->assertFalse( $repair->caused_by_give_up( $time + DAY_IN_SECONDS, [ $time ] ) );
		$this->assertFalse( $repair->caused_by_give_up( $time - HOUR_IN_SECONDS, [ $time ] ) );
	}

	public function test_puts_back_a_groundhogg_contact_bounced_by_a_give_up() {

		if ( ! is_groundhogg_active() ) {
			$this->markTestSkipped( 'Groundhogg is not active.' );
		}

		$bounced = new \Groundhogg\Contact( [ 'email' => 'restore-me@example.com', 'optin_status' => \Groundhogg\Preferences::CONFIRMED ] );
		$bounced->change_marketing_preference( \Groundhogg\Preferences::HARD_BOUNCE );

		$long_ago = new \Groundhogg\Contact( [ 'email' => 'old-bounce@example.com', 'optin_status' => \Groundhogg\Preferences::CONFIRMED ] );
		$long_ago->update( [ 'optin_status' => \Groundhogg\Preferences::HARD_BOUNCE, 'date_optin_status_changed' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 5 * DAY_IN_SECONDS ) ] );

		$result = ( new Give_Up_Repair() )->repair( [
			[ 'rcpt_to' => 'restore-me@example.com', 'message_id' => 'a', 'time' => time() - 60 ],
			[ 'rcpt_to' => 'old-bounce@example.com', 'message_id' => 'b', 'time' => time() - 60 ],
		] );

		$this->assertSame( 1, $result['contacts_restored'] );
		$this->assertSame( \Groundhogg\Preferences::UNCONFIRMED, \Groundhogg\get_contactdata( 'restore-me@example.com' )->get_optin_status() );
		$this->assertSame( \Groundhogg\Preferences::HARD_BOUNCE, \Groundhogg\get_contactdata( 'old-bounce@example.com' )->get_optin_status() );
	}
}
