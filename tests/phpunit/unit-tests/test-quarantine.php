<?php

use MailHawk\Classes\Email_Log_Item;
use MailHawk\Cron_Events;
use MailHawk\Hawk_Mailer;
use MailHawk\Plugin;
use MailHawk\Quarantine;
use function MailHawk\assess_risk;
use function MailHawk\get_free_inbox_provider_risk;
use function MailHawk\get_quarantine_retention_days;

class Test_Quarantine extends WP_UnitTestCase {

	/**
	 * Nothing in these tests may reach the MTA
	 */
	protected function block_http() {
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'blocked', 'HTTP is blocked in tests' );
		} );
	}

	/**
	 * Add a log row
	 *
	 * @param string $status
	 * @param int    $days_ago
	 * @param array  $recipients
	 *
	 * @return Email_Log_Item
	 */
	protected function add_log_item( $status = 'sent', $days_ago = 0, $recipients = [ 'someone@example.net' ] ) {

		$id = Plugin::instance()->log->add( [
			'recipients'   => $recipients,
			'from_address' => 'info@example.com',
			'subject'      => 'Test',
			'content'      => 'Some email content',
			'headers'      => [ [ 'Content-Type', 'text/plain' ], [ 'From', 'Test <info@example.com>' ] ],
			'status'       => $status,
			'date_sent'    => date( 'Y-m-d H:i:s', strtotime( $days_ago . ' days ago' ) ),
		] );

		$this->assertNotFalse( $id );

		return new Email_Log_Item( $id );
	}

	protected function get_mailer( $to ) {
		$mailer = new Hawk_Mailer( true );
		$mailer->setFrom( 'info@example.com', 'Test' );
		$mailer->Subject = 'Hello';
		$mailer->Body    = 'Hello there';

		foreach ( (array) $to as $address ) {
			$mailer->addAddress( $address );
		}

		return $mailer;
	}

	protected function get_log_id_property() {
		$property = new ReflectionProperty( Hawk_Mailer::class, 'log_item_id' );
		$property->setAccessible( true );

		return $property;
	}

	public function test_assess_risk_test_address_is_always_quarantined() {
		$this->assertGreaterThanOrEqual( 5, assess_risk( 'someone+test-quarantine@example.net' ) );
	}

	public function test_assess_risk_admin_email_is_never_risky() {
		// gmail.com is a free provider, so without the exemption this would score above 0
		$this->assertGreaterThan( 0, assess_risk( 'boss@gmail.com' ) );

		update_option( 'admin_email', 'boss@gmail.com' );

		$this->assertSame( 0, assess_risk( 'boss@gmail.com' ) );
	}

	public function test_free_provider_risk_exact_and_subdomain_matches() {
		$this->assertSame( 2, get_free_inbox_provider_risk( 'gmail.com' ) );
		$this->assertSame( 2, get_free_inbox_provider_risk( 'GMAIL.com' ) );
		$this->assertNull( get_free_inbox_provider_risk( 'example.com' ) );

		// Entries starting with a dot cover subdomains
		$this->assertSame( 5, get_free_inbox_provider_risk( 'someone.ddns.org' ) );
		$this->assertNull( get_free_inbox_provider_risk( 'ddns.org' ) );
	}

	public function test_assess_risk_uses_subdomain_provider_entries() {
		$this->assertGreaterThanOrEqual( 5, assess_risk( 'me@someone.ddns.org' ) );
	}

	public function test_reject_only_deletes_quarantined_items() {

		$sent = $this->add_log_item( 'sent' );

		$this->assertFalse( $sent->reject() );
		$this->assertNotFalse( Plugin::instance()->log->get( $sent->get_id() ) );

		$held = $this->add_log_item( 'quarantine' );

		$this->assertTrue( $held->reject() );
		$this->assertEmpty( Plugin::instance()->log->get( $held->get_id() ) );
	}

	public function test_release_ignores_items_that_are_not_quarantined() {

		$sent = $this->add_log_item( 'sent' );

		$this->assertFalse( $sent->release() );
		$this->assertSame( 'sent', Plugin::instance()->log->get( $sent->get_id() )->status );
	}

	public function test_retry_resets_the_log_item_id() {

		$this->block_http();

		$failed = $this->add_log_item( 'failed' );
		$failed->retry();

		$this->assertFalse( $this->get_log_id_property()->getValue() );
	}

	public function test_mailer_holds_the_whole_message_when_every_recipient_is_risky() {

		$this->block_http();

		$result = $this->get_mailer( 'someone+test-quarantine@example.net' )->send();

		$this->assertTrue( $result );
		$this->assertEquals( 1, Plugin::instance()->log->count( [ 'status' => 'quarantine' ] ) );
		$this->assertNotFalse( wp_next_scheduled( 'mailhawk/send_quarantine_notice' ) );
	}

	public function test_mailer_holds_only_the_risky_recipients() {

		$this->block_http();

		try {
			// Sending the safe recipient fails because HTTP is blocked, which is fine here
			$this->get_mailer( [ 'safe@example.net', 'someone+test-quarantine@example.net' ] )->send();
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			// expected
		}

		$log = Plugin::instance()->log;

		// query() returns raw rows, get() unserializes the recipients
		$held = $log->query( [ 'status' => 'quarantine' ] );

		$this->assertCount( 1, $held );
		$this->assertSame( [ 'someone+test-quarantine@example.net' ], array_values( (array) $log->get( $held[0]->ID )->recipients ) );

		$failed = $log->query( [ 'status' => 'failed' ] );

		$this->assertCount( 1, $failed );
		$this->assertSame( [ 'safe@example.net' ], array_values( (array) $log->get( $failed[0]->ID )->recipients ) );
	}

	public function test_report_counts_what_is_waiting_and_what_is_about_to_expire() {

		update_option( 'mailhawk_log_retention_in_days', 14 );

		// The retention is 30 days, so the one held 25 days ago expires within 7 days
		$old    = $this->add_log_item( 'quarantine', 25, [ 'old@example.net', 'two@example.net', 'three@example.net' ] );
		$recent = $this->add_log_item( 'quarantine', 1 );
		$this->add_log_item( 'sent', 40 );
		$this->add_log_item( 'failed', 40 );

		$report = Quarantine::get_report();

		$this->assertSame( 2, $report['waiting'] );
		$this->assertSame( 1, $report['expiring'] );
		$this->assertSame( 30, $report['retention_days'] );

		// Closest to being rejected first
		$this->assertCount( 2, $report['items'] );
		$this->assertSame( $old->get_id(), $report['items'][0]['id'] );
		$this->assertSame( $recent->get_id(), $report['items'][1]['id'] );
		$this->assertSame( [ 'old@example.net', 'two@example.net', 'three@example.net' ], $report['items'][0]['recipients'] );
		$this->assertGreaterThan( time(), $report['items'][0]['expires_at'] );
		$this->assertLessThan( $report['items'][1]['expires_at'], $report['items'][0]['expires_at'] );
	}

	public function test_report_limits_the_listed_emails() {

		for ( $i = 0; $i < 4; $i ++ ) {
			$this->add_log_item( 'quarantine', $i );
		}

		$report = Quarantine::get_report( 7, 3 );

		$this->assertSame( 4, $report['waiting'] );
		$this->assertCount( 3, $report['items'] );
	}

	public function test_report_counts_recipients_that_need_attention() {

		$recipients = Plugin::instance()->recipients;

		if ( ! $recipients->installed() ) {
			$recipients->create_table();
		}

		$recipients->trust( 'friend@example.net' );
		$recipients->trust( 'other@example.net' );
		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );
		do_action( 'mailhawk/bounced', 'dead@example.net', 'msg-2' );

		$report = Quarantine::get_report();

		$this->assertSame( 2, $report['trusted'] );
		$this->assertSame( 1, $report['blocked'] );
		$this->assertSame( 1, $report['bouncing'] );

		// A bounce that is old enough to be forgotten is not something to act on
		global $wpdb;
		$wpdb->update( $recipients->table_name, [ 'last_bounce' => date( 'Y-m-d H:i:s', strtotime( '120 days ago' ) ) ], [ 'email' => 'dead@example.net' ] );

		$this->assertSame( 0, Quarantine::get_report()['bouncing'] );
	}

	protected function render_overview_report() {

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/views/overview-quarantine.php';

		return ob_get_clean();
	}

	public function test_overview_report_is_left_out_when_nothing_needs_attention() {

		// Not an empty box, not an all clear message: nothing at all
		$this->assertSame( '', trim( $this->render_overview_report() ) );

		// Emails that were sent or failed are not held
		$this->add_log_item( 'sent', 1 );
		$this->add_log_item( 'failed', 1 );

		$this->assertSame( '', trim( $this->render_overview_report() ) );
	}

	public function test_overview_report_comes_back_when_something_is_held() {

		$this->assertSame( '', trim( $this->render_overview_report() ) );

		$this->add_log_item( 'quarantine', 1, [ 'held@example.net' ] );

		$html = $this->render_overview_report();

		$this->assertStringContainsString( 'mailhawk-quarantine-report', $html );
		$this->assertStringContainsString( 'waiting for your review', $html );
	}

	public function test_overview_report_links_to_what_needs_action() {

		$this->add_log_item( 'quarantine', 28, [ 'held@example.net' ] );
		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );

		$html = $this->render_overview_report();

		// A siren by the heading, hidden from screen readers since the list says it in words
		$this->assertMatchesRegularExpression( '/<h2><span class="quarantine-alert" aria-hidden="true">&#128680;<\/span> Quarantine<\/h2>/', $html );
		$this->assertStringContainsString( 'waiting for your review', $html );
		$this->assertStringContainsString( 'rejected automatically', $html );
		$this->assertStringContainsString( 'reported your email as spam', $html );
		$this->assertStringContainsString( 'status=quarantine', $html );
		$this->assertStringContainsString( 'view=recipients', $html );
		$this->assertStringContainsString( 'held@example.net', $html );
	}

	public function test_quarantine_notice_is_throttled_to_once_a_day() {

		update_option( 'mailhawk_quarantine_notice_sent', time() );

		Quarantine::schedule_quarantine_notice();

		$next = wp_next_scheduled( 'mailhawk/send_quarantine_notice' );

		$this->assertGreaterThan( time() + DAY_IN_SECONDS - 60, $next );
	}

	public function test_quarantine_notice_is_scheduled_soon_when_none_was_sent() {

		delete_option( 'mailhawk_quarantine_notice_sent' );

		Quarantine::schedule_quarantine_notice();

		$next = wp_next_scheduled( 'mailhawk/send_quarantine_notice' );

		$this->assertLessThan( time() + HOUR_IN_SECONDS, $next );
	}

	public function test_quarantine_retention_is_never_shorter_than_log_retention() {

		update_option( 'mailhawk_log_retention_in_days', 14 );
		$this->assertSame( 30, get_quarantine_retention_days() );

		update_option( 'mailhawk_log_retention_in_days', 90 );
		$this->assertSame( 90, get_quarantine_retention_days() );
	}

	public function test_trim_keeps_quarantined_emails_longer_than_other_entries() {

		update_option( 'mailhawk_log_retention_in_days', 14 );

		$old_sent        = $this->add_log_item( 'sent', 20 );
		$recent_sent     = $this->add_log_item( 'sent', 2 );
		$old_quarantine  = $this->add_log_item( 'quarantine', 20 );
		$very_old_quarantine = $this->add_log_item( 'quarantine', 40 );

		( new Cron_Events() )->trim_log_entries();

		$log = Plugin::instance()->log;

		$this->assertEmpty( $log->get( $old_sent->get_id() ) );
		$this->assertNotEmpty( $log->get( $recent_sent->get_id() ) );
		$this->assertNotEmpty( $log->get( $old_quarantine->get_id() ) );
		$this->assertEmpty( $log->get( $very_old_quarantine->get_id() ) );
	}
}
