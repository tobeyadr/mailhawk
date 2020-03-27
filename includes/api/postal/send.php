<?php

namespace MailHawk\Api\Postal;

class Send extends Postal {

	/**
	 * Return dummy WP error
	 *
	 * @param string $mail_from
	 * @param array $to
	 * @param string $data
	 *
	 * @return mixed|void
	 */
	public static function raw_error( $mail_from = '', $to = [], $data = '' ) {

		// Dummy error
		return new \WP_Error( 'dummy_error', 'Dummy error message.' );
	}
	/**
	 * Return dummy message ID
	 *
	 * @param string $mail_from
	 * @param array $to
	 * @param string $data
	 *
	 * @return mixed|void
	 */
	public static function raw_success( $mail_from = '', $to = [], $data = '' ) {

		// random string of chars.
		return wp_generate_password( 10, false , false);
	}

	/**
	 * Sends an email, will return a string of the message ID.
	 *
	 * @param string $mail_from
	 * @param array $to
	 * @param string $data
	 *
	 * @return string|\WP_Error
	 */
	public static function raw( $mail_from = '', $to = [], $data = '' ) {

		$args = [
			'mail_from' => $mail_from,
			'rcpt_to'   => array_filter( array_values( $to ) ),
			'data'      => base64_encode( $data )
		];

		$response = self::request( 'send/raw', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return sanitize_text_field( $response->data->message_id );
	}

}