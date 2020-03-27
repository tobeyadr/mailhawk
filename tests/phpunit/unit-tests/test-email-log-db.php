<?php

use MailHawk\Plugin;

class Test_Email_Log_DB extends WP_UnitTestCase {

	public function test_wp_mail_adds_entry() {

		$status = wp_mail( 'adrian.methoss@gmail.com, groundhogg.inc@gmail.com', 'Hello world!', 'Hey there, nice world you got there', 'From: MailHawk <info@mailhawk.io>' );

		$this->assertTrue( $status );

		$entries = Plugin::instance()->log->query();

		$entry = $entries;

		$this->assertNotEmpty( $entry->msg_id );
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
