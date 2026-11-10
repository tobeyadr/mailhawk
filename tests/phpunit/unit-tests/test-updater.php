<?php

use MailHawk\Api\Postal\Postal;
use MailHawk\Plugin;
use function MailHawk\get_mailhawk_webhook_error;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_webhook_error;

/**
 * What the database updates do about the webhook. The MTA is answered by the tests.
 */
class Test_Updater extends WP_UnitTestCase {

	/**
	 * @var string[]
	 */
	protected $urls = [];

	public function set_up() {
		parent::set_up();

		$this->urls = [];

		set_mailhawk_is_connected( true );
		set_mailhawk_webhook_error( '' );
		Postal::set_api_key( 'secret-key' );
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		set_mailhawk_webhook_error( '' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	protected function mta_answers( $response ) {

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $response ) {

			$this->urls[] = $url;

			return $response;
		}, 10, 3 );
	}

	protected function mta_accepts() {
		$this->mta_answers( [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'status' => 'success', 'data' => [], 'success' => true ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		] );
	}

	public function test_the_update_to_1_5_0_tells_when_the_webhook_could_not_be_updated() {

		$this->mta_answers( new WP_Error( 'down', 'The MTA did not answer' ) );

		Plugin::instance()->updater->version_1_5_0();

		$this->assertStringContainsString( 'webhooks/update', $this->urls[0] );
		$this->assertStringContainsString( 'The MTA did not answer', get_mailhawk_webhook_error() );
	}

	public function test_the_update_to_1_4_0_tells_when_the_webhook_could_not_be_updated() {

		$this->mta_answers( new WP_Error( 'down', 'The MTA did not answer' ) );

		Plugin::instance()->updater->version_1_4_0();

		$this->assertStringContainsString( 'The MTA did not answer', get_mailhawk_webhook_error() );
	}

	public function test_a_webhook_that_is_updated_clears_an_earlier_error() {

		set_mailhawk_webhook_error( 'old error' );
		$this->mta_accepts();

		Plugin::instance()->updater->version_1_5_0();

		$this->assertCount( 1, $this->urls );
		$this->assertSame( '', get_mailhawk_webhook_error() );
	}

	public function test_a_site_that_is_not_connected_has_no_webhook_to_update() {

		set_mailhawk_is_connected( false );
		$this->mta_accepts();

		Plugin::instance()->updater->version_1_5_0();

		$this->assertSame( [], $this->urls );
		$this->assertSame( '', get_mailhawk_webhook_error() );
	}
}
