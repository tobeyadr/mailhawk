<?php

namespace MailHawk;

class Api_Helper {

	public static $license_sever_url = 'http://localhost/mailhawk';
	public static $smtp_sever_url = 'https://mta01.mailhawk.io';
	public static $validation_sever_url = 'https://validate.mailhawkwp.com';

	/**
	 * @var string
	 */
	protected $access_token;

	/**
	 * @var string
	 */
	protected $public_key;

	/**
	 * @var string
	 */
	protected $api_key;

	/**
	 * @var Api_Helper
	 */
	public static $instance;

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @since  1.0.0
	 * @access public
	 * @static
	 *
	 * @return Api_Helper An instance of the class.
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
	 * Api_Helper constructor.
	 */
	public function __construct() {
		$this->access_token = Keys::instance()->access_token();
		$this->public_key   = Keys::instance()->public_key();
	}

	/**
	 * @param string $key
	 */
	public function set_api_key( $key = '' ) {
		$this->api_key = $key;
	}

	/**
	 * Whether the current site is connected to MailHawk
	 *
	 * @return bool
	 */
	public function is_connected_for_mail() {
		return get_option( 'mailhawk_is_connected' ) === 'yes';
	}

	/**
	 * Whether this account can validate email addresses.
	 *
	 * @return bool
	 */
	public function is_connected_for_validation() {
		return get_option( 'mailhawk_is_connected_for_validation' ) === 'yes';
	}

	/**
	 * Whether the token and public key are set.
	 *
	 * @return bool
	 */
	public function keys_set() {
		return ( $this->access_token && $this->public_key ) || $this->api_key;
	}

	/**
	 * Get an authorization token from MailHawk
	 *
	 * @param $code string
	 *
	 * @return bool|object
	 */
	public function get_token_and_license_key( $code ){

		$data = [
			'code' => $code,
		];

		$response = $this->request( self::$license_sever_url . '/wp-json/mailhawk/token', $data );

		if ( is_wp_error( $response ) ){
			return false;
		}

		return $response->data;
	}

	/**
	 * Get the account status of the connected site.
	 *
	 * @return array|\WP_Error
	 */
	public function get_account_status() {

		$args = [
			'action' => 'get_status'
		];

		$response = $this->request( self::$license_sever_url . '/wp-json/mailhawk/account/', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'smtp'       => [
				// The number of emails they can send in a single billing period.
				'limit'  => $response->status->smtp->limit,
				// The number of emails sent in the billing period.
				'usage'  => $response->status->smtp->usage,
				// Whether the account is active or not, 'yes' or 'no'
				'active' => $response->status->smtp->active,
			],
			'validation' => [
				// The number of emails which can be validated in a single billing period
				'limit'  => $response->status->validation->limit,
				// the number of emails validated within the billing period
				'usage'  => $response->status->validation->usage,
				// Whether the account is active or not 'yes' or 'no'
				'active' => $response->status->validation->active,
			]
		];
	}

	/**
	 * Send a raw email message to the mailhawk API
	 *
	 * @param string $mime_message
	 *
	 * @return true|\WP_Error true if successful, \WP_Error otherwise
	 */
	public function send_raw_email( $mail_from = '', $to = [], $data = '' ) {

		if ( ! $this->is_connected_for_mail() ) {
			return new \WP_Error( 'account_inactive', 'You have not connected your MailHawk account, or your account is currently inactive.' );
		}

		$args = [
			'mail_from' => $mail_from,
			'rcpt_to'   => array_filter( array_values( $to ) ),
			'data'      => base64_encode( $data )
		];

		$response = $this->request( self::$smtp_sever_url . '/api/v1/send/raw', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Validates a given email address
	 *
	 * @param $email_address
	 *
	 * @return array|\WP_Error
	 */
	public function validate_email_address( $email_address ) {

		if ( ! $this->is_connected_for_validation() ) {
			return new \WP_Error( 'account_inactive', 'Your account does not currently support validating emails.' );
		} else if ( ! is_email( $email_address ) ) {
			return new \WP_Error( 'invalid_email', 'The provided address is not a valid email address.' );
		}

		$args = [
			'email_address' => $email_address
		];

		$response = $this->request( self::$validation_sever_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'status' => $response->address->status // Valid or Invalid, maybe another status?
		];
	}

	/**
	 * Send a request to the API
	 *
	 * @param string $endpoint the API endpoint
	 * @param string $body     array the body of the request
	 *
	 * @return \WP_Error|object json object when successful, otherwise a WP_Error
	 */
	public function request( $endpoint = '', $body = '' ) {

		$headers = [
			'Content-Type' => sprintf( 'application/json; charset=%s', get_bloginfo( 'charset' ) ),
		];

		$request = [
			'method'      => 'POST',
			'headers'     => $headers,
			'body'        => wp_json_encode( $body ),
			'data_format' => 'body',
			'sslverify'   => true
		];

		$response = wp_remote_post( $endpoint, $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body =  wp_remote_retrieve_body( $response );
		
		wp_die( $body );

		$json = json_decode( $body );

		$result = false;

		// Postal
		if ( isset_not_empty( $json, 'status' ) ){

			switch ( $json->status ){
				case 'success':
					$result = $json;
					break;
				case 'error':
					$result = new \WP_Error( $json->data->code, $json->data->message );
					break;
			}

		// WordPress Success
		} else if ( isset_not_empty( $json, 'success' ) ) {

			$result = $json;

		// WordPress error
		}

		wp_die( $result );

		return $result;
	}

}