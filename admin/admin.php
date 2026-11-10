<?php

namespace MailHawk\Admin;

use MailHawk\Api\Licensing;
use MailHawk\Api\Postal\Domains;
use MailHawk\Api\Postal\Postal;
use MailHawk\Api\Postal\Reporting;
use MailHawk\Api\Postal\Webhooks;
use MailHawk\Classes\Email_Log_Item;
use MailHawk\Keys;
use MailHawk\Plugin;
use function Groundhogg\get_contactdata;
use function Groundhogg\is_a_contact;
use function MailHawk\dismiss_notice;
use function MailHawk\get_array_var;
use function MailHawk\get_test_email;
use function MailHawk\set_next_alt_body;
use function MailHawk\get_dismissible_notices;
use function MailHawk\get_hostname;
use function MailHawk\get_mailhawk_api_key;
use function MailHawk\get_mailhawk_suspension;
use function MailHawk\get_mailhawk_webhook_error;
use function MailHawk\get_post_var;
use function MailHawk\get_request_var;
use function MailHawk\get_rest_api_webhook_listener_uri;
use function MailHawk\get_setup_domains;
use function MailHawk\get_suggested_spf_record;
use function MailHawk\get_url_var;
use function MailHawk\get_webhook_batch_interval;
use function MailHawk\get_webhook_events;
use function MailHawk\is_free_email_provider;
use function MailHawk\is_groundhogg_active;
use function MailHawk\is_mailhawk_network_active;
use function MailHawk\is_mailhawk_setup_incomplete;
use function MailHawk\is_notice_dismissed;
use function MailHawk\mailhawk_account_url;
use function MailHawk\mailhawk_admin_page;
use function MailHawk\mailhawk_is_connected;
use function MailHawk\mailhawk_is_suspended;
use function MailHawk\mailhawk_spf_is_set;
use function MailHawk\mailhawk_suspended_since;
use function MailHawk\set_mailhawk_api_credentials;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;
use function MailHawk\set_mailhawk_setup_complete;
use function MailHawk\set_mailhawk_webhook_error;
use function MailHawk\set_setup_domain_names;
use function MailHawk\should_suggest_groundhogg;

/**
 * Class Admin
 *
 * @package MailHawk\Admin
 */
class Admin {

	public function __construct() {

		// Do not show on subsites...
		if ( is_mailhawk_network_active() && ! is_main_site() ) {
			return;
		}

		// Load any scripts
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );

		// Show as last option
		add_action( 'admin_menu', [ $this, 'register' ], 99 );

		// Add notice to go to admin page.
		add_action( 'admin_notices', [ $this, 'installed_not_connected_notice' ] );
		add_action( 'admin_notices', [ $this, 'setup_incomplete_notice' ] );
		add_action( 'admin_notices', [ $this, 'account_suspended_notice' ] );

		// A network that has MailHawk active everywhere is managed from the network admin
		add_action( 'network_admin_notices', [ $this, 'network_not_connected_notice' ] );

		// Process any actions relevant for the admin page
		add_action( 'load-tools_page_mailhawk', [ $this, 'do_actions' ] );

