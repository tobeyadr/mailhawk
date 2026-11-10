<?php

use MailHawk\Spam_Complaints;

/**
 * The digest of spam complaints that goes to the site owner
 */
class Test_Spam_Complaints extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Spam_Complaints::OPTION );
		wp_clear_scheduled_hook( 'mailhawk/send_complaint_digest' );
	}

	public function tear_down() {
		delete_option( Spam_Complaints::OPTION );
		wp_clear_scheduled_hook( 'mailhawk/send_complaint_digest' );
		parent::tear_down();
	}

	public function test_the_same_report_sent_twice_is_one_entry_in_the_digest() {

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );
		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );
		do_action( 'mailhawk/spam_complaint', 'ANGRY@example.net', 'msg-1', [] );

		$this->assertCount( 1, get_option( Spam_Complaints::OPTION ) );
	}

	public function test_different_reports_are_different_entries() {

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );
		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-2', [] );
		do_action( 'mailhawk/spam_complaint', 'other@example.net', 'msg-1', [] );

		$this->assertCount( 3, get_option( Spam_Complaints::OPTION ) );
	}

	public function test_reports_without_a_message_id_can_not_be_told_apart_so_all_are_kept() {

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', '', [] );
		do_action( 'mailhawk/spam_complaint', 'angry@example.net', '', [] );

		$this->assertCount( 2, get_option( Spam_Complaints::OPTION ) );
	}

	public function test_one_digest_is_scheduled_however_many_reports_come_in() {

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );
		$first = wp_next_scheduled( 'mailhawk/send_complaint_digest' );

		do_action( 'mailhawk/spam_complaint', 'angry@example.net', 'msg-1', [] );

		$this->assertNotFalse( $first );
		$this->assertSame( $first, wp_next_scheduled( 'mailhawk/send_complaint_digest' ) );
	}
}
