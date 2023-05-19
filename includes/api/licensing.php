<?php

namespace MailHawk\Api;

use function MailHawk\get_core_option;
use function MailHawk\get_json_error;
use function MailHawk\is_json_error;
use function MailHawk\update_core_option;

class Licensing {

	public static $sever_url = 'https://mailhawk.io';
//	public static $sever_url = 'http://localhost/mailhawk';

	/**
	 * @var Licensing
	 */
	public static $instance;

	/**
	 * @var string
	 */
	protected $token;

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @since  1.0.0
	 * @access public
	 * @static
	 *
	 * @return Licensing An instance of the class.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	final public function __clone() {
		trigger_error( "Singleton. No cloning allowed!", E_USER_ERROR );
	}

	final public function __wakeup() {
		trigger_error( "Singleton. No serialization allowed!", E_USER_ERROR );
	}

	/**
	 * Licensing constructor.
	 */
	public function __construct() {
		$this->token = get_core_option( 'mailhawk_license_server_token' );
	}

	/**
	 * Sets the token and updates the option
	 *
	 * @param $token string
	 */
	public function set_token( $token ) {
		$this->token = $token;
		update_core_option( 'mailhawk_license_server_token', $token );
	}

	/**
	 * Get an authorization token from MailHawk
	 *
	 * @param $code string
	 *
	 * @return bool|object
	 */
	public function get_token( $code ) {

		$data = [
			'code' => $code,
		];

		$response = $this->request( 'wp-json/mailhawk/token', $data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$token = sanitize_text_field( $response->token );

		// set the token so it can be instantly used to get the credentials.
		$this->set_token( $token );

		return $token;
	}

	/**
	 * Get the credentials for the license key and postal API key
	 *
	 * @return object|\WP_Error
	 */
	public function get_credentials() {
		return $this->request( 'wp-json/mailhawk/credentials', [], 'GET' );
	}

	/**
	 * Get the credentials for the license key and postal API key
	 *
	 * @return object|\WP_Error
	 */
	public function get_account() {
		return $this->request( 'wp-json/mailhawk/account', [], 'GET' );
	}

	public function deactivate(){
		return $this->request( 'wp-json/mailhawk/deactivate', [], 'POST' );
	}

	/**
	 * Send a request to the API
	 *
	 * @param string $endpoint the API endpoint
	 * @param string $body     array the body of the request
	 * @param string $method   the type of request
	 *
	 * @return \WP_Error|object json object when successful, otherwise a WP_Error
	 */
	public function request( $endpoint = '', $body = '', $method = 'POST' ) {

		$headers = [
			'Content-Type' => sprintf( 'application/json; charset=%s', get_bloginfo( 'charset' ) ),
		];

		if ( $this->token ) {
			$headers['X-API-Token'] = $this->token;
		}

		$method = strtoupper( $method );

		$request = [
			'method'      => $method,
			'headers'     => $headers,
			'body'        => wp_json_encode( $body ),
			'data_format' => 'body',
			'sslverify'   => true
		];

		if ( $method === 'GET' ) {
			unset( $request['data_format'] );
			unset( $request['body'] );
		}

		$response = wp_remote_request( self::$sever_url . '/' . $endpoint, $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body );

		if ( is_json_error( $json ) ) {
			return get_json_error( $json );
		}

		return $json;
	}

}
