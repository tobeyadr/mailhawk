<?php

use MailHawk\Api\Postal\Postal;
use function MailHawk\get_email_usage;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;

/**
 * How much of the monthly limit is used, as the MTA reports it. The MTA is answered by the tests.
 */
class Test_Email_Usage extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );
		Postal::set_api_key( 'secret-key' );
		delete_transient( 'mailhawk_email_usage' );
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		delete_transient( 'mailhawk_email_usage' );
		parent::tear_down();
	}

	/**
	 * Be the MTA, reporting this usage against this limit
	 *
	 * @param int $usage
	 * @param int $limit
	 */
	protected function mta( $usage, $limit ) {

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $usage, $limit ) {

			if ( false === strpos( $url, 'reporting/limits' ) ) {
				return new WP_Error( 'blocked', 'Unexpected request to ' . $url );
			}

			return [
				'headers'  => [],
				'body'     => wp_json_encode( [
					'status' => 'success',
					'time'   => 0.004,
					'flags'  => new stdClass(),
					'data'   => [ [ 'type' => 'monthly_send_limit', 'usage' => $usage, 'limit' => $limit ] ],
				] ),
				'response' => [ 'code' => 200, 'message' => '' ],
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );
	}

	public function test_nothing_used_is_zero_percent() {
		$this->mta( 0, 1000 );

		$usage = get_email_usage();

		$this->assertEquals( 0, $usage['percent'] );
		$this->assertEquals( 1000, $usage['remaining'] );
	}

	public function test_part_of_the_limit_used_is_that_part() {
		$this->mta( 250, 1000 );

		$this->assertEquals( 25, get_email_usage()['percent'] );
	}

	public function test_all_of_the_limit_used_is_a_hundred_percent() {
		$this->mta( 1000, 1000 );

		$this->assertEquals( 100, get_email_usage()['percent'] );
	}

	public function test_a_limit_of_zero_does_not_divide_by_zero() {
		$this->mta( 5, 0 );

		$this->assertEquals( 0, get_email_usage()['percent'] );
	}
}
