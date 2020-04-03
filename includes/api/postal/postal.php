<?php

namespace MailHawk\Api\Postal;

use function MailHawk\isset_not_empty;
use function MailHawk\set_mailhawk_is_suspended;

class Postal {

	/**
	 * The client's api key...
	 *
	 * @var string
	 */
	protected static $api_key;

	/**
	 * The MTA server URL
	 *
	 * @var string
	 */
	protected static $server_url = 'https://mta01.mailhawk.io';

	/**
	 * @param $key
	 */
	public static function set_api_key( $key ) {
		self::$api_key = $key;
	}

	/**
	 * @return bool
	 */
	public static function has_key() {
		return ! empty( self::$api_key );
	}

	/**
	 * Send a request to the API
	 *
	 * @param string $endpoint the API endpoint
	 * @param string $body     array the body of the request
	 *
	 * @return \WP_Error|object json object when successful, otherwise a WP_Error
	 */
	public static function request( $endpoint = '', $body = '' ) {

		// Try and get from settings, otherwise return an error.
		if ( empty( self::$api_key ) ){
			$api_key = get_option( 'mailhawk_mta_credential_key' );

			if ( ! $api_key ){
				return new \WP_Error( 'error', 'Invalid API key.' );
			}

			self::set_api_key( $api_key );
		}

		$headers = [
			'Content-Type'     => sprintf( 'application/json; charset=%s', get_bloginfo( 'charset' ) ),
			'X-Server-API-Key' => self::$api_key
		];

		$request = [
			'method'      => 'POST',
			'headers'     => $headers,
			'body'        => wp_json_encode( $body ),
			'data_format' => 'body',
			'sslverify'   => true
		];

		$response = wp_remote_post( self::$server_url . '/api/v1/' . $endpoint, $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		$json = json_decode( $body );

//		var_dump( $json );

		$result = new \WP_Error( 'error', 'Unable to complete request.' );

		// Postal
		if ( isset_not_empty( $json, 'status' ) ) {

			switch ( $json->status ) {
				case 'success':
					$result = $json;
					break;
				case 'parameter-error':
					$result = new \WP_Error( 'parameter-error', $json->data->message );
					break;
				case 'error':
					$result = new \WP_Error( $json->data->code, $json->data->message );
					break;
			}
		}

		// Automatically suspend
		if ( is_wp_error( $result ) && 'ServerSuspended' == $result->get_error_code() ){
			set_mailhawk_is_suspended( true );
		}

		return $result;
	}
}
