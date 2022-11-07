<?php

namespace MailHawk\Admin;

use MailHawk\Api\Licensing;
use MailHawk\Api\Postal\Domains;
use MailHawk\Api\Postal\Webhooks;
use MailHawk\Classes\Email_Log_Item;
use MailHawk\Keys;
use MailHawk\Plugin;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_array_var;
use function MailHawk\get_post_var;
use function MailHawk\get_request_var;
use function MailHawk\get_rest_api_webhook_listener_uri;
use function MailHawk\get_suggested_spf_record;
use function MailHawk\get_url_var;
use function MailHawk\is_mailhawk_network_active;
use function MailHawk\mailhawk_is_connected;
use function MailHawk\mailhawk_is_suspended;
use function MailHawk\mailhawk_spf_is_set;
use function MailHawk\set_mailhawk_api_credentials;
use function MailHawk\set_mailhawk_is_connected;
use function MailHawk\set_mailhawk_is_suspended;

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
		add_action( 'admin_notices', [ $this, 'account_suspended_notice' ] );

		// Process any actions relevant for the admin page
		add_action( 'load-tools_page_mailhawk', [ $this, 'do_actions' ] );

		add_action( 'wp_ajax_mailhawk_preview_email', [ $this, 'ajax_load_preview_content' ] );
	}

	/**
	 * Add a review request in the admin footer.
	 *
	 * @param $text
	 *
	 * @return string|string[]|null
	 */
	public function admin_footer_text( $text ) {
		return preg_replace( "/<\/span>/", sprintf( __( ' | Like MailHawk? <a target="_blank" href="%s">Leave a &#x2B50;&#x2B50;&#x2B50;&#x2B50;&#x2B50; Review</a>!</span>' ), __( 'https://wordpress.org/support/plugin/mailhawk/reviews/#new-post' ) ), $text );
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

		$saved_state = Keys::instance()->state();

		if ( $saved_state !== $state ) {
			return;
		}

		$response = Licensing::instance()->get_token( $code );

		if ( is_wp_error( $response ) ) {
			add_action( 'mailhawk_notices', [ $this, 'connection_failed_notice' ] );

			wp_send_json_error( [ 'where' => 'token', 'response' => $response, 'code' => $code ] );

			return;
		}

		$credentials = Licensing::instance()->get_credentials();

		if ( is_wp_error( $credentials ) ) {

			add_action( 'mailhawk_notices', [ $this, 'connection_failed_notice' ] );

			wp_send_json_error( [ 'where' => 'credentials', 'response' => $response ] );

			return;
		}

		$mta_credential_key = sanitize_text_field( $credentials->credential_key );

		set_mailhawk_api_credentials( $mta_credential_key );

		// Now we have to activate it via EDD.
		if ( is_wp_error( $response ) ) {
			add_action( 'mailhawk_notices', [ $this, 'connection_failed_notice' ] );

			wp_send_json_error( [ 'where' => 'license', 'response' => $response ] );

			return;
		}

		// All checks passed, connect MailHawk!
		set_mailhawk_is_connected( true );
		set_mailhawk_is_suspended( false );

		// Create the webhook listener.
		$this->maybe_register_webhook();

		die( wp_safe_redirect( get_admin_mailhawk_uri( [ 'action' => 'setup' ] ) ) );
	}

	/**
	 * Disconnect mailhawk
	 */
	protected function maybe_disconnect() {
		if ( ! wp_verify_nonce( get_url_var( '_wpnonce' ), 'disconnect_mailhawk' ) ) {
			return;
		}

		// remotely deactivate this site
		Licensing::instance()->deactivate();

		// Set local is connected to false
		set_mailhawk_is_connected( false );

		die( wp_safe_redirect( get_admin_mailhawk_uri() ) );
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
		if ( is_email( $from_email ) ) {
			update_option( 'mailhawk_default_from_email_address', $from_email );
		}

		$emails  = map_deep( get_post_var( 'emails', [] ), 'sanitize_email' );
		$domains = [];

		// Add the deafult from email to the list of emails to check...
		$emails[] = $from_email;

		foreach ( $emails as $email ) {

			// Make sure it's a valid email
			if ( ! is_email( $email ) ) {
				continue;
			}

			// Get the domain portion of the email
			$domain = substr( $email, strpos( $email, '@' ) + 1 );

			// Check if we already found it
			if ( ! in_array( $domain, $domains ) ) {
				$domains[] = $domain;
			}
		}

		foreach ( $domains as $domain ) {
			Domains::create( $domain );
		}

		// Send to DNS
		die( wp_safe_redirect( get_admin_mailhawk_uri( [ 'action' => 'post_setup' ] ) ) );

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

		die( wp_safe_redirect( get_admin_mailhawk_uri( [ 'deleted_domain' => $domain, 'view' => 'domains' ] ) ) );
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

		die( wp_safe_redirect( get_admin_mailhawk_uri( [
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

		die( wp_safe_redirect( get_admin_mailhawk_uri( [
			'view'   => 'domains',
			'domain' => $domain,
			'added'  => 'yes',
		] ) ) );
	}

	/**
	 * Add emails to the blacklist or whitelist
	 */
	protected function maybe_manage_blacklist() {

		// Add to blacklist
		if ( wp_verify_nonce( get_post_var( '_mailhawk_nonce' ), 'add_to_blacklist' ) ) {
			$email = sanitize_email( get_post_var( 'address' ) );

			Plugin::instance()->emails->add( [
				'email'  => $email,
				'status' => 'blacklist'
			] );

			add_action( 'mailhawk_notices', [ $this, 'show_address_added_to_blacklist_notice' ] );
		}

		// Add to whitelist
		if ( wp_verify_nonce( get_post_var( '_mailhawk_nonce' ), 'add_to_whitelist' ) ) {
			$email = sanitize_email( get_post_var( 'address' ) );

			Plugin::instance()->emails->add( [
				'email'  => $email,
				'status' => 'whitelist'
			] );

			add_action( 'mailhawk_notices', [ $this, 'show_address_added_to_whitelist_notice' ] );
		}

	}

	/**
	 * Register the webhook response.
	 */
	protected function maybe_register_webhook() {

		// Delete any old webhooks with the same URI
		Webhooks::delete( get_rest_api_webhook_listener_uri() );

		Webhooks::create( get_bloginfo( 'name' ), get_rest_api_webhook_listener_uri(), false, [
			'MessageDeliveryFailed',
			'MessageBounced',
		] );
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

		$to      = sanitize_email( get_post_var( 'to_address' ) );
		$subject = sanitize_text_field( get_post_var( 'subject' ) );
		$body    = sanitize_textarea_field( get_post_var( 'content' ) );

		$this->test_data['to']      = $to;
		$this->test_data['subject'] = $subject;
		$this->test_data['body']    = $body;

		if ( ! $to ) {
			return;
		}

		add_action( 'wp_mail_failed', [ $this, 'maybe_email_failed' ] );

		$success = wp_mail( $to, $subject, $body, [
			'sender' => 'app@mailhawk.io'
		] );

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
	 * Output the email content.
	 */
	protected function maybe_preview_email() {

		if ( ! wp_verify_nonce( get_url_var( '_mailhawk_nonce' ), 'preview_email' ) ) {
			return;
		}

		$preview_id = absint( get_url_var( 'preview' ) );

		$log_item = new Email_Log_Item( $preview_id );

		if ( ! $log_item->exists() ) {
			wp_die( 'Invalid log item ID.' );
		}

		if ( preg_match( '/<html[^>]*>/', $log_item->content ) ) {
			echo $log_item->content;
		} else {
			echo wpautop( esc_html( $log_item->content ) );
		}

		die();
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

		add_action( 'mailhawk_notices', [ $this, 'show_email_retry_success_notice' ] );
	}

	protected function get_table_action() {
		return get_post_var( 'action', get_post_var( 'action2' ) );
	}

	/**
	 * Maybe resend an email if viewing the log and resending the email!
	 *
	 * @return void
	 */
	protected function maybe_bulk_resend_email() {
		if ( ! wp_verify_nonce( get_post_var( '_wpnonce' ), 'bulk-emails' ) || ! in_array( $this->get_table_action(), [
				'resend',
				'retry'
			] ) ) {
			return;
		}

		$item_ids = wp_parse_id_list( get_post_var( 'email' ) );

        if ( empty( $item_ids ) ){
	        add_action( 'mailhawk_notices', [ $this, 'show_no_emails_selected_notice' ] );
	        return;
        }

		foreach ( $item_ids as $item_id ) {
			$log_item = new Email_Log_Item( $item_id );
			add_action( 'wp_mail_failed', [ $this, 'maybe_resend_failed' ] );

			if ( ! $log_item->exists() ) {
				return;
			} else if ( ! $log_item->retry() ) {
				return;
			}
		}

        if ( ! $this->send_error ){
	        add_action( 'mailhawk_notices', [ $this, 'show_email_retry_success_notice' ] );
        }
	}

	/**
	 * Any relevant actions for the plugin go here.
	 */
	public function do_actions() {

		remove_action( 'admin_notices', [ $this, 'installed_not_connected_notice' ] );
		remove_action( 'admin_notices', [ $this, 'account_suspended_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'maybe_show_domain_deleted_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'maybe_show_domain_added_notice' ] );
		add_filter( 'admin_footer_text', [ $this, 'admin_footer_text' ] );

		$this->maybe_connect();
		$this->maybe_disconnect();
		$this->maybe_setup_initial_domains();
		$this->maybe_delete_domain();
		$this->maybe_check_domain();
		$this->maybe_register_new_domain();
		$this->maybe_manage_blacklist();
		$this->maybe_send_test_email();
		$this->maybe_preview_email();
		$this->maybe_save_settings();
		$this->maybe_resend_email();
        $this->maybe_bulk_resend_email();

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

		wp_enqueue_script( 'mailhawk-full-frame', MAILHAWK_ASSETS_URL . 'js/fullframe.js', [ 'jquery' ], null, true );
		wp_enqueue_style( 'mailhawk-admin', MAILHAWK_ASSETS_URL . 'css/admin.css' );
		wp_enqueue_script( 'mailhawk-admin', MAILHAWK_ASSETS_URL . 'js/admin.js', [ 'jquery' ], null, true );

		wp_localize_script( 'mailhawk-admin', 'MailHawkConnect', [
			'connecting_text' => '<span class="dashicons dashicons-admin-generic"></span>' . __( 'Connecting You To MailHawk...', 'mailhawk' )
		] );
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
            </div>

			<?php include __DIR__ . '/views/menu.php'; ?>

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
					default:
						if ( file_exists( __DIR__ . '/views/' . $view . '.php' ) ) {
							include __DIR__ . '/views/' . $view . '.php';
						}
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

		$check_again = add_query_arg( [ 'action' => 'refresh_dns' ], get_admin_mailhawk_uri() );

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

		if ( mailhawk_is_connected() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
        <div class="notice notice-warning is-dismissible">
            <img class="alignleft" height="70" style="margin: 3px 10px 0 0"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/hawk-head.png' ); ?>" alt="Hawk">
            <p>
				<?php _e( '<b>Attention:</b> It looks like MailHawk is installed but is not connected to the MailHawk service.', 'mailhawk' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( get_admin_mailhawk_uri() ); ?>"
                   class="button button-secondary"><?php _e( 'Connect or Register Now!', 'mailhawk' ); ?></a>
            </p>
        </div>
		<?php

	}


	/**
	 * Show a sitewide notice that MailHawk was suspended
	 */
	public function account_suspended_notice() {

		if ( ! mailhawk_is_suspended() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
        <div class="notice notice-warning is-dismissible">
            <img class="alignleft" height="70" style="margin: 3px 10px 0 0"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/hawk-head.png' ); ?>" alt="Hawk">
            <p>
				<?php _e( '<b>Attention:</b> It looks like your account has been suspended. To continue sending email you must reactivate your account.', 'mailhawk' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( get_admin_mailhawk_uri() ); ?>"
                   class="button button-secondary"><?php _e( 'Reactivate Now!', 'mailhawk' ); ?></a>
            </p>
        </div>
		<?php

	}

	/**
	 * Show a notice when connection to the mailhawk API fails!
	 */
	public function connection_failed_notice() {

		?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( 'We failed to connect you to the MailHawk service. Please try connecting again. If the problem persists, get in touch with us!', 'mailhawk' ); ?></p>
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
            <p><?php printf( __( 'Successfully registered %s.', 'mailhawk' ), "<code>" . sanitize_text_field( get_url_var( 'domain' ) ) . "</code>" ); ?></p>
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
            <p><?php printf( __( 'Successfully deleted %s.', 'mailhawk' ), "<code>" . sanitize_text_field( get_url_var( 'deleted_domain' ) ) . "</code>" ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when an email address is added to the blacklist
	 */
	public function show_address_added_to_blacklist_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( __( 'Added %s to the blacklist.', 'mailhawk' ), "<code>" . sanitize_email( get_post_var( 'address' ) ) . "</code>" ); ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when an email address is added to the whitelist
	 */
	public function show_address_added_to_whitelist_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( __( 'Added %s to the whitelist.', 'mailhawk' ), "<code>" . sanitize_email( get_post_var( 'address' ) ) . "</code>" ); ?></p>
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
            <p><?php printf( __( 'There was a problem sending the email to %s.', 'mailhawk' ), "<code>" . get_array_var( $this->test_data, 'to' ) . "</code>", "<code>" . $this->send_error->get_error_message() . "</code>" ); ?></p>
            <p><?php echo "<code>" . $this->send_error->get_error_message() . "</code>"; ?></p>
        </div>
		<?php
	}

	/**
	 * Show a notice when a test email was delivered.
	 */
	public function show_test_successful_notice() {
		?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( __( 'We sent a test email to %s.', 'mailhawk' ), "<code>" . get_array_var( $this->test_data, 'to' ) . "</code>" ); ?></p>
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
            <p><?php _e( 'There was a problem re-sending the email.', 'mailhawk' ); ?></p>
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
