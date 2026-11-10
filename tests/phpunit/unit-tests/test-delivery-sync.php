<?php

use MailHawk\Api\Webhook_Listener;
use MailHawk\Api\Postal\Messages;
use MailHawk\Api\Postal\Postal;
use MailHawk\Delivery_Sync;
use MailHawk\Plugin;
use function MailHawk\mailhawk_is_suspended;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;

/**
 * The log catches up with the MTA on emails whose events never came. The MTA is answered by the tests, so nothing here
 * reaches it, and an unexpected request fails the test instead of leaving the machine.
 */
class Test_Delivery_Sync extends WP_UnitTestCase {

	/**
	 * What the MTA was asked, in order: [ 'url', 'args', 'ids' ]
	 *
	 * @var array[]
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

		delete_transient( Delivery_Sync::PAUSE );
		delete_option( Delivery_Sync::CURSOR );
		wp_clear_scheduled_hook( Delivery_Sync::HOOK );

		$this->requests = [];
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		delete_transient( Delivery_Sync::PAUSE );
		parent::tear_down();
	}

	/**
	 * The sync, without the hooks the plugin already registered for its own, and with the time it has under control
	 *
	 * @param bool $out_of_time
	 *
	 * @return Delivery_Sync
	 */
	protected function sync_with( $out_of_time = false ) {
		return new class( $out_of_time ) extends Delivery_Sync {

			protected $out_of_time;

			public function __construct( $out_of_time ) {
				$this->out_of_time = $out_of_time;
			}

			protected function out_of_time( $requests ) {
				return $this->out_of_time;
			}
		};
	}