		add_action( 'wp_ajax_mailhawk_preview_email', [ $this, 'ajax_load_preview_content' ] );
		add_action( 'wp_ajax_mailhawk_dismiss_notice', [ $this, 'ajax_dismiss_notice' ] );
	}

	/**
	 * Maybe do the connection from MailHawK!
	 */
	protected function maybe_connect() {

		// Returned from MailHawk oauth
		if ( ! get_url_var( 'state' ) || ! get_url_var( 'code' ) ) {
			return;
		}

		$state = sanitize_text_field( get_url_var( 'state' ) );
		$code  = sanitize_text_field( get_url_var( 'code' ) );

		// Not started by this user, expired, or already used. The state can only be used once.
		if ( ! Keys::instance()->consume_state( $state ) ) {
			$this->connection_failed( __( 'The connection request expired or was already used.', 'mailhawk' ) );
		}

		$response = Licensing::instance()->get_token( $code );

		if ( is_wp_error( $response ) ) {
			$this->connection_failed();
		}

		$credentials = Licensing::instance()->get_credentials();

		if ( is_wp_error( $credentials ) ) {
			$this->connection_failed( $credentials->get_error_code() === 'activation_limit_reached' ? $credentials->get_error_message() : '' );
		}

		// Anything that is not a credential key, for example a page from a firewall in front of MailHawk, is a failed connection.
		if ( ! is_object( $credentials ) || empty( $credentials->credential_key ) ) {
			$this->connection_failed();
		}

		$mta_credential_key = sanitize_text_field( $credentials->credential_key );

		set_mailhawk_api_credentials( $mta_credential_key );

		// Make sure the MTA accepts the key before this site is counted. A suspended server still knows the key.
		Postal::set_api_key( $mta_credential_key );
		$check = Domains::query_all();

		if ( is_wp_error( $check ) && 'ServerSuspended' !== $check->get_error_code() ) {

			set_mailhawk_api_credentials( '' );
			$this->connection_failed();
		}

		// Saved and confirmed: now the site counts against the plan.
		$activated = Licensing::instance()->activate();

		if ( is_wp_error( $activated ) ) {

			set_mailhawk_api_credentials( '' );
			$this->connection_failed( $activated->get_error_code() === 'activation_limit_reached' ? $activated->get_error_message() : '' );
		}

		// All checks passed, connect MailHawk!
		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );

		// Connected, but the sending address and domains are still to be chosen
		set_mailhawk_setup_complete( false );

		// Create the webhook listener, the setup page says so when that fails.
		$this->maybe_register_webhook();

		die( wp_safe_redirect( mailhawk_admin_page( [ 'action' => 'setup' ] ) ) );
	}

	/**
	 * A suspended account that MailHawk answers for again is no longer suspended. Asked before anything is shown, so the
	 * page can redirect, and no more than once a minute because the answer rarely changes that quickly. Reactivating
	 * goes through connecting, which clears the suspension itself.
	 */
	protected function maybe_clear_suspension() {

		if ( ! mailhawk_is_connected() || ! mailhawk_is_suspended() || get_transient( 'mailhawk_suspension_check' ) ) {
			return;
		}

		if ( is_wp_error( Reporting::limits() ) ) {
			set_transient( 'mailhawk_suspension_check', 1, MINUTE_IN_SECONDS );

			return;
		}

		set_mailhawk_is_suspended( false );

		die( wp_safe_redirect( mailhawk_admin_page() ) );
	}

	/**
	 * Go back to the page without the code and state of the failed attempt in the URL, the notice says it failed.
	 *
	 * @param string $message why it failed, when the customer can do something about it
	 */
	protected function connection_failed( $message = '' ) {

		set_transient( 'mailhawk_connection_error_' . get_current_user_id(), sanitize_text_field( $message ), 5 * MINUTE_IN_SECONDS );

		die( wp_safe_redirect( mailhawk_admin_page( [ 'connection' => 'failed' ] ) ) );
	}

	/**
	 * Disconnect mailhawk
	 */
	protected function maybe_disconnect() {
		if ( ! wp_verify_nonce( get_url_var( '_wpnonce' ), 'disconnect_mailhawk' ) ) {
			return;
		}

		$leftovers = $this->disconnect();

		die( wp_safe_redirect( mailhawk_admin_page( [ 'disconnected' => empty( $leftovers ) ? 'yes' : 'partial' ] ) ) );
	}

	/**
	 * Take everything of the connection off this site and off MailHawk. The site is always disconnected locally,
	 * so the customer is never stuck connected.
	 *
	 * @return string[] what could not be removed remotely: 'webhook' and/or 'activation'
	 */
	public function disconnect() {

		$leftovers = [];

		// The webhook needs the credentials, so it goes first. Nothing should call this site back once it is gone.
		if ( mailhawk_is_connected() && get_mailhawk_api_key() && is_wp_error( Webhooks::delete( get_rest_api_webhook_listener_uri() ) ) ) {
			$leftovers[] = 'webhook';
		}

		// remotely deactivate this site, so it no longer counts against the plan
		if ( is_wp_error( Licensing::instance()->deactivate() ) ) {
			$leftovers[] = 'activation';
		}

		// The credentials and token are of no use after this, and should not stay in the database
		set_mailhawk_api_credentials( '' );
		Postal::set_api_key( '' );
		Licensing::instance()->set_token( '' );

		// Set local is connected to false
		set_mailhawk_is_connected( false );
		set_mailhawk_is_suspended( false );

		// Nothing of the previous connection applies to the next one
		delete_option( 'mailhawk_setup_complete' );
		set_setup_domain_names( [] );
		set_mailhawk_webhook_error( '' );

		return $leftovers;
	}

	/**
	 * Tell the customer how the disconnect went
	 */
	public function disconnected_notice() {

		$result = get_url_var( 'disconnected' );

		if ( 'yes' === $result ) {
			?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e( 'MailHawk is disconnected. Email is sent with the default WordPress mailer again.', 'mailhawk' ); ?></p>
            </div>
			<?php
		} else if ( 'partial' === $result ) {
			?>
            <div class="notice notice-warning is-dismissible">
                <p><?php _e( 'MailHawk is disconnected and email is sent with the default WordPress mailer again, but MailHawk could not be reached to remove this site completely. It may still count towards your plan until you remove it from your MailHawk account.', 'mailhawk' ); ?></p>
            </div>
			<?php
		}
	}

	/**
	 * Setup any initial domains from the setup page.
	 */
	protected function maybe_setup_initial_domains() {

		if ( ! wp_verify_nonce( get_post_var( '_mailhawk_setup_nonce' ), 'mailhawk_register_domains' ) ) {
			return;
		}

		// From Name
		$from_name = sanitize_text_field( get_post_var( 'default_from_name' ) );
		if ( $from_name ) {
			update_option( 'mailhawk_default_from_name', $from_name );
		}

		// Email Address
		$from_email = sanitize_text_field( get_post_var( 'default_from_email_address' ) );

		// A free inbox provider can't be authenticated, so mail sent from it would not be accepted by the recipients
		if ( is_free_email_provider( $from_email ) ) {
			$this->setup_error = 'free_from';
			add_action( 'mailhawk_notices', [ $this, 'setup_failed_notice' ] );

			return;
		}

		if ( is_email( $from_email ) ) {
			update_option( 'mailhawk_default_from_email_address', $from_email );
		}

		$emails = array_map( 'sanitize_email', (array) get_post_var( 'emails', [] ) );

		// Add the default from email to the list of emails to check...
		$emails[] = $from_email;

		$domains = get_setup_domains( $emails )['domains'];

		if ( empty( $domains ) ) {
			$this->setup_error = 'no_domains';
			add_action( 'mailhawk_notices', [ $this, 'setup_failed_notice' ] );

			return;
		}

		$registered = [];
		$failed     = [];

		foreach ( $domains as $domain ) {

			$response = Domains::create( $domain );

			// A domain that is registered already is what we wanted
			if ( ! is_wp_error( $response ) || 'DomainNameExists' === $response->get_error_code() ) {
				$registered[] = $domain;
				continue;
			}

			$failed[ $domain ] = $response->get_error_code();
		}

		// Nothing was registered, there is nothing to configure DNS for yet
		if ( ! $registered ) {
			$this->setup_error = in_array( 'ReachedDomainLimit', $failed, true ) ? 'limit' : 'invalid';
			add_action( 'mailhawk_notices', [ $this, 'setup_failed_notice' ] );

			return;
		}

		set_mailhawk_setup_complete( true );

		// The next step is about these domains
		set_setup_domain_names( $registered );

		// Send to DNS, saying how many domains could not be added
		die( wp_safe_redirect( mailhawk_admin_page( array_filter( [
			'action'  => 'post_setup',
			'skipped' => count( $failed ),
		] ) ) ) );

	}

	/**
	 * Why the setup could not continue
	 *
	 * @var string
	 */
	protected $setup_error = '';

	/**
	 * Say why the setup stayed on the same page
	 */
	public function setup_failed_notice() {

		switch ( $this->setup_error ) {
			case 'free_from':
				$message = __( 'The From email address can not be on a free email provider like gmail.com. Emails from it would be rejected by the people you send to. Use an address on a domain you own, for example <code>wordpress@yourdomain.com</code>.', 'mailhawk' );
				break;
			case 'no_domains':
				$message = __( 'Select at least one email address on a domain you own. Addresses on free email providers like gmail.com can not be used.', 'mailhawk' );
				break;
			case 'limit':
				$message = __( "You've reached your domain limit, so none of these domains could be registered. Upgrade your plan to register additional domains.", 'mailhawk' );
				break;
			default:
				$message = __( 'None of the domains could be registered. Check the email addresses and try again.', 'mailhawk' );
				break;
		}

		?>
        <div class="notice notice-error">
            <p><?php echo wp_kses( $message, [ 'code' => [] ] ); ?></p>
        </div>
		<?php
	}

	/**
	 * Say that some of the domains from the setup were not registered
	 */
	public function setup_skipped_notice() {

		$skipped = absint( get_url_var( 'skipped' ) );

		if ( ! $skipped ) {
			return;
		}

		?>
        <div class="notice notice-warning">
            <p><?php echo esc_html( sprintf( _n( '%s domain could not be registered, most likely because you reached your domain limit. You can add it later from the Domains page.', '%s domains could not be registered, most likely because you reached your domain limit. You can add them later from the Domains page.', $skipped, 'mailhawk' ), number_format_i18n( $skipped ) ) ); ?></p>
        </div>
		<?php
	}

	/**
	 * Delete a domain from postal
	 */
	protected function maybe_delete_domain() {

		if ( ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'mailhawk_delete_domain' ) ) {
			return;
		}

		$domain = sanitize_text_field( get_url_var( 'domain' ) );

		if ( ! $domain ) {
			return;
		}

		$response = Domains::delete( $domain );

		if ( is_wp_error( $response ) ) {
			wp_die( $response );
		}

		die( wp_safe_redirect( mailhawk_admin_page( [ 'deleted_domain' => $domain, 'view' => 'domains' ] ) ) );
	}

	/**
	 * Check a domain in postal
	 */
	protected function maybe_check_domain() {

		if ( ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'mailhawk_check_domain' ) ) {
			return;
		}

		$domain = sanitize_text_field( get_url_var( 'domain' ) );

		if ( ! $domain ) {
			return;
		}

		$response = Domains::check( $domain );

		if ( is_wp_error( $response ) ) {
			wp_die( $response );
		}

		die( wp_safe_redirect( mailhawk_admin_page( [
			'domain' => $domain,
			'view'   => 'domains',
			'action' => 'is_verified'
		] ) ) );
	}

	/**
	 * Register a new domain
	 */
	protected function maybe_register_new_domain() {

		if ( ! wp_verify_nonce( get_post_var( '_mailhawk_nonce' ), 'register_new_domain' ) ) {
			return;
		}

		$domain = sanitize_text_field( get_post_var( 'domain' ) );
		$domain = str_replace( 'www.', '', wp_parse_url( $domain, PHP_URL_HOST ) );

		// Invalid domain
		if ( ! $domain ) {
			return;
		}

		$potential_email = 'wp@' . $domain;

		// Can't build an email address form this domain
		if ( ! is_email( $potential_email ) ) {
			return;
		}

		$response = Domains::create( $domain );

		if ( is_wp_error( $response ) ) {

			switch ( $response->get_error_code() ) {
				case 'ReachedDomainLimit':
					add_action( 'mailhawk_notices', [ $this, 'domain_limit_reached_notice' ] );

					return;
				default:
				case 'InvalidDomainName':
				case 'DomainNameMissing':
					add_action( 'mailhawk_notices', [ $this, 'invalid_domain_provided_notice' ] );

					return;
				case 'DomainNameExists':
					add_action( 'mailhawk_notices', [ $this, 'domain_name_already_registered' ] );

					return;
			}
		}

		die( wp_safe_redirect( mailhawk_admin_page( [
			'view'   => 'domains',
			'domain' => $domain,
			'added'  => 'yes',
		] ) ) );
	}

	/**
	 * Register the webhook response.
	 */
	protected function maybe_register_webhook() {

		// Delete any old webhooks with the same URI
		Webhooks::delete( get_rest_api_webhook_listener_uri() );

		$response = Webhooks::create( get_hostname(), get_rest_api_webhook_listener_uri(), false, get_webhook_events(), get_webhook_batch_interval() );

		// Remembered so the customer is told, bounces and spam complaints never arrive without it
		if ( is_wp_error( $response ) ) {
			set_mailhawk_webhook_error( $response->get_error_message() ?: $response->get_error_code() );

			return false;
		}

		set_mailhawk_webhook_error( '' );

		return true;
	}

	/**
	 * Register the webhook again, after it failed when connecting
	 */
	protected function maybe_retry_webhook() {

		if ( get_url_var( 'action' ) !== 'register_webhook' || ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( get_url_var( '_wpnonce' ), 'register_webhook' ) ) {
			return;
		}

		$registered = mailhawk_is_connected() && $this->maybe_register_webhook();

		die( wp_safe_redirect( mailhawk_admin_page( [ 'webhook' => $registered ? 'registered' : 'failed' ] ) ) );
	}

	/**
	 * Tell the customer that the webhook is not registered, with a way to try again
	 */
	public function webhook_error_notice() {

		$error = get_mailhawk_webhook_error();

		if ( get_url_var( 'webhook' ) === 'registered' && ! $error ) {
			?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e( 'The webhook is registered. MailHawk will now learn from bounces and spam complaints.', 'mailhawk' ); ?></p>
            </div>
			<?php
			return;
		}

		if ( ! $error ) {
			return;
		}

		?>
        <div class="notice notice-warning">
            <p><?php _e( "<b>MailHawk couldn't register its webhook.</b> Until it is registered, bounces and spam complaints can't reach your site, so MailHawk can't learn which recipients to hold email for.", 'mailhawk' ); ?></p>
            <p><code><?php echo esc_html( $error ); ?></code></p>
            <p>
                <a class="button button-secondary"
                   href="<?php echo esc_url( wp_nonce_url( mailhawk_admin_page( [ 'action' => 'register_webhook' ] ), 'register_webhook' ) ); ?>"><?php _e( 'Try again', 'mailhawk' ); ?></a>
            </p>
        </div>
		<?php
	}

	/**
	 * @var array
	 */
	protected $test_data = [];

	/**
	 * Send a test email
	 */
	protected function maybe_send_test_email() {

		if ( ! wp_verify_nonce( get_post_var( '_mailhawk_nonce' ), 'send_test_email' ) ) {
			return;
		}

		$to   = sanitize_email( get_post_var( 'to_address' ) );
		$from = sanitize_email( get_post_var( 'from_address' ) );

		$this->test_data['to']   = $to;
		$this->test_data['from'] = $from;

		if ( ! $to ) {
			return;
		}

		add_action( 'wp_mail_failed', [ $this, 'maybe_email_failed' ] );

		// Always the same email, what is being tested is whether it arrives and where
		$email   = get_test_email( $to, $from );
		$headers = [ 'Content-Type: text/html' ];

		// From the address that was picked, with the default from name. Without one it is the default from address.
		if ( $from ) {
			$name      = (string) get_option( 'mailhawk_default_from_name' );
			$headers[] = $name ? sprintf( 'From: %s <%s>', $name, $from ) : sprintf( 'From: %s', $from );
		}

		$alt_body = set_next_alt_body( $email['text'] );

		$success = wp_mail( $to, $email['subject'], $email['html'], $headers );

		remove_action( 'phpmailer_init', $alt_body, 99 );

		if ( $success ) {
			add_action( 'mailhawk_notices', [ $this, 'show_test_successful_notice' ] );
		}
	}

	/**
	 * @var \WP_Error
	 */
	protected $send_error;

	/**
	 * If error happens log notice...
	 *
	 * @param $error \WP_Error
	 */
	public function maybe_email_failed( $error ) {
		if ( is_wp_error( $error ) ) {
			$this->send_error = $error;
			add_action( 'mailhawk_notices', [ $this, 'show_test_not_successful_notice' ] );
		}
	}

	/**
	 * If error happens log notice...
	 *
	 * @param $error \WP_Error
	 */
	public function maybe_resend_failed( $error ) {
		if ( is_wp_error( $error ) ) {
			$this->send_error = $error;
			add_action( 'mailhawk_notices', [ $this, 'show_retry_email_not_successful_notice' ] );
		}
	}

	/**
	 * Return the preview content
	 */
	public function ajax_load_preview_content() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		ob_start();

		include __DIR__ . '/views/log-preview.php';

		$content = ob_get_clean();

		wp_send_json_success( [ 'content' => $content ] );
	}

	/**
	 * Maybe save the mailhawk settings.
	 */
	protected function maybe_save_settings() {
		if ( ! wp_verify_nonce( get_post_var( '_mailhawk_nonce' ), 'save_settings' ) ) {
			return;
		}

		// From Name
		$from_name = sanitize_text_field( get_post_var( 'default_from_name' ) );
		if ( $from_name ) {
			update_option( 'mailhawk_default_from_name', $from_name );
		}

		// Email Address
		$from_email = sanitize_text_field( get_post_var( 'default_from_email_address' ) );
		if ( is_email( $from_email ) ) {
			update_option( 'mailhawk_default_from_email_address', $from_email );
		}

		// Retention Days
		$log_retention = absint( get_post_var( 'log_retention_in_days' ) );
		if ( $log_retention > 0 ) {
			update_option( 'mailhawk_log_retention_in_days', $log_retention );
		}

		// Disable email logging
		$enable_retries = absint( get_post_var( 'disable_email_logging' ) );
		update_option( 'mailhawk_disable_email_logging', $enable_retries );

		// Enable failed email retries
		$enable_retries = absint( get_post_var( 'retry_failed_emails' ) );
		update_option( 'mailhawk_retry_failed_emails', $enable_retries );


		// Number of retries
		$number_of_retries = absint( get_post_var( 'number_of_retries' ) );
		if ( $number_of_retries > 0 ) {
			update_option( 'mailhawk_failed_email_retries', $number_of_retries );
		}

		update_option( 'mailhawk_mta_credential_key', sanitize_text_field( get_post_var( 'mailhawk_mta_credential_key' ) ) );
		update_option( 'mailhawk_license_server_token', sanitize_text_field( get_post_var( 'mailhawk_license_server_token' ) ) );

		// Enable failed email retries
		$delete_all_data = absint( get_post_var( 'delete_all_data' ) );
		update_option( 'mailhawk_delete_all_data', $delete_all_data );

		if ( is_multisite() && is_main_site() ) {
			update_option( 'mailhawk_ms_sender_domain', sanitize_text_field( get_post_var( 'sender_domain' ) ) );
		}

		add_action( 'mailhawk_notices', [ $this, 'show_settings_saved_notice' ] );
	}

	/**
	 * Maybe resend an email if viewing the log and resending the email!
	 *
	 * @return void
	 */
	protected function maybe_resend_email() {
		if ( ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'retry_email' ) ) {
			return;
		}

		$item_id = absint( get_url_var( 'id' ) );

		$log_item = new Email_Log_Item( $item_id );

		add_action( 'wp_mail_failed', [ $this, 'maybe_resend_failed' ] );

		if ( ! $log_item->exists() ) {
			return;
		} else if ( ! $log_item->retry() ) {
			return;
		}

		if ( $log_item->is_quarantined() ) {
			add_action( 'mailhawk_notices', [ $this, 'show_email_released_success_notice' ] );
		} else {
			add_action( 'mailhawk_notices', [ $this, 'show_email_retry_success_notice' ] );
		}
	}

	/**
	 * Maybe release a quarantined email if viewing the log and clicked release!
	 *
	 * @return void
	 */
	protected function maybe_release_email() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$item_id = absint( get_url_var( 'id' ) );

		if ( ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'release_email_' . $item_id ) ) {
			return;
		}

		$log_item = new Email_Log_Item( $item_id );

		add_action( 'wp_mail_failed', [ $this, 'maybe_resend_failed' ] );

		// Not quarantined (anymore), most likely released already
		if ( ! $log_item->exists() || ! $log_item->release() ) {
			return;
		}

		add_action( 'mailhawk_notices', [ $this, 'show_email_released_success_notice' ] );
	}

	/**
	 * Maybe release a quarantined email and trust its recipients!
	 *
	 * @return void
	 */
	protected function maybe_release_and_trust_email() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$item_id = absint( get_url_var( 'id' ) );

		if ( ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'release_trust_email_' . $item_id ) ) {
			return;
		}

		$log_item = new Email_Log_Item( $item_id );

		add_action( 'wp_mail_failed', [ $this, 'maybe_resend_failed' ] );

		// Not quarantined (anymore), most likely released already
		if ( ! $log_item->exists() || ! $log_item->release_and_trust() ) {
			return;
		}

		add_action( 'mailhawk_notices', [ $this, 'show_email_released_trusted_notice' ] );
	}

	/**
	 * Maybe trust an address from the recipients view
	 *
	 * @return void
	 */
	protected function maybe_trust_recipient() {

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( get_post_var( '_mailhawk_nonce' ), 'trust_recipient' ) ) {
			return;
		}

		$address = sanitize_email( get_post_var( 'address' ) );

		if ( Plugin::instance()->recipients->trust( $address, 'manual' ) ) {
			add_action( 'mailhawk_notices', [ $this, 'show_recipient_trusted_notice' ] );
		} else {
			add_action( 'mailhawk_notices', [ $this, 'show_recipient_not_trusted_notice' ] );
		}
	}

	/**
	 * Maybe forget what MailHawk knows about a recipient, it is treated as any other address again
	 *
	 * @return void
	 */
	protected function maybe_forget_recipient() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$id = absint( get_url_var( 'recipient' ) );

		if ( ! $id || ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'forget_recipient_' . $id ) ) {
			return;
		}

		$recipients = Plugin::instance()->recipients;

		// The record of someone who reported spam is what keeps them from being emailed or trusted
		if ( $recipients->has_reported_spam( $recipients->get( $id ) ) ) {
			add_action( 'mailhawk_notices', [ $this, 'show_recipient_kept_notice' ] );

			return;
		}

		if ( $recipients->delete( $id ) ) {
			add_action( 'mailhawk_notices', [ $this, 'show_recipient_forgotten_notice' ] );
		}
	}

	/**
	 * Maybe reject an email if viewing the log and rejected a quarantined email!
	 *
	 * @return void
	 */
	protected function maybe_reject_email() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$item_id = absint( get_url_var( 'id' ) );

		if ( ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'reject_email_' . $item_id ) ) {
			return;
		}

		$log_item = new Email_Log_Item( $item_id );

		if ( ! $log_item->exists() ) {
			return;
		} else if ( ! $log_item->reject() ) {
			return;
		}

		add_action( 'mailhawk_notices', [ $this, 'show_email_rejected_notice' ] );
	}

	/**
	 * Rejects and email and unsubscribes them from the list
	 *
	 * @return void
	 */
	protected function maybe_reject_and_unsubscribe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$item_id = absint( get_url_var( 'id' ) );

		if ( ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'reject_unsub_' . $item_id ) ) {
			return;
		}

		$log_item = new Email_Log_Item( $item_id );

		if ( ! $log_item->exists() ) {
			return;
		} else if ( ! $log_item->reject() ) {
			return;
		}

		if ( is_groundhogg_active() ) {

			// Unsubscribe them as well
			foreach ( $log_item->recipients as $recipient ) {

                $contact = get_contactdata( $recipient );

                if ( is_a_contact( $contact ) ) {
					$contact->unsubscribe();
				}
			}
		}

		add_action( 'mailhawk_notices', [ $this, 'show_email_rejected_notice' ] );
	}


	/**
	 * The table action from the list table
	 *
	 * @return mixed
	 */
	protected function get_table_action() {
		return get_post_var( 'action', get_post_var( 'action2' ) );
	}

	/**
	 * Maybe resend an email if viewing the log and resending the email!
	 *
	 * @return void
	 */
	protected function maybe_handle_bulk_action() {
		if ( ! wp_verify_nonce( get_post_var( '_wpnonce' ), 'bulk-emails' ) ) {
			return;
		}

		$item_ids = wp_parse_id_list( get_post_var( 'email' ) );

		if ( empty( $item_ids ) ) {
			add_action( 'mailhawk_notices', [ $this, 'show_no_emails_selected_notice' ] );

			return;
		}

		$log_items = array_map( function ( $id ) {
			return new Email_Log_Item( $id );
		}, $item_ids );

		switch ( $this->get_table_action() ) {
			case 'release':

				foreach ( $log_items as $log_item ) {

					add_action( 'wp_mail_failed', [ $this, 'maybe_resend_failed' ] );

					// Skip anything that is not quarantined so it can't be sent twice
					if ( ! $log_item->exists() || ! $log_item->is_quarantined() ) {
						continue;
					} else if ( ! $log_item->release() ) {
						// The failure notice is added by maybe_resend_failed(), carry on with the rest
						continue;
					}
				}

				if ( ! $this->send_error ) {
					add_action( 'mailhawk_notices', [ $this, 'show_email_released_success_notice' ] );
				}

				break;
			case 'release_trust':

				foreach ( $log_items as $log_item ) {

					add_action( 'wp_mail_failed', [ $this, 'maybe_resend_failed' ] );

					// Skip anything that is not quarantined so it can't be sent twice, a failure notice is added by maybe_resend_failed()
					if ( $log_item->exists() && $log_item->is_quarantined() ) {
						$log_item->release_and_trust();
					}
				}

				if ( ! $this->send_error ) {
					add_action( 'mailhawk_notices', [ $this, 'show_email_released_trusted_notice' ] );
				}

				break;
			case 'retry':
			case 'resend':

				foreach ( $log_items as $log_item ) {

					add_action( 'wp_mail_failed', [ $this, 'maybe_resend_failed' ] );

					if ( ! $log_item->exists() ) {
						continue;
					} else if ( ! $log_item->retry() ) {
						// The failure notice is added by maybe_resend_failed(), carry on with the rest
						continue;
					}
				}

				if ( ! $this->send_error ) {
					add_action( 'mailhawk_notices', [ $this, 'show_email_retry_success_notice' ] );
				}

				break;
			case 'reject':

				foreach ( $log_items as $log_item ) {

					// Only quarantined emails can be rejected
					if ( ! $log_item->exists() || ! $log_item->is_quarantined() ) {
						continue;
					} else if ( ! $log_item->reject() ) {
						continue;
					}
				}

				add_action( 'mailhawk_notices', [ $this, 'show_email_rejected_notice' ] );
				break;

			case 'reject_unsub':

				foreach ( $log_items as $log_item ) {

					// Only quarantined emails can be rejected
					if ( ! $log_item->exists() || ! $log_item->is_quarantined() ) {
						continue;
					} else if ( ! $log_item->reject() ) {
						continue;
					}

					if ( is_groundhogg_active() ) {

						// Unsubscribe them as well
						foreach ( $log_item->recipients as $recipient ) {
							$contact = get_contactdata( $recipient );
							if ( is_a_contact( $contact ) ) {
								$contact->unsubscribe();
							}
						}
					}
				}

				add_action( 'mailhawk_notices', [ $this, 'show_email_rejected_notice' ] );
				break;
		}
	}

	/**
	 * Any relevant actions for the plugin go here.
	 */
	public function do_actions() {

		// The page says these itself
		remove_action( 'admin_notices', [ $this, 'installed_not_connected_notice' ] );
		remove_action( 'admin_notices', [ $this, 'setup_incomplete_notice' ] );
		remove_action( 'admin_notices', [ $this, 'account_suspended_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'connection_failed_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'disconnected_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'setup_incomplete_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'webhook_error_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'setup_skipped_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'maybe_show_domain_deleted_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'maybe_show_domain_added_notice' ] );

		$this->maybe_connect();
		$this->maybe_disconnect();
		$this->maybe_clear_suspension();
		$this->maybe_retry_webhook();
		$this->maybe_setup_initial_domains();
		$this->maybe_delete_domain();
		$this->maybe_check_domain();
		$this->maybe_register_new_domain();
		$this->maybe_send_test_email();
		$this->maybe_save_settings();
		$this->maybe_resend_email();
		$this->maybe_release_email();
		$this->maybe_release_and_trust_email();
		$this->maybe_trust_recipient();
		$this->maybe_forget_recipient();
		$this->maybe_reject_email();
		$this->maybe_reject_and_unsubscribe();
		$this->maybe_handle_bulk_action();

	}

	/**
	 * Load any scripts and perform any page specific functions.
	 *
	 * @param $hook
	 */
	public function scripts( $hook ) {

		if ( $hook !== 'tools_page_mailhawk' ) {
			return;
		}

		wp_register_script( 'chart-js', MAILHAWK_ASSETS_URL . 'lib/chart/Chart.bundle.min.js' );

//		wp_enqueue_script( 'mailhawk-full-frame', MAILHAWK_ASSETS_URL . 'js/fullframe.js', [ 'jquery' ], null, true );
		wp_enqueue_style( 'mailhawk-admin', MAILHAWK_ASSETS_URL . 'css/admin.css' );
		wp_enqueue_script( 'mailhawk-admin', MAILHAWK_ASSETS_URL . 'js/admin.js', [ 'jquery' ], null, true );

		wp_localize_script( 'mailhawk-admin', 'MailHawkConnect', [
			'connecting_text' => '<span class="dashicons dashicons-admin-generic"></span>' . __( 'Connecting You To MailHawk...', 'mailhawk' )
		] );

		// What the DNS screen says when a name is copied
		wp_localize_script( 'mailhawk-admin', 'MailHawkDns', [
			'host_copied'     => __( 'Host copied', 'mailhawk' ),
			'hostname_copied' => __( 'Full hostname copied', 'mailhawk' ),
		] );

		// Not asking for a review again once the customer said so
		wp_localize_script( 'mailhawk-admin', 'MailHawkReview', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'mailhawk_dismiss_notice' ),
		] );

		// The suggestion on the recipients page hides itself when asked to
		wp_localize_script( 'mailhawk-admin', 'MailHawkGroundhogg', [
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'dismiss_nonce' => wp_create_nonce( 'mailhawk_dismiss_notice' ),
		] );

		// Learn more about Groundhogg opens WordPress' plugin details in a modal
		if ( should_suggest_groundhogg() ) {
			add_thickbox();
		}
	}

	/**
	 * Register the admin page
	 */
	public function register() {
		$sub_page = add_submenu_page(
			'tools.php',
			'MailHawk',
			'MailHawk',
			'manage_options',
			'mailhawk',
			array( $this, 'page' )
		);
	}

	/**
	 * Output the page contents
	 */
	public function page() {

		if ( ! mailhawk_is_connected() ) {
			include __DIR__ . '/views/connect.php';

			return;
		} else if ( mailhawk_is_suspended() ) {
			include __DIR__ . '/views/suspended.php';

			return;
		} else if ( get_url_var( 'action' ) === 'setup' ) {
			include __DIR__ . '/views/setup.php';

			return;
		} else if ( get_url_var( 'action' ) === 'post_setup' ) {
			include __DIR__ . '/views/post-setup.php';

			return;
		}

		?>
        <div class="wrap">
            <div class="mailhawk-header">
                <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                         src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
				<?php do_action( 'mailhawk_notices' ); ?>
				<?php if ( get_url_var( 'view', 'overview' ) === 'overview' ) : ?>
                    <div class="mailhawk-range">
                        <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                        <label for="report-range"><?php _e( 'Show data for', 'mailhawk' ); ?></label>
                        <select id="report-range">
							<?php foreach ( \MailHawk\overview_ranges() as $key => $option ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) $key, \MailHawk\overview_range_key() ); ?>><?php echo esc_html( $option['label'] ); ?></option>
							<?php endforeach; ?>
                        </select>
                    </div>
                    <script>
                      // The range is a query arg, so changing it reloads the overview with that range.
                      document.getElementById('report-range').addEventListener('change', function () {
                        var url = new URL(window.location.href)
                        url.searchParams.set('range', this.value)
                        window.location.href = url.toString()
                      })
                    </script>
				<?php endif; ?>
            </div>

			<?php include __DIR__ . '/menu.php'; ?>

            <div class="mailhawk-view-content">

				<?php

				$view = get_url_var( 'view', 'overview' );

				switch ( $view ):

					case 'domains':

						if ( get_url_var( 'domain' ) ) {
							include __DIR__ . '/views/dns-single.php';
						} else {
							include __DIR__ . '/views/domains.php';
						}

						break;

					case 'overview':
                    case 'dns':
                    case 'log':
                    case 'recipients':
                    case 'manage':
                    case 'test':
                    case 'settings':
	                    include __DIR__ . '/views/' . $view . '.php';
	                    break;
					default:
                        include __DIR__ . '/views/overview.php';
						break;
				endswitch;

				?>

            </div>
        </div>

		<?php

	}

	/**
	 * Status to show in the status textarea
	 *
	 * @return string
	 */
	protected function get_status() {

		$status = "";

		$status .= sprintf( "Send Limit:     %s\n", 0 ); // Todo actually show limit
		$status .= sprintf( "Send Usage:     %s (%s%%)\n", 0, 0 ); // Todo show actual usage
		$status .= sprintf( "Connected:      %s\n", mailhawk_is_connected() ? 'Yes' : 'No' );
		$status .= sprintf( "SPF Set:        %s", mailhawk_spf_is_set() ? 'Yes' : 'No' );

		return $status;

	}

	/**
	 * Show a notice when the SPF record is missing.
	 */
	public function spf_missing_notice() {

		$check_again = add_query_arg( [ 'action' => 'refresh_dns' ], mailhawk_admin_page() );

		?>
        <div class="notice notice-warning">
            <p><?php _e( 'We noticed your SPF record is missing. This can lead to delivery issues when sending crucial email.', 'mailhawk' ); ?></p>
            <p><b><?php _e( 'Fix Your SPF Record!', 'mailhawk' ); ?></b></p>
            <p><?php _e( "Please ensure you add <code>include:spf.mailhawk.io</code> to your SPF record.", 'mailhawk' ); ?></p>
            <p><input id="spf" type="text" class="code" value="<?php esc_attr_e( get_suggested_spf_record() ); ?>"
                      onfocus="this.select()" readonly></p>
            <p>
                <a href="#" class="button button-secondary"><?php _e( 'Instructions', 'mailhawk' ); ?></a>&nbsp;
                <a href="<?php echo wp_nonce_url( $check_again, 'refresh_dns' ); ?>"
                   class="button button-secondary"><?php _e( 'Check Again', 'mailhawk' ); ?></a>
            </p>
        </div>
		<?php
	}

	/**
	 * Show a sitewide notice that MailHawk is installed but not connected.
	 */
	public function installed_not_connected_notice() {

		if ( mailhawk_is_connected() || ! current_user_can( 'manage_options' ) || is_notice_dismissed( 'connect' ) ) {
			return;
		}

		$this->output_dismissible_notice(
			'connect',
			__( '<b>Attention:</b> MailHawk is installed but not connected yet. Until it is, WordPress keeps sending your email with its default mailer, and that email often lands in spam or never arrives.', 'mailhawk' ),
			mailhawk_admin_page(),
			__( 'Connect MailHawk', 'mailhawk' )
		);
	}

	/**
	 * The same notice for a network, where MailHawk is connected on the main site for every site
	 */
	public function network_not_connected_notice() {

		if ( ! is_mailhawk_network_active() || mailhawk_is_connected() || ! current_user_can( 'manage_options' ) || is_notice_dismissed( 'connect' ) ) {
			return;
		}

		$this->output_dismissible_notice(
			'connect',
			__( '<b>Attention:</b> MailHawk is active on your network but not connected yet. Until it is connected on the main site, the sites on your network keep sending email with the default WordPress mailer, and that email often lands in spam or never arrives.', 'mailhawk' ),
			get_admin_url( get_main_site_id(), 'tools.php?page=mailhawk' ),
			__( 'Connect MailHawk', 'mailhawk' )
		);
	}

	/**
	 * Remind the customer that connecting is not the end of the setup
	 */
	public function setup_incomplete_notice() {

		if ( ! mailhawk_is_connected() || ! is_mailhawk_setup_incomplete() || ! current_user_can( 'manage_options' ) || is_notice_dismissed( 'setup' ) ) {
			return;
		}

		// Already in the setup
		if ( in_array( get_url_var( 'action' ), [ 'setup', 'post_setup' ], true ) ) {
			return;
		}

		$this->output_dismissible_notice(
			'setup',
			__( "<b>Almost there:</b> MailHawk is connected, but the setup isn't finished. Choose the address you send from and register your domain so MailHawk can start delivering your email.", 'mailhawk' ),
			mailhawk_admin_page( [ 'action' => 'setup' ] ),
			__( 'Finish setup', 'mailhawk' )
		);
	}

	/**
	 * Output a notice with the hawk, one button, and a close button that is remembered for the user
	 *
	 * @param string $notice the notice to remember the dismissal of
	 * @param string $text   html, already translated
	 * @param string $url    where the button goes
	 * @param string $label  the button text
	 *
	 * @return void
	 */
	protected function output_dismissible_notice( $notice, $text, $url, $label ) {

		static $script_printed = false;

		?>
        <div class="notice notice-warning is-dismissible mailhawk-notice" data-notice="<?php echo esc_attr( $notice ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'mailhawk_dismiss_notice' ) ); ?>">
            <img class="alignleft" height="70" style="margin: 3px 10px 0 0"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/hawk-head.png' ); ?>" alt="Hawk">
            <p><?php echo wp_kses( $text, [ 'b' => [] ] ); ?></p>
            <p>
                <a href="<?php echo esc_url( $url ); ?>" class="button button-primary"><?php echo esc_html( $label ); ?></a>
            </p>
        </div>
		<?php if ( ! $script_printed ): ?>
            <script>
              // WordPress only hides a dismissed notice, so tell the server to keep it hidden for a while
              jQuery(function ($) {
                $(document).on('click', '.mailhawk-notice .notice-dismiss', function () {
                  var $notice = $(this).closest('.mailhawk-notice')
                  $.post(ajaxurl, {
                    action: 'mailhawk_dismiss_notice',
                    notice: $notice.data('notice'),
                    _wpnonce: $notice.data('nonce'),
                  })
                })
              })
            </script>
			<?php $script_printed = true; ?>
		<?php endif;
	}

	/**
	 * Remember that the user dismissed a notice
	 */
	public function ajax_dismiss_notice() {

		check_ajax_referer( 'mailhawk_dismiss_notice' );

		$notice = sanitize_key( get_request_var( 'notice' ) );

		if ( ! current_user_can( 'manage_options' ) || ! in_array( $notice, get_dismissible_notices(), true ) ) {
			wp_send_json_error( null, 403 );
		}

		dismiss_notice( $notice );

		wp_send_json_success();
	}


	/**
	 * Show a sitewide notice that MailHawk was suspended
	 */
	public function account_suspended_notice() {

		if ( ! mailhawk_is_suspended() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$suspension = get_mailhawk_suspension();
		$since      = mailhawk_suspended_since();

		?>
        <div class="notice notice-warning is-dismissible">
            <img class="alignleft" height="70" style="margin: 3px 10px 0 0"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/hawk-head.png' ); ?>" alt="Hawk">
            <p>
				<?php if ( $suspension['reason'] ) : ?>
					<?php
					/* translators: %s: the reason MailHawk gave for suspending the account */
					printf( __( '<b>Attention:</b> MailHawk has suspended your account, so email from this site is not being sent. Reason: %s', 'mailhawk' ), esc_html( $suspension['reason'] ) );
					?>
				<?php else : ?>
					<?php _e( '<b>Attention:</b> It looks like your account has been suspended. To continue sending email you must reactivate your account.', 'mailhawk' ); ?>
				<?php endif; ?>
            </p>
			<?php if ( $since || 'organization' === $suspension['scope'] ) : ?>
                <p>
					<?php if ( $since ) : ?>
						<?php
						/* translators: %s: date and time the account was suspended */
						printf( esc_html__( 'Suspended since %s.', 'mailhawk' ), esc_html( $since ) );
						?>
					<?php endif; ?>
					<?php if ( 'organization' === $suspension['scope'] ) : ?>
						<?php _e( 'The whole MailHawk account is suspended, so none of its sites can send email.', 'mailhawk' ); ?>
					<?php endif; ?>
                </p>
			<?php endif; ?>
            <p>
                <a href="<?php echo esc_url( mailhawk_account_url() ); ?>" target="_blank" rel="noopener"
                   class="button button-primary"><?php _e( 'See what to do next', 'mailhawk' ); ?></a>
                <a href="<?php echo esc_url( mailhawk_admin_page() ); ?>"
                   class="button button-secondary"><?php _e( 'Reactivate Now!', 'mailhawk' ); ?></a>
            </p>
        </div>
		<?php

	}

	/**
	 * Show a notice when connection to the mailhawk API fails!
	 */
	public function connection_failed_notice() {

		if ( get_url_var( 'connection' ) !== 'failed' ) {
			return;
		}

		// Said once, a reload of the page shouldn't repeat it
		$error_key = 'mailhawk_connection_error_' . get_current_user_id();
		$error     = (string) get_transient( $error_key );
		delete_transient( $error_key );

		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( 'We failed to connect you to the MailHawk service. Please try connecting again. If the problem persists, get in touch with us!', 'mailhawk' ); ?></p>
			<?php if ( $error ) : ?>
                <p><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
        </div>
		<?php
	}

	/**
	 * Show a notice when connection to the mailhawk API fails!
	 */
	public function connection_success_notice() {

		if ( get_url_var( 'is_connected' ) !== 'true' ) {
			return;
		}

		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'You are now connected to the MailHawk service! get ready to experience superior WordPress email deliverability :)', 'mailhawk' ); ?></p>
        </div>
		<?php

	}

	/**
	 * Show a notice when connection to the mailhawk API fails!
	 */
	public function maybe_show_domain_added_notice() {

		if ( get_url_var( 'added' ) !== 'yes' || get_url_var( 'view' ) !== 'domains' ) {
			return;
		}

		?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( __( 'Successfully registered %s.', 'mailhawk' ), "<code>" . esc_html( sanitize_text_field( get_url_var( 'domain' ) ) ) . "</code>" ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when connection to the mailhawk API fails!
	 */
	public function maybe_show_domain_deleted_notice() {

		if ( ! get_url_var( 'deleted_domain' ) ) {
			return;
		}

		?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( __( 'Successfully deleted %s.', 'mailhawk' ), "<code>" . esc_html( sanitize_text_field( get_url_var( 'deleted_domain' ) ) ) . "</code>" ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when an email address is added to the whitelist
	 */
	public function domain_limit_reached_notice() {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( "You've reached your domain limit. Upgrade your plan to register additional domains.", 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when an email address is added to the whitelist
	 */
	public function invalid_domain_provided_notice() {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( "Not a valid domain name.", 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when an email address is added to the whitelist
	 */
	public function domain_name_already_registered() {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( "You've already registered this domain name.", 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when a test email could not be delivered.
	 */
	public function show_test_not_successful_notice() {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php printf( __( 'There was a problem sending the email to %s.', 'mailhawk' ), "<code>" . esc_html( get_array_var( $this->test_data, 'to' ) ) . "</code>" ); ?></p>
            <p><?php echo "<code>" . esc_html( $this->send_error->get_error_message() ) . "</code>"; ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when a test email was delivered.
	 */
	public function show_test_successful_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php
				if ( get_array_var( $this->test_data, 'from' ) ) {
					/* translators: 1: recipient of the test email, 2: the address it was sent from */
					printf( __( 'We sent a test email to %1$s from %2$s.', 'mailhawk' ), "<code>" . esc_html( get_array_var( $this->test_data, 'to' ) ) . "</code>", "<code>" . esc_html( get_array_var( $this->test_data, 'from' ) ) . "</code>" );
				} else {
					/* translators: %s: recipient of the test email */
					printf( __( 'We sent a test email to %s.', 'mailhawk' ), "<code>" . esc_html( get_array_var( $this->test_data, 'to' ) ) . "</code>" );
				}
				?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when settings are saved!
	 */
	public function show_settings_saved_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Settings saved!', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice if email retry succeeded
	 */
	public function show_email_retry_success_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Email resent!', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice if email retry succeeded
	 */
	public function show_email_rejected_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Email rejected!', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice if a quarantined email was released and its recipients trusted
	 */
	public function show_email_released_trusted_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Email released and sent! Future emails to these recipients will not be quarantined, unless they bounce or report spam.', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice when an address was trusted
	 */
	public function show_recipient_trusted_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( __( 'Emails to %s will not be quarantined.', 'mailhawk' ), "<code>" . esc_html( sanitize_email( get_post_var( 'address' ) ) ) . "</code>" ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice when an address could not be trusted
	 */
	public function show_recipient_not_trusted_notice() {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php printf( __( '%s could not be trusted. Check the address is valid, and that the recipient has not reported your email as spam.', 'mailhawk' ), "<code>" . esc_html( sanitize_email( get_post_var( 'address' ) ) ) . "</code>" ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice when a recipient was not forgotten, because they reported email as spam
	 */
	public function show_recipient_kept_notice() {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( 'That person reported email from this site as spam. The record is kept so they are not emailed again.', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice when a recipient was forgotten
	 */
	public function show_recipient_forgotten_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Recipient removed. It will be treated like any other address.', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice if email retry succeeded
	 */
	public function show_email_released_success_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Email released and sent!', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when a test email could not be delivered.
	 */
	public function show_no_emails_selected_notice() {
		?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e( 'No emails were selected to resend/retry.', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when a test email could not be delivered.
	 */
	public function show_retry_email_not_successful_notice() {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( 'There was a problem sending the email.', 'mailhawk' ); ?></p>
            <p><?php echo "<code>" . $this->send_error->get_error_message() . "</code>"; ?></p>
        </div>
		<?php
	}

	/**
	 * Notice if domain verified
	 */
	public function show_domain_verified_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Your domain has been verified successfully!', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Notice if domain unverified
	 */
	public function show_domain_unverified_notice() {
		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( "We were unable to verify your domain. It can take up to 24 hours for records to propogate, so check again in an hour or so.", 'mailhawk' ); ?></p>
        </div>
		<?php
	}
}
