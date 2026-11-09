<?php

/**
 * The warnings about nearing the send limit come from the MTA, not from the plugin
 */
class Test_Usage_Alerts extends WP_UnitTestCase {

	public function test_sending_does_not_count_or_report_usage_from_the_plugin() {

		delete_option( 'mailhawk_sent_this_month' );

		$requests = [];

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$requests ) {
			$requests[] = $url;

			return new WP_Error( 'blocked', 'No requests in tests' );
		}, 10, 3 );

		do_action( 'mailhawk/wp_mail/sent', true, null );

		$this->assertFalse( get_option( 'mailhawk_sent_this_month' ), 'No counter of its own' );
		$this->assertSame( [], $requests, 'Nothing is reported from the site' );
		$this->assertFalse( class_exists( 'MailHawk\Telemetry' ) );
		$this->assertFalse( has_action( 'mailhawk/monthly' ) );
	}
}
