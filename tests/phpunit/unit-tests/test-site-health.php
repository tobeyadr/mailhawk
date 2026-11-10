<?php

use MailHawk\Api\Postal\Postal;
use MailHawk\Delivery_Sync;
use MailHawk\Site_Health;
use function MailHawk\set_mailhawk_api_credentials;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;
use function MailHawk\set_mailhawk_is_throttled;
use function MailHawk\set_mailhawk_setup_complete;

/**
 * What MailHawk adds to Site Health. The MTA is answered by the tests.
 */
class Test_Site_Health extends WP_UnitTestCase {

	/**
	 * @var Site_Health
	 */
	protected $health;

	/**
	 * What the MTA was asked, in order
	 *
	 * @var string[]
	 */
	protected $requests = [];

	public function set_up() {
		parent::set_up();

		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );
		Postal::set_api_key( 'secret-key' );
		delete_transient( 'mailhawk_email_usage' );
		delete_transient( 'mailhawk_is_throttled' );
		delete_option( 'mailhawk_setup_complete' );

		$this->health   = new Site_Health();
		$this->requests = [];
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		set_mailhawk_is_connected( false );
		delete_transient( 'mailhawk_email_usage' );
		delete_transient( 'mailhawk_is_throttled' );
		delete_option( 'mailhawk_setup_complete' );
		delete_option( 'mailhawk_mta_credential_key' );
		wp_clear_scheduled_hook( Delivery_Sync::HOOK );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Be the MTA, reporting this usage against this limit, or an error
	 *
	 * @param int|null $usage null for an MTA that does not answer
	 * @param int      $limit
	 */
	protected function mta( $usage, $limit = 1000 ) {

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $usage, $limit ) {

			$this->requests[] = $url;

			if ( null === $usage ) {
				return new WP_Error( 'down', 'No answer' );
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

	/**
	 * What Site Health needs of a result
	 *
	 * @param array $result
	 */
	protected function assertIsResult( array $result ) {

		foreach ( [ 'label', 'status', 'badge', 'description', 'actions', 'test' ] as $key ) {
			$this->assertArrayHasKey( $key, $result, $key );
		}

		$this->assertContains( $result['status'], [ 'good', 'recommended', 'critical' ] );
		$this->assertNotEmpty( $result['badge']['label'] );
		$this->assertNotEmpty( $result['badge']['color'] );
	}

	public function test_the_plugin_adds_its_tests_to_site_health() {

		$tests = apply_filters( 'site_status_tests', [] );

		$this->assertSame( [ 'mailhawk_connection', 'mailhawk_throttled', 'mailhawk_delivery_sync' ], array_keys( $tests['direct'] ) );
		$this->assertSame( [ 'mailhawk_usage' ], array_keys( $tests['async'] ) );
		$this->assertSame( 'mailhawk-usage', $tests['async']['mailhawk_usage']['test'] );
		$this->assertFalse( $tests['async']['mailhawk_usage']['has_rest'] );
		$this->assertTrue( has_action( 'wp_ajax_health-check-mailhawk-usage' ) !== false, 'Site Health asks for it by this action' );

		foreach ( $tests['direct'] as $test ) {
			$this->assertIsCallable( $test['test'] );
			$this->assertNotEmpty( $test['label'] );
		}
	}

	public function test_a_site_that_is_not_connected_only_gets_told_so() {

		set_mailhawk_is_connected( false );

		$tests = apply_filters( 'site_status_tests', [] );

		$this->assertSame( [ 'mailhawk_connection' ], array_keys( $tests['direct'] ) );
		$this->assertArrayNotHasKey( 'async', $tests );
	}

	public function test_not_connected_is_recommended_to_fix() {

		set_mailhawk_is_connected( false );

		$result = $this->health->test_connection();

		$this->assertIsResult( $result );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'page=mailhawk', $result['actions'] );
	}

	public function test_a_suspended_site_is_critical() {

		set_mailhawk_is_suspended( true );

		$result = $this->health->test_connection();

		$this->assertIsResult( $result );
		$this->assertSame( 'critical', $result['status'] );
	}

	public function test_unfinished_setup_is_recommended_to_finish() {

		set_mailhawk_setup_complete( false );

		$this->assertSame( 'recommended', $this->health->test_connection()['status'] );
	}

	public function test_a_connected_site_is_good() {

		$result = $this->health->test_connection();

		$this->assertIsResult( $result );
		$this->assertSame( 'good', $result['status'] );
	}

	public function test_email_turned_away_is_critical_and_leads_to_the_upgrade_and_the_failed_emails() {

		set_mailhawk_is_throttled( 600 );

		$result = $this->health->test_throttled();

		$this->assertIsResult( $result );
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'mailhawk.io/pricing', $result['actions'] );
		$this->assertStringContainsString( 'status=failed', $result['actions'] );
	}

	public function test_email_that_is_not_turned_away_is_good() {

		$this->assertSame( 'good', $this->health->test_throttled()['status'] );
	}

