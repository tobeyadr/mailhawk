<?php

use MailHawk\Classes\Email_Log_Item;
use MailHawk\Plugin;

/**
 * Reading a property on a log item must never call the method by the same name.
 */
class Test_Base_Object_Get extends WP_UnitTestCase {

	protected function add_quarantined_item() {

		$id = Plugin::instance()->log->add( [
			'recipients'   => [ 'someone@example.net' ],
			'from_address' => 'info@example.com',
			'subject'      => 'Test',
			'content'      => 'Some email content',
			'headers'      => [ [ 'Content-Type', 'text/plain' ] ],
			'status'       => 'quarantine',
			'date_sent'    => date( 'Y-m-d H:i:s' ),
		] );

		$this->assertNotFalse( $id );

		return new Email_Log_Item( $id );
	}

	public function test_reading_a_method_name_does_not_call_the_method() {

		$this->setExpectedIncorrectUsage( 'MailHawk\Classes\Email_Log_Item::__get' );

		$item = $this->add_quarantined_item();

		// reject() deletes a quarantined email
		$this->assertFalse( $item->reject );

		$fresh = new Email_Log_Item( $item->get_id() );

		$this->assertTrue( $fresh->exists(), 'reading $item->reject deleted the log item' );
		$this->assertTrue( $fresh->is_quarantined() );
	}

	public function test_columns_still_read() {

		$item = $this->add_quarantined_item();

		$this->assertSame( 'quarantine', $item->status );
		$this->assertSame( 'Test', $item->subject );
		$this->assertFalse( $item->no_such_column_or_method );
	}
}
