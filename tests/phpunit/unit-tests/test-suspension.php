<?php

use MailHawk\Admin\Admin;
use MailHawk\Api\Postal\Postal;
use MailHawk\Site_Health;
use function MailHawk\get_mailhawk_suspension;
use function MailHawk\mailhawk_is_suspended;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;
use function MailHawk\set_mailhawk_suspension;

/**
 * The MTA says why a server is suspended, the site tells the customer. Older MTA builds say nothing but the code, which
 * has to keep working. The MTA is answered by the tests.
 */
class Test_Suspension extends WP_UnitTestCase {

	const REASON = 'A bounce rate of 15.0% (150 of 1000 messages) in the last 24 hours.';

	public function set_up() {
		parent::set_up();

		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );
		Postal::set_api_key( 'secret-key' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		set_mailhawk_is_suspended( false );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Be the MTA, answering every request with a ServerSuspended error
	 *
	 * @param array $data
	 */
	protected function mta_suspended( $data ) {

		add_filter( 'pre_http_request', function () use ( $data ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'status' => 'error', 'time' => 0.004, 'flags' => new stdClass(), 'data' => $data ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		} );
	}

	protected function suspend_with_reason() {
		set_mailhawk_is_suspended( true );
		set_mailhawk_suspension( [ 'reason' => self::REASON, 'suspended_at' => '2026-11-09T16:20:50Z', 'scope' => 'organization' ] );
	}

	public function test_the_reason_is_kept_with_the_error_and_remembered() {

		$this->mta_suspended( [
			'code'         => 'ServerSuspended',
			'message'      => 'This server is suspended: ' . self::REASON,
			'reason'       => self::REASON,
			'suspended_at' => '2026-11-09T16:20:50Z',
			'scope'        => 'organization',
		] );

		$result = Postal::request( 'limits' );

		$this->assertWPError( $result );
		$this->assertSame( 'ServerSuspended', $result->get_error_code() );
		$this->assertSame( 'This server is suspended: ' . self::REASON, $result->get_error_message() );
		$this->assertSame( self::REASON, $result->get_error_data()->reason );

		$this->assertTrue( mailhawk_is_suspended() );
		$this->assertSame( [
			'reason'       => self::REASON,
			'suspended_at' => strtotime( '2026-11-09T16:20:50Z' ),
			'scope'        => 'organization',
		], get_mailhawk_suspension() );
	}

	public function test_an_older_mta_that_only_gives_the_code_still_suspends() {

		$this->mta_suspended( [ 'code' => 'ServerSuspended' ] );

		$result = Postal::request( 'limits' );

		$this->assertWPError( $result );
		$this->assertSame( 'ServerSuspended', $result->get_error_code() );
		$this->assertNotEmpty( $result->get_error_message() );

		$this->assertTrue( mailhawk_is_suspended() );
		$this->assertSame( [ 'reason' => '', 'suspended_at' => 0, 'scope' => 'server' ], get_mailhawk_suspension() );
	}

	public function test_a_reason_that_is_not_given_is_empty() {

		$this->mta_suspended( [ 'code' => 'ServerSuspended', 'message' => 'This server is suspended.', 'reason' => null, 'suspended_at' => null, 'scope' => 'server' ] );

		$this->assertSame( 'This server is suspended.', Postal::request( 'limits' )->get_error_message() );
		$this->assertSame( [ 'reason' => '', 'suspended_at' => 0, 'scope' => 'server' ], get_mailhawk_suspension() );
	}

	public function test_other_errors_without_a_message_say_their_code() {

		$this->mta_suspended( [ 'code' => 'SomethingElse' ] );

		$result = Postal::request( 'limits' );

		$this->assertSame( 'SomethingElse', $result->get_error_code() );
		$this->assertStringContainsString( 'SomethingElse', $result->get_error_message() );
		$this->assertFalse( mailhawk_is_suspended() );
	}

	public function test_the_reason_is_forgotten_with_the_suspension() {

		$this->suspend_with_reason();

		set_mailhawk_is_suspended( false );

		$this->assertSame( '', get_mailhawk_suspension()['reason'] );
	}

	public function test_markup_in_the_reason_is_not_kept() {

		set_mailhawk_suspension( [ 'reason' => '<script>alert(1)</script>Suspended <b>now</b>' ] );

		$this->assertSame( 'Suspended now', get_mailhawk_suspension()['reason'] );
	}

	public function test_the_notice_says_why_and_where_to_go() {

		$this->suspend_with_reason();
		set_mailhawk_suspension( [ 'reason' => 'Bounces & complaints', 'suspended_at' => '2026-11-09T16:20:50Z', 'scope' => 'organization' ] );

		ob_start();
		( new Admin() )->account_suspended_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Bounces &amp; complaints', $html );
		$this->assertStringContainsString( 'https://mailhawk.io/account/', $html );
		$this->assertStringContainsString( 'none of its sites', $html );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( '2026-11-09T16:20:50Z' ) ), $html );
	}

	public function test_the_notice_of_an_older_mta_keeps_the_old_text() {

		set_mailhawk_is_suspended( true );

		ob_start();
		( new Admin() )->account_suspended_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'you must reactivate your account', $html );
		$this->assertStringContainsString( 'https://mailhawk.io/account/', $html );
		$this->assertStringNotContainsString( 'none of its sites', $html );
	}

	public function test_the_suspended_page_says_why() {

		$this->suspend_with_reason();

		ob_start();
		include MAILHAWK_PATH . 'admin/views/suspended.php';
		$html = ob_get_clean();

		$this->assertStringContainsString( esc_html( self::REASON ), $html );
		$this->assertStringContainsString( 'https://mailhawk.io/account/', $html );
		$this->assertStringNotContainsString( 'Your last payment failed.', $html );
	}

	public function test_the_suspended_page_of_an_older_mta_lists_the_usual_causes() {

		set_mailhawk_is_suspended( true );

		ob_start();
		include MAILHAWK_PATH . 'admin/views/suspended.php';
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Your last payment failed.', $html );
		$this->assertStringContainsString( 'https://mailhawk.io/account/', $html );
	}

	public function test_site_health_says_why() {

		$this->suspend_with_reason();

		$health = new Site_Health();
		$result = $health->test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( esc_html( self::REASON ), $result['description'] );
		$this->assertStringContainsString( 'none of its sites', $result['description'] );
		$this->assertStringContainsString( 'https://mailhawk.io/account/', $result['actions'] );

		$fields = $health->debug_information( [] )['mailhawk']['fields'];
		$this->assertStringContainsString( self::REASON, $fields['suspension']['value'] );
		$this->assertStringContainsString( '2026-11-09 16:20:50 UTC', $fields['suspension']['value'] );
	}

	public function test_site_health_of_an_older_mta() {

		set_mailhawk_is_suspended( true );

		$health = new Site_Health();
		$result = $health->test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'problem with the account or the subscription', $result['description'] );
		$this->assertStringContainsString( 'https://mailhawk.io/account/', $result['actions'] );
		$this->assertSame( 'Not given', $health->debug_information( [] )['mailhawk']['fields']['suspension']['value'] );
	}
}
