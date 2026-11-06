<?php

use MailHawk\Admin\Admin;
use MailHawk\Api\Licensing;
use MailHawk\Api\Postal\Postal;
use MailHawk\Keys;
use function MailHawk\dismiss_notice;
use function MailHawk\get_core_option;
use function MailHawk\get_dismissible_notices;
use function MailHawk\get_mailhawk_api_key;
use function MailHawk\get_mailhawk_webhook_error;
use function MailHawk\get_domains_needing_dns;
use function MailHawk\get_setup_domain_names;
use function MailHawk\get_sending_health;
use function MailHawk\get_setup_domains;
use function MailHawk\is_groundhogg_active;
use function MailHawk\is_review_eligible;
use function MailHawk\should_suggest_groundhogg;
use function MailHawk\set_setup_domain_names;
use function MailHawk\is_mailhawk_setup_incomplete;
use function MailHawk\is_notice_dismissed;
use function MailHawk\mailhawk_is_connected;
use function MailHawk\mailhawk_is_suspended;
use function MailHawk\set_mailhawk_api_credentials;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;
use function MailHawk\set_mailhawk_setup_complete;
use function MailHawk\set_mailhawk_webhook_error;
use function MailHawk\should_redirect_to_connect_after_activation;

class Test_Connection extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	protected $admin_id;

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( Admin::class ) ) {
			require_once dirname( MAILHAWK__FILE__ ) . '/admin/admin.php';
		}

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		set_mailhawk_is_connected( false );
	}

	protected function render( $method ) {

		ob_start();
		( new Admin() )->$method();

		return ob_get_clean();
	}

	public function test_setup_domains_leave_out_free_providers_and_duplicates() {

		$result = get_setup_domains( [
			'Boss@Example.com',
			'wordpress@example.com',
			'me@gmail.com',
			'me@gmail.com',
			'sales@other.org',
			'not-an-email',
			'',
		] );

		$this->assertSame( [ 'example.com', 'other.org' ], $result['domains'] );
		$this->assertSame( [ 'me@gmail.com' ], $result['free'] );
	}

	public function test_setup_domains_are_empty_when_every_address_is_on_a_free_provider() {

		$result = get_setup_domains( [ 'a@gmail.com', 'b@yahoo.com' ] );

		$this->assertSame( [], $result['domains'] );
		$this->assertCount( 2, $result['free'] );
	}

	public function test_setup_is_only_incomplete_once_it_was_started() {

		// A site that connected before this was tracked has nothing saved and is not nagged
		delete_option( 'mailhawk_setup_complete' );
		$this->assertFalse( is_mailhawk_setup_incomplete() );

		set_mailhawk_setup_complete( false );
		$this->assertTrue( is_mailhawk_setup_incomplete() );

		set_mailhawk_setup_complete( true );
		$this->assertFalse( is_mailhawk_setup_incomplete() );
	}

	public function test_webhook_error_is_remembered_and_cleared() {

		$this->assertSame( '', get_mailhawk_webhook_error() );

		set_mailhawk_webhook_error( 'Could not reach the MTA' );
		$this->assertSame( 'Could not reach the MTA', get_mailhawk_webhook_error() );

		set_mailhawk_webhook_error( '' );
		$this->assertSame( '', get_mailhawk_webhook_error() );
	}

	public function test_dismissed_notices_come_back_later() {

		$this->assertFalse( is_notice_dismissed( 'connect' ) );

		$this->assertTrue( dismiss_notice( 'connect' ) );
		$this->assertTrue( is_notice_dismissed( 'connect' ) );

		// Each notice is dismissed on its own
		$this->assertFalse( is_notice_dismissed( 'setup' ) );

		// Only for this user
		$this->assertFalse( is_notice_dismissed( 'connect', self::factory()->user->create() ) );

		update_user_meta( $this->admin_id, 'mailhawk_dismissed_connect', strtotime( '8 days ago' ) );
		$this->assertFalse( is_notice_dismissed( 'connect' ) );
	}

	public function test_only_known_notices_can_be_dismissed() {
		$this->assertFalse( dismiss_notice( 'something-else' ) );
		$this->assertEmpty( get_user_meta( $this->admin_id, 'mailhawk_dismissed_something-else', true ) );
	}

	public function test_activation_goes_to_connecting_only_when_it_helps() {

		// The folder the plugin is installed in depends on where it is checked out
		$plugin = plugin_basename( MAILHAWK__FILE__ );

		$this->assertTrue( should_redirect_to_connect_after_activation( $plugin, 'activate' ) );

		// Another plugin, or a bulk activation
		$this->assertFalse( should_redirect_to_connect_after_activation( 'other/other.php', 'activate' ) );
		$this->assertFalse( should_redirect_to_connect_after_activation( $plugin, 'activate-selected' ) );
		$this->assertFalse( should_redirect_to_connect_after_activation( $plugin, '' ) );

		// Nothing to connect
		set_mailhawk_is_connected( true );
		$this->assertFalse( should_redirect_to_connect_after_activation( $plugin, 'activate' ) );

		// Not somebody who can connect
		set_mailhawk_is_connected( false );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertFalse( should_redirect_to_connect_after_activation( $plugin, 'activate' ) );
	}

	public function test_not_connected_notice_says_what_it_means_and_offers_to_connect() {

		$html = $this->render( 'installed_not_connected_notice' );

		$this->assertStringContainsString( 'default mailer', $html );
		$this->assertStringContainsString( 'Connect MailHawk', $html );
		$this->assertStringContainsString( 'button-primary', $html );
		$this->assertStringContainsString( 'data-notice="connect"', $html );
	}

	public function test_not_connected_notice_stays_hidden_once_dismissed() {

		dismiss_notice( 'connect' );

		$this->assertSame( '', $this->render( 'installed_not_connected_notice' ) );
	}

	public function test_not_connected_notice_is_for_administrators_who_are_not_connected() {

		set_mailhawk_is_connected( true );
		$this->assertSame( '', $this->render( 'installed_not_connected_notice' ) );

		set_mailhawk_is_connected( false );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( '', $this->render( 'installed_not_connected_notice' ) );
	}

	public function test_network_notice_is_only_for_a_network_activation() {
		// A single site is never network active
		$this->assertSame( '', $this->render( 'network_not_connected_notice' ) );
	}

	public function test_setup_notice_nags_only_while_connected_and_unfinished() {

		set_mailhawk_is_connected( true );
		set_mailhawk_setup_complete( false );

		$html = $this->render( 'setup_incomplete_notice' );

		$this->assertStringContainsString( 'Finish setup', $html );
		$this->assertStringContainsString( 'action=setup', $html );
		$this->assertStringContainsString( 'data-notice="setup"', $html );

		set_mailhawk_setup_complete( true );
		$this->assertSame( '', $this->render( 'setup_incomplete_notice' ) );

		set_mailhawk_setup_complete( false );
		dismiss_notice( 'setup' );
		$this->assertSame( '', $this->render( 'setup_incomplete_notice' ) );

		// Not connected, that is the other notice
		delete_user_meta( $this->admin_id, 'mailhawk_dismissed_setup' );
		set_mailhawk_is_connected( false );
		$this->assertSame( '', $this->render( 'setup_incomplete_notice' ) );
	}

	public function test_webhook_notice_shows_why_with_a_way_to_try_again() {

		$this->assertSame( '', $this->render( 'webhook_error_notice' ) );

		set_mailhawk_webhook_error( 'Invalid API key.' );

		$html = $this->render( 'webhook_error_notice' );

		$this->assertStringContainsString( "couldn't register its webhook", $html );
		$this->assertStringContainsString( 'Invalid API key.', $html );
		$this->assertStringContainsString( 'action=register_webhook', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_state_is_long_random_and_used_up_by_the_first_return() {

		$state = Keys::instance()->state();

		$this->assertMatchesRegularExpression( '/^[0-9a-zA-Z]{32}$/', $state );

		// The same one is shown for as long as it is valid, the connect page may be open twice
		$this->assertSame( $state, Keys::instance()->state() );

		$this->assertFalse( Keys::instance()->consume_state( 'wrong' ) );
		$this->assertFalse( Keys::instance()->consume_state( '' ) );

		// A wrong guess doesn't use it up
		$this->assertTrue( Keys::instance()->consume_state( $state ) );

		// Replaying the same return is refused
		$this->assertFalse( Keys::instance()->consume_state( $state ) );
		$this->assertNotSame( $state, Keys::instance()->state() );
	}

	public function test_state_is_refused_when_expired_or_for_another_user() {

		$state = Keys::instance()->state();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( Keys::instance()->consume_state( $state ) );

		wp_set_current_user( $this->admin_id );
		update_user_meta( $this->admin_id, 'mailhawk_state', [
			'value'      => $state,
			'expiration' => time() - 1,
		] );
		$this->assertFalse( Keys::instance()->consume_state( $state ) );
	}

	public function test_keys_are_not_made_with_a_predictable_generator() {

		mt_srand( 1 );
		$first = Keys::instance()->generate_random_key( 40 );
		mt_srand( 1 );
		$second = Keys::instance()->generate_random_key( 40 );

		$this->assertSame( 40, strlen( $first ) );
		$this->assertNotSame( $first, $second );
	}

	protected function connect_for_disconnect() {
		set_mailhawk_is_connected( true );
		set_mailhawk_setup_complete( false );
		set_mailhawk_webhook_error( 'old error' );
		set_mailhawk_api_credentials( 'credential-key' );
		Licensing::instance()->set_token( 'license-token' );
		set_mailhawk_is_suspended( true );
	}

	public function test_disconnecting_removes_the_webhook_and_activation_and_forgets_everything() {

		$this->connect_for_disconnect();

		$urls = [];
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$urls ) {
			$urls[] = $url;

			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'status' => 'success', 'data' => [], 'success' => true ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );

		$leftovers = ( new Admin() )->disconnect();

		$this->assertSame( [], $leftovers );
		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'webhooks/delete', $urls[0], 'The webhook goes first, it needs the credentials' );
		$this->assertStringContainsString( 'mailhawk/deactivate', $urls[1] );

		$this->assertFalse( mailhawk_is_connected() );
		$this->assertFalse( mailhawk_is_suspended() );
		$this->assertFalse( is_mailhawk_setup_incomplete() );
		$this->assertSame( '', get_mailhawk_webhook_error() );
		$this->assertEmpty( get_mailhawk_api_key() );
		$this->assertEmpty( get_core_option( 'mailhawk_license_server_token' ) );
	}

	public function test_disconnecting_is_complete_locally_even_when_mailhawk_can_not_be_reached() {

		$this->connect_for_disconnect();

		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'blocked', 'HTTP is blocked in tests' );
		} );

		$leftovers = ( new Admin() )->disconnect();

		$this->assertSame( [ 'webhook', 'activation' ], $leftovers );
		$this->assertFalse( mailhawk_is_connected() );
		$this->assertEmpty( get_mailhawk_api_key() );
		$this->assertEmpty( get_core_option( 'mailhawk_license_server_token' ) );
	}

	public function test_disconnected_notice_is_honest_about_what_is_left() {

		$_GET['disconnected'] = 'yes';
		$this->assertStringContainsString( 'notice-success', $this->render( 'disconnected_notice' ) );

		$_GET['disconnected'] = 'partial';
		$html = $this->render( 'disconnected_notice' );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'still count towards your plan', $html );

		unset( $_GET['disconnected'] );
		$this->assertSame( '', $this->render( 'disconnected_notice' ) );
	}

	public function test_failed_connection_notice_says_why_once_and_only_after_a_failure() {

		$this->assertSame( '', $this->render( 'connection_failed_notice' ) );

		$_GET['connection'] = 'failed';
		set_transient( 'mailhawk_connection_error_' . $this->admin_id, 'Activation limit reached.' );

		$html = $this->render( 'connection_failed_notice' );

		$this->assertStringContainsString( 'We failed to connect', $html );
		$this->assertStringContainsString( 'Activation limit reached.', $html );

		// The reason is not repeated when the page is loaded again
		$this->assertStringNotContainsString( 'Activation limit reached.', $this->render( 'connection_failed_notice' ) );

		unset( $_GET['connection'] );
	}

	protected function mta_domain( $name, $spf = 'OK', $dkim = 'OK' ) {
		return (object) [
			'name'        => $name,
			'spf'         => (object) [ 'spf_status' => $spf ],
			'dkim'        => (object) [ 'dkim_status' => $dkim ],
			'return_path' => (object) [ 'return_path_status' => 'OK' ],
		];
	}

	protected function render_setup_complete( $domains ) {

		Postal::set_api_key( 'x' );

		add_filter( 'pre_http_request', function () use ( $domains ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'status' => 'success', 'data' => $domains ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		} );

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/views/post-setup.php';

		return ob_get_clean();
	}

	public function test_setup_domain_names_are_remembered_for_the_next_step() {

		$this->assertSame( [], get_setup_domain_names() );

		set_setup_domain_names( [ 'Example.com', 'example.com', 'other.org' ] );
		$this->assertSame( [ 'example.com', 'other.org' ], get_setup_domain_names() );

		set_setup_domain_names( [] );
		$this->assertSame( [], get_setup_domain_names() );
	}

	public function test_only_the_setup_domains_without_dns_need_attention() {

		$domains = [
			$this->mta_domain( 'example.com', 'OK', 'OK' ),
			$this->mta_domain( 'Other.org', 'OK', 'Missing' ),
			$this->mta_domain( 'third.net', 'Missing', 'Missing' ),
			$this->mta_domain( 'elsewhere.com', 'Missing', 'Missing' ),
		];

		$needed = get_domains_needing_dns( [ 'example.com', 'other.org', 'third.net' ], $domains );

		$this->assertSame( [ 'Other.org', 'third.net' ], wp_list_pluck( $needed, 'name' ) );

		// Not a list, for example when the MTA could not be reached
		$this->assertSame( [], get_domains_needing_dns( [ 'example.com' ], new WP_Error( 'x' ) ) );
	}

	public function test_setup_complete_makes_dns_the_next_step() {

		set_setup_domain_names( [ 'example.com', 'other.org' ] );

		$html = $this->render_setup_complete( [
			$this->mta_domain( 'example.com', 'Missing', 'Missing' ),
			$this->mta_domain( 'other.org', 'OK', 'OK' ),
			$this->mta_domain( 'elsewhere.com', 'Missing', 'Missing' ),
		] );

		$this->assertStringContainsString( 'Last step: authenticate your domains', $html );
		$this->assertStringContainsString( 'Set up DNS for example.com', $html );
		$this->assertStringContainsString( 'domain=example.com', $html );
		$this->assertStringContainsString( 'later, take me to the Overview', $html );

		// Verified, and not from this setup
		$this->assertStringNotContainsString( 'other.org', $html );
		$this->assertStringNotContainsString( 'elsewhere.com', $html );

		// The old extras are gone
		$this->assertStringNotContainsString( 'groundhogg-ad', $html );
		$this->assertStringNotContainsString( 'review', strtolower( $html ) );
	}

	public function test_setup_complete_with_several_domains_lists_each_one() {

		set_setup_domain_names( [ 'example.com', 'other.org' ] );

		$html = $this->render_setup_complete( [
			$this->mta_domain( 'example.com', 'Missing', 'Missing' ),
			$this->mta_domain( 'other.org', 'OK', 'Missing' ),
		] );

		$this->assertStringContainsString( 'Set up DNS records', $html );
		$this->assertStringContainsString( 'example.com', $html );
		$this->assertStringContainsString( 'other.org', $html );
		$this->assertSame( 2, substr_count( $html, 'Configure</a>' ) );
	}

	public function test_setup_complete_celebrates_when_everything_is_authenticated() {

		set_setup_domain_names( [ 'example.com' ] );

		$html = $this->render_setup_complete( [ $this->mta_domain( 'example.com' ) ] );

		$this->assertStringContainsString( "You're all set!", $html );
		$this->assertStringContainsString( 'Go to the Overview', $html );
		$this->assertStringNotContainsString( 'Last step', $html );
	}

	public function test_setup_complete_still_points_at_dns_when_the_domains_are_unknown() {

		// Nothing remembered, for example when the page is opened directly
		$html = $this->render_setup_complete( [] );

		$this->assertStringContainsString( 'Last step: authenticate your domains', $html );
		$this->assertStringContainsString( 'Set up DNS records', $html );
		$this->assertStringContainsString( 'view=domains', $html );
	}

	protected function render_setup() {

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/views/setup.php';

		return ob_get_clean();
	}

	public function test_register_domains_screen_has_labelled_fields_and_the_progress() {

		update_option( 'admin_email', 'boss@gmail.com' );

		$html = $this->render_setup();

		$this->assertStringContainsString( 'Choose where your email comes from', $html );
		$this->assertStringContainsString( 'Register your domains', $html );
		$this->assertStringContainsString( 'for="default_from_name"', $html );
		$this->assertStringContainsString( 'id="default_from_email_address"', $html );
		$this->assertStringContainsString( 'wordpress@example.org', $html );

		// A free provider can't be authenticated, so it isn't offered and the customer is told
		$this->assertStringNotContainsString( 'value="boss@gmail.com"', $html );
		$this->assertStringContainsString( 'Left out because they are on a free email provider', $html );
		$this->assertStringContainsString( 'boss@gmail.com', $html );

		$this->assertStringContainsString( 'Skip for now', $html );
	}

	public function test_register_domains_screen_keeps_what_was_entered_after_a_problem() {

		$_POST['_mailhawk_setup_nonce']       = 'x';
		$_POST['default_from_name']           = 'My Shop';
		$_POST['default_from_email_address']  = 'someone@gmail.com';
		$_POST['emails']                      = [ 'wordpress@example.org' ];

		$html = $this->render_setup();

		$this->assertStringContainsString( 'value="My Shop"', $html );
		$this->assertStringContainsString( 'value="someone@gmail.com"', $html );
		$this->assertMatchesRegularExpression( '/value="wordpress@example.org"\s+checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="admin@example.org"\s+checked/', $html );
	}

	public function test_progress_marks_what_is_done_and_what_is_next() {

		// The step being done, and how many steps are done and current
		$expected = [
			2 => [ 'done' => 1, 'current' => 1 ],
			3 => [ 'done' => 2, 'current' => 1 ],
			4 => [ 'done' => 3, 'current' => 0 ],
		];

		foreach ( $expected as $mailhawk_step => $counts ) {

			ob_start();
			include dirname( MAILHAWK__FILE__ ) . '/admin/views/setup-steps.php';
			$html = ob_get_clean();

			$this->assertSame( $counts['done'], substr_count( $html, '<li class="done">' ), "done at step $mailhawk_step" );
			$this->assertSame( $counts['current'], substr_count( $html, '<li class="current">' ), "current at step $mailhawk_step" );
		}
	}

	protected function render_menu() {

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/menu.php';

		return ob_get_clean();
	}

	/**
	 * A connected site with its sending numbers as the MTA would report them for the last 30 days
	 *
	 * @param int $sent
	 * @param int $bounces
	 * @param int $complaints
	 */
	protected function make_site_send( $sent = 500, $bounces = 5, $complaints = 0 ) {

		set_mailhawk_is_connected( true );
		set_mailhawk_setup_complete( true );
		set_mailhawk_is_suspended( false );
		set_mailhawk_webhook_error();
		delete_transient( 'mailhawk_sending_health' );
		Postal::set_api_key( 'x' );

		$graph   = array_fill( 0, 29, (object) [ 'outgoing' => 0, 'bounces' => 0, 'complaints' => 0 ] );
		$graph[] = (object) [ 'outgoing' => $sent, 'bounces' => $bounces, 'complaints' => $complaints ];

		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', function () use ( $graph ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'status' => 'success', 'data' => [ 'graph_data' => $graph ] ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		} );
	}

	public function test_a_site_that_sends_well_is_eligible_for_a_review_ask() {

		$this->make_site_send( 500, 5, 0 );

		$this->assertTrue( is_review_eligible() );
		$this->assertSame( 500, get_sending_health()['sent'] );
	}

	public function test_sites_with_sending_problems_are_not_asked_for_a_review() {

		// Not enough sent to have seen what MailHawk does
		$this->make_site_send( 40, 0, 0 );
		$this->assertFalse( is_review_eligible(), 'barely sending' );

		// 8% bounced
		$this->make_site_send( 500, 40, 0 );
		$this->assertFalse( is_review_eligible(), 'high bounce rate' );

		// Right at the limit is still fine
		$this->make_site_send( 500, 25, 0 );
		$this->assertTrue( is_review_eligible(), 'bounce rate at 5%' );

		// A single complaint in 500 is 0.2%
		$this->make_site_send( 500, 0, 1 );
		$this->assertFalse( is_review_eligible(), 'spam complaints' );

		// A connection that is not fully working
		$this->make_site_send();
		set_mailhawk_webhook_error( 'Could not register' );
		$this->assertFalse( is_review_eligible(), 'webhook error' );

		$this->make_site_send();
		set_mailhawk_is_suspended();
		$this->assertFalse( is_review_eligible(), 'suspended' );

		$this->make_site_send();
		set_mailhawk_setup_complete( false );
		$this->assertFalse( is_review_eligible(), 'setup unfinished' );

		$this->make_site_send();
		set_mailhawk_is_connected( false );
		$this->assertFalse( is_review_eligible(), 'not connected' );
	}

	public function test_sends_that_fail_on_the_site_count_against_a_review_ask() {

		$this->make_site_send();

		$log = \MailHawk\Plugin::instance()->log;

		for ( $i = 0; $i < 8; $i ++ ) {
			$log->add( [ 'status' => 'sent', 'recipients' => 'a@example.com' ] );
		}

		for ( $i = 0; $i < 2; $i ++ ) {
			$log->add( [ 'status' => 'failed', 'recipients' => 'a@example.com' ] );
		}

		// 20% of the log failed
		$this->assertFalse( is_review_eligible() );
		$this->assertSame( 2, get_sending_health( true )['failed'] );

		// Held back on purpose, not a failure
		$wpdb_table = $log->get_table_name();
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb_table} SET status = 'quarantine' WHERE status = 'failed'" );

		$this->assertSame( 0, get_sending_health( true )['failed'] );
		$this->assertSame( 8, get_sending_health()['logged'] );
		$this->assertTrue( is_review_eligible() );
	}

	public function test_the_limits_for_a_review_ask_can_be_changed() {

		$this->make_site_send( 500, 40, 0 );
		$this->assertFalse( is_review_eligible() );

		add_filter( 'mailhawk/review/max_bounce_rate', function () {
			return 10.0;
		} );

		$this->assertTrue( is_review_eligible() );

		add_filter( 'mailhawk/review/min_sent', function () {
			return 1000;
		} );

		$this->assertFalse( is_review_eligible() );
	}

	public function test_an_unreachable_mta_means_no_review_ask_and_is_retried_soon() {

		$this->make_site_send();

		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'blocked', 'HTTP is blocked in tests' );
		} );

		delete_transient( 'mailhawk_sending_health' );

		$this->assertNull( get_sending_health() );
		$this->assertFalse( is_review_eligible() );

		// The failure is remembered for a few minutes, not for hours
		$this->assertGreaterThan( time(), (int) get_option( '_transient_timeout_mailhawk_sending_health' ) );
		$this->assertLessThanOrEqual( time() + 5 * MINUTE_IN_SECONDS, (int) get_option( '_transient_timeout_mailhawk_sending_health' ) );
	}

	public function test_the_sending_numbers_are_only_fetched_once_in_a_while() {

		$this->make_site_send();

		$calls = 0;
		add_filter( 'pre_http_request', function ( $pre ) use ( &$calls ) {
			$calls ++;

			return $pre;
		}, 1 );

		get_sending_health();
		get_sending_health();
		is_review_eligible();

		$this->assertSame( 1, $calls );
	}

	public function test_the_menu_leaves_out_the_review_button_for_sites_not_sending_well() {

		$this->make_site_send( 500, 40, 0 );
		$html = $this->render_menu();

		$this->assertStringNotContainsString( 'mailhawk-review-button', $html );
		$this->assertStringNotContainsString( 'mailhawk-review-modal', $html );
		$this->assertStringContainsString( 'mailhawk-menu', $html, 'The menu itself stays' );
	}

	public function test_menu_has_a_review_button_with_a_modal_for_administrators() {

		$this->make_site_send();

		$html = $this->render_menu();

		$this->assertStringContainsString( 'id="mailhawk-review-button"', $html );
		$this->assertStringContainsString( 'id="mailhawk-review-modal"', $html );
		$this->assertStringContainsString( 'Enjoying MailHawk?', $html );
		$this->assertStringContainsString( "Don't ask again.", $html );
		$this->assertStringContainsString( 'Maybe later', $html );
		$this->assertStringContainsString( 'Review Now!', $html );
		$this->assertStringContainsString( 'https://wordpress.org/support/plugin/mailhawk/reviews/#new-post', $html );

		// Hidden until opened
		$this->assertMatchesRegularExpression( '/id="mailhawk-review-modal"[^>]*\shidden/', $html );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertStringNotContainsString( 'mailhawk-review-button', $this->render_menu() );
	}

	public function test_saying_not_to_ask_again_removes_the_review_button_for_good() {

		$this->make_site_send();

		$this->assertContains( 'review', get_dismissible_notices() );
		$this->assertStringContainsString( 'mailhawk-review-button', $this->render_menu() );

		$this->assertTrue( dismiss_notice( 'review' ) );

		$html = $this->render_menu();
		$this->assertStringNotContainsString( 'mailhawk-review-button', $html );
		$this->assertStringNotContainsString( 'mailhawk-review-modal', $html );
		$this->assertStringContainsString( 'mailhawk-menu', $html, 'The menu itself stays' );

		// Unlike the connect notices, it doesn't come back after a while
		update_user_meta( $this->admin_id, 'mailhawk_dismissed_review', strtotime( '2 years ago' ) );
		$this->assertTrue( is_notice_dismissed( 'review' ) );

		// Only for the person who said it
		$this->assertFalse( is_notice_dismissed( 'review', self::factory()->user->create( [ 'role' => 'administrator' ] ) ) );
	}

	protected function render_recipients() {

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/views/recipients.php';

		return ob_get_clean();
	}

	protected function skip_when_groundhogg_is_running() {
		if ( is_groundhogg_active() ) {
			$this->markTestSkipped( 'Groundhogg is running in this environment.' );
		}
	}

	public function test_recipients_with_trouble_get_a_groundhogg_suggestion() {

		$this->skip_when_groundhogg_is_running();

		$recipients = \MailHawk\Plugin::instance()->recipients;

		$this->assertStringNotContainsString( 'mailhawk-groundhogg-card', $this->render_recipients(), 'nothing on the list' );

		$recipients->trust( 'friend@example.com' );
		$this->assertStringNotContainsString( 'mailhawk-groundhogg-card', $this->render_recipients(), 'only trusted addresses' );

		$recipients->record_bounce( 'gone@example.com' );
		$html = $this->render_recipients();

		$this->assertStringContainsString( 'id="mailhawk-groundhogg-card"', $html );

		// Learn more opens WordPress' plugin details, and the name goes to the WordPress.org listing
		$this->assertStringContainsString( 'id="groundhogg-learn-more"', $html );
		$this->assertStringContainsString( 'open-plugin-details-modal', $html );
		$this->assertStringContainsString( 'plugin-install.php?tab=plugin-information&#038;plugin=groundhogg', $html );
		$this->assertStringContainsString( 'href="https://wordpress.org/plugins/groundhogg/"', $html );

		// Nothing installs from this page without the person seeing what it is first
		$this->assertStringNotContainsString( 'groundhogg-connect', $html );
		$this->assertStringContainsString( "Don't show this again", $html );
	}

	public function test_the_groundhogg_suggestion_is_for_people_who_can_install_plugins() {

		$this->skip_when_groundhogg_is_running();

		\MailHawk\Plugin::instance()->recipients->record_bounce( 'gone@example.com' );
		$this->assertTrue( should_suggest_groundhogg() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( should_suggest_groundhogg() );
		$this->assertStringNotContainsString( 'mailhawk-groundhogg-card', $this->render_recipients() );
	}

	public function test_saying_not_to_show_the_groundhogg_suggestion_again_is_final() {

		$this->skip_when_groundhogg_is_running();

		\MailHawk\Plugin::instance()->recipients->record_bounce( 'gone@example.com' );

		$this->assertContains( 'groundhogg', get_dismissible_notices() );
		$this->assertStringContainsString( 'mailhawk-groundhogg-card', $this->render_recipients() );

		$this->assertTrue( dismiss_notice( 'groundhogg' ) );
		$this->assertStringNotContainsString( 'mailhawk-groundhogg-card', $this->render_recipients() );

		// Unlike the connect notices, it doesn't come back after a while
		update_user_meta( $this->admin_id, 'mailhawk_dismissed_groundhogg', strtotime( '2 years ago' ) );
		$this->assertTrue( is_notice_dismissed( 'groundhogg' ) );

		// And it is the same person who is asked about a review, one answer doesn't answer the other
		$this->assertFalse( is_notice_dismissed( 'review' ) );
	}

	protected function render_promo() {

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/views/groundhogg-promo.php';

		return ob_get_clean();
	}

	protected function render_overview() {

		set_mailhawk_is_connected( true );
		Postal::set_api_key( 'x' );

		$day = (object) [ 'outgoing' => 10, 'bounces' => 1, 'complaints' => 0 ];

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $day ) {

			$data = false !== strpos( $url, 'reporting/limits' )
				? [ (object) [ 'type' => 'monthly_send_limit', 'usage' => 20, 'limit' => 1000 ] ]
				: (object) [ 'last_date' => gmdate( 'Y-m-d' ), 'graph_data' => array_fill( 0, 14, $day ) ];

			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'status' => 'success', 'data' => $data ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );

		ob_start();
		include dirname( MAILHAWK__FILE__ ) . '/admin/views/overview.php';

		return ob_get_clean();
	}

	public function test_the_overview_has_a_marketing_card_for_groundhogg() {

		$this->skip_when_groundhogg_is_running();

		$html = $this->render_promo();

		$this->assertStringContainsString( 'id="mailhawk-groundhogg-promo"', $html );
		$this->assertStringContainsString( 'open-plugin-details-modal', $html );
		$this->assertStringContainsString( 'plugin-install.php?tab=plugin-information&#038;plugin=groundhogg', $html );
		$this->assertStringContainsString( 'href="https://wordpress.org/plugins/groundhogg/"', $html );
		$this->assertStringContainsString( 'Learn more', $html );
		$this->assertStringContainsString( "Don't show this again", $html );
		$this->assertStringNotContainsString( 'Install', $html, 'It asks for a look, not for an install' );

		// What it does, as a list with a check in front of each
		$this->assertGreaterThanOrEqual( 5, substr_count( $html, 'dashicons-yes-alt' ) );
		$this->assertStringContainsString( 'Automate follow-ups', $html );
		$this->assertStringContainsString( 'newsletters', $html );
	}

	public function test_the_overview_shows_the_marketing_card_first() {

		$this->skip_when_groundhogg_is_running();

		// The quarantine report only shows when something is held
		\MailHawk\Plugin::instance()->log->add( [
			'recipients'   => [ 'held@example.net' ],
			'from_address' => 'info@example.com',
			'subject'      => 'Held',
			'content'      => 'Some email content',
			'headers'      => [ [ 'Content-Type', 'text/plain' ] ],
			'status'       => 'quarantine',
			'date_sent'    => current_time( 'mysql' ),
		] );

		$html = $this->render_overview();

		$this->assertStringContainsString( 'id="mailhawk-groundhogg-promo"', $html );
		$this->assertStringContainsString( 'mailhawk-quarantine-report', $html );

		// Above everything else on the screen, or nobody gets to it
		$promo = strpos( $html, 'id="mailhawk-groundhogg-promo"' );
		$this->assertLessThan( strpos( $html, 'mailhawk-quarantine-report' ), $promo );
		$this->assertLessThan( strpos( $html, 'id="graphdata"' ), $promo );
	}

	public function test_the_marketing_card_is_for_people_who_can_install_plugins_and_stays_gone_once_dismissed() {

		$this->skip_when_groundhogg_is_running();

		$this->assertStringContainsString( 'mailhawk-groundhogg-promo', $this->render_promo() );

		// One answer for the overview and the recipients
		dismiss_notice( 'groundhogg' );
		$this->assertSame( '', $this->render_promo() );
		delete_user_meta( $this->admin_id, 'mailhawk_dismissed_groundhogg' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( '', $this->render_promo() );
	}

	public function test_the_groundhogg_install_script_is_gone_from_the_class() {
		$this->assertFalse( method_exists( \MailHawk\Groundhogg::class, 'output_js' ) );
	}

	public function test_the_footer_no_longer_asks_for_a_review() {
		$this->assertFalse( method_exists( Admin::class, 'admin_footer_text' ) );
	}

	public function test_disconnecting_forgets_the_setup_domains() {

		set_setup_domain_names( [ 'example.com' ] );
		$this->connect_for_disconnect();

		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'blocked', 'HTTP is blocked in tests' );
		} );

		( new Admin() )->disconnect();

		$this->assertSame( [], get_setup_domain_names() );
	}

	public function tear_down() {
		unset( $_GET['disconnected'], $_GET['connection'], $_POST['_mailhawk_setup_nonce'], $_POST['default_from_name'], $_POST['default_from_email_address'], $_POST['emails'] );
		Postal::set_api_key( '' );

		parent::tear_down();
	}

	public function test_setup_failures_say_what_to_change() {

		$property = new ReflectionProperty( Admin::class, 'setup_error' );
		$property->setAccessible( true );

		$messages = [
			'free_from'  => 'free email provider',
			'no_domains' => 'at least one email address',
			'limit'      => 'domain limit',
			'invalid'    => 'None of the domains',
		];

		foreach ( $messages as $error => $expected ) {

			$admin = new Admin();
			$property->setValue( $admin, $error );

			ob_start();
			$admin->setup_failed_notice();
			$html = ob_get_clean();

			$this->assertStringContainsString( $expected, $html, $error );
			$this->assertStringContainsString( 'notice-error', $html, $error );
		}
	}
}
