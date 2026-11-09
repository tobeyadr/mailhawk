<?php

use MailHawk\Api\Postal\Postal;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;

/**
 * The Usage box on the overview, and the nudge to a bigger plan when the month's emails are nearly gone. The MTA is
 * answered by the tests.
 */
class Test_Overview_Usage extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );
		Postal::set_api_key( 'secret-key' );
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'mailhawk/upgrade_url' );
		\MailHawk\clear_mailhawk_is_throttled();
		parent::tear_down();
	}

	protected function envelope( $data ) {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'status' => 'success', 'time' => 0.004, 'flags' => new stdClass(), 'data' => $data ] ),
			'response' => [ 'code' => 200, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * Render the overview with the MTA reporting this usage against this limit
	 *
	 * @param int $usage
	 * @param int $limit
	 *
	 * @return string
	 */
	protected function render_overview( $usage, $limit ) {

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $usage, $limit ) {

			if ( false !== strpos( $url, 'reporting/limits' ) ) {
				return $this->envelope( [ [ 'type' => 'monthly_send_limit', 'usage' => $usage, 'limit' => $limit ] ] );
			}

			if ( false !== strpos( $url, 'reporting/query' ) ) {
				return $this->envelope( [
					'last_date'  => gmdate( 'Y-m-d' ),
					'graph_data' => [ [ 'outgoing' => 10, 'bounces' => 1, 'complaints' => 0 ] ],
				] );
			}

			return new WP_Error( 'blocked', 'Unexpected request to ' . $url );
		}, 10, 3 );

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/views/overview.php';
		$html = ob_get_clean();

		remove_all_filters( 'pre_http_request' );

		return $html;
	}

	public function test_no_upgrade_push_up_to_eighty_percent() {

		$this->assertStringNotContainsString( 'usage-upgrade', $this->render_overview( 0, 1000 ) );
		$this->assertStringNotContainsString( 'usage-upgrade', $this->render_overview( 800, 1000 ) );
	}

	public function test_upgrade_push_once_more_than_eighty_percent_is_used() {

		$html = $this->render_overview( 801, 1000 );

		$this->assertStringContainsString( 'usage-upgrade', $html );
		$this->assertStringContainsString( "You've used 81% of this month's emails.", $html );
		$this->assertStringContainsString( 'href="https://mailhawk.io/pricing/"', $html );
	}

	public function test_upgrade_push_says_so_when_the_month_is_used_up() {

		$html = $this->render_overview( 1000, 1000 );

		$this->assertStringContainsString( "You've used all of this month's emails.", $html );
		$this->assertStringContainsString( 'style="width:100%"', $html );
	}

	public function test_almost_all_is_not_all() {

		$html = $this->render_overview( 995, 1000 );

		$this->assertStringContainsString( "You've used 100% of this month's emails.", $html );
		$this->assertStringNotContainsString( "used all of this month's emails", $html );
	}

	public function test_notice_when_the_month_is_used_up_and_the_mta_is_turning_email_away() {

		\MailHawk\set_mailhawk_is_throttled( 600 );

		$html = $this->render_overview( 1000, 1000 );

		$this->assertStringContainsString( 'mailhawk-throttle-notice', $html );
		$this->assertStringContainsString( 'Email is being held back', $html );
		$this->assertStringContainsString( 'status=failed', $html );

		// More than the plan has is the same
		$this->assertStringContainsString( 'mailhawk-throttle-notice', $this->render_overview( 1200, 1000 ) );
	}

	public function test_no_notice_when_nothing_was_turned_away() {

		$this->assertStringNotContainsString( 'mailhawk-throttle-notice', $this->render_overview( 1000, 1000 ) );
	}

	public function test_no_notice_when_the_mta_slows_the_site_down_with_usage_left() {

		\MailHawk\set_mailhawk_is_throttled( 600 );

		$this->assertStringNotContainsString( 'mailhawk-throttle-notice', $this->render_overview( 500, 1000 ) );
	}

	public function test_no_notice_once_email_goes_through_again() {

		\MailHawk\set_mailhawk_is_throttled( 600 );
		\MailHawk\clear_mailhawk_is_throttled();

		$this->assertStringNotContainsString( 'mailhawk-throttle-notice', $this->render_overview( 1000, 1000 ) );
	}

	public function test_upgrade_link_can_be_changed() {

		add_filter( 'mailhawk/upgrade_url', function () {
			return 'https://example.com/plans/';
		} );

		$this->assertStringContainsString( 'href="https://example.com/plans/"', $this->render_overview( 900, 1000 ) );
	}
}
