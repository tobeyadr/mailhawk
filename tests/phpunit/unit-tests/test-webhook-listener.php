<?php

use MailHawk\Api\Postal\Postal;
use MailHawk\Api\Webhook_Listener;
use MailHawk\Cron_Events;
use MailHawk\Plugin;
use function MailHawk\get_email_problem_explanation;
use function MailHawk\get_email_status_pretty_name;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;

/**
 * What the MTA tells the site about an email ends up on the email in the log. The events are handled the way the MTA
 * sends them, through the route's callback, which is after the signature was checked.
 */
class Test_Webhook_Listener extends WP_UnitTestCase {

	/**
	 * @var Webhook_Listener
	 */
	protected $listener;

	/**
	 * What the MTA was asked, in order
	 *
	 * @var string[]
	 */
	protected $sent_to_mta = [];

	public function set_up() {
		parent::set_up();

		// The constructor registers the route, which is for rest_api_init
		$this->listener = ( new ReflectionClass( Webhook_Listener::class ) )->newInstanceWithoutConstructor();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Plugin::instance()->log->get_table_name() );
		Plugin::instance()->log->flush_cache();

		$this->sent_to_mta = [];
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		delete_option( 'mailhawk_retry_failed_emails' );
		parent::tear_down();
	}

	protected function add_entry( $msg_id, $status = 'sent', $recipients = [ 'bob@example.net' ] ) {

		$id = Plugin::instance()->log->add( [
			'msg_id'       => $msg_id,
			'recipients'   => $recipients,
			'from_address' => 'info@example.com',
			'subject'      => 'Your order',
			'content'      => 'Thanks for your order',
			'headers'      => [ [ 'Content-Type', 'text/plain' ] ],
			'status'       => $status,
			'date_sent'    => current_time( 'mysql' ),
		] );

		$this->assertNotFalse( $id );

		return $id;
	}

	protected function entry( $id ) {
		Plugin::instance()->log->flush_cache();

		return Plugin::instance()->log->get( $id );
	}

	/**
	 * @param string $event
	 * @param array  $payload
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected function receive( $event, $payload ) {

		$request = new WP_REST_Request( 'POST', '/mailhawk/listen' );
		$request->set_param( 'event', $event );
		$request->set_param( 'payload', $payload );

		return $this->listener->process( $request );
	}

	protected function message( $msg_id, $extra = [] ) {
		return array_merge( [ 'message' => [ 'message_id' => $msg_id, 'to' => 'bob@example.net' ] ], $extra );
	}

	/**
	 * Answer the requests to the MTA as it does when an email is accepted
	 */
	protected function accept_email_as( $message_id ) {

		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );
		Postal::set_api_key( 'x' );

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $message_id ) {

			$this->sent_to_mta[] = substr( $url, strpos( $url, '/api/v1/' ) + 8 );

			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'status' => 'success', 'data' => [ 'message_id' => $message_id ] ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );
	}

	public function test_an_email_the_mta_delivered_is_marked_delivered() {

		$id = $this->add_entry( 'one@mta', 'softfail' );

		$this->assertTrue( $this->receive( 'MessageSent', $this->message( 'one@mta' ) )->get_data()['success'] );

		$entry = $this->entry( $id );

		$this->assertSame( 'delivered', $entry->status );
		$this->assertSame( '', $entry->error_code );
		$this->assertSame( '', $entry->error_message );
	}

	public function test_a_delayed_email_says_why() {

		$id = $this->add_entry( 'one@mta' );

		$this->receive( 'MessageDelayed', $this->message( 'one@mta', [ 'status' => 'Held', 'details' => 'Mailbox busy, will try again' ] ) );

		$entry = $this->entry( $id );

		$this->assertSame( 'softfail', $entry->status, 'Whatever the MTA calls it' );
		$this->assertSame( 'MessageDelayed', $entry->error_code );
		$this->assertSame( 'Mailbox busy, will try again', $entry->error_message );
	}

	public function test_a_failed_delivery_says_why_and_a_hard_failure_is_a_bounce() {

		$id = $this->add_entry( 'one@mta' );

		$bounces = [];
		add_action( 'mailhawk/bounced', function ( $address, $msg_id ) use ( &$bounces ) {
			$bounces[] = [ $address, $msg_id ];
		}, 10, 2 );

		$this->receive( 'MessageDeliveryFailed', $this->message( 'one@mta', [ 'status' => 'SoftFail', 'details' => 'Try later' ] ) );

		$this->assertSame( [], $bounces, 'A soft failure is not the end of it' );
		$this->assertSame( 'softfail', $this->entry( $id )->status, 'It is only held up while the MTA tries again' );

		$this->receive( 'MessageDeliveryFailed', $this->message( 'one@mta', [ 'status' => 'HardFail', 'details' => '550 No such user' ] ) );

		$entry = $this->entry( $id );

		$this->assertSame( 'failed', $entry->status );
		$this->assertSame( '550 No such user', $entry->error_message );
		$this->assertSame( [ [ 'bob@example.net', 'one@mta' ] ], $bounces );
	}

	/**
	 * What the recipient list knows about the address, false when nothing
	 *
	 * @param string $email
	 *
	 * @return object|false
	 */
	protected function recipient( $email ) {

		$recipients = Plugin::instance()->recipients;

		if ( ! $recipients->installed() ) {
			$recipients->create_table();
		}

		$recipients->flush_cache();

		return $recipients->get_by_email( $email );
	}

	/**
	 * Failures the MTA made itself, as it words them
	 *
	 * @return array[]
	 */
	public function mta_side_failures() {
		return [
			'a cancelled hold'            => [ 'HoldCancelled', 'The hold on this message has been removed without action.', '' ],
			'its suppression list'        => [ 'HardFail', 'Recipient (bob@example.net) is on the suppression list (reason: too many soft fails)', '' ],
			'giving up after timeouts'    => [ 'HardFail', 'Maximum number of delivery attempts (3) has been reached. Added bob@example.net to suppression list because delivery has failed 3 times.', '' ],
			'a timeout as the last word'  => [ 'HardFail', 'An error occurred while sending the message to mx.example.net', 'execution expired' ],
			'a day of no answer'          => [ 'HardFail', 'Gave up after 24 hours of temporary failures that were not about the recipient (no answer from the receiving mail server). bob@example.net was not added to the suppression list.', '' ],
			'a day of our IP turned away' => [ 'HardFail', 'Gave up after 24 hours of temporary failures that were not about the recipient (the receiving mail server turned our IP away). bob@example.net was not added to the suppression list.', '554 5.7.1 Service unavailable; bad reputation' ],
			'a day of slowing down'       => [ 'HardFail', 'Gave up after 24 hours of temporary failures that were not about the recipient (the provider asked us to slow down). bob@example.net was not added to the suppression list.', '' ],
		];
	}

	/**
	 * @dataProvider mta_side_failures
	 */
	public function test_a_failure_on_the_mta_side_fails_the_email_but_is_not_held_against_the_recipient( $status, $details, $output ) {

		$this->assertFalse( $this->recipient( 'bob@example.net' ), 'Nothing known about the address to begin with' );

		$id = $this->add_entry( 'one@mta' );

		$bounces = [];
		add_action( 'mailhawk/bounced', function ( $address, $msg_id ) use ( &$bounces ) {
			$bounces[] = [ $address, $msg_id ];
		}, 10, 2 );

		$this->receive( 'MessageDeliveryFailed', $this->message( 'one@mta', [ 'status' => $status, 'details' => $details, 'output' => $output ] ) );

		$entry = $this->entry( $id );

		$this->assertSame( 'failed', $entry->status, 'It was never sent' );
		$this->assertSame( 'MessageDeliveryFailed', $entry->error_code );
		$this->assertSame( $details, $entry->error_message );

		$this->assertSame( [], $bounces );
		$this->assertFalse( $this->recipient( 'bob@example.net' ), 'No bounce counted, and not watched' );
	}

	public function test_a_5xx_answer_from_the_recipients_server_counts_against_the_address() {

		$this->assertFalse( $this->recipient( 'bob@example.net' ) );

		$id = $this->add_entry( 'one@mta' );

		$this->receive( 'MessageDeliveryFailed', $this->message( 'one@mta', [
			'status'  => 'HardFail',
			'details' => 'Permanent SMTP delivery error when sending to mx.example.net. Recipient added to suppression list (too many hard fails).',
			'output'  => '550 5.1.1 <bob@example.net>: Recipient address rejected: User unknown',
		] ) );

		$this->assertSame( 'failed', $this->entry( $id )->status );

		$row = $this->recipient( 'bob@example.net' );

		$this->assertNotFalse( $row );
		$this->assertEquals( 1, $row->bounces );
		$this->assertSame( '', $row->status, 'Watched' );
		$this->assertSame( 'webhook', $row->source );
	}

	public function test_only_an_answer_from_the_recipients_server_is_a_rejection() {

		$this->assertTrue( Webhook_Listener::is_rejected_by_recipient( 'Permanent SMTP delivery error when sending to mx.example.net' ) );
		$this->assertTrue( Webhook_Listener::is_rejected_by_recipient( 'Something else', '554 5.7.1 Rejected' ) );
		$this->assertTrue( Webhook_Listener::is_rejected_by_recipient( '550 No such user' ) );

		$this->assertFalse( Webhook_Listener::is_rejected_by_recipient( 'The hold on this message has been removed without action.' ) );
		$this->assertFalse( Webhook_Listener::is_rejected_by_recipient( 'Recipient (bob@example.net) is on the suppression list (reason: hard fail)' ) );
		$this->assertFalse( Webhook_Listener::is_rejected_by_recipient( 'Temporary SMTP delivery error when sending to mx.example.net', '421 Try again later' ) );
		$this->assertFalse( Webhook_Listener::is_rejected_by_recipient( '', '' ) );
	}

	public function test_a_bounce_and_a_spam_report_are_marked_and_reported() {

		$bounced   = $this->add_entry( 'bounce@mta' );
		$complaint = $this->add_entry( 'spam@mta' );

		$reports = [];
		add_action( 'mailhawk/spam_complaint', function ( $address, $msg_id, $details ) use ( &$reports ) {
			$reports[] = [ $address, $msg_id, $details ];
		}, 10, 3 );

		$this->receive( 'MessageBounced', [ 'original_message' => [ 'message_id' => 'bounce@mta', 'to' => 'bob@example.net' ] ] );
		$this->receive( 'MessageSpamComplaint', $this->message( 'spam@mta', [ 'complaint' => [ 'feedback_type' => 'abuse' ] ] ) );

		$this->assertSame( 'bounced', $this->entry( $bounced )->status );
		$this->assertSame( 'Bounced', $this->entry( $bounced )->error_code );
		$this->assertSame( 'complained', $this->entry( $complaint )->status );
		$this->assertSame( [ [ 'bob@example.net', 'spam@mta', [ 'feedback_type' => 'abuse' ] ] ], $reports );
	}

	public function test_an_event_that_arrives_late_does_not_undo_a_later_one() {

		$delivered  = $this->add_entry( 'delivered@mta' );
		$bounced    = $this->add_entry( 'bounced@mta' );
		$complained = $this->add_entry( 'complained@mta' );
		$failed     = $this->add_entry( 'failed@mta' );

		$this->receive( 'MessageSent', $this->message( 'delivered@mta' ) );
		$this->receive( 'MessageDelayed', $this->message( 'delivered@mta', [ 'details' => 'Late news' ] ) );
		$this->assertSame( 'delivered', $this->entry( $delivered )->status, 'A delay does not undo a delivery' );
		$this->assertSame( '', $this->entry( $delivered )->error_message );

		$this->receive( 'MessageBounced', [ 'original_message' => [ 'message_id' => 'bounced@mta', 'to' => 'bob@example.net' ] ] );
		$this->receive( 'MessageSent', $this->message( 'bounced@mta' ) );
		$this->assertSame( 'bounced', $this->entry( $bounced )->status, 'A delivery does not hide a bounce' );

		$this->receive( 'MessageSpamComplaint', $this->message( 'complained@mta' ) );
		$this->receive( 'MessageBounced', [ 'original_message' => [ 'message_id' => 'complained@mta', 'to' => 'bob@example.net' ] ] );
		$this->receive( 'MessageSent', $this->message( 'complained@mta' ) );
		$this->assertSame( 'complained', $this->entry( $complained )->status, 'Nothing hides a spam report' );

		$this->receive( 'MessageDeliveryFailed', $this->message( 'failed@mta', [ 'status' => 'HardFail', 'details' => 'Gone' ] ) );
		$this->receive( 'MessageSent', $this->message( 'failed@mta' ) );
		$this->assertSame( 'failed', $this->entry( $failed )->status );
		$this->assertSame( 'Gone', $this->entry( $failed )->error_message );
	}

	public function test_events_for_other_emails_and_incomplete_events_change_nothing() {

		$id = $this->add_entry( 'mine@mta' );

		$this->receive( 'MessageSent', $this->message( 'someone-elses@mta' ) );
		$this->receive( 'MessageSent', [] );
		$this->receive( 'MessageSent', [ 'message' => [] ] );
		$this->receive( 'MessageDelayed', [] );
		$this->receive( 'MessageDeliveryFailed', [] );
		$this->receive( 'SomethingNew', $this->message( 'mine@mta' ) );

		$this->assertSame( 'sent', $this->entry( $id )->status );

		$this->assertWPError( $this->receive( 'MessageBounced', [] ), 'A bounce has to say who it was for' );
		$this->assertWPError( $this->receive( 'MessageSpamComplaint', $this->message( 'mine@mta', [ 'message' => [ 'message_id' => 'mine@mta', 'to' => 'nope' ] ] ) ) );
		$this->assertSame( 'sent', $this->entry( $id )->status );
	}

	public function test_a_batch_is_handled_event_by_event_and_a_bad_one_does_not_fail_it() {

		$one = $this->add_entry( 'one@mta' );
		$two = $this->add_entry( 'two@mta' );

		$response = $this->receive( 'Batch', [
			'events' => [
				[ 'event' => 'MessageDelayed', 'payload' => $this->message( 'one@mta', [ 'details' => 'Wait' ] ) ],
				'not an event',
				[ 'event' => 'MessageBounced', 'payload' => [] ],
				[ 'event' => 'MessageSent', 'payload' => $this->message( 'one@mta' ) ],
				[ 'event' => 'MessageSent', 'payload' => $this->message( 'two@mta' ) ],
			],
		] )->get_data();

		$this->assertSame( [ 'success' => true, 'received' => 5, 'processed' => 3 ], $response );
		$this->assertSame( 'delivered', $this->entry( $one )->status, 'In the order they came' );
		$this->assertSame( 'delivered', $this->entry( $two )->status );
	}

	public function test_failed_emails_are_retried_only_when_the_mta_never_took_them() {

		$this->accept_email_as( 'retried@mta' );
		update_option( 'mailhawk_retry_failed_emails', 1 );

		// One on each side of it, whichever end the cron starts from
		$dead_first = $this->add_entry( 'dead1@mta', 'failed', [ 'dead1@example.net' ] );
		$never_sent = $this->add_entry( '', 'failed', [ 'a@example.net' ] );
		$dead_last  = $this->add_entry( 'dead2@mta', 'failed', [ 'dead2@example.net' ] );

		( new Cron_Events() )->retry_failed_emails();

		$this->assertSame( 'sent', $this->entry( $never_sent )->status, 'The MTA never got this one' );
		$this->assertSame( 'retried@mta', $this->entry( $never_sent )->msg_id );

		foreach ( [ $dead_first => 'dead1@mta', $dead_last => 'dead2@mta' ] as $id => $msg_id ) {
			$this->assertSame( 'failed', $this->entry( $id )->status, 'The MTA took it and the address does not exist' );
			$this->assertSame( $msg_id, $this->entry( $id )->msg_id );
		}

		$this->assertSame( [ 'send/raw' ], $this->sent_to_mta, 'One email went out' );
	}

	public function test_every_status_has_a_name_and_an_explanation() {

		foreach ( array_keys( Webhook_Listener::DELIVERY_STAGES ) as $status ) {
			$this->assertNotEmpty( get_email_status_pretty_name( $status ), $status );
		}

		$this->assertSame( 'Delayed', get_email_status_pretty_name( 'softfail' ) );

		$this->assertStringContainsString( 'delayed', get_email_problem_explanation( 'softfail', true ) );
		$this->assertStringContainsString( 'bounced', get_email_problem_explanation( 'bounced', true ) );
		$this->assertStringContainsString( 'spam', get_email_problem_explanation( 'complained', true ) );
		$this->assertStringContainsString( 'could not be delivered', get_email_problem_explanation( 'failed', true ) );
		$this->assertStringContainsString( 'problem sending', get_email_problem_explanation( 'failed', false ), 'MailHawk never took it' );
	}

	public function test_nothing_is_retried_when_retries_are_off() {

		$this->accept_email_as( 'retried@mta' );

		$id = $this->add_entry( '', 'failed' );

		( new Cron_Events() )->retry_failed_emails();

		$this->assertSame( 'failed', $this->entry( $id )->status );
		$this->assertSame( [], $this->sent_to_mta );
	}
}
