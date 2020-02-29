<?php

namespace MailHawk\Admin;

use MailHawk\Api_Helper;
use MailHawk\Keys;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_suggested_spf_record;
use function MailHawk\get_url_var;
use function MailHawk\mailhawk_is_connected;
use function MailHawk\mailhawk_spf_set;

class Admin {

	protected static $oauth_url = 'https://www.mailhawkwp.com/oauth/';

	public function __construct() {

		// Load any scripts
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );

		// Show as last option
		add_action( 'admin_menu', [ $this, 'register' ], 99 );

		// Add notice to go to admin page.
		add_action( 'admin_notices', [ $this, 'installed_not_connected_notice' ] );

		// Process any actions relevant for the admin page
		add_action( 'load-tools_page_mailhawk', [ $this, 'do_actions' ] );

		add_filter( 'admin_footer_text', [ $this, 'admin_footer_text' ] );
	}

	public function admin_footer_text( $text ){
		return preg_replace( "/<\/span>/", sprintf( __( ' | Like MailHawk? <a target="_blank" href="%s">Leave a &#x2B50;&#x2B50;&#x2B50;&#x2B50;&#x2B50; Review</a>!</span>' ), __( 'https://wordpress.org/support/plugin/mailhawk/reviews/#new-post' ) ), $text );
    }

	/**
	 * Any relevant actions for the plugin go here.
	 */
	public function do_actions() {

		remove_action( 'admin_notices', [ $this, 'installed_not_connected_notice' ] );

		if ( get_url_var( 'action' ) === 'refresh_dns' && wp_verify_nonce( get_url_var( '_wpnonce' ), 'refresh_dns' ) ) {
			delete_transient( 'mailhawk_spf_set' );
		}

		if ( ! mailhawk_spf_set() && mailhawk_is_connected() ) {
			add_action( 'admin_notices', [ $this, 'spf_missing_notice' ] );
		}
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

		$form_inputs = [
			'client_id'    => Keys::instance()->client_id(),
			'clint_secret' => Keys::instance()->client_secret(),
			'public_key'   => Keys::instance()->public_key(),
			'state'        => Keys::instance()->state(),
			'redirect_uri' => get_admin_mailhawk_uri(),
		];

		?>
        <div class="wrap">
            <div class="mailhawk-connect-header">
                <h1><img title="MailHawk Logo" alt="MailHawk Logo"
                         src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/logo.png' ); ?>"></h1>
            </div>
            <div class="mailhawk-connect">

				<?php if ( ! mailhawk_is_connected() ): ?>

                    <p><?php _e( 'Connect to <b>MailHawk</b> and instantly solve your WordPress email delivery woes. Starts at just <b>$14.97</b>/m.', 'mailhawk' ); ?></p>
                    <form method="post" action="<?php echo esc_url( self::$oauth_url ); ?>">
						<?php

						foreach ( $form_inputs as $input => $value ) {
							?><input type="hidden" name="<?php esc_attr_e( $input ); ?>"
                                     value="<?php esc_attr_e( $value ); ?>"><?php
						}

						?>
                        <button class="button button-primary big-button" type="submit" value="connect">
                            <span class="dashicons dashicons-email-alt"></span>
							<?php _e( 'Connect MailHawk Now!', 'mailhawk' ); ?>
                        </button>
                    </form>

				<?php else : ?>

                    <p><?php _e( 'You are connected to MailHawk! We are ensuring the safe delivery of your email to your customers\'s inbox.', 'mailhawk' ); ?></p>
                    <p><a href="#" class="button button-secondary"><?php _e( 'My Account', 'mailhawk' ); ?></a></p>

                    <p><b><?php _e( 'Status:', 'mailhawk' ); ?></b></p>
                    <p><textarea name="status" id="mailhawk-status" class="code" onfocus="this.select()"
                                 readonly><?php esc_html_e( $this->get_status() ); ?></textarea></p>

				<?php endif; ?>
            </div>
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
            <img class="alignleft" height="40" style="margin: 3px 10px 0 0"
                 src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/hawk-head.png' ); ?>" alt="Hawk">
            <p>
				<?php _e( 'Oops, looks like MailHawk is installed but not connected.', 'mailhawk' ); ?>&nbsp;
                <a href="<?php echo esc_url( get_admin_mailhawk_uri() ); ?>"
                   class="button button-secondary"><?php _e( 'Connect Now!', 'mailhawk' ); ?></a>
            </p>
        </div>
		<?php

	}

}
