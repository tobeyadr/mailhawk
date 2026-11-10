<?php

use MailHawk\Admin\Admin;
use function MailHawk\get_test_from_addresses;

/**
 * The test email: who it can be sent from, and that it is sent from that address
 */
class Test_Test_Email extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( Admin::class ) ) {
			require_once dirname( MAILHAWK__FILE__ ) . '/admin/admin.php';
		}

		update_option( 'mailhawk_default_from_email_address', 'Hello@Example.com' );
		update_option( 'mailhawk_default_from_name', 'Example Shop' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator', 'user_email' => 'owner@shop.example.net' ] ) );
	}

	public function tear_down() {
		unset( $_POST['_mailhawk_nonce'], $_POST['to_address'], $_POST['from_address'], $_POST['subject'], $_POST['content'] );
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	protected function domain( $name, $authenticated ) {
		$status = $authenticated ? 'OK' : 'Missing';

		return (object) [
			'name' => $name,
			'spf'  => (object) [ 'spf_status' => $status ],
			'dkim' => (object) [ 'dkim_status' => $status ],
		];
	}

	public function test_the_default_from_address_comes_first_then_the_same_name_at_each_domain_verified_first() {

		$from = get_test_from_addresses( [
			$this->domain( 'zeta.example.org', true ),
			$this->domain( 'shop.example.net', false ),
			$this->domain( 'example.com', true ),
		] );

		$this->assertSame( [
			[ 'email' => 'hello@example.com', 'default' => true, 'authenticated' => true ],
			[ 'email' => 'hello@zeta.example.org', 'default' => false, 'authenticated' => true ],
			[ 'email' => 'hello@shop.example.net', 'default' => false, 'authenticated' => false ],
			[ 'email' => 'owner@shop.example.net', 'default' => false, 'authenticated' => false ],
		], $from['addresses'], 'The current user, on a registered domain, is suggested too' );

		$this->assertSame( [ 'example.com' => true, 'zeta.example.org' => true, 'shop.example.net' => false ], $from['domains'] );
	}

	public function test_without_domains_only_the_default_from_address_is_suggested_and_it_is_not_known_to_be_verified() {

		$from = get_test_from_addresses( new WP_Error( 'down', 'No answer' ) );

		$this->assertSame( [ [ 'email' => 'hello@example.com', 'default' => true, 'authenticated' => null ] ], $from['addresses'] );
		$this->assertSame( [], $from['domains'] );
	}

	/**
	 * Send the test email from the form, and catch it before it goes anywhere
	 *
	 * @return array what wp_mail() was asked to send
	 */
	protected function send_test( $from ) {

		$sent = [];

		add_filter( 'pre_wp_mail', function ( $return, $atts ) use ( &$sent ) {
			$sent = $atts;

			return true;
		}, 10, 2 );

		$_POST['_mailhawk_nonce'] = wp_create_nonce( 'send_test_email' );
		$_POST['to_address']      = 'me@example.org';
		$_POST['from_address']    = $from;
		$_POST['subject']         = 'Testing';
		$_POST['content']         = 'Hello';

		$method = new ReflectionMethod( Admin::class, 'maybe_send_test_email' );
		$method->setAccessible( true );
		$method->invoke( new Admin() );

		return $sent;
	}

	public function test_the_test_email_is_sent_from_the_address_that_was_picked() {

		$sent = $this->send_test( 'news@example.com' );

		$this->assertSame( 'me@example.org', $sent['to'] );
		$this->assertContains( 'From: Example Shop <news@example.com>', $sent['headers'] );
	}

	public function test_without_an_address_the_test_email_is_sent_from_the_default() {

		$sent = $this->send_test( 'not an address' );

		$this->assertSame( [ 'Content-Type: text/html' ], $sent['headers'], 'Nothing in the way of the default from address' );
	}

	public function test_the_test_email_is_the_branded_one_whatever_was_posted() {

		$_POST['subject'] = 'Anything at all';

		$sent = $this->send_test( 'news@example.com' );

		$this->assertStringStartsWith( 'MailHawk test email from ', $sent['subject'] );
		$this->assertContains( 'Content-Type: text/html', $sent['headers'] );
		$this->assertStringContainsString( 'It works!', $sent['message'] );
		$this->assertStringContainsString( 'news@example.com', $sent['message'], 'Says who it is from' );

		foreach ( [ 'Gmail put it in Updates', 'Gmail put it in Promotions', 'It landed in spam' ] as $question ) {
			$this->assertStringContainsString( $question, $sent['message'], $question );
		}

		$this->assertStringNotContainsString( 'did not arrive', $sent['message'], 'An email that arrived does not ask that' );
		$this->assertStringNotContainsString( 'Anything at all', $sent['message'] );
	}

	public function test_the_test_email_has_a_plain_text_version_and_the_next_email_does_not_get_it() {

		$email = \MailHawk\get_test_email( 'me@example.org', 'news@example.com' );

		$this->assertStringContainsString( 'It works!', $email['text'] );
		$this->assertStringNotContainsString( '<', $email['text'], 'No markup' );
		$this->assertMatchesRegularExpression( '/Domains screen \(http[^)]+view=domains\)/', $email['text'], 'Links keep their address' );

		$hook = \MailHawk\set_next_alt_body( $email['text'] );
		remove_action( 'phpmailer_init', $hook, 99 );

		$this->assertFalse( has_action( 'phpmailer_init', $hook ) );
	}

	public function test_the_test_screen_explains_where_the_email_can_land_including_when_it_did_not() {

		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'blocked', 'No requests in tests' );
		} );

		ob_start();
		( function () {
			include dirname( MAILHAWK__FILE__ ) . '/admin/views/test.php';
		} )->call( new Admin() );
		$html = ob_get_clean();

		remove_all_filters( 'pre_http_request' );

		$this->assertStringContainsString( 'mailhawk-test-faq', $html );
		$this->assertStringContainsString( 'It did not arrive at all', $html );
		$this->assertStringNotContainsString( 'name="subject"', $html, 'Nothing to write, the email is always the same' );
		$this->assertStringNotContainsString( 'name="content"', $html );
	}
}
