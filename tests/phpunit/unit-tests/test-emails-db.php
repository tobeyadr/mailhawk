<?php

use MailHawk\Plugin;

class Test_Emails_DB extends WP_UnitTestCase {

	public function test_add() {

		$record_id = Plugin::instance()->emails->add( [
			'email'  => 'invalid@example.com',
			'status' => 'invalid'
		] );

		$this->assertNotFalse( $record_id );
	}

	public function test_get() {

		$record_id = Plugin::instance()->emails->add( [
			'email'  => 'valid@example.com',
			'status' => 'valid'
		] );

		$record = Plugin::instance()->emails->get( $record_id );

		$this->assertEquals( 'valid', $record->status );
	}

	public function test_query(){

		$record_id = Plugin::instance()->emails->add( [
			'email'  => 'who@example.com',
			'status' => 'whitelist'
		] );

		$records = Plugin::instance()->emails->query( [
			'status' => 'whitelist'
		] );

		$this->assertCount( 1, $records );
	}

}