	public function test_email_is_not_turned_away_once_the_wait_is_over_even_if_nothing_was_sent_since() {

		// Remembered until an email goes through, but past
		set_transient( 'mailhawk_is_throttled', time() - 10, DAY_IN_SECONDS );

		$this->assertSame( 'good', $this->health->test_throttled()['status'] );
	}

	public function test_the_log_update_that_is_not_scheduled_is_recommended_to_fix() {

		wp_clear_scheduled_hook( Delivery_Sync::HOOK );

		$result = $this->health->test_delivery_sync();

		$this->assertIsResult( $result );
		$this->assertSame( 'recommended', $result['status'] );
	}

	public function test_the_log_update_that_is_scheduled_is_good() {

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Delivery_Sync::HOOK );

		$this->assertSame( 'good', $this->health->test_delivery_sync()['status'] );
	}

	public function test_the_log_update_that_is_scheduled_but_long_overdue_is_recommended_to_fix() {

		// Scheduled, but nothing runs WP-Cron
		wp_schedule_event( time() - 2 * HOUR_IN_SECONDS, 'hourly', Delivery_Sync::HOOK );

		$result = $this->health->test_delivery_sync();

		$this->assertIsResult( $result );
		$this->assertSame( 'recommended', $result['status'] );
	}

	public function test_the_log_update_that_is_a_little_late_is_good() {

		wp_schedule_event( time() - 5 * MINUTE_IN_SECONDS, 'hourly', Delivery_Sync::HOOK );

		$this->assertSame( 'good', $this->health->test_delivery_sync()['status'] );
	}

	public function test_the_log_update_has_nothing_to_do_when_logging_is_off() {

		update_option( 'mailhawk_disable_email_logging', 1 );
		wp_clear_scheduled_hook( Delivery_Sync::HOOK );

		$this->assertSame( 'good', $this->health->test_delivery_sync()['status'] );

		delete_option( 'mailhawk_disable_email_logging' );
	}

	public function test_a_log_update_that_is_paused_says_why() {

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Delivery_Sync::HOOK );
		set_transient( Delivery_Sync::PAUSE, 'mailhawk_unsupported', HOUR_IN_SECONDS );

		$result = $this->health->test_delivery_sync();

		delete_transient( Delivery_Sync::PAUSE );

		$this->assertIsResult( $result );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'mailhawk_unsupported', $result['description'] );
	}

	public function test_usage_is_good_up_to_eighty_percent() {

		$this->mta( 0 );
		$this->assertSame( 'good', $this->health->test_usage()['status'] );

		delete_transient( 'mailhawk_email_usage' );
		remove_all_filters( 'pre_http_request' );
		$this->mta( 800 );

		$result = $this->health->test_usage();

		$this->assertIsResult( $result );
		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( '800 of the 1,000', $result['description'] );
	}

	public function test_usage_over_eighty_percent_is_recommended_to_upgrade() {

		$this->mta( 801 );

		$result = $this->health->test_usage();

		$this->assertIsResult( $result );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'mailhawk.io/pricing', $result['actions'] );
	}

	public function test_all_of_the_usage_is_critical() {

		$this->mta( 1000 );

		$result = $this->health->test_usage();

		$this->assertIsResult( $result );
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'mailhawk.io/pricing', $result['actions'] );
	}

	public function test_an_mta_that_does_not_answer_is_not_a_good_usage() {

		$this->mta( null );

		$result = $this->health->test_usage();

		$this->assertIsResult( $result );
		$this->assertSame( 'recommended', $result['status'] );
	}

	public function test_nothing_asks_the_mta_until_the_usage_test_runs() {

		$this->mta( 10 );

		apply_filters( 'site_status_tests', [] );
		$this->health->test_connection();
		$this->health->test_throttled();
		$this->health->test_delivery_sync();
		apply_filters( 'debug_information', [] );

		$this->assertSame( [], $this->requests );
	}

	public function test_the_info_tab_has_a_section_for_support_without_secrets() {

		set_mailhawk_api_credentials( 'super-secret-key' );
		set_mailhawk_is_throttled( 600 );

		$info = apply_filters( 'debug_information', [] );

		$this->assertArrayHasKey( 'mailhawk', $info );
		$this->assertSame( 'MailHawk', $info['mailhawk']['label'] );

		$fields = $info['mailhawk']['fields'];

		$this->assertSame( MAILHAWK_VERSION, $fields['version']['value'] );
		$this->assertSame( 'Yes', $fields['connected']['value'] );
		$this->assertSame( 'Yes', $fields['api_key']['value'], 'Only that there is one' );
		$this->assertSame( 'No', $fields['suspended']['value'] );
		$this->assertNotSame( 'No', $fields['throttled']['value'], 'When it ends' );

		foreach ( $fields as $key => $field ) {
			$this->assertNotEmpty( $field['label'], $key );
			$this->assertNotSame( '', (string) $field['value'], $key );
		}

		$this->assertStringNotContainsString( 'super-secret-key', wp_json_encode( $info ) );
		$this->assertSame( [], $this->requests );
	}
}
