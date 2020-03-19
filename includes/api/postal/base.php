<?php

namespace MailHawk\Api\Postal;

use function MailHawk\isset_not_empty;

class Base {

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
	protected static $server_url = 'https://mta.mailhawk.io/api/v1';

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

		$response = wp_remote_post( self::$server_url . '/' . $endpoint, $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		wp_die( $body );

		$json = json_decode( $body );

		$result = false;

		// Postal
		if ( isset_not_empty( $json, 'status' ) ) {

			switch ( $json->status ) {
				case 'success':
					$result = $json;
					break;
				default:
				case 'error':
					$result = new \WP_Error( $json->data->code, $json->data->message );
					break;
			}
		}

		return $result;
	}
}
