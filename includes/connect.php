<?php

namespace MailHawk;

use function Sodium\add;

class Connect {

	protected static $oauth_server = 'https://www.mailhawkwp.com';

	protected $code;
	protected $state;

	public function __construct() {
		add_action( 'admin_init', [ $this, 'listen' ] );
	}

	public function listen() {

		// If returning from MailHawk with authentication data.
		$state  = get_url_var( 'state' );
		$action = get_url_var( 'action' );

		// Not a connect request?
		if ( $action !== 'mailhawk_connect' || $state !== Keys::instance()->state() ) {
			return;
		}

		// Get the authorization code
		$code = sanitize_text_field( get_url_var( 'code' ) );

		$body = [
			'grant_type' => 'authorization_code',
			'code'       => $code,
		];

		$request = [
			'method'      => 'POST',
			'body'        => wp_json_encode( $body ),
			'data_format' => 'body',
			'sslverify'   => true
		];

		$response = wp_remote_post( self::$oauth_server . '/wp-json/oauth/token/', $request );

		if ( is_wp_error( $response ) ) {
			add_action( 'admin_notices', [ $this, 'connection_failed_notice' ] );
			return;
		}

		$json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset_not_empty( $json, 'error' ) ) {
			add_action( 'admin_notices', [ $this, 'connection_failed_notice' ] );
			return;
		}

		$access_token = sanitize_text_field( $json->access_token );

		update_option( 'mailhawk_access_token', $access_token );

		add_action( 'admin_menu', [ $this, 'connect_successful_notice' ] );

	}

	/**
	 * Show a success notice when the connection is a success!
	 *
	 * @return void
	 */
	public function connect_successful_notice(){
		?>
		<div class="notice notice-success">
			<p><?php _e( 'Connection to MailHawk successful!', 'mailhawk' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Show a failed connection notice
	 *
	 * @return void
	 */
	public function connection_failed_notice() {
		?>
		<div class="notice notice-error">
			<p><?php _e( 'Connection to MailHawk failed.', 'mailhawk' ); ?></p>
		</div>
		<?php
	}

}
