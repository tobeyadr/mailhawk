<?php

use MailHawk\Abilities\Abilities;
use MailHawk\Api\Postal\Postal;
use MailHawk\DB\Recipients;
use MailHawk\Plugin;
use function MailHawk\get_inbox_risk;
use function MailHawk\is_groundhogg_active;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;
use function MailHawk\set_mailhawk_setup_complete;
use function MailHawk\set_mailhawk_suspension;
use function MailHawk\set_mailhawk_webhook_error;

/**
 * The abilities MailHawk registers for AI tools. Every one is run through WordPress, which checks the input against its
 * schema before and the output after, so a schema that does not describe what comes back fails here.
 */
class Test_Abilities extends WP_UnitTestCase {

	/**
	 * @var Recipients
	 */
	protected $recipients;

	/**
	 * What the fake MTA was asked, in order
	 *
	 * @var array
	 */
	protected $calls = [];

	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API needs WordPress 6.9.' );
		}

		$this->recipients = Plugin::instance()->recipients;

		if ( ! $this->recipients->installed() ) {
			$this->recipients->create_table();
		}

		// Whatever the tests before left in the log is not what these are about
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Plugin::instance()->log->get_table_name() );
		wp_cache_flush();

		// The query cache of the DB classes only lives as long as a request, but a test is not one
		Plugin::instance()->log->flush_cache();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		delete_option( 'mailhawk_disable_email_logging' );
		delete_transient( 'mailhawk_sending_health' );
		delete_transient( 'mailhawk_is_suspended' );

		$this->connect( false );
		$this->mta( [] );
	}

	public function tear_down() {
		Postal::set_api_key( '' );
		parent::tear_down();
	}

	/**
	 * @param bool $connected
	 */
	protected function connect( $connected = true ) {
		set_mailhawk_is_connected( $connected );
		set_mailhawk_is_suspended( false );
		set_mailhawk_setup_complete( true );
		set_mailhawk_webhook_error( '' );
		Postal::set_api_key( $connected ? 'x' : '' );
	}

	/**
	 * Stand in for the MTA. A route is the data to answer with, a WP_Error to fail with, or a closure that gets what was
	 * asked and answers with either. Anything not routed fails, so a call that should not happen shows.
	 *
	 * @param array $routes endpoint => answer
	 */
	protected function mta( array $routes ) {

		$this->calls = [];

		remove_all_filters( 'pre_http_request' );

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $routes ) {

			$at = strpos( $url, '/api/v1/' );

			if ( false === $at ) {
				return new WP_Error( 'blocked', 'HTTP is blocked in tests' );
			}

			$endpoint = substr( $url, $at + 8 );
			$body     = json_decode( $args['body'] ?? '{}', true ) ?: [];

			$this->calls[] = [ 'endpoint' => $endpoint, 'body' => $body ];

			$answer = $routes[ $endpoint ] ?? new WP_Error( 'NoRoute', 'The test has no answer for ' . $endpoint );

			if ( $answer instanceof Closure ) {
				$answer = $answer( $body );
			}

			if ( is_wp_error( $answer ) ) {
				$json = [ 'status' => 'error', 'data' => [ 'code' => $answer->get_error_code(), 'message' => $answer->get_error_message() ] ];
			} else {
				$json = [ 'status' => 'success', 'data' => $answer ];
			}

			return [
				'headers'  => [],
				'body'     => wp_json_encode( $json ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );
	}

	/**
	 * @return string[] the endpoints the MTA was asked, in order
	 */
	protected function endpoints() {
		return wp_list_pluck( $this->calls, 'endpoint' );
	}

	protected function run_ability( $name, $input = null ) {
		return wp_get_ability( $name )->execute( $input );
	}

	/**
	 * Runs the ability and expects it to work
	 */
	protected function ok( $name, $input = null ) {

		$result = $this->run_ability( $name, $input );

		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );

		return $result;
	}

	/**
	 * Runs the ability and expects it to refuse
	 */
	protected function refused( $name, $input, $code ) {

		$result = $this->run_ability( $name, $input );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );

		return $result;
	}

	protected function domain( $name, $verified = true ) {

		$status = $verified ? 'OK' : 'Missing';

		return (object) [
			'name'        => $name,
			'spf'         => (object) [ 'spf_status' => $status, 'spf_record' => 'v=spf1 a mx include:spf.mailhawk.io ~all' ],
			'dkim'        => (object) [ 'dkim_status' => $status, 'dkim_record_name' => 'MH._domainkey.' . $name, 'dkim_record' => 'v=DKIM1; p=abc' ],
			'return_path' => (object) [ 'return_path_status' => $status, 'return_path_domain' => 'psrp.' . $name ],
		];
	}

	/**
	 * @return int the ID of the log entry
	 */
	protected function add_log_item( $recipients, $status = 'sent', $extra = [] ) {

		$id = Plugin::instance()->log->add( array_merge( [
			'recipients'   => (array) $recipients,
			'from_address' => 'info@example.com',
			'subject'      => 'Your order',
			'content'      => 'Thanks for your order',
			'headers'      => [ [ 'Content-Type', 'text/plain' ], [ 'From', 'Test <info@example.com>' ] ],
			'status'       => $status,
			'date_sent'    => current_time( 'mysql' ),
		], $extra ) );

		$this->assertNotFalse( $id );

		return $id;
	}

	protected function log_has( $id ) {
		return (bool) Plugin::instance()->log->get( $id );
	}

	protected function send_succeeds() {
		return [ 'send/raw' => (object) [ 'message_id' => 'abc123' ] ];
	}

	// Registration

	public function test_every_ability_is_registered_with_its_category_and_annotations() {

		// name => category, read only, destructive
		$expected = [
			'mailhawk/get-status'            => [ 'mailhawk-status', true, false ],
			'mailhawk/get-reports'           => [ 'mailhawk-status', true, false ],
			'mailhawk/list-domains'          => [ 'mailhawk-domains', true, false ],
			'mailhawk/get-domain-dns'        => [ 'mailhawk-domains', true, false ],
			'mailhawk/add-domain'            => [ 'mailhawk-domains', false, false ],
			'mailhawk/check-domain-dns'      => [ 'mailhawk-domains', false, false ],
			'mailhawk/delete-domain'         => [ 'mailhawk-domains', false, true ],
			'mailhawk/list-emails'           => [ 'mailhawk-email', true, false ],
			'mailhawk/get-email'             => [ 'mailhawk-email', true, false ],
			'mailhawk/send-test-email'       => [ 'mailhawk-email', false, true ],
			'mailhawk/retry-email'           => [ 'mailhawk-email', false, true ],
			'mailhawk/get-quarantine-report' => [ 'mailhawk-quarantine', true, false ],
			'mailhawk/release-email'         => [ 'mailhawk-quarantine', false, true ],
			'mailhawk/reject-email'          => [ 'mailhawk-quarantine', false, true ],
			'mailhawk/list-recipients'       => [ 'mailhawk-recipients', true, false ],
			'mailhawk/assess-recipient-risk' => [ 'mailhawk-recipients', true, false ],
			'mailhawk/trust-recipient'       => [ 'mailhawk-recipients', false, false ],
			'mailhawk/remove-recipient'      => [ 'mailhawk-recipients', false, true ],
			'mailhawk/get-settings'          => [ 'mailhawk-settings', true, false ],
			'mailhawk/update-settings'       => [ 'mailhawk-settings', false, false ],
		];

		foreach ( $expected as $name => list( $category, $read_only, $destructive ) ) {

			$ability = wp_get_ability( $name );

			$this->assertNotNull( $ability, $name );
			$this->assertSame( $category, $ability->get_category(), $name );
			$this->assertNotSame( '', $ability->get_label(), $name );
			$this->assertNotSame( '', $ability->get_description(), $name );

			$annotations = $ability->get_meta()['annotations'];

			$this->assertSame( $read_only, $annotations['readonly'], $name . ' read only' );
			$this->assertSame( $destructive, $annotations['destructive'], $name . ' destructive' );
			$this->assertTrue( $ability->get_meta()['public'], $name . ' is public' );
		}

		// And nothing else, in particular nothing that touches the credentials
		$registered = [];

		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 === strpos( $ability->get_name(), 'mailhawk/' ) ) {
				$registered[] = $ability->get_name();
			}
		}

		$this->assertEqualsCanonicalizing( array_keys( $expected ), $registered );
	}

	public function test_only_administrators_can_use_them() {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->refused( 'mailhawk/get-settings', null, 'ability_invalid_permissions' );
		$this->refused( 'mailhawk/update-settings', [ 'from_name' => 'Hijack' ], 'ability_invalid_permissions' );

		wp_set_current_user( 0 );

		$this->refused( 'mailhawk/get-status', null, 'ability_invalid_permissions' );
		$this->assertNotSame( 'Hijack', get_option( 'mailhawk_default_from_name' ) );
	}

	public function test_a_site_can_turn_them_off() {

		$fired = 0;

		add_action( 'mailhawk/abilities/register_abilities', function () use ( &$fired ) {
			$fired ++;
		} );

		add_filter( 'mailhawk/abilities/enabled', '__return_false' );

		( new Abilities() )->register_abilities();
		( new Abilities() )->register_categories();

		$this->assertSame( 0, $fired );

		remove_filter( 'mailhawk/abilities/enabled', '__return_false' );

		( new Abilities() )->register_abilities();

		$this->assertSame( 1, $fired, 'Add-ons get their turn when they are on' );
	}

	// Status

	public function test_status_without_a_connection_says_so_and_asks_nothing() {

		$status = $this->ok( 'mailhawk/get-status' );

		$this->assertFalse( $status['connected'] );
		$this->assertFalse( $status['ready_to_send'] );
		$this->assertNull( $status['usage'] );
		$this->assertNull( $status['domains'] );
		$this->assertNull( $status['sending'] );
		$this->assertSame( MAILHAWK_VERSION, $status['version'] );
		$this->assertSame( [ 'not_connected' ], wp_list_pluck( $status['issues'], 'code' ) );
		$this->assertSame( [], $this->calls, 'There is no one to ask' );
	}

	public function test_status_of_a_healthy_site() {

		$this->connect();

		$days = array_fill( 0, 30, (object) [ 'outgoing' => 10, 'bounces' => 0, 'complaints' => 0 ] );

		$this->mta( [
			'reporting/limits' => [ (object) [ 'type' => 'monthly_send_limit', 'usage' => 25, 'limit' => 100, 'resets_at' => '2026-12-01T00:00:00+00:00' ] ],
			'domains/query'    => [ $this->domain( 'example.com' ), $this->domain( 'example.net' ) ],
			'reporting/query'  => (object) [ 'last_date' => '2026-11-06', 'graph_data' => $days ],
		] );

		$status = $this->ok( 'mailhawk/get-status', [ 'refresh' => true ] );

		$this->assertTrue( $status['connected'] );
		$this->assertTrue( $status['ready_to_send'] );
		$this->assertSame( [], $status['issues'] );
		$this->assertSame( [ 'used' => 25, 'limit' => 100, 'remaining' => 75, 'percent' => 25.0, 'resets_at' => '2026-12-01T00:00:00+00:00' ], $status['usage'] );
		$this->assertSame( [ 'total' => 2, 'authenticated' => 2, 'unauthenticated' => [] ], $status['domains'] );
		$this->assertSame( 300, $status['sending']['sent'] );
		$this->assertSame( 0.0, $status['sending']['bounce_rate'] );
		$this->assertSame( 0, $status['emails_held'] );
	}

	public function test_status_lists_everything_that_needs_attention() {

		$this->connect();
		set_mailhawk_setup_complete( false );
		set_mailhawk_webhook_error( 'Could not reach the site' );
		$this->add_log_item( 'held@example.net', 'quarantine' );

		$days = array_fill( 0, 30, (object) [ 'outgoing' => 10, 'bounces' => 1, 'complaints' => 0 ] );
		$days[29]->complaints = 3;

		$this->mta( [
			'reporting/limits' => [ (object) [ 'type' => 'monthly_send_limit', 'usage' => 95, 'limit' => 100 ] ],
			'domains/query'    => [ $this->domain( 'example.com', false ) ],
			'reporting/query'  => (object) [ 'last_date' => '2026-11-06', 'graph_data' => $days ],
		] );

		$status = $this->ok( 'mailhawk/get-status', [ 'refresh' => true ] );

		$this->assertEqualsCanonicalizing(
			[ 'setup_incomplete', 'webhook_not_registered', 'no_authenticated_domain', 'usage_high', 'emails_held', 'high_bounce_rate', 'spam_complaints' ],
			wp_list_pluck( $status['issues'], 'code' )
		);
		$this->assertFalse( $status['ready_to_send'] );
		$this->assertSame( [ 'example.com' ], $status['domains']['unauthenticated'] );
		$this->assertNull( $status['usage']['resets_at'], 'Older MTA builds do not say' );
		$this->assertSame( 1, $status['emails_held'] );
	}

	public function test_status_of_a_suspended_account_does_not_ask_the_service() {

		$this->connect();
		set_mailhawk_is_suspended( true );

		$status = $this->ok( 'mailhawk/get-status' );

		$this->assertTrue( $status['suspended'] );
		$this->assertContains( 'suspended', wp_list_pluck( $status['issues'], 'code' ) );
		$this->assertSame( [], $this->calls );

		// An older MTA said nothing but the code
		$this->assertNull( $status['suspension']['reason'] );
		$this->assertNull( $status['suspension']['suspended_at'] );
		$this->assertSame( 'server', $status['suspension']['scope'] );
		$this->assertSame( 'https://mailhawk.io/account/', $status['suspension']['next_steps'] );
	}

	public function test_status_says_why_the_account_is_suspended() {

		$this->connect();
		set_mailhawk_is_suspended( true );
		set_mailhawk_suspension( [ 'reason' => 'MailHawk: suspended by support.', 'suspended_at' => '2026-11-09T16:20:50Z', 'scope' => 'organization' ] );

		$status = $this->ok( 'mailhawk/get-status' );

		$this->assertSame( [
			'reason'       => 'MailHawk: suspended by support.',
			'suspended_at' => '2026-11-09T16:20:50Z',
			'scope'        => 'organization',
			'next_steps'   => 'https://mailhawk.io/account/',
		], $status['suspension'] );

		$issue = wp_list_filter( $status['issues'], [ 'code' => 'suspended' ] );
		$this->assertStringContainsString( 'MailHawk: suspended by support.', reset( $issue )['message'] );
	}

	public function test_status_of_an_account_that_is_not_suspended_has_no_suspension() {

		$this->connect();
		$this->mta( [] );

		$this->assertNull( $this->ok( 'mailhawk/get-status' )['suspension'] );
	}

	public function test_status_survives_a_service_that_does_not_answer() {

		$this->connect();

		$this->mta( [
			'reporting/limits' => new WP_Error( 'Boom', 'down' ),
			'domains/query'    => new WP_Error( 'Boom', 'down' ),
			'reporting/query'  => new WP_Error( 'Boom', 'down' ),
		] );

		$status = $this->ok( 'mailhawk/get-status', [ 'refresh' => true ] );

		$this->assertNull( $status['usage'] );
		$this->assertNull( $status['domains'] );
		$this->assertNull( $status['sending'] );
		$this->assertTrue( $status['ready_to_send'], 'Not knowing is not a reason to say it can not send' );
	}

	// Reports

	protected function graph( $periods, $current, $previous ) {

		$data = [];

		for ( $i = 0; $i < $periods; $i ++ ) {
			$data[] = (object) $previous;
		}

		for ( $i = 0; $i < $periods; $i ++ ) {
			$data[] = (object) $current;
		}

		return (object) [ 'last_date' => '2026-11-06', 'graph_data' => $data ];
	}

	public function test_reports_compare_the_range_with_the_one_before() {

		$this->connect();

		$this->mta( [
			'reporting/query'  => $this->graph( 7, [ 'outgoing' => 20, 'bounces' => 2, 'complaints' => 1 ], [ 'outgoing' => 10, 'bounces' => 1, 'complaints' => 0 ] ),
			'reporting/limits' => [ (object) [ 'type' => 'monthly_send_limit', 'usage' => 5, 'limit' => 10 ] ],
		] );

		$report = $this->ok( 'mailhawk/get-reports' );

		$this->assertSame( [ 'duration' => 14, 'type' => 'daily' ], $this->calls[0]['body'], 'Twice the range, to compare with' );
		$this->assertSame( '7', $report['range'] );
		$this->assertSame( 'daily', $report['interval'] );

		$this->assertSame( [ 'sent' => 140, 'bounces' => 14, 'complaints' => 7, 'bounce_rate' => 10.0, 'delivery_rate' => 90.0 ], $report['current'] );
		$this->assertSame( 70, $report['previous']['sent'] );
		$this->assertSame( 100.0, $report['change']['sent'] );
		$this->assertSame( 100.0, $report['change']['bounces'] );
		$this->assertNull( $report['change']['complaints'], 'Nothing before it to compare with' );

		$this->assertCount( 7, $report['series'] );
		$this->assertSame( '2026-10-31', $report['series'][0]['label'] );
		$this->assertSame( '2026-11-06', $report['series'][6]['label'] );
		$this->assertSame( 20, $report['series'][6]['sent'] );
		$this->assertSame( 50.0, $report['usage']['percent'] );
	}

	public function test_reports_take_the_range_as_text_or_a_number_and_do_hours() {

		$this->connect();

		$this->mta( [
			'reporting/query'  => $this->graph( 24, [ 'outgoing' => 1, 'bounces' => 0, 'complaints' => 0 ], [ 'outgoing' => 1, 'bounces' => 0, 'complaints' => 0 ] ),
			'reporting/limits' => new WP_Error( 'Boom', 'down' ),
		] );

		$today = $this->ok( 'mailhawk/get-reports', [ 'range' => 'today' ] );

		$this->assertSame( 'hourly', $today['interval'] );
		$this->assertCount( 24, $today['series'] );
		$this->assertSame( [ 'duration' => 48, 'type' => 'hourly' ], $this->calls[0]['body'] );
		$this->assertNull( $today['usage'], 'The numbers are worth having without it' );

		$this->mta( [
			'reporting/query'  => $this->graph( 30, [ 'outgoing' => 1, 'bounces' => 0, 'complaints' => 0 ], [ 'outgoing' => 1, 'bounces' => 0, 'complaints' => 0 ] ),
			'reporting/limits' => [],
		] );

		$month = $this->ok( 'mailhawk/get-reports', [ 'range' => 30 ] );

		$this->assertSame( '30', $month['range'] );
		$this->assertCount( 30, $month['series'] );

		$this->refused( 'mailhawk/get-reports', [ 'range' => '90' ], 'ability_invalid_input' );
	}

	public function test_reports_do_not_make_up_complaints_an_older_server_does_not_report() {

		$this->connect();

		$this->mta( [
			'reporting/query'  => $this->graph( 7, [ 'outgoing' => 10, 'bounces' => 0 ], [ 'outgoing' => 10, 'bounces' => 0 ] ),
			'reporting/limits' => [],
		] );

		$report = $this->ok( 'mailhawk/get-reports' );

		$this->assertNull( $report['current']['complaints'] );
		$this->assertNull( $report['change']['complaints'] );
		$this->assertNull( $report['series'][0]['complaints'] );
		$this->assertSame( 100.0, $report['current']['delivery_rate'] );
	}

	public function test_reports_say_when_the_service_fails_or_there_is_no_connection() {

		$this->refused( 'mailhawk/get-reports', null, 'mailhawk_not_connected' );

		$this->connect();
		$this->mta( [ 'reporting/query' => new WP_Error( 'Boom', 'The MTA is down' ) ] );

		$error = $this->refused( 'mailhawk/get-reports', null, 'Boom' );

		$this->assertSame( 'The MTA is down', $error->get_error_message() );
	}

	// Domains

	public function test_domains_are_listed_by_name_with_their_state() {

		$this->connect();
		$this->mta( [ 'domains/query' => [ $this->domain( 'zebra.com', false ), $this->domain( 'apple.com' ) ] ] );

		$list = $this->ok( 'mailhawk/list-domains' );

		$this->assertSame( [ 'apple.com', 'zebra.com' ], wp_list_pluck( $list['domains'], 'name' ) );
		$this->assertTrue( $list['domains'][0]['authenticated'] );
		$this->assertFalse( $list['domains'][1]['authenticated'] );
		$this->assertSame( 'Missing', $list['domains'][1]['dkim'] );
	}

	public function test_domains_need_a_connection_and_report_service_errors() {

		$this->refused( 'mailhawk/list-domains', null, 'mailhawk_not_connected' );

		$this->connect();
		set_mailhawk_is_suspended( true );

		$this->refused( 'mailhawk/list-domains', null, 'mailhawk_suspended' );

		$this->connect();
		$this->mta( [ 'domains/query' => new WP_Error( 'Boom', 'down' ) ] );

		$this->refused( 'mailhawk/list-domains', null, 'Boom' );
	}

	public function test_the_dns_records_are_what_the_dns_screen_shows() {

		$this->connect();
		$this->mta( [ 'domains/query' => $this->domain( 'example.com' ) ] );

		$dns = $this->ok( 'mailhawk/get-domain-dns', [ 'domain' => 'example.com' ] );

		$this->assertSame( [ 'name' => 'example.com', 'authenticated' => true, 'spf' => 'OK', 'dkim' => 'OK', 'return_path' => 'OK' ], $dns['domain'] );
		$this->assertSame( [ 'dkim', 'return_path', 'spf' ], wp_list_pluck( $dns['records'], 'purpose' ) );

		list( $dkim, $return_path, $spf ) = $dns['records'];

		$this->assertSame( 'TXT', $dkim['type'] );
		$this->assertSame( 'mh._domainkey.example.com', $dkim['name'], 'Lowercase, as the screen has it' );
		$this->assertSame( 'mh._domainkey', $dkim['host'] );
		$this->assertSame( 'mh._domainkey.example.com', $dkim['hostname'] );
		$this->assertSame( 'psrp', $dns['records'][1]['host'] );
		$this->assertSame( 'psrp.example.com', $dns['records'][1]['hostname'] );
		$this->assertSame( '@', $dns['records'][2]['host'] );
		$this->assertSame( 'example.com', $dns['records'][2]['hostname'] );
		$this->assertSame( 'v=DKIM1; p=abc', $dkim['value'] );
		$this->assertTrue( $dkim['verified'] );

		$this->assertSame( 'CNAME', $return_path['type'] );
		$this->assertSame( 'psrp.example.com', $return_path['name'] );
		$this->assertSame( 'rp.mta01.mailhawk.io', $return_path['value'] );

		$this->assertSame( '@', $spf['name'] );
		$this->assertSame( 'v=spf1 a mx include:spf.mailhawk.io ~all', $spf['value'] );
		$this->assertNull( $dns['existing_spf_record'] );
		$this->assertSame( [ 'domains/query' ], $this->endpoints() );
		$this->assertSame( 'example.com', $this->calls[0]['body']['name'] );
	}

	public function test_the_dns_records_of_a_subdomain_say_the_host_and_the_full_hostname() {

		$this->connect();

		// As the MTA has it: the DKIM name relative to the domain, the return path in full
		$domain                         = $this->domain( 'academy.example.com' );
		$domain->dkim->dkim_record_name = 'postal-AbC123._domainkey';

		$this->mta( [ 'domains/query' => $domain ] );

		list( $dkim, $return_path, $spf ) = $this->ok( 'mailhawk/get-domain-dns', [ 'domain' => 'academy.example.com' ] )['records'];

		$this->assertSame( 'postal-abc123._domainkey', $dkim['host'] );
		$this->assertSame( 'postal-abc123._domainkey.academy.example.com', $dkim['hostname'] );
		$this->assertSame( 'psrp', $return_path['host'] );
		$this->assertSame( 'psrp.academy.example.com', $return_path['hostname'] );
		$this->assertSame( '@', $spf['host'] );
		$this->assertSame( 'academy.example.com', $spf['hostname'] );
	}

	public function test_the_dns_screen_shows_the_host_and_the_full_hostname_of_each_record() {

		$this->connect();

		$domain                         = $this->domain( 'academy.example.com' );
		$domain->dkim->dkim_record_name = 'postal-AbC123._domainkey';

		$this->mta( [ 'domains/query' => $domain ] );

		$_GET['domain'] = 'academy.example.com';

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/views/dns-single.php';
		$html = ob_get_clean();

		unset( $_GET['domain'] );

		// The pill copies the full hostname, the host inside it just the host
		foreach ( [ 'postal-abc123._domainkey', 'postal-abc123._domainkey.academy.example.com', 'psrp', 'psrp.academy.example.com', '@', 'academy.example.com' ] as $value ) {
			$this->assertStringContainsString( 'data-copy="' . $value . '"', $html, $value );
		}

		$this->assertStringContainsString( '>.academy.example.com</span>', $html, 'The rest of the full hostname follows the host' );

		// The TTL and the values copy the same way
		foreach ( [ '3600', 'v=DKIM1; p=abc', 'rp.mta01.mailhawk.io', 'v=spf1 a mx include:spf.mailhawk.io ~all' ] as $value ) {
			$this->assertStringContainsString( 'data-copy="' . esc_attr( $value ) . '"', $html, $value );
		}

		$this->assertStringNotContainsString( 'onfocus="this.select()"', $html, 'No plain fields left' );

		// The instructions for whoever adds the records, in the button that copies them
		$this->assertStringContainsString( 'mailhawk-copy-button', $html );
		$this->assertStringContainsString( 'Copy instructions', $html );
		$this->assertStringContainsString( esc_attr( 'Full hostname: postal-abc123._domainkey.academy.example.com' ), $html );
		$this->assertStringContainsString( 'postal-abc._domainkey.academy', $html, 'The parent domain case is explained' );
	}

	public function test_a_domain_that_has_an_spf_record_gets_mailhawk_added_to_it() {

		$this->connect();
		$this->mta( [ 'domains/query' => $this->domain( 'example.com', false ) ] );

		add_filter( 'mailhawk/abilities/existing_spf_record', function () {
			return 'v=spf1 include:_spf.google.com ~all';
		} );

		$dns = $this->ok( 'mailhawk/get-domain-dns', [ 'domain' => 'example.com' ] );

		$this->assertSame( 'v=spf1 include:_spf.google.com ~all', $dns['existing_spf_record'] );
		$this->assertSame( 'v=spf1 include:_spf.google.com include:spf.mailhawk.io ~all', $dns['records'][2]['value'], 'One record only, with both in it' );
		$this->assertFalse( $dns['records'][2]['verified'] );
	}

	public function test_dns_for_a_domain_name_that_is_not_one_is_refused_without_asking() {

		$this->connect();

		$this->refused( 'mailhawk/get-domain-dns', [ 'domain' => 'not a domain' ], 'mailhawk_invalid_domain' );
		$this->assertSame( [], $this->calls );
	}

	public function test_adding_a_domain_registers_it_and_returns_the_records() {

		$this->connect();
		$this->mta( [
			'domains/create' => (object) [],
			'domains/query'  => $this->domain( 'example.com', false ),
		] );

		$added = $this->ok( 'mailhawk/add-domain', [ 'domain' => 'https://www.Example.com/some/page' ] );

		$this->assertTrue( $added['created'] );
		$this->assertSame( 'example.com', $added['domain']['name'] );
		$this->assertCount( 3, $added['records'] );
		$this->assertSame( [ 'domains/create', 'domains/query' ], $this->endpoints() );
		$this->assertSame( 'example.com', $this->calls[0]['body']['name'], 'A URL and a www are not part of the domain' );
	}

	public function test_adding_a_domain_that_is_registered_already_is_fine() {

		$this->connect();
		$this->mta( [
			'domains/create' => new WP_Error( 'DomainNameExists', 'It is there' ),
			'domains/query'  => $this->domain( 'example.com' ),
		] );

		$added = $this->ok( 'mailhawk/add-domain', [ 'domain' => 'example.com' ] );

		$this->assertFalse( $added['created'] );
		$this->assertTrue( $added['domain']['authenticated'] );
	}

	public function test_adding_a_domain_passes_on_the_limit_of_the_plan() {

		$this->connect();
		$this->mta( [ 'domains/create' => new WP_Error( 'ReachedDomainLimit', 'Upgrade' ) ] );

		$this->refused( 'mailhawk/add-domain', [ 'domain' => 'example.com' ], 'ReachedDomainLimit' );
		$this->assertSame( [ 'domains/create' ], $this->endpoints() );
	}

	public function test_domains_that_could_never_be_authenticated_are_not_added() {

		$this->connect();

		$this->refused( 'mailhawk/add-domain', [ 'domain' => 'not a domain' ], 'mailhawk_invalid_domain' );
		$this->refused( 'mailhawk/add-domain', [ 'domain' => 'mailhawk.local' ], 'mailhawk_reserved_domain' );
		$this->refused( 'mailhawk/add-domain', [ 'domain' => 'site.test' ], 'mailhawk_reserved_domain' );
		$this->refused( 'mailhawk/add-domain', [ 'domain' => 'localhost' ], 'mailhawk_invalid_domain' );
		$this->refused( 'mailhawk/add-domain', [ 'domain' => 'gmail.com' ], 'mailhawk_free_provider' );

		$this->assertSame( [], $this->calls, 'None of them used up a domain of the plan' );
	}

	public function test_checking_a_domain_asks_the_service_to_look_then_reports_what_it_found() {

		$this->connect();
		$this->mta( [
			'domains/check' => $this->domain( 'example.com' ),
			'domains/query' => $this->domain( 'example.com' ),
		] );

		$dns = $this->ok( 'mailhawk/check-domain-dns', [ 'domain' => 'example.com' ] );

		$this->assertSame( [ 'domains/check', 'domains/query' ], $this->endpoints() );
		$this->assertTrue( $dns['domain']['authenticated'] );
	}

	public function test_deleting_a_domain_has_to_be_confirmed_by_saying_it_again() {

		$this->connect();

		$this->refused( 'mailhawk/delete-domain', [ 'domain' => 'example.com', 'confirm' => 'example.net' ], 'mailhawk_confirmation_mismatch' );
		$this->assertSame( [], $this->calls );

		$this->mta( [ 'domains/delete' => [] ] );

		$deleted = $this->ok( 'mailhawk/delete-domain', [ 'domain' => 'Example.com', 'confirm' => 'WWW.example.com' ] );

		$this->assertSame( [ 'deleted' => true, 'domain' => 'example.com' ], $deleted );
		$this->assertSame( [ 'domains/delete' ], $this->endpoints() );
		$this->assertSame( 'example.com', $this->calls[0]['body']['name'] );
	}

	public function test_the_domain_the_site_sends_from_is_not_deleted() {

		$this->connect();
		update_option( 'mailhawk_default_from_email_address', 'Hello@Example.com' );

		$this->refused( 'mailhawk/delete-domain', [ 'domain' => 'example.com', 'confirm' => 'example.com' ], 'mailhawk_domain_in_use' );
		$this->assertSame( [], $this->calls );
	}

	// Email log

	protected function seed_log() {

		return [
			'a' => $this->add_log_item( 'a@example.com', 'sent', [ 'subject' => 'Welcome aboard', 'date_sent' => '2026-01-10 10:00:00' ] ),
			'b' => $this->add_log_item( 'b@example.com', 'failed', [ 'subject' => 'Receipt', 'date_sent' => '2026-01-11 09:00:00', 'error_code' => 'dummy_error', 'error_message' => 'Dummy error message.' ] ),
			'c' => $this->add_log_item( 'c@example.net', 'quarantine', [ 'subject' => 'Welcome back', 'date_sent' => '2026-01-11 18:00:00' ] ),
			'd' => $this->add_log_item( [ 'a@example.com', 'd@example.org' ], 'sent', [ 'subject' => 'Newsletter', 'date_sent' => '2026-01-12 08:00:00' ] ),
		];
	}

	public function test_the_log_is_listed_newest_first_without_what_the_emails_said() {

		$ids = $this->seed_log();

		$list = $this->ok( 'mailhawk/list-emails' );

		$this->assertSame( 4, $list['total'] );
		$this->assertSame( 20, $list['limit'] );
		$this->assertSame( [ $ids['d'], $ids['c'], $ids['b'], $ids['a'] ], wp_list_pluck( $list['emails'], 'id' ) );
		$this->assertSame( [ 'a@example.com', 'd@example.org' ], $list['emails'][0]['recipients'] );

		foreach ( $list['emails'] as $email ) {
			$this->assertArrayNotHasKey( 'content', $email );
			$this->assertArrayNotHasKey( 'body', $email );
		}

		$failed = $list['emails'][2];

		$this->assertSame( 'failed', $failed['status'] );
		$this->assertSame( 'Failed', $failed['status_label'] );
		$this->assertSame( 'dummy_error', $failed['error_code'] );
		$this->assertSame( [ 'mailhawk/retry-email' ], $failed['actions'] );
		$this->assertSame( [ 'mailhawk/release-email', 'mailhawk/reject-email' ], $list['emails'][1]['actions'], 'A held email is released or rejected' );
	}

	public function test_the_log_can_be_searched() {

		$ids = $this->seed_log();

		$by = function ( $input ) {
			return wp_list_pluck( $this->ok( 'mailhawk/list-emails', $input )['emails'], 'id' );
		};

		$this->assertSame( [ $ids['b'] ], $by( [ 'status' => 'failed' ] ) );
		$this->assertSame( [ $ids['c'] ], $by( [ 'status' => 'quarantine' ] ) );
		$this->assertSame( [ $ids['c'] ], $by( [ 'recipient' => 'example.net' ] ) );
		$this->assertSame( [ $ids['d'], $ids['a'] ], $by( [ 'recipient' => 'a@example.com' ] ), 'Anything it was one of the recipients of' );
		$this->assertSame( [ $ids['c'], $ids['a'] ], $by( [ 'subject' => 'welcome' ] ) );
		$this->assertSame( [ $ids['d'], $ids['c'] ], $by( [ 'after' => '2026-01-11 12:00' ] ) );
		$this->assertSame( [ $ids['c'], $ids['b'] ], $by( [ 'after' => '2026-01-11', 'before' => '2026-01-11' ] ), 'A date alone is the whole day' );
		$this->assertSame( [ $ids['a'], $ids['b'] ], $by( [ 'before' => '2026-01-11 09:00:00', 'order' => 'asc' ] ) );
		$this->assertSame( [], $by( [ 'status' => 'bounced' ] ) );
	}

	public function test_the_log_is_paged_and_counted_whatever_the_page() {

		$ids = $this->seed_log();

		$page = $this->ok( 'mailhawk/list-emails', [ 'limit' => 2, 'offset' => 1 ] );

		$this->assertSame( 4, $page['total'] );
		$this->assertSame( 1, $page['offset'] );
		$this->assertSame( [ $ids['c'], $ids['b'] ], wp_list_pluck( $page['emails'], 'id' ) );

		$this->refused( 'mailhawk/list-emails', [ 'limit' => 500 ], 'ability_invalid_input' );
		$this->refused( 'mailhawk/list-emails', [ 'after' => 'not a date' ], 'mailhawk_invalid_date' );
		$this->refused( 'mailhawk/list-emails', [ 'status' => 'lost' ], 'ability_invalid_input' );
	}

	public function test_an_email_shows_its_headers_and_only_says_what_it_said_when_asked() {

		$id = $this->add_log_item( 'a@example.com', 'sent', [
			'content' => '<p>Secret</p>',
			'headers' => [ [ 'Content-Type', 'text/html' ], [ 'From', 'Test <info@example.com>' ] ],
			'msg_id'  => 'abc',
		] );

		$email = $this->ok( 'mailhawk/get-email', [ 'id' => $id ] );

		$this->assertNull( $email['body'] );
		$this->assertFalse( $email['body_truncated'] );
		$this->assertSame( 'text/html', $email['content_type'] );
		$this->assertSame( [ 'name' => 'From', 'value' => 'Test <info@example.com>' ], $email['headers'][1] );
		$this->assertSame( 'abc', $email['message_id'] );

		$with = $this->ok( 'mailhawk/get-email', [ 'id' => $id, 'include_body' => true ] );

		$this->assertSame( '<p>Secret</p>', $with['body'] );
		$this->assertFalse( $with['body_truncated'] );

		add_filter( 'mailhawk/abilities/max_body_length', function () {
			return 5;
		} );

		$cut = $this->ok( 'mailhawk/get-email', [ 'id' => $id, 'include_body' => true ] );

		$this->assertSame( '<p>Se', $cut['body'] );
		$this->assertTrue( $cut['body_truncated'] );
	}

	public function test_an_email_that_is_not_in_the_log_is_not_found() {
		$this->refused( 'mailhawk/get-email', [ 'id' => 987654 ], 'mailhawk_email_not_found' );
	}

	public function test_a_test_email_goes_to_the_current_user_and_is_logged() {

		$this->connect();
		$this->mta( $this->send_succeeds() );

		$sent = $this->ok( 'mailhawk/send-test-email' );

		$user = wp_get_current_user();

		$this->assertTrue( $sent['sent'] );
		$this->assertFalse( $sent['held'] );
		$this->assertSame( $user->user_email, $sent['to'] );
		$this->assertSame( 'sent', $sent['status'] );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $sent['subject'] );
		$this->assertSame( [ 'send/raw' ], $this->endpoints() );
		$this->assertSame( [ $user->user_email ], $this->calls[0]['body']['rcpt_to'] );

		$this->assertSame( [ $user->user_email ], Plugin::instance()->log->get( $sent['log_id'] )->recipients );
	}

	public function test_a_test_email_to_a_risky_address_is_held_not_sent() {

		$this->connect();
		$this->mta( $this->send_succeeds() );

		$sent = $this->ok( 'mailhawk/send-test-email', [ 'to' => 'someone+test-quarantine@example.net' ] );

		$this->assertFalse( $sent['sent'] );
		$this->assertTrue( $sent['held'] );
		$this->assertSame( 'quarantine', $sent['status'] );
		$this->assertSame( [], $this->endpoints() );
	}

	public function test_a_test_email_that_fails_says_why() {

		$this->connect();
		$this->mta( [ 'send/raw' => new WP_Error( 'Boom', 'The MTA said no' ) ] );

		$error = $this->refused( 'mailhawk/send-test-email', null, 'mailhawk_test_failed' );

		$this->assertStringContainsString( 'The MTA said no', $error->get_error_message() );
	}

	public function test_a_test_email_needs_a_connection_and_an_address() {

		$this->refused( 'mailhawk/send-test-email', null, 'mailhawk_not_connected' );

		$this->connect();

		$this->refused( 'mailhawk/send-test-email', [ 'to' => 'not-an-address' ], 'ability_invalid_input' );
		$this->assertSame( [], $this->calls );
	}

	public function test_a_failed_email_is_sent_again() {

		$this->connect();
		$this->mta( $this->send_succeeds() );

		$id = $this->add_log_item( 'a@example.net', 'failed', [ 'error_code' => 'dummy_error', 'error_message' => 'Dummy error message.' ] );

		$retry = $this->ok( 'mailhawk/retry-email', [ 'id' => $id ] );

		$this->assertTrue( $retry['retried'] );
		$this->assertSame( 'sent', $retry['email']['status'] );
		$this->assertSame( $id, $retry['email']['id'] );
		$this->assertSame( [ 'send/raw' ], $this->endpoints() );
		$this->assertSame( [ 'a@example.net' ], $this->calls[0]['body']['rcpt_to'] );
	}

	public function test_an_email_that_fails_again_says_why_and_stays_failed() {

		$this->connect();
		$this->mta( [ 'send/raw' => new WP_Error( 'Boom', 'Still down' ) ] );

		$id = $this->add_log_item( 'a@example.net', 'failed' );

		$error = $this->refused( 'mailhawk/retry-email', [ 'id' => $id ], 'mailhawk_retry_failed' );

		$this->assertStringContainsString( 'Still down', $error->get_error_message() );
		$this->assertSame( 'failed', Plugin::instance()->log->get( $id )->status );
	}

	public function test_an_email_is_not_sent_again_to_someone_who_would_be_held_now() {

		$this->connect();
		$this->mta( $this->send_succeeds() );

		$this->recipients->record_complaint( 'angry@example.net' );
		$this->recipients->record_bounce( 'dead@example.net' );
		$this->recipients->record_bounce( 'dead@example.net' );

		$spam    = $this->add_log_item( 'angry@example.net', 'sent' );
		$bounced = $this->add_log_item( [ 'fine@example.net', 'dead@example.net' ], 'bounced' );

		$error = $this->refused( 'mailhawk/retry-email', [ 'id' => $spam ], 'mailhawk_recipient_at_risk' );
		$this->assertStringContainsString( 'angry@example.net', $error->get_error_message() );

		$this->refused( 'mailhawk/retry-email', [ 'id' => $bounced ], 'mailhawk_recipient_at_risk' );

		$this->assertSame( [], $this->calls, 'Nothing was sent' );

		// Trusting somebody is the way to say it is fine
		$this->recipients->trust( 'dead@example.net' );

		$this->assertTrue( $this->ok( 'mailhawk/retry-email', [ 'id' => $bounced ] )['retried'] );
	}

	public function test_nothing_a_site_does_can_raise_the_bar_for_holding_an_email() {

		$this->connect();
		$this->mta( $this->send_succeeds() );

		add_filter( 'mailhawk/quarantine_threshold', function () {
			return 100000;
		} );

		$this->recipients->record_bounce( 'dead@example.net' );
		$this->recipients->record_bounce( 'dead@example.net' );

		$assessed = $this->ok( 'mailhawk/assess-recipient-risk', [ 'email' => 'dead@example.net' ] );

		$this->assertSame( 5, $assessed['threshold'] );
		$this->assertTrue( $assessed['held'] );

		$sent = $this->ok( 'mailhawk/send-test-email', [ 'to' => 'someone+test-quarantine@example.net' ] );

		$this->assertTrue( $sent['held'] );
		$this->assertSame( [], $this->endpoints() );
	}

	public function test_a_held_email_is_not_retried_and_a_missing_one_is_not_found() {

		$this->connect();

		$held = $this->add_log_item( 'a@example.net', 'quarantine' );

		$this->refused( 'mailhawk/retry-email', [ 'id' => $held ], 'mailhawk_email_held' );
		$this->refused( 'mailhawk/retry-email', [ 'id' => 987654 ], 'mailhawk_email_not_found' );
		$this->assertSame( [], $this->calls );
	}

	// Quarantine

	public function test_the_quarantine_report_has_the_emails_closest_to_being_deleted_first() {

		$old = $this->add_log_item( 'old@example.net', 'quarantine', [ 'subject' => 'Old', 'date_sent' => date( 'Y-m-d H:i:s', strtotime( '28 days ago' ) ) ] );
		$new = $this->add_log_item( 'new@example.net', 'quarantine', [ 'subject' => 'New', 'date_sent' => date( 'Y-m-d H:i:s', strtotime( '2 days ago' ) ) ] );
		$this->add_log_item( 'fine@example.net', 'sent' );

		$this->recipients->trust( 'friend@example.net' );
		$this->recipients->record_complaint( 'angry@example.net' );

		$report = $this->ok( 'mailhawk/get-quarantine-report' );

		$this->assertSame( 2, $report['waiting'] );
		$this->assertSame( 1, $report['expiring'], 'Only the old one goes within a week' );
		$this->assertSame( 30, $report['retention_days'] );
		$this->assertSame( [ $old, $new ], wp_list_pluck( $report['items'], 'id' ) );
		$this->assertSame( [ 'old@example.net' ], $report['items'][0]['recipients'] );
		$this->assertSame( 1, $report['blocked'] );
		$this->assertSame( 1, $report['trusted'] );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $report['items'][0]['held_at'] );
		$this->assertSame( strtotime( $report['items'][0]['held_at'] ) + 30 * DAY_IN_SECONDS, strtotime( $report['items'][0]['expires_at'] ) );

		$wider = $this->ok( 'mailhawk/get-quarantine-report', [ 'expiring_days' => 29, 'limit' => 1 ] );

		$this->assertSame( 2, $wider['expiring'] );
		$this->assertCount( 1, $wider['items'] );
	}

	public function test_a_held_email_is_released_and_sent() {

		$this->connect();
		$this->mta( $this->send_succeeds() );

		$id = $this->add_log_item( 'held@example.net', 'quarantine' );

		$released = $this->ok( 'mailhawk/release-email', [ 'id' => $id ] );

		$this->assertTrue( $released['released'] );
		$this->assertSame( 'sent', $released['email']['status'] );
		$this->assertSame( [], $released['trusted'] );
		$this->assertSame( [ 'send/raw' ], $this->endpoints() );
		$this->assertFalse( $this->recipients->get_by_email( 'held@example.net' ), 'Not trusted unless asked' );

		$this->refused( 'mailhawk/release-email', [ 'id' => $id ], 'mailhawk_not_held' );
		$this->assertCount( 1, $this->calls, 'It is never sent twice' );
	}

	public function test_releasing_can_trust_the_recipients_except_those_who_asked_to_stop() {

		$this->connect();
		$this->mta( $this->send_succeeds() );

		$this->recipients->record_complaint( 'angry@example.net' );

		$id = $this->add_log_item( [ 'friend@example.net', 'angry@example.net' ], 'quarantine' );

		$released = $this->ok( 'mailhawk/release-email', [ 'id' => $id, 'trust_recipients' => true ] );

		$this->assertSame( [ 'friend@example.net' ], $released['trusted'] );
		$this->assertSame( [ 'angry@example.net' ], $released['not_trusted'] );
		$this->assertSame( Recipients::TRUSTED, $this->recipients->get_by_email( 'friend@example.net' )->status );
		$this->assertSame( Recipients::BLOCKED, $this->recipients->get_by_email( 'angry@example.net' )->status );
	}

	public function test_an_email_that_can_not_be_released_is_failed_and_can_be_retried() {

		$this->connect();
		$this->mta( [ 'send/raw' => new WP_Error( 'Boom', 'down' ) ] );

		$id = $this->add_log_item( 'held@example.net', 'quarantine' );

		$error = $this->refused( 'mailhawk/release-email', [ 'id' => $id ], 'mailhawk_release_failed' );

		$this->assertStringContainsString( 'mailhawk/retry-email', $error->get_error_message() );
		$this->assertSame( 'failed', Plugin::instance()->log->get( $id )->status, 'Sending it again is what fails it' );

		$this->mta( $this->send_succeeds() );

		$this->assertTrue( $this->ok( 'mailhawk/retry-email', [ 'id' => $id ] )['retried'] );

		$this->refused( 'mailhawk/release-email', [ 'id' => 987654 ], 'mailhawk_email_not_found' );
	}

	public function test_releasing_needs_a_connection() {

		$id = $this->add_log_item( 'held@example.net', 'quarantine' );

		$this->refused( 'mailhawk/release-email', [ 'id' => $id ], 'mailhawk_not_connected' );
		$this->assertSame( 'quarantine', Plugin::instance()->log->get( $id )->status );
	}

	public function test_a_held_email_is_rejected_for_good() {

		$id   = $this->add_log_item( 'held@example.net', 'quarantine' );
		$sent = $this->add_log_item( 'sent@example.net', 'sent' );

		$rejected = $this->ok( 'mailhawk/reject-email', [ 'id' => $id ] );

		$this->assertSame( [ 'rejected' => true, 'id' => $id, 'unsubscribed' => 0 ], $rejected );
		$this->assertFalse( $this->log_has( $id ) );

		$this->refused( 'mailhawk/reject-email', [ 'id' => $sent ], 'mailhawk_not_held' );
		$this->assertTrue( $this->log_has( $sent ), 'Only a held email can be rejected' );
		$this->refused( 'mailhawk/reject-email', [ 'id' => $id ], 'mailhawk_email_not_found' );
	}

	public function test_nothing_is_rejected_when_contacts_were_to_be_unsubscribed_without_groundhogg() {

		if ( is_groundhogg_active() ) {
			$this->markTestSkipped( 'Groundhogg is active.' );
		}

		$id = $this->add_log_item( 'held@example.net', 'quarantine' );

		$this->refused( 'mailhawk/reject-email', [ 'id' => $id, 'unsubscribe_contacts' => true ], 'mailhawk_groundhogg_inactive' );
		$this->assertTrue( $this->log_has( $id ), 'Nothing is half done' );
	}

	// Recipients

	protected function seed_recipients() {
		$this->recipients->trust( 'friend@example.net', 'manual' );
		$this->recipients->record_bounce( 'bounce@example.net' );
		$this->recipients->record_bounce( 'bounce@example.net' );
		$this->recipients->record_complaint( 'angry@example.net' );
	}

	public function test_recipients_are_listed_with_what_is_known_about_them() {

		$this->seed_recipients();

		$list = $this->ok( 'mailhawk/list-recipients' );

		$this->assertSame( 3, $list['total'] );

		$by_email = array_column( $list['recipients'], null, 'email' );

		$this->assertSame( 'trusted', $by_email['friend@example.net']['status'] );
		$this->assertSame( 'manual', $by_email['friend@example.net']['source'] );
		$this->assertNull( $by_email['friend@example.net']['last_bounce'] );
		$this->assertSame( 'watching', $by_email['bounce@example.net']['status'] );
		$this->assertSame( 2, $by_email['bounce@example.net']['bounces'] );
		$this->assertNotNull( $by_email['bounce@example.net']['last_bounce'] );
		$this->assertSame( 'blocked', $by_email['angry@example.net']['status'] );
		$this->assertSame( 1, $by_email['angry@example.net']['complaints'] );
	}

	public function test_recipients_can_be_filtered_searched_and_paged() {

		$this->seed_recipients();

		$emails = function ( $input ) {
			return wp_list_pluck( $this->ok( 'mailhawk/list-recipients', $input )['recipients'], 'email' );
		};

		$this->assertSame( [ 'friend@example.net' ], $emails( [ 'status' => 'trusted' ] ) );
		$this->assertSame( [ 'bounce@example.net' ], $emails( [ 'status' => 'watching' ] ) );
		$this->assertSame( [ 'angry@example.net' ], $emails( [ 'status' => 'blocked' ] ) );
		$this->assertSame( [ 'angry@example.net' ], $emails( [ 'search' => 'ANGRY' ] ) );

		$page = $this->ok( 'mailhawk/list-recipients', [ 'limit' => 1, 'offset' => 1 ] );

		$this->assertSame( 3, $page['total'] );
		$this->assertCount( 1, $page['recipients'] );
	}

	public function test_the_risk_of_a_trusted_or_blocked_address_is_settled() {

		$this->seed_recipients();

		$trusted = $this->ok( 'mailhawk/assess-recipient-risk', [ 'email' => 'Friend@Example.net' ] );

		$this->assertSame( 'friend@example.net', $trusted['email'] );
		$this->assertSame( 0, $trusted['risk'] );
		$this->assertFalse( $trusted['held'] );
		$this->assertSame( [ 'trusted' ], wp_list_pluck( $trusted['factors'], 'code' ) );
		$this->assertSame( 'trusted', $trusted['recipient']['status'] );

		$blocked = $this->ok( 'mailhawk/assess-recipient-risk', [ 'email' => 'angry@example.net' ] );

		$this->assertTrue( $blocked['held'] );
		$this->assertSame( 999, $blocked['risk'] );
		$this->assertSame( [ 'reported_spam' ], wp_list_pluck( $blocked['factors'], 'code' ) );
	}

	public function test_bounces_and_free_providers_add_up_to_the_risk_they_are_shown_as() {

		$this->seed_recipients();

		$bouncing = $this->ok( 'mailhawk/assess-recipient-risk', [ 'email' => 'bounce@example.net' ] );

		$this->assertSame( 6, $bouncing['risk'] );
		$this->assertTrue( $bouncing['held'], 'Two bounces hold the next email' );
		$this->assertSame( 5, $bouncing['threshold'] );
		$this->assertSame( [ 'bounces' ], wp_list_pluck( $bouncing['factors'], 'code' ) );

		$free = $this->ok( 'mailhawk/assess-recipient-risk', [ 'email' => 'someone@gmail.com' ] );

		$this->assertSame( get_inbox_risk( 'someone@gmail.com' ), $free['risk'] );
		$this->assertSame( $free['risk'], array_sum( wp_list_pluck( $free['factors'], 'points' ) ), 'The factors are the whole score' );
		$this->assertNull( $free['recipient'], 'Nothing is known about them yet' );

		$user = $this->factory()->user->create_and_get( [ 'user_email' => 'member@example.net' ] );

		$member = $this->ok( 'mailhawk/assess-recipient-risk', [ 'email' => $user->user_email ] );

		$this->assertSame( - 1, $member['risk'] );
		$this->assertSame( [ 'site_user' ], wp_list_pluck( $member['factors'], 'code' ) );
		$this->assertFalse( $member['held'] );

		$admin = $this->ok( 'mailhawk/assess-recipient-risk', [ 'email' => get_option( 'admin_email' ) ] );

		$this->assertSame( [ 'admin' ], wp_list_pluck( $admin['factors'], 'code' ) );

		$this->refused( 'mailhawk/assess-recipient-risk', [ 'email' => 'not an address' ], 'mailhawk_invalid_email' );
	}

	public function test_an_address_is_trusted() {

		$trusted = $this->ok( 'mailhawk/trust-recipient', [ 'email' => ' Friend@Example.net ' ] );

		$this->assertTrue( $trusted['trusted'] );
		$this->assertSame( 'friend@example.net', $trusted['recipient']['email'] );
		$this->assertSame( 'trusted', $trusted['recipient']['status'] );
		$this->assertSame( 'manual', $trusted['recipient']['source'] );

		$again = $this->ok( 'mailhawk/trust-recipient', [ 'email' => 'friend@example.net' ] );

		$this->assertTrue( $again['trusted'], 'Trusting twice is fine' );
		$this->assertSame( 0, \MailHawk\assess_risk( 'friend@example.net' ) );
	}

	public function test_someone_who_asked_not_to_be_emailed_is_not_trusted() {

		$this->recipients->record_complaint( 'angry@example.net' );

		$this->refused( 'mailhawk/trust-recipient', [ 'email' => 'angry@example.net' ], 'mailhawk_reported_spam' );
		$this->assertSame( Recipients::BLOCKED, $this->recipients->get_by_email( 'angry@example.net' )->status );

		$this->refused( 'mailhawk/trust-recipient', [ 'email' => 'not an address' ], 'mailhawk_invalid_email' );
	}

	public function test_an_address_is_forgotten() {

		$this->seed_recipients();

		$removed = $this->ok( 'mailhawk/remove-recipient', [ 'email' => 'bounce@example.net' ] );

		$this->assertSame( [ 'removed' => true, 'email' => 'bounce@example.net' ], $removed );
		$this->assertFalse( $this->recipients->get_by_email( 'bounce@example.net' ) );
		$this->assertSame( 0, \MailHawk\assess_risk( 'bounce@example.net' ) );

		$none = $this->ok( 'mailhawk/remove-recipient', [ 'email' => 'bounce@example.net' ] );

		$this->assertFalse( $none['removed'], 'Nothing to forget is not an error' );
	}

	public function test_someone_who_asked_not_to_be_emailed_is_not_forgotten() {

		$this->seed_recipients();

		$this->refused( 'mailhawk/remove-recipient', [ 'email' => 'angry@example.net' ], 'mailhawk_reported_spam' );
		$this->assertNotFalse( $this->recipients->get_by_email( 'angry@example.net' ) );
		$this->assertGreaterThanOrEqual( 5, \MailHawk\assess_risk( 'angry@example.net' ) );
	}

	// Settings

	public function test_settings_never_include_the_credentials() {

		update_option( 'mailhawk_mta_credential_key', 'secret-key' );
		update_option( 'mailhawk_license_server_token', 'secret-token' );
		update_option( 'mailhawk_default_from_name', 'Acme' );
		update_option( 'mailhawk_default_from_email_address', 'hello@acme.example' );

		$settings = $this->ok( 'mailhawk/get-settings' );

		$this->assertSame( 'Acme', $settings['from_name'] );
		$this->assertSame( 'hello@acme.example', $settings['from_email'] );
		$this->assertTrue( $settings['email_logging_enabled'] );
		$this->assertSame( 14, $settings['log_retention_days'] );
		$this->assertSame( 30, $settings['quarantine_retention_days'] );
		$this->assertFalse( $settings['retry_failed_emails'] );
		$this->assertSame( 3, $settings['failed_email_retries'] );

		$this->assertEqualsCanonicalizing(
			[ 'from_name', 'from_email', 'email_logging_enabled', 'log_retention_days', 'quarantine_retention_days', 'retry_failed_emails', 'failed_email_retries' ],
			array_keys( $settings )
		);
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $settings ) );
	}

	public function test_settings_are_changed_and_the_change_is_reported() {

		$updated = $this->ok( 'mailhawk/update-settings', [
			'from_name'             => 'Acme Co',
			'from_email'            => 'Hello@Acme.example',
			'email_logging_enabled' => false,
			'log_retention_days'    => 60,
			'retry_failed_emails'   => true,
			'failed_email_retries'  => 5,
		] );

		$this->assertEqualsCanonicalizing(
			[ 'from_name', 'from_email', 'email_logging_enabled', 'log_retention_days', 'quarantine_retention_days', 'retry_failed_emails', 'failed_email_retries' ],
			$updated['changed']
		);
		$this->assertSame( 'Acme Co', get_option( 'mailhawk_default_from_name' ) );
		$this->assertSame( 'Hello@Acme.example', get_option( 'mailhawk_default_from_email_address' ) );
		$this->assertEquals( 1, get_option( 'mailhawk_disable_email_logging' ) );
		$this->assertEquals( 1, get_option( 'mailhawk_retry_failed_emails' ) );
		$this->assertSame( 60, $updated['settings']['quarantine_retention_days'], 'The held emails are kept at least as long as the log' );
		$this->assertSame( $updated['settings'], $this->ok( 'mailhawk/get-settings' ) );

		$again = $this->ok( 'mailhawk/update-settings', [ 'log_retention_days' => 60 ] );

		$this->assertSame( [], $again['changed'], 'Saying what is already so changes nothing' );

		$on = $this->ok( 'mailhawk/update-settings', [ 'email_logging_enabled' => true, 'retry_failed_emails' => false ] );

		$this->assertEqualsCanonicalizing( [ 'email_logging_enabled', 'retry_failed_emails' ], $on['changed'] );
		$this->assertEquals( 0, get_option( 'mailhawk_disable_email_logging' ) );
	}

	public function test_a_free_provider_is_refused_as_the_from_address_and_nothing_is_saved() {

		update_option( 'mailhawk_default_from_name', 'Before' );

		$this->refused( 'mailhawk/update-settings', [ 'from_name' => 'After', 'from_email' => 'me@gmail.com' ], 'mailhawk_free_provider' );

		$this->assertSame( 'Before', get_option( 'mailhawk_default_from_name' ), 'Everything is checked first' );
	}

	public function test_settings_that_make_no_sense_or_are_not_settings_are_refused() {

		update_option( 'mailhawk_mta_credential_key', 'secret-key' );

		$this->refused( 'mailhawk/update-settings', [], 'ability_invalid_input' );
		$this->refused( 'mailhawk/update-settings', [ 'log_retention_days' => 0 ], 'ability_invalid_input' );
		$this->refused( 'mailhawk/update-settings', [ 'failed_email_retries' => 50 ], 'ability_invalid_input' );
		$this->refused( 'mailhawk/update-settings', [ 'from_email' => 'not an address' ], 'ability_invalid_input' );
		$this->refused( 'mailhawk/update-settings', [ 'mailhawk_mta_credential_key' => 'hijacked' ], 'ability_invalid_input' );
		$this->refused( 'mailhawk/update-settings', [ 'delete_all_data' => true ], 'ability_invalid_input' );

		$this->assertSame( 'secret-key', get_option( 'mailhawk_mta_credential_key' ) );
		$this->assertFalse( get_option( 'mailhawk_delete_all_data' ) );
	}
}
