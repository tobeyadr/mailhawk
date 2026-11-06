<?php

use MailHawk\Api\Postal\Postal;
use MailHawk\Classes\Email_Log_Item;
use MailHawk\DB\Recipients;
use MailHawk\Hawk_Mailer;
use MailHawk\Plugin;
use function MailHawk\assess_risk;

class Test_Recipients extends WP_UnitTestCase {

	/**
	 * @var Recipients
	 */
	protected $recipients;

	public function set_up() {
		parent::set_up();

		$this->recipients = Plugin::instance()->recipients;

		if ( ! $this->recipients->installed() ) {
			$this->recipients->create_table();
		}

		$this->assertTrue( $this->recipients->is_ready() );

		// Dedupe transients live in the options table, so they are rolled back with each test
		delete_option( 'mailhawk_disable_email_logging' );
	}

	/**
	 * Nothing in these tests may reach the MTA
	 */
	protected function block_http() {
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'blocked', 'HTTP is blocked in tests' );
		} );
	}

	/**
	 * Pretend the MTA accepts every message
	 */
	protected function fake_successful_send() {

		Postal::set_api_key( 'x' );

		add_filter( 'pre_http_request', function () {
			return [
				'headers'  => [],
				'body'     => '{"status":"success","data":{"message_id":"abc"}}',
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		} );
	}

	protected function get_row( $email ) {
		return $this->recipients->get_by( 'email', strtolower( $email ), '', false );
	}

	protected function add_quarantined_item( $recipients = [ 'someone@example.net' ] ) {

		$id = Plugin::instance()->log->add( [
			'recipients'   => $recipients,
			'from_address' => 'info@example.com',
			'subject'      => 'Test',
			'content'      => 'Some email content',
			'headers'      => [ [ 'Content-Type', 'text/plain' ], [ 'From', 'Test <info@example.com>' ] ],
			'status'       => 'quarantine',
			'date_sent'    => date( 'Y-m-d H:i:s' ),
		] );

		$this->assertNotFalse( $id );

		return new Email_Log_Item( $id );
	}

	public function test_trust_adds_a_trusted_row_and_normalizes_the_address() {

		$this->assertTrue( $this->recipients->trust( '  Friend@Example.NET ' ) );

		$row = $this->get_row( 'friend@example.net' );

		$this->assertNotEmpty( $row );
		$this->assertSame( Recipients::TRUSTED, $row->status );
		$this->assertSame( 'release', $row->source );

		// Trusting again is fine and does not add a duplicate
		$this->assertTrue( $this->recipients->trust( 'friend@example.net' ) );
		$this->assertCount( 1, $this->recipients->query( [ 'email' => 'friend@example.net' ], '', false ) );
	}

	public function test_trust_rejects_invalid_addresses() {
		$this->assertFalse( $this->recipients->trust( 'not-an-email' ) );
		$this->assertFalse( $this->recipients->trust( '' ) );
		$this->assertFalse( $this->recipients->trust( str_repeat( 'a', 200 ) . '@example.net' ) );
	}

	public function test_trusted_address_is_not_risky() {

		// A free provider scores above zero on its own
		$this->assertGreaterThan( 0, assess_risk( 'someone@gmail.com' ) );

		$this->recipients->trust( 'someone@gmail.com' );

		$this->assertSame( 0, assess_risk( 'someone@gmail.com' ) );
	}

	public function test_bounce_webhook_adds_a_row_and_counts_bounces() {

		do_action( 'mailhawk/bounced', 'Dead@Example.net', 'msg-1' );
		do_action( 'mailhawk/bounced', 'dead@example.net', 'msg-2' );

		$row = $this->get_row( 'dead@example.net' );

		$this->assertNotEmpty( $row );
		$this->assertEquals( 2, $row->bounces );
		$this->assertEquals( 0, $row->complaints );
		$this->assertSame( '', $row->status );
		$this->assertSame( 'webhook', $row->source );
	}

	public function test_the_same_bounce_event_is_only_counted_once() {

		do_action( 'mailhawk/bounced', 'dead@example.net', 'msg-1' );
		do_action( 'mailhawk/bounced', 'dead@example.net', 'msg-1' );

		$this->assertEquals( 1, $this->get_row( 'dead@example.net' )->bounces );
	}

	public function test_bounces_make_an_address_risky_and_the_risk_fades() {

		$email = 'dead@example.net';

		$this->assertSame( 0, assess_risk( $email ) );

		do_action( 'mailhawk/bounced', $email, 'msg-1' );
		$one_bounce = assess_risk( $email );
		$this->assertSame( 3, $one_bounce );

		do_action( 'mailhawk/bounced', $email, 'msg-2' );

		// Two bounces reach the quarantine threshold
		$this->assertGreaterThanOrEqual( 5, assess_risk( $email ) );

		// The risk is capped at three bounces
		do_action( 'mailhawk/bounced', $email, 'msg-3' );
		do_action( 'mailhawk/bounced', $email, 'msg-4' );
		$this->assertSame( 9, assess_risk( $email ) );

		// Once the last bounce is older than the memory, the address is forgiven
		global $wpdb;
		$wpdb->update( $this->recipients->table_name, [ 'last_bounce' => date( 'Y-m-d H:i:s', strtotime( '120 days ago' ) ) ], [ 'email' => $email ] );
		$this->recipients->set_last_changed();

		$this->assertSame( 0, assess_risk( $email ) );
	}

	public function test_spam_complaint_blocks_the_address() {

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );

		$row = $this->get_row( 'angry@example.net' );

		$this->assertSame( Recipients::BLOCKED, $row->status );
		$this->assertEquals( 1, $row->complaints );
		$this->assertGreaterThanOrEqual( 5, assess_risk( 'angry@example.net' ) );
	}

	public function test_spam_complaint_is_deduped_by_message() {

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );
		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );

		$this->assertEquals( 1, $this->get_row( 'angry@example.net' )->complaints );
	}

	public function test_a_complaint_withdraws_trust_and_stops_it_being_given_again() {

		$this->recipients->trust( 'angry@example.net' );
		$this->assertSame( 0, assess_risk( 'angry@example.net' ) );

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );

		$this->assertSame( Recipients::BLOCKED, $this->get_row( 'angry@example.net' )->status );
		$this->assertGreaterThanOrEqual( 5, assess_risk( 'angry@example.net' ) );

		$this->assertFalse( $this->recipients->trust( 'angry@example.net' ) );
		$this->assertSame( Recipients::BLOCKED, $this->get_row( 'angry@example.net' )->status );
	}

	public function test_a_bounce_withdraws_trust() {

		$this->recipients->trust( 'moved@example.net' );

		do_action( 'mailhawk/bounced', 'moved@example.net', 'msg-1' );

		$row = $this->get_row( 'moved@example.net' );

		$this->assertSame( '', $row->status );
		$this->assertEquals( 1, $row->bounces );
		$this->assertSame( 3, assess_risk( 'moved@example.net' ) );
	}

	public function test_deleting_a_row_forgets_the_address() {

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );

		$row = $this->get_row( 'angry@example.net' );

		$this->recipients->delete( $row->ID );
		$this->recipients->set_last_changed();

		$this->assertEmpty( $this->get_row( 'angry@example.net' ) );
		$this->assertSame( 0, assess_risk( 'angry@example.net' ) );
	}

	public function test_release_and_trust_sends_the_email_and_trusts_the_recipients() {

		$this->fake_successful_send();

		$item = $this->add_quarantined_item( [ 'one@example.net', 'two@example.net' ] );

		$this->assertTrue( $item->release_and_trust() );

		$this->assertSame( Recipients::TRUSTED, $this->get_row( 'one@example.net' )->status );
		$this->assertSame( Recipients::TRUSTED, $this->get_row( 'two@example.net' )->status );
		$this->assertSame( 'sent', Plugin::instance()->log->get( $item->get_id() )->status );
	}

	public function test_release_and_trust_does_not_trust_when_the_release_fails() {

		$this->block_http();
		Postal::set_api_key( 'x' );

		$item = $this->add_quarantined_item( [ 'one@example.net' ] );

		$this->assertFalse( $item->release_and_trust() );
		$this->assertEmpty( $this->get_row( 'one@example.net' ) );
	}

	public function test_release_and_trust_leaves_complainers_untrusted() {

		$this->fake_successful_send();

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );

		$item = $this->add_quarantined_item( [ 'angry@example.net' ] );
		$item->release_and_trust();

		$this->assertSame( Recipients::BLOCKED, $this->get_row( 'angry@example.net' )->status );
	}

	public function test_releasing_with_logging_disabled_still_updates_the_log() {

		$this->fake_successful_send();

		update_option( 'mailhawk_disable_email_logging', 1 );

		$result = ( new Hawk_Mailer( true ) );
		$result->setFrom( 'info@example.com', 'Test' );
		$result->Subject = 'Hello';
		$result->Body    = 'Hello there';
		$result->addAddress( 'someone+test-quarantine@example.net' );

		$this->assertTrue( $result->send() );

		// The held email is logged even though logging is off, nothing else is
		$log   = Plugin::instance()->log;
		$held  = $log->query( [ 'status' => 'quarantine' ], '', false );

		$this->assertCount( 1, $held );

		$item = new Email_Log_Item( $held[0]->ID );

		$this->assertTrue( $item->release() );

		// The row is updated, not left in quarantine, so it can't be released twice
		$this->assertSame( 'sent', $log->get( $item->get_id() )->status );
		$this->assertCount( 0, $log->query( [ 'status' => 'quarantine' ], '', false ) );
		$this->assertFalse( ( new Email_Log_Item( $item->get_id() ) )->release() );
	}

	public function test_the_update_drops_the_legacy_email_addresses_table() {

		global $wpdb;

		$legacy = $wpdb->prefix . 'mh_email_addresses';

		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$legacy}` ( ID bigint(20) unsigned NOT NULL AUTO_INCREMENT, email varchar(50) NOT NULL, PRIMARY KEY (ID) )" );
		update_option( $legacy . '_db_version', '1.0' );

		// The test suite creates tables as temporary tables, which SHOW TABLES doesn't list, so query the table instead
		$table_exists = function () use ( $wpdb, $legacy ) {
			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->query( "SELECT 1 FROM `{$legacy}` LIMIT 1" );
			$wpdb->suppress_errors( $suppress );

			return $result !== false;
		};

		$this->assertTrue( $table_exists() );

		// The update also re-registers the webhook with the MTA
		$this->block_http();

		Plugin::instance()->updater->version_1_4_0();

		$this->assertFalse( $table_exists() );
		$this->assertFalse( get_option( $legacy . '_db_version' ) );

		// The recipients table is still there
		$this->assertTrue( $this->recipients->installed() );
	}

	public function test_new_log_rows_are_not_created_when_logging_is_disabled() {

		$this->fake_successful_send();

		update_option( 'mailhawk_disable_email_logging', 1 );

		$mailer = new Hawk_Mailer( true );
		$mailer->setFrom( 'info@example.com', 'Test' );
		$mailer->Subject = 'Hello';
		$mailer->Body    = 'Hello there';
		$mailer->addAddress( 'safe@example.net' );

		$this->assertTrue( $mailer->send() );
		$this->assertEquals( 0, Plugin::instance()->log->count() );
	}
}
