<?php

namespace MailHawk\Api\Postal;

use function MailHawk\get_mailhawk_api_key;
use function MailHawk\isset_not_empty;
use function MailHawk\set_mailhawk_is_suspended;
use function MailHawk\set_mailhawk_suspension;

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
			$api_key = get_mailhawk_api_key();

			if ( ! $api_key ){
				return new \WP_Error( 'error', __( 'Invalid API key.', 'mailhawk' ) );
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

		// Not a failure, the MTA is asking the site to slow down, and how long to wait
		if ( 429 === (int) wp_remote_retrieve_response_code( $response ) ) {

			$retry_after = absint( wp_remote_retrieve_header( $response, 'retry-after' ) );

			if ( ! $retry_after && isset( $json->data->retry_after ) ) {
				$retry_after = absint( $json->data->retry_after );
			}

			return new \WP_Error(
				'mailhawk_rate_limited',
				__( "MailHawk is slowing down email from this site, usually because the plan's monthly sending limit has been reached. Upgrade the plan, or wait for the limit to reset.", 'mailhawk' ),
				[ 'retry_after' => $retry_after ? $retry_after : 5 * MINUTE_IN_SECONDS ]
			);
		}

		$result = new \WP_Error( 'error', __( 'Unable to complete request.', 'mailhawk' ) );

		// Postal
		if ( isset_not_empty( $json, 'status' ) ) {

			switch ( $json->status ) {
				case 'success':
					$result = $json;
					break;
				case 'parameter-error':
					$result = new \WP_Error( 'parameter-error', $json->data->message ?? __( 'Invalid request.', 'mailhawk' ) );
					break;
				case 'error':
					$data = $json->data ?? null;
					$code = isset_not_empty( $data, 'code' ) ? (string) $data->code : 'error';

					// Older MTA builds give no message with ServerSuspended. The data is kept, it says why.
					if ( isset_not_empty( $data, 'message' ) ) {
						$message = (string) $data->message;
					} else if ( 'ServerSuspended' === $code ) {
						$message = __( 'MailHawk has suspended this account.', 'mailhawk' );
					} else {
						/* translators: %s: error code returned by MailHawk */
						$message = sprintf( __( 'MailHawk returned an error: %s', 'mailhawk' ), $code );
					}

					$result = new \WP_Error( $code, $message, $data );
					break;
			}
		}

		// Automatically suspend
		if ( is_wp_error( $result ) && 'ServerSuspended' == $result->get_error_code() ){
			set_mailhawk_is_suspended( true );
			set_mailhawk_suspension( $result->get_error_data() );
		}

		return $result;
	}
}