	/**
	 * Be the MTA. The handler gets the IDs that were asked about and answers with what reply() or a WP_Error gives.
	 *
	 * @param callable $handler
	 */
	protected function mta( callable $handler ) {

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $handler ) {

			if ( false === strpos( $url, '/api/v1/messages/statuses' ) ) {
				return new WP_Error( 'blocked', 'Unexpected request to ' . $url );
			}

			$body = json_decode( $args['body'], true );

			$this->requests[] = [ 'url' => $url, 'args' => $args, 'body' => $body, 'ids' => $body['message_ids'] ?? null ];

			return $handler( $body['message_ids'] ?? [], $args );
		}, 10, 3 );
	}

	/**
	 * An answer from the MTA
	 *
	 * @param array|string $data    the data of the envelope, or the whole body when it is a string
	 * @param int          $http
	 * @param array        $headers
	 * @param string       $status
	 *
	 * @return array
	 */
	protected function reply( $data, $http = 200, $headers = [], $status = 'success' ) {
		return [
			'headers'  => $headers,
			'body'     => is_string( $data ) ? $data : wp_json_encode( [ 'status' => $status, 'time' => 0.004, 'flags' => new stdClass(), 'data' => $data ] ),
			'response' => [ 'code' => $http, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	protected function entry( $rcpt_to, $status, $details = '' ) {
		return [ 'rcpt_to' => $rcpt_to, 'status' => $status, 'time' => time(), 'details' => $details ];
	}

	/**
	 * The MTA knows each of the IDs and says this about it
	 *
	 * @param array $per_id id => entries, or null when it has no such email
	 *
	 * @return array
	 */
	protected function messages( array $per_id ) {

		$messages = [];

		foreach ( $per_id as $id => $entries ) {
			$messages[ $id ] = null === $entries ? [ 'found' => false, 'recipients' => [] ] : [ 'found' => true, 'recipients' => $entries ];
		}

		return $this->reply( [ 'messages' => $messages ] );
	}

	/**
	 * The MTA has nothing on any of them
	 */
	protected function know_nothing() {
		$this->mta( function ( $ids ) {
			return $this->messages( array_fill_keys( $ids, null ) );
		} );
	}

	protected function add_row( $msg_id, $recipients = [ 'bob@example.net' ], $status = 'sent', $seconds_ago = HOUR_IN_SECONDS ) {

		$id = Plugin::instance()->log->add( [
			'msg_id'       => $msg_id,
			'recipients'   => $recipients,
			'from_address' => 'info@example.com',
			'subject'      => 'Your order',
			'content'      => 'Thanks for your order',
			'headers'      => [ [ 'Content-Type', 'text/plain' ] ],
			'status'       => $status,
			'date_sent'    => wp_date( 'Y-m-d H:i:s', time() - $seconds_ago ),
		] );

		$this->assertNotFalse( $id );

		return $id;
	}

	protected function row( $id ) {
		return Plugin::instance()->log->get( $id );
	}

	/**
	 * How long until the MTA is asked again
	 *
	 * @return int|false
	 */
	protected function paused_for() {

		$until = get_option( '_transient_timeout_' . Delivery_Sync::PAUSE );

		return $until ? $until - time() : false;
	}

	public function test_it_asks_for_the_ids_of_the_emails_still_waiting_and_sends_nothing_else() {

		$this->know_nothing();

		$this->add_row( 'one@mta' );
		$this->add_row( 'Two@mta', [ 'bob@example.net' ], 'softfail' );

		$this->sync_with()->sync();

		$this->assertCount( 1, $this->requests );

		$request = $this->requests[0];

		$this->assertSame( 'https://mta01.mailhawk.io/api/v1/messages/statuses', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'secret-key', $request['args']['headers']['X-Server-API-Key'] );
		$this->assertSame( [ 'message_ids' ], array_keys( $request['body'] ), 'Only the IDs are sent' );
		$this->assertSame( [ 'one@mta', 'Two@mta' ], $request['ids'], 'As stored, case and all' );
		$this->assertLessThanOrEqual( 10, $request['args']['timeout'], 'It is a background job with a short timeout' );
	}

	public function test_it_only_asks_about_emails_that_are_waiting_and_old_enough_to_have_news() {

		$this->know_nothing();

		$this->add_row( 'sent@mta' );
		$this->add_row( 'softfail@mta', [ 'bob@example.net' ], 'softfail' );
		$this->add_row( 'brand-new@mta', [ 'bob@example.net' ], 'sent', 2 * MINUTE_IN_SECONDS );
		$this->add_row( 'forgotten@mta', [ 'bob@example.net' ], 'sent', 8 * DAY_IN_SECONDS );
		$this->add_row( 'delivered@mta', [ 'bob@example.net' ], 'delivered' );
		$this->add_row( 'failed@mta', [ 'bob@example.net' ], 'failed' );
		$this->add_row( 'held@mta', [ 'bob@example.net' ], 'quarantine' );
		$this->add_row( '', [ 'bob@example.net' ], 'sent' );

		$this->sync_with()->sync();

		$this->assertSame( [ [ 'sent@mta', 'softfail@mta' ] ], array_column( $this->requests, 'ids' ) );
	}

	public function test_it_asks_in_batches_of_a_hundred() {

		$this->know_nothing();

		for ( $i = 1; $i <= 250; $i ++ ) {
			$this->add_row( "msg-{$i}@mta" );
		}

		$done = $this->sync_with()->sync();

		$this->assertSame( [ 100, 100, 50 ], array_map( 'count', array_column( $this->requests, 'ids' ) ) );
		$this->assertSame( 3, $done['requests'] );
		$this->assertSame( 250, $done['checked'] );
		$this->assertSame( [], $this->follow_ups(), 'It got to the end' );
		$this->assertSame( 0, absint( get_option( Delivery_Sync::CURSOR ) ), 'The next run starts from the top' );
	}

	public function test_a_run_is_limited_and_the_next_one_carries_on_where_it_stopped() {

		add_filter( 'mailhawk/delivery_sync_max_requests', function () {
			return 1;
		} );

		$this->mta( function ( $ids ) {
			// Still queued, not going anywhere
			return $this->messages( array_fill_keys( $ids, [ $this->entry( 'bob@example.net', 'queued' ) ] ) );
		} );

		for ( $i = 1; $i <= 150; $i ++ ) {
			$this->add_row( "msg-{$i}@mta" );
		}

		$this->sync_with()->sync();

		$follow_ups = $this->follow_ups();

		$this->assertCount( 1, $follow_ups );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $follow_ups[0], 5 );
		$this->assertSame( 'msg-1@mta', $this->requests[0]['ids'][0] );
		$this->assertSame( 'msg-100@mta', $this->requests[0]['ids'][99] );

		$this->sync_with()->sync();

		$this->assertCount( 50, $this->requests[1]['ids'], 'The ones the first run did not get to, not the same hundred again' );
		$this->assertSame( 'msg-101@mta', $this->requests[1]['ids'][0] );

		$this->sync_with()->sync();

		$this->assertSame( 'msg-1@mta', $this->requests[2]['ids'][0], 'And then round again' );
	}

	public function test_it_carries_on_shortly_when_it_runs_out_of_time() {

		$this->mta( function ( $ids ) {
			return $this->messages( array_fill_keys( $ids, null ) );
		} );

		for ( $i = 1; $i <= 250; $i ++ ) {
			$this->add_row( "msg-{$i}@mta" );
		}

		$this->sync_with( true )->sync();

		$this->assertCount( 1, $this->requests, 'One batch is all the time there was' );
		$this->assertCount( 1, $this->follow_ups() );
	}

	public function test_each_recipient_is_matched_by_address() {

		$this->mta( function () {
			return $this->messages( [
				'all-delivered@mta' => [
					$this->entry( 'a@example.net', 'delivered' ),
					$this->entry( 'b@example.net', 'delivered' ),
				],
				'one-delayed@mta'   => [
					$this->entry( 'a@example.net', 'delivered' ),
					$this->entry( 'b@example.net', 'delayed', '451 try again later' ),
				],
				'one-pending@mta'   => [
					$this->entry( 'a@example.net', 'delivered' ),
					$this->entry( 'b@example.net', 'queued' ),
				],
				'one-missing@mta'   => [
					$this->entry( 'a@example.net', 'delivered' ),
				],
				'someone-else@mta'  => [
					$this->entry( 'x@example.net', 'delivered' ),
					$this->entry( 'y@example.net', 'delivered' ),
				],
				'mixed-case@mta'    => [
					$this->entry( 'a@example.net', 'delivered' ),
				],
			] );
		} );

		$ids = [
			'all-delivered' => $this->add_row( 'all-delivered@mta', [ 'a@example.net', 'b@example.net' ] ),
			'one-delayed'   => $this->add_row( 'one-delayed@mta', [ 'a@example.net', 'b@example.net' ] ),
			'one-pending'   => $this->add_row( 'one-pending@mta', [ 'a@example.net', 'b@example.net' ] ),
			'one-missing'   => $this->add_row( 'one-missing@mta', [ 'a@example.net', 'b@example.net' ] ),
			'someone-else'  => $this->add_row( 'someone-else@mta', [ 'a@example.net', 'b@example.net' ] ),
			'mixed-case'    => $this->add_row( 'mixed-case@mta', [ 'A@Example.net' ] ),
		];

		$this->sync_with()->sync();

		$this->assertSame( 'delivered', $this->row( $ids['all-delivered'] )->status );
		$this->assertSame( 'softfail', $this->row( $ids['one-delayed'] )->status );
		$this->assertSame( 'MessageDelayed', $this->row( $ids['one-delayed'] )->error_code );
		$this->assertSame( '451 try again later', $this->row( $ids['one-delayed'] )->error_message );
		$this->assertSame( 'sent', $this->row( $ids['one-pending'] )->status, 'Not everyone has it yet' );
		$this->assertSame( 'sent', $this->row( $ids['one-missing'] )->status, 'The MTA said nothing about b' );
		$this->assertSame( 'sent', $this->row( $ids['someone-else'] )->status, 'None of those are this email\'s' );
		$this->assertSame( 'delivered', $this->row( $ids['mixed-case'] )->status );
	}

	public function test_an_address_that_did_not_take_the_email_decides_it() {

		$this->mta( function () {
			return $this->messages( [
				'failed@mta'  => [
					$this->entry( 'a@example.net', 'delivered' ),
					$this->entry( 'dead@example.net', 'failed', '550 no such user' ),
				],
				'bounced@mta' => [
					$this->entry( 'a@example.net', 'delivered' ),
					$this->entry( 'gone@example.net', 'bounced' ),
					$this->entry( 'dead@example.net', 'failed', '550 no such user' ),
				],
			] );
		} );

		$failed  = $this->add_row( 'failed@mta', [ 'a@example.net', 'dead@example.net' ] );
		$bounced = $this->add_row( 'bounced@mta', [ 'a@example.net', 'gone@example.net', 'dead@example.net' ] );

		$bounces = [];

		add_action( 'mailhawk/bounced', function ( $address, $msg_id ) use ( &$bounces ) {
			$bounces[] = [ $address, $msg_id ];
		}, 10, 2 );

		$this->sync_with()->sync();

		$this->assertSame( 'failed', $this->row( $failed )->status );
		$this->assertSame( 'MessageDeliveryFailed', $this->row( $failed )->error_code );
		$this->assertSame( '550 no such user', $this->row( $failed )->error_message );
		$this->assertSame( 'bounced', $this->row( $bounced )->status );

		// What the webhooks would have said, for the risk of sending to those addresses, and for no one else
		$this->assertEqualsCanonicalizing( [
			[ 'dead@example.net', 'failed@mta' ],
			[ 'gone@example.net', 'bounced@mta' ],
			[ 'dead@example.net', 'bounced@mta' ],
		], $bounces );
	}

	public function test_what_the_mta_does_not_have_or_has_not_done_yet_leaves_the_email_alone() {

		$this->mta( function () {
			return $this->messages( [
				'unknown@mta' => null,
				'queued@mta'  => [ $this->entry( 'bob@example.net', 'queued' ) ],
				'held@mta'    => [ $this->entry( 'bob@example.net', 'held', 'Suppressed' ) ],
				'no-answer@mta' => [],
			] );
		} );

		$ids = [];

		foreach ( [ 'unknown', 'queued', 'held', 'no-answer', 'not-asked-about' ] as $name ) {
			$ids[ $name ] = $this->add_row( "{$name}@mta", [ 'bob@example.net' ], 'softfail' );
			Plugin::instance()->log->update( $ids[ $name ], [ 'error_code' => 'MessageDelayed', 'error_message' => 'Still trying' ] );
		}

		$this->sync_with()->sync();

		foreach ( $ids as $name => $id ) {
			$row = $this->row( $id );

			$this->assertSame( 'softfail', $row->status, $name );
			$this->assertSame( 'Still trying', $row->error_message, $name );
		}
	}

	public function test_an_email_that_is_delayed_and_then_delivered_is_delivered() {

		$this->mta( function () {
			return $this->messages( [ 'late@mta' => [ $this->entry( 'bob@example.net', 'delivered', '250 OK' ) ] ] );
		} );

		$id = $this->add_row( 'late@mta', [ 'bob@example.net' ], 'softfail' );
		Plugin::instance()->log->update( $id, [ 'error_code' => 'MessageDelayed', 'error_message' => '451 try again later' ] );

		$this->sync_with()->sync();

		$row = $this->row( $id );

		$this->assertSame( 'delivered', $row->status );
		$this->assertSame( '', $row->error_code );
		$this->assertSame( '', $row->error_message );
	}

	public function test_it_never_takes_an_email_back_from_where_it_got_to() {

		$log = Plugin::instance()->log;

		$complained = $this->add_row( 'complained@mta' );
		$bounced    = $this->add_row( 'bounced@mta' );

		// While the MTA is being asked, the webhooks get there first
		$this->mta( function () use ( $log, $complained, $bounced ) {

			$log->update( $complained, [ 'status' => 'complained', 'error_code' => 'SpamComplaint', 'error_message' => 'Reported as spam.' ] );
			$log->update( $bounced, [ 'status' => 'bounced', 'error_code' => 'Bounced', 'error_message' => 'Bounced back.' ] );

			return $this->messages( [
				'complained@mta' => [ $this->entry( 'bob@example.net', 'delivered' ) ],
				'bounced@mta'    => [ $this->entry( 'bob@example.net', 'delayed', '451 try again later' ) ],
			] );
		} );

		$this->sync_with()->sync();

		$this->assertSame( 'complained', $this->row( $complained )->status );
		$this->assertSame( 'SpamComplaint', $this->row( $complained )->error_code );
		$this->assertSame( 'bounced', $this->row( $bounced )->status );
		$this->assertSame( 'Bounced back.', $this->row( $bounced )->error_message );
	}

	public function test_a_rate_limit_is_waited_out_for_as_long_as_the_mta_says() {

		$this->mta( function () {
			return $this->reply( [ 'code' => 'RateLimitExceeded', 'retry_after' => 30 ], 429, [ 'retry-after' => '90' ], 'error' );
		} );

		for ( $i = 1; $i <= 250; $i ++ ) {
			$this->add_row( "msg-{$i}@mta" );
		}

		$this->sync_with()->sync();

		$this->assertCount( 1, $this->requests, 'No second request into a limit' );
		$this->assertEqualsWithDelta( 90, $this->paused_for(), 5, 'The header is what counts' );
		$this->assertSame( [], $this->follow_ups() );
		$this->assertSame( 0, absint( get_option( Delivery_Sync::CURSOR ) ), 'It will start again from where it was' );

		// Not before it is time
		$this->sync_with()->sync();
		$this->assertCount( 1, $this->requests );

		// And then it does
		delete_transient( Delivery_Sync::PAUSE );
		$this->sync_with()->sync();
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_rate_limit_without_the_header_uses_the_time_in_the_answer() {

		$this->mta( function () {
			return $this->reply( [ 'code' => 'RateLimitExceeded', 'retry_after' => 45 ], 429, [], 'error' );
		} );

		$this->add_row( 'one@mta' );

		$this->sync_with()->sync();

		$this->assertEqualsWithDelta( 45, $this->paused_for(), 5 );
	}

	public function test_an_mta_without_the_endpoint_is_left_alone_for_hours() {

		$answers = [
			'404'              => $this->reply( '{"error":"not found"}', 404 ),
			'InvalidAPIRequest' => $this->reply( [ 'code' => 'InvalidAPIRequest', 'message' => 'Unknown action' ], 200, [], 'error' ),
		];

		$unsupported = [];

		add_action( 'mailhawk/delivery_sync_failed', function ( $error ) use ( &$unsupported ) {
			$unsupported[] = $error->get_error_code();
		} );

		foreach ( $answers as $name => $answer ) {

			delete_transient( Delivery_Sync::PAUSE );
			$this->requests = [];

			remove_all_filters( 'pre_http_request' );
			$this->mta( function () use ( $answer ) {
				return $answer;
			} );

			$id = $this->add_row( "{$name}@mta" );

			$this->sync_with()->sync();

			$this->assertCount( 1, $this->requests, $name );
			$this->assertGreaterThan( 5 * HOUR_IN_SECONDS, $this->paused_for(), $name );
			$this->assertSame( 'sent', $this->row( $id )->status, $name );

			// Remembered: it does not ask again
			$this->sync_with()->sync();
			$this->assertCount( 1, $this->requests, $name );

			// ... and nothing is made of it
			$this->assertTrue( \MailHawk\mailhawk_is_connected(), $name );
			$this->assertFalse( mailhawk_is_suspended(), $name );
		}

		$this->assertSame( [ 'mailhawk_unsupported', 'mailhawk_unsupported' ], $unsupported );
	}

	public function test_it_picks_the_endpoint_up_when_the_pause_is_over() {

		$answer = $this->reply( '', 404 );

		$this->mta( function () use ( &$answer ) {
			return $answer;
		} );

		$id = $this->add_row( 'later@mta' );

		$this->sync_with()->sync();
		$this->assertSame( 'sent', $this->row( $id )->status );

		// The MTA is upgraded, and the pause is over
		delete_transient( Delivery_Sync::PAUSE );
		$answer = $this->messages( [ 'later@mta' => [ $this->entry( 'bob@example.net', 'delivered' ) ] ] );

		$this->sync_with()->sync();

		$this->assertSame( 'delivered', $this->row( $id )->status );
	}

	public function test_any_other_failure_falls_back_quietly_for_a_little_while() {

		$failures = [
			'network'      => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ),
			'server error' => $this->reply( 'Bad gateway', 502 ),
			'not json'     => $this->reply( '<html>Maintenance</html>', 200 ),
			'no messages'  => $this->reply( [ 'something' => 'else' ] ),
			'suspended'    => $this->reply( [ 'code' => 'ServerSuspended', 'message' => 'Suspended' ], 200, [], 'error' ),
			'bad key'      => $this->reply( [ 'code' => 'InvalidServerAPIKey', 'message' => 'Nope' ], 200, [], 'error' ),
			'parameters'   => $this->reply( [ 'message' => 'message_ids must be an array' ], 200, [], 'parameter-error' ),
		];

		foreach ( $failures as $name => $failure ) {

			delete_transient( Delivery_Sync::PAUSE );
			$this->requests = [];

			remove_all_filters( 'pre_http_request' );
			$this->mta( function () use ( $failure ) {
				return $failure;
			} );

			$id = $this->add_row( "{$name}@mta" );

			$notices_before = $GLOBALS['wp_filter']['admin_notices'] ?? null;

			$this->sync_with()->sync();

			$this->assertCount( 1, $this->requests, $name );
			$this->assertEqualsWithDelta( 30 * MINUTE_IN_SECONDS, $this->paused_for(), 5, $name );
			$this->assertSame( 'sent', $this->row( $id )->status, $name );
			$this->assertSame( $notices_before, $GLOBALS['wp_filter']['admin_notices'] ?? null, $name . ': no notice for the admin' );
			$this->assertFalse( mailhawk_is_suspended(), $name . ': sending is not stopped by a failed catch up' );

			// And nothing more until then
			$this->sync_with()->sync();
			$this->assertCount( 1, $this->requests, $name );
		}
	}

	public function test_it_does_nothing_when_the_site_is_not_sending_through_mailhawk() {

		$this->know_nothing();
		$this->add_row( 'one@mta' );

		set_mailhawk_is_connected( false );
		$this->sync_with()->sync();

		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( true );
		$this->sync_with()->sync();

		set_mailhawk_is_suspended( false );
		update_option( 'mailhawk_disable_email_logging', 1 );
		$this->sync_with()->sync();

		$this->assertSame( [], $this->requests );
	}

	public function test_it_will_not_ask_for_more_than_a_hundred_or_for_nothing() {

		$this->know_nothing();

		$this->assertWPError( Messages::statuses( [] ) );
		$this->assertWPError( Messages::statuses( [ '', 5, null ] ) );
		$this->assertSame( 'mailhawk_bad_request', Messages::statuses( array_map( function ( $i ) {
			return "msg-{$i}@mta";
		}, range( 1, 101 ) ) )->get_error_code() );

		$this->assertSame( [], $this->requests, 'Not even tried' );

		$ids = array_map( function ( $i ) {
			return "msg-{$i}@mta";
		}, range( 1, 100 ) );

		$this->assertIsArray( Messages::statuses( array_merge( $ids, $ids, [ 5 ] ) ), 'Twice the same is still a hundred' );
		$this->assertCount( 100, $this->requests[0]['ids'] );
	}

	/**
	 * An event from the MTA, the way the route hands it over after the signature was checked
	 *
	 * @param string $event
	 * @param array  $payload
	 */
	protected function webhook( $event, $payload ) {

		$listener = ( new ReflectionClass( Webhook_Listener::class ) )->newInstanceWithoutConstructor();

		$request = new WP_REST_Request( 'POST', '/mailhawk/listen' );
		$request->set_param( 'event', $event );
		$request->set_param( 'payload', $payload );

		$listener->process( $request );
	}

	protected function hard_fail_webhook( $msg_id, $to ) {
		$this->webhook( 'MessageDeliveryFailed', [
			'status'  => 'HardFail',
			'details' => '550 no such user',
			'message' => [ 'message_id' => $msg_id, 'to' => $to ],
		] );
	}

	/**
	 * Keep what is announced as bounced
	 *
	 * @return ArrayObject [ address, msg_id ] for each
	 */
	protected function listen_for_bounces() {

		$bounces = new ArrayObject();

		add_action( 'mailhawk/bounced', function ( $address, $msg_id ) use ( $bounces ) {
			$bounces[] = [ $address, $msg_id ];
		}, 10, 2 );

		return $bounces;
	}

	public function test_a_bounce_the_sync_found_is_not_announced_again_by_the_webhook_that_comes_late() {

		$bounces = $this->listen_for_bounces();

		$this->mta( function () {
			return $this->messages( [ 'late@mta' => [ $this->entry( 'dead@example.net', 'failed', '550 no such user' ) ] ] );
		} );

		$id = $this->add_row( 'late@mta', [ 'dead@example.net' ] );

		$this->sync_with()->sync();

		$this->assertSame( 'failed', $this->row( $id )->status );
		$this->assertCount( 1, $bounces );

		$this->hard_fail_webhook( 'late@mta', 'dead@example.net' );

		$this->assertCount( 1, $bounces, 'It was announced when the sync found it' );
	}

	public function test_a_bounce_the_webhook_announced_is_left_alone_by_the_sync() {

		$bounces = $this->listen_for_bounces();

		$this->mta( function () {
			return $this->messages( [ 'early@mta' => [ $this->entry( 'dead@example.net', 'failed', '550 no such user' ) ] ] );
		} );

		$id = $this->add_row( 'early@mta', [ 'dead@example.net' ] );

		$this->hard_fail_webhook( 'early@mta', 'dead@example.net' );

		$this->assertSame( 'failed', $this->row( $id )->status );

		$this->sync_with()->sync();

		$this->assertSame( [], $this->requests, 'An email that is failed is not asked about' );
		$this->assertCount( 1, $bounces );
	}

	public function test_a_webhook_that_arrives_while_the_sync_is_asking_is_announced_once() {

		$bounces = $this->listen_for_bounces();

		$this->mta( function () {

			// The event gets here while the MTA is answering
			$this->hard_fail_webhook( 'between@mta', 'dead@example.net' );

			return $this->messages( [ 'between@mta' => [ $this->entry( 'dead@example.net', 'failed', '550 no such user' ) ] ] );
		} );

		$id = $this->add_row( 'between@mta', [ 'dead@example.net' ] );

		$this->sync_with()->sync();

		$this->assertSame( 'failed', $this->row( $id )->status );
		$this->assertCount( 1, $bounces );
	}

	public function test_an_event_the_mta_sends_again_is_announced_once() {

		$bounces = $this->listen_for_bounces();

		$this->add_row( 'again@mta', [ 'dead@example.net' ] );
		$this->add_row( 'again-2@mta', [ 'gone@example.net' ] );

		$this->hard_fail_webhook( 'again@mta', 'dead@example.net' );
		$this->hard_fail_webhook( 'again@mta', 'dead@example.net' );

		$bounced = [ 'original_message' => [ 'message_id' => 'again-2@mta', 'to' => 'gone@example.net' ] ];

		$this->webhook( 'MessageBounced', $bounced );
		$this->webhook( 'MessageBounced', $bounced );

		$this->assertSame( [ [ 'dead@example.net', 'again@mta' ], [ 'gone@example.net', 'again-2@mta' ] ], $bounces->getArrayCopy() );
	}

	public function test_every_email_of_an_address_is_its_own_bounce() {

		$bounces = $this->listen_for_bounces();

		$this->add_row( 'one@mta', [ 'a@example.net' ] );
		$this->add_row( 'two@mta', [ 'a@example.net' ] );

		$this->hard_fail_webhook( 'one@mta', 'a@example.net' );
		$this->hard_fail_webhook( 'two@mta', 'a@example.net' );

		// Sent again
		$this->hard_fail_webhook( 'two@mta', 'A@Example.net' );

		$this->assertSame( [
			[ 'a@example.net', 'one@mta' ],
			[ 'a@example.net', 'two@mta' ],
		], $bounces->getArrayCopy() );
	}

	public function test_only_the_first_address_of_an_email_that_does_not_take_it_is_announced() {

		$bounces = $this->listen_for_bounces();

		$this->add_row( 'many@mta', [ 'a@example.net', 'b@example.net' ] );

		$this->hard_fail_webhook( 'many@mta', 'a@example.net' );
		$this->hard_fail_webhook( 'many@mta', 'b@example.net' );

		// The entry is the one for the email, so it has nothing to tell the second address by. An email to more than one
		// address is not common, and the second one is counted on its next email.
		$this->assertSame( [ [ 'a@example.net', 'many@mta' ] ], $bounces->getArrayCopy() );
	}

	public function test_the_sync_and_the_webhook_agree_on_every_address_of_an_email() {

		$bounces = $this->listen_for_bounces();

		$this->mta( function () {
			return $this->messages( [ 'both@mta' => [
				$this->entry( 'a@example.net', 'failed', '550 no such user' ),
				$this->entry( 'b@example.net', 'failed', '550 no such user' ),
			] ] );
		} );

		$this->add_row( 'both@mta', [ 'a@example.net', 'b@example.net' ] );

		$this->sync_with()->sync();
		$this->assertCount( 2, $bounces );

		$this->hard_fail_webhook( 'both@mta', 'a@example.net' );
		$this->hard_fail_webhook( 'both@mta', 'b@example.net' );

		$this->assertCount( 2, $bounces );
	}

	public function test_the_sync_does_not_announce_what_the_email_already_got_past() {

		$bounces = $this->listen_for_bounces();

		$this->mta( function () {

			// A bounce takes the email further than the failure the MTA is about to report
			$this->webhook( 'MessageBounced', [ 'original_message' => [ 'message_id' => 'moved@mta', 'to' => 'gone@example.net' ] ] );

			return $this->messages( [ 'moved@mta' => [
				$this->entry( 'gone@example.net', 'delivered' ),
				$this->entry( 'dead@example.net', 'failed', '550 no such user' ),
			] ] );
		} );

		$id = $this->add_row( 'moved@mta', [ 'gone@example.net', 'dead@example.net' ] );

		$this->sync_with()->sync();

		$this->assertSame( 'bounced', $this->row( $id )->status );
		$this->assertSame( [ [ 'gone@example.net', 'moved@mta' ] ], $bounces->getArrayCopy() );
	}

	public function test_what_the_mta_failed_on_its_own_side_fails_the_email_but_is_not_a_bounce() {

		$bounces = $this->listen_for_bounces();

		$this->mta( function () {
			return $this->messages( [
				'cancelled@mta'   => [ $this->entry( 'held@example.net', 'failed', 'The hold on this message has been removed without action.' ) ],
				'suppressed@mta'  => [ $this->entry( 'held@example.net', 'failed', 'Recipient (held@example.net) is on the suppression list (reason: too many soft fails)' ) ],
				'gave-up@mta'     => [ $this->entry( 'slow@example.net', 'failed', 'Maximum number of delivery attempts (3) has been reached. Added slow@example.net to suppression list because delivery has failed 3 times.' ) ],
				'gave-up-day@mta' => [ $this->entry( 'slow@example.net', 'failed', 'Gave up after 24 hours of temporary failures that were not about the recipient (no answer from the receiving mail server). slow@example.net was not added to the suppression list.' ) ],
				'rejected@mta'    => [ $this->entry( 'dead@example.net', 'failed', 'Permanent SMTP delivery error when sending to mx.example.net' ) ],
				'mixed@mta'       => [
					$this->entry( 'gone@example.net', 'bounced' ),
					$this->entry( 'held@example.net', 'failed', 'The hold on this message has been removed without action.' ),
				],
			] );
		} );

		$ids = [];

		foreach ( [ 'cancelled' => 'held', 'suppressed' => 'held', 'gave-up' => 'slow', 'gave-up-day' => 'slow', 'rejected' => 'dead' ] as $msg => $who ) {
			$ids[ $msg ] = $this->add_row( $msg . '@mta', [ $who . '@example.net' ] );
		}

		$mixed = $this->add_row( 'mixed@mta', [ 'gone@example.net', 'held@example.net' ] );

		$this->sync_with()->sync();

		foreach ( $ids as $msg => $id ) {
			$this->assertSame( 'failed', $this->row( $id )->status, $msg );
		}

		$this->assertSame( 'The hold on this message has been removed without action.', $this->row( $ids['cancelled'] )->error_message );
		$this->assertSame( 'bounced', $this->row( $mixed )->status );

		$this->assertEqualsCanonicalizing( [
			[ 'dead@example.net', 'rejected@mta' ],
			[ 'gone@example.net', 'mixed@mta' ],
		], $bounces->getArrayCopy(), 'Only what the recipient\'s server turned down' );
	}

	public function test_a_bounce_without_an_id_is_always_announced() {

		$bounces = $this->listen_for_bounces();

		$this->hard_fail_webhook( '', 'dead@example.net' );
		$this->hard_fail_webhook( '', 'dead@example.net' );

		$this->assertCount( 2, $bounces, 'Nothing to tell one from the other by' );
	}

	public function test_a_bounce_for_an_email_that_is_not_in_the_log_is_always_announced() {

		$bounces = $this->listen_for_bounces();

		$this->hard_fail_webhook( 'not-ours@mta', 'dead@example.net' );
		$this->hard_fail_webhook( 'not-ours@mta', 'dead@example.net' );

		$this->assertCount( 2, $bounces, 'Nothing to tell one from the other by' );
	}

	public function test_the_one_that_does_not_move_the_entry_does_not_announce() {

		global $wpdb;

		$bounces = $this->listen_for_bounces();
		$table   = Plugin::instance()->log->get_table_name();
		$id      = $this->add_row( 'race@mta', [ 'dead@example.net' ] );

		// Somebody else moves the entry between it being read and it being changed
		$flip = function ( $query ) use ( &$flip, $table, $id, $wpdb ) {

			if ( 0 === strpos( ltrim( $query ), 'UPDATE `' . $table . '`' ) && false !== strpos( $query, "`status` = 'failed'" ) ) {
				remove_filter( 'query', $flip );
				$wpdb->query( "UPDATE `{$table}` SET `status` = 'bounced' WHERE `ID` = {$id}" );
			}

			return $query;
		};

		add_filter( 'query', $flip );

		$this->hard_fail_webhook( 'race@mta', 'dead@example.net' );

		$this->assertSame( 'bounced', $this->row( $id )->status, 'Not taken back to failed' );
		$this->assertSame( [], $bounces->getArrayCopy(), 'It is for the one that moved it to say' );
	}

	public function test_it_is_scheduled_every_fifteen_minutes() {

		$this->assertSame( 15 * MINUTE_IN_SECONDS, wp_get_schedules()['mailhawk_fifteen_minutes']['interval'] );
		$this->assertNotFalse( has_action( Delivery_Sync::HOOK ) );
	}

	/**
	 * When the sync is scheduled to run again soon
	 *
	 * @return int[]
	 */
	protected function follow_ups() {

		$found = [];

		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			if ( isset( $hooks[ Delivery_Sync::HOOK ] ) ) {
				$found[] = $time;
			}
		}

		return $found;
	}
}
