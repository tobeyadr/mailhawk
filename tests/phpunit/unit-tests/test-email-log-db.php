<?php

use MailHawk\Api\Postal\Postal;
use MailHawk\Plugin;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;

class Test_Email_Log_DB extends WP_UnitTestCase {

	/**
	 * Answer the requests to the MTA as it does when an email is accepted, so nothing is sent
	 *
	 * @param string $message_id
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

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		parent::tear_down();
	}

	public function test_sending_adds_an_entry_with_the_id_the_mta_gave() {

		$this->accept_email_as( 'msg-123@example.com' );

		$log = Plugin::instance()->log;
		$log->flush_cache();

		$last = absint( $GLOBALS['wpdb']->get_var( 'SELECT MAX(ID) FROM ' . $log->get_table_name() ) );

		$this->assertTrue( mailhawk_mail( 'first@example.net, second@example.net', 'Hello world!', 'Hey there, nice world you got there', 'From: MailHawk <info@example.com>' ) );

		$log->flush_cache();

		$row = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM ' . $log->get_table_name() . ' WHERE ID > %d ORDER BY ID DESC LIMIT 1', $last ) );

		$this->assertNotNull( $row, 'The email was not logged' );

		$entry = $log->get( $row->ID );

		$this->assertSame( 'sent', $entry->status );
		$this->assertSame( 'msg-123@example.com', $entry->msg_id );
		$this->assertSame( 'Hello world!', $entry->subject );
		$this->assertSame( [ 'first@example.net', 'second@example.net' ], $entry->recipients );
	}

	protected function add_entry( $status ) {

		$id = Plugin::instance()->log->add( [
			'recipients'   => 'info@example.com',
			'from_address' => 'info@mailhawk.io',
			'subject'      => 'Cache test',
			'content'      => 'Some email content',
			'headers'      => [ 'header' => 'header-content' ],
			'status'       => $status,
		] );

		$this->assertNotFalse( $id );

		return $id;
	}

	public function test_counts_follow_every_kind_of_write_within_a_request() {

		$log = Plugin::instance()->log;

		$count = function () use ( $log ) {
			return absint( $log->count( [ 'status' => 'cachetest' ] ) );
		};

		$log->flush_cache();
		$this->assertSame( 0, $count() );

		$first = $this->add_entry( 'cachetest' );
		$this->assertSame( 1, $count(), 'After add' );

		$this->add_entry( 'cachetest' );
		$this->assertSame( 2, $count() );

		$log->update( $first, [ 'status' => 'sent' ] );
		$this->assertSame( 1, $count(), 'After update' );

		$this->assertTrue( $log->mass_update( [ 'status' => 'cachetest' ], [ 'status' => 'sent', 'subject' => 'Cache test' ] ) );
		$this->assertSame( 2, $count(), 'After mass update' );

		$log->delete( $first );
		$this->assertSame( 1, $count(), 'After delete' );

		$log->bulk_delete( [ 'status' => 'cachetest' ] );
		$this->assertSame( 0, $count(), 'After bulk delete' );
	}

	public function test_add() {

		$record_id = Plugin::instance()->log->add( [
			'recipients'   => 'info@example.com',
			'from_address' => 'info@mailhawk.io',
			'subject'      => 'Test',
			'content'      => 'Some email content',
			'headers'      => [ 'header' => 'header-content' ],
			'raw'          => 'base64encoded(stuff)',
			'status'       => 'sent',
		] );

		$this->assertNotFalse( $record_id );
	}

	public function test_get() {

		$record_id = Plugin::instance()->log->add( [
			'recipients'   => 'info@example.com',
			'from_address' => 'info@mailhawk.io',
			'subject'      => 'Test',
			'content'      => 'Some email content',
			'headers'      => [ 'header' => 'header-content' ],
			'raw'          => 'base64encoded(stuff)',
			'status'       => 'sent',
		] );

		$record = Plugin::instance()->log->get( $record_id );

		$this->assertEquals( 'info@example.com', $record->recipients );
	}

	public function test_unserialize_works() {

		$id = Plugin::instance()->log->add( [
			'recipients'   => [ 'info@example.com', 'to@example.com' ],
			'from_address' => 'info@mailhawk.io',
			'subject'      => 'Test',
			'content'      => 'Some email content',
			'headers'      => [ 'header' => 'header-content' ],
			'raw'          => 'base64encoded(stuff)',
			'status'       => 'sent',
		] );

		$record = Plugin::instance()->log->get( $id );

		$this->assertIsArray( $record->recipients );
		$this->assertIsArray( $record->headers );
	}

}
