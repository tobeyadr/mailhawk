<?php

use MailHawk\DB\Recipients;
use MailHawk\Plugin;
use MailHawk\Privacy;

class Test_Privacy extends WP_UnitTestCase {

	/**
	 * @var Privacy
	 */
	protected $privacy;

	/**
	 * @var Recipients
	 */
	protected $recipients;

	public function set_up() {
		parent::set_up();

		$this->privacy    = new Privacy();
		$this->recipients = Plugin::instance()->recipients;

		if ( ! $this->recipients->installed() ) {
			$this->recipients->create_table();
		}
	}

	protected function add_log_item( $recipients, $status = 'sent' ) {

		$id = Plugin::instance()->log->add( [
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

	protected function log_has( $id ) {
		return (bool) Plugin::instance()->log->get( $id );
	}

	public function test_both_erasers_are_registered_with_wordpress() {

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', [] );

		$this->assertArrayHasKey( 'mailhawk-email-log', $erasers );
		$this->assertArrayHasKey( 'mailhawk-recipients', $erasers );
		$this->assertIsCallable( $erasers['mailhawk-email-log']['callback'] );
		$this->assertIsCallable( $erasers['mailhawk-recipients']['callback'] );

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );

		$this->assertArrayHasKey( 'mailhawk-email-log', $exporters );
		$this->assertArrayHasKey( 'mailhawk-recipients', $exporters );
		$this->assertIsCallable( $exporters['mailhawk-email-log']['callback'] );
		$this->assertIsCallable( $exporters['mailhawk-recipients']['callback'] );
	}

	public function test_the_emails_sent_to_a_person_are_exported_without_the_other_recipients() {

		$only  = $this->add_log_item( [ 'bob@example.com' ] );
		$both  = $this->add_log_item( [ 'Bob <BOB@example.com>', 'alice@example.com' ] );
		$other = $this->add_log_item( [ 'alice@example.com' ] );
		$this->add_log_item( [ 'notbob@example.com' ] );

		$export = $this->privacy->export_email_log( 'Bob@Example.com' );

		$this->assertTrue( $export['done'] );
		$this->assertSame( [ 'mailhawk-email-' . $only, 'mailhawk-email-' . $both ], wp_list_pluck( $export['data'], 'item_id' ) );

		$item = array_column( $export['data'][1]['data'], 'value', 'name' );

		$this->assertSame( 'bob@example.com', $item['Sent to'] );
		$this->assertSame( 'Your order', $item['Subject'] );
		$this->assertSame( 'Thanks for your order', $item['Content'] );
		$this->assertStringNotContainsString( 'alice', wp_json_encode( $export ), 'The other recipients are not his to see' );
		$this->assertTrue( $this->log_has( $only ), 'Exporting removes nothing' );
	}

	public function test_exported_content_is_text_and_not_the_whole_email() {

		$html = $this->add_log_item( [ 'bob@example.com' ] );
		Plugin::instance()->log->update( $html, [ 'content' => '<p>Hello <b>Bob</b></p>' . str_repeat( 'long ', 1000 ) ] );

		$item = array_column( $this->privacy->export_email_log( 'bob@example.com' )['data'][0]['data'], 'value', 'name' );

		$this->assertStringStartsWith( 'Hello Bob long', $item['Content'] );
		$this->assertStringNotContainsString( '<', $item['Content'] );
		$this->assertLessThanOrEqual( Privacy::EXPORT_CONTENT_LENGTH + 1, mb_strlen( $item['Content'] ) );
	}

	public function test_the_export_is_done_in_batches() {

		for ( $i = 0; $i < Privacy::BATCH + 5; $i ++ ) {
			$this->add_log_item( [ 'bob@example.com' ] );
		}

		$first  = $this->privacy->export_email_log( 'bob@example.com', 1 );
		$second = $this->privacy->export_email_log( 'bob@example.com', 2 );

		$this->assertFalse( $first['done'] );
		$this->assertCount( Privacy::BATCH, $first['data'] );
		$this->assertTrue( $second['done'] );
		$this->assertCount( 5, $second['data'] );
	}

	public function test_nothing_is_exported_for_a_stranger_or_something_that_is_not_an_address() {

		$this->add_log_item( [ 'bob@example.com' ] );

		$this->assertSame( [], $this->privacy->export_email_log( 'nobody@example.com' )['data'] );
		$this->assertSame( [], $this->privacy->export_email_log( 'not an address' )['data'] );
		$this->assertSame( [], $this->privacy->export_recipient( 'nobody@example.com' )['data'] );
	}

	public function test_what_is_known_about_an_address_is_exported() {

		$this->recipients->trust( 'bob@example.com' );
		$this->recipients->record_bounce( 'dead@example.com' );
		$this->recipients->record_complaint( 'angry@example.com' );

		$values = function ( $email ) {
			return array_column( $this->privacy->export_recipient( $email )['data'][0]['data'], 'value', 'name' );
		};

		$this->assertSame( 'Trusted', $values( 'Bob@example.com' )['Status'] );
		$this->assertArrayNotHasKey( 'Last bounce', $values( 'bob@example.com' ) );

		$dead = $values( 'dead@example.com' );
		$this->assertSame( 'Some email to the address bounced', $dead['Status'] );
		$this->assertSame( '1', $dead['Bounces'] );
		$this->assertArrayHasKey( 'Last bounce', $dead );

		$this->assertSame( 'Reported email from this site as spam', $values( 'angry@example.com' )['Status'] );
		$this->assertSame( '1', $values( 'angry@example.com' )['Spam reports'] );
	}

	public function test_emails_sent_only_to_the_person_are_erased() {

		$only      = $this->add_log_item( [ 'bob@example.com' ] );
		$held      = $this->add_log_item( [ 'bob@example.com' ], 'quarantine' );
		$failed    = $this->add_log_item( [ 'bob@example.com' ], 'failed' );
		$someone   = $this->add_log_item( [ 'alice@example.com' ] );

		$response = $this->privacy->erase_email_log( 'bob@example.com' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertTrue( $response['done'] );
		$this->assertSame( [], $response['messages'] );

		$this->assertFalse( $this->log_has( $only ) );
		$this->assertFalse( $this->log_has( $held ), 'A held email is erased too, so it is never released' );
		$this->assertFalse( $this->log_has( $failed ) );
		$this->assertTrue( $this->log_has( $someone ), 'Other people are left alone' );
	}

	public function test_emails_that_went_to_other_people_too_are_kept_and_said_so() {

		$shared = $this->add_log_item( [ 'bob@example.com', 'alice@example.com' ] );
		$other  = $this->add_log_item( [ 'carol@example.com', 'bob@example.com', 'dave@example.com' ] );
		$only   = $this->add_log_item( [ 'bob@example.com' ] );

		$response = $this->privacy->erase_email_log( 'bob@example.com' );

		$this->assertTrue( $this->log_has( $shared ) );
		$this->assertTrue( $this->log_has( $other ) );
		$this->assertFalse( $this->log_has( $only ) );

		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertCount( 1, $response['messages'] );
		$this->assertStringContainsString( '2 emails', $response['messages'][0] );
		$this->assertStringContainsString( 'also sent to other people', $response['messages'][0] );
		$this->assertStringContainsString( '14 days', $response['messages'][0] );
	}

	public function test_the_address_is_matched_whole_whatever_its_case_or_name() {

		$upper   = $this->add_log_item( [ 'BOB@Example.com' ] );
		$named   = $this->add_log_item( [ 'Bob Smith <bob@example.com>' ] );
		$twice   = $this->add_log_item( [ 'bob@example.com', 'Bob@example.com' ] );
		$longer  = $this->add_log_item( [ 'bigbob@example.com' ] );
		$another = $this->add_log_item( [ 'bob@example.com.au' ] );

		$response = $this->privacy->erase_email_log( ' Bob@Example.com ' );

		$this->assertFalse( $this->log_has( $upper ) );
		$this->assertFalse( $this->log_has( $named ) );
		$this->assertFalse( $this->log_has( $twice ), 'The same address twice is still only them' );
		$this->assertTrue( $this->log_has( $longer ), 'An address that merely ends the same is someone else' );
		$this->assertTrue( $this->log_has( $another ) );
		$this->assertFalse( $response['items_retained'] );
	}

	public function test_nothing_to_erase_is_not_an_error() {

		$kept = $this->add_log_item( [ 'alice@example.com' ] );

		$response = $this->privacy->erase_email_log( 'nobody@example.com' );

		$this->assertFalse( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertTrue( $response['done'] );
		$this->assertTrue( $this->log_has( $kept ) );

		// And an address that is not one
		$this->assertTrue( $this->privacy->erase_email_log( 'not an address' )['done'] );
		$this->assertTrue( $this->log_has( $kept ) );
	}

	public function test_a_big_log_is_erased_in_batches_without_skipping_any() {

		// Kept entries come first, so the ones erased are not where the next batch would start from by page number
		$shared = [];

		for ( $i = 0; $i < 3; $i ++ ) {
			$shared[] = $this->add_log_item( [ 'bob@example.com', 'alice@example.com' ] );
		}

		$sole = [];

		for ( $i = 0; $i < Privacy::BATCH + 20; $i ++ ) {
			$sole[] = $this->add_log_item( [ 'bob@example.com' ] );
		}

		$first = $this->privacy->erase_email_log( 'bob@example.com', 1 );

		$this->assertFalse( $first['done'] );
		$this->assertTrue( $first['items_removed'] );
		$this->assertSame( [], $first['messages'], 'Said once, at the end' );

		$second = $this->privacy->erase_email_log( 'bob@example.com', 2 );

		$this->assertTrue( $second['done'] );
		$this->assertCount( 1, $second['messages'] );
		$this->assertStringContainsString( '3 emails', $second['messages'][0] );

		foreach ( $sole as $id ) {
			$this->assertFalse( $this->log_has( $id ) );
		}

		foreach ( $shared as $id ) {
			$this->assertTrue( $this->log_has( $id ) );
		}
	}

	public function test_a_person_who_asked_to_stop_is_not_forgotten() {

		$this->recipients->record_complaint( 'angry@example.com' );

		$response = $this->privacy->erase_recipient( 'angry@example.com' );

		$this->assertFalse( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertStringContainsString( 'reported email as spam', $response['messages'][0] );
		$this->assertNotFalse( $this->recipients->get_by_email( 'angry@example.com' ), 'The record stays' );
		$this->assertGreaterThanOrEqual( 5, \MailHawk\assess_risk( 'angry@example.com' ), 'And they are still not emailed' );
	}

	public function test_trusted_and_bouncing_addresses_are_forgotten() {

		$this->recipients->trust( 'friend@example.com' );
		$this->recipients->record_bounce( 'gone@example.com' );

		foreach ( [ 'friend@example.com', 'gone@example.com' ] as $email ) {

			$this->assertNotFalse( $this->recipients->get_by_email( $email ) );

			$response = $this->privacy->erase_recipient( $email );

			$this->assertTrue( $response['items_removed'], $email );
			$this->assertFalse( $response['items_retained'], $email );
			$this->assertFalse( $this->recipients->get_by_email( $email ), $email );
		}

		$unknown = $this->privacy->erase_recipient( 'unknown@example.com' );

		$this->assertFalse( $unknown['items_removed'] );
		$this->assertFalse( $unknown['items_retained'] );
		$this->assertTrue( $unknown['done'] );
	}

	protected function get_policy_text() {

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		// WordPress keeps what plugins suggested in a static list, which would carry over from one test to the next
		$suggested = new ReflectionProperty( WP_Privacy_Policy_Content::class, 'policy_content' );
		$suggested->setAccessible( true );
		$suggested->setValue( null, [] );

		// Only MailHawk's part of the admin, the rest of it is not what is tested
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_init' );
		add_action( 'admin_init', [ $this->privacy, 'add_policy_content' ] );
		do_action( 'admin_init' );

		foreach ( WP_Privacy_Policy_Content::get_suggested_policy_text() as $text ) {
			if ( 'MailHawk' === $text['plugin_name'] ) {
				return $text['policy_text'];
			}
		}

		return '';
	}

	public function test_the_privacy_policy_gets_suggested_text_with_the_sites_own_numbers() {

		update_option( 'mailhawk_log_retention_in_days', 21 );
		delete_option( 'mailhawk_disable_email_logging' );

		$text = $this->get_policy_text();

		$this->assertNotSame( '', $text, 'There is text for MailHawk' );
		$this->assertStringContainsString( 'mailhawk.io', $text );
		$this->assertStringContainsString( 'deleted after 21 days', $text );
		$this->assertStringContainsString( 'reported spam', $text );
	}

	public function test_the_privacy_policy_says_so_when_there_is_no_log() {

		update_option( 'mailhawk_disable_email_logging', 1 );

		$text = $this->get_policy_text();

		$this->assertStringContainsString( 'does not keep a log', $text );
		$this->assertStringNotContainsString( 'Entries are deleted after', $text );
	}
}
