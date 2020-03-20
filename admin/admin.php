<?php

namespace MailHawk\Admin;

use MailHawk\Api\Licensing;
use MailHawk\Api\Postal\Domains;
use MailHawk\Api_Helper;
use MailHawk\Keys;
use MailHawk\Plugin;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_post_var;
use function MailHawk\get_request_var;
use function MailHawk\get_suggested_spf_record;
use function MailHawk\get_url_var;
use function MailHawk\mailhawk_is_connected;
use function MailHawk\mailhawk_spf_set;
use function MailHawk\set_mailhawk_is_connected;

class Admin {

	protected static $server_url = 'http://localhost/mailhawk/';

	public function __construct() {

		// Load any scripts
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );

		// Show as last option
		add_action( 'admin_menu', [ $this, 'register' ], 99 );

		// Add notice to go to admin page.
		add_action( 'admin_notices', [ $this, 'installed_not_connected_notice' ] );

		// Process any actions relevant for the admin page
		add_action( 'load-tools_page_mailhawk', [ $this, 'do_actions' ] );

//		set_mailhawk_is_connected( false );
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

			wp_send_json_error( [ 'where' => 'token', 'response' => $response ] );

			return;
		}

		$credentials = Licensing::instance()->get_license_and_credentials();

		if ( is_wp_error( $credentials ) ) {

			add_action( 'mailhawk_notices', [ $this, 'connection_failed_notice' ] );

			wp_send_json_error( [ 'where' => 'credentials', 'response' => $response ] );

			return;
		}

		$mta_credential_key = sanitize_text_field( $credentials->credential_key );
		$license_key        = sanitize_text_field( $credentials->license_key );
		$item_id            = absint( $credentials->item_id );

		update_option( 'mailhawk_license_key', $license_key );
		update_option( 'mailhawk_mta_credential_key', $mta_credential_key );

		$response = Licensing::instance()->activate( $license_key, $item_id );

		// Now we have to activate it via EDD.
		if ( is_wp_error( $response ) ) {
			add_action( 'mailhawk_notices', [ $this, 'connection_failed_notice' ] );

			wp_send_json_error( [ 'where' => 'license', 'response' => $response ] );

			return;
		}

		// All checks passed, connect mailhawk!
		set_mailhawk_is_connected( true );

		// todo: Add daily check to licensing server
		die( wp_safe_redirect( get_admin_mailhawk_uri( [ 'action' => 'setup' ] ) ) );
	}

	/**
	 * Setup any initial domains from the setup page.
	 */
	protected function maybe_setup_initial_domains() {

		if ( ! wp_verify_nonce( get_post_var( '_mailhawk_setup_nonce' ), 'mailhawk_register_domains' ) ) {
			return;
		}

		$emails  = map_deep( get_post_var( 'emails' ), 'sanitize_email' );
		$domains = [];

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
//		    Domains::create( $domain );
		}

		// Send to DNS
		die( wp_safe_redirect( get_admin_mailhawk_uri( [ 'action' => 'dns' ] ) ) );

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

		// Domains::delete( $domain );

		die( wp_safe_redirect( get_admin_mailhawk_uri( [ 'deleted_domain' => $domain ] ) ) );
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

//	    Domains::create( $domain );

		die( wp_safe_redirect( get_admin_mailhawk_uri( [
			'action' => 'dns-single',
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
	 * Any relevant actions for the plugin go here.
	 */
	public function do_actions() {

		remove_action( 'admin_notices', [ $this, 'installed_not_connected_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'maybe_show_domain_deleted_notice' ] );
		add_action( 'mailhawk_notices', [ $this, 'maybe_show_domain_added_notice' ] );
		add_filter( 'admin_footer_text', [ $this, 'admin_footer_text' ] );

		$this->maybe_connect();
		$this->maybe_setup_initial_domains();
		$this->maybe_delete_domain();
		$this->maybe_register_new_domain();
		$this->maybe_manage_blacklist();

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

		wp_enqueue_style( 'mailhawk-admin', MAILHAWK_ASSETS_URL . 'css/admin.css' );
		wp_enqueue_script( 'mailhawk-admin', MAILHAWK_ASSETS_URL . 'js/admin.js' );

		wp_localize_script( 'mailhawk-admin', 'MailHawkConnect', [
			'foo'             => 'bar',
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

		?>
        <div class="wrap">
            <div class="mailhawk-connect-header">
                <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                         src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
				<?php do_action( 'mailhawk_notices' ); ?>
            </div>

			<?php if ( get_url_var( 'action' ) == 'setup' ): ?>
				<?php include __DIR__ . '/views/setup.php'; ?>
			<?php elseif ( get_url_var( 'action' ) == 'dns' ): ?>
				<?php include __DIR__ . '/views/dns.php'; ?>
			<?php elseif ( get_url_var( 'action' ) == 'dns-single' ): ?>
				<?php include __DIR__ . '/views/dns-single.php'; ?>
			<?php elseif ( ! mailhawk_is_connected() ): ?>
				<?php include __DIR__ . '/views/connect.php'; ?>
			<?php else : ?>
				<?php include __DIR__ . '/views/manage.php'; ?>
			<?php endif; ?>

			<?php if ( mailhawk_is_connected() && ! get_url_var( 'action' ) ): ?>
				<?php include __DIR__ . '/views/domains.php'; ?>
				<?php include __DIR__ . '/views/blacklist.php'; ?>
			<?php endif; ?>

            <div class="mailhawk-legal">
                <!-- TODO Add Real Links -->
                <a href="#"><?php _e( 'Privacy Policy', 'mailhawk' ); ?></a> |
                <a href="#"><?php _e( 'Terms & Conditions', 'mailhawk' ); ?></a> |
                <a href="#"><?php _e( 'Support', 'mailhawk' ); ?></a>
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
		$status .= sprintf( "SPF Set:        %s", mailhawk_spf_set() ? 'Yes' : 'No' );

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
            <p><?php _e( "Please ensure you add <code>include:spf.mailhawkwp.com</code> to your SPF record.", 'mailhawk' ); ?></p>
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
                &nbsp;
            </p>
            <p>
                <a href="<?php echo esc_url( get_admin_mailhawk_uri() ); ?>"
                   class="button button-secondary"><?php _e( 'Connect or Register Now!', 'mailhawk' ); ?></a>
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

		if ( get_url_var( 'added' ) !== 'yes' || get_url_var( 'action' ) !== 'dns-single' ) {
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


}
