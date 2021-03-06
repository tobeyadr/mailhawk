<?php

use MailHawk\Api_Helper;

class Test_API extends WP_UnitTestCase {

	public function setUp() {

		update_option( 'mailhawk_is_connected', 'yes' );

		Api_Helper::instance()->set_api_key( getenv( 'MAILHAWK_API_KEY' ) );

		parent::setUp(); // TODO: Change the autogenerated stub
	}

	public function test_mailhawk_mail_is_true() {

		$this->setUp();

		$to = 'adrian.methoss@gmail.com';
		$subject = 'Hi!';
		$message = 'Hello world.';
		$headers = [
			'From: MailHawk <info@mta01.mailhawk.io>'
		];

		add_action( 'wp_mail_failed', function ( $error ){
			var_dump( $error );
		} );

		$result = mailhawk_mail( $to, $subject, $message, $headers );

		$this->assertTrue( $result );

	}

	public function test_send_email_with_sender_header() {

		$this->setUp();

		$to = 'deloga6943@oppamail.com';
		$subject = 'Hi!';
		$message = 'Hello world.';
		$headers = [
			'From:   MailHawk <info@mailhawk.io>',
			'Sender: MailHawk <info@mta01.mailhawk.io>'
		];

		add_action( 'wp_mail_failed', function ( $error ){
			var_dump( $error );
		} );

		$result = mailhawk_mail( $to, $subject, $message, $headers );

		$this->assertTrue( $result );

	}

}
