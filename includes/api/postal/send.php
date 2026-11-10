<?php

namespace MailHawk\Api\Postal;

use function MailHawk\clear_mailhawk_is_throttled;
use function MailHawk\mailhawk_is_throttled;
use function MailHawk\set_mailhawk_is_throttled;

class Send extends Postal {

	/**
	 * Whether the last call to raw() was turned away by the MTA with a 429
	 *
	 * @since 1.5.1
	 *
	 * @var bool
	 */
	public static $last_rate_limited = false;

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

		self::$last_rate_limited = false;

		$args = [
			'mail_from' => $mail_from,
			'rcpt_to'   => array_filter( array_values( $to ) ),
			'data'      => base64_encode( $data )
		];

		$response = self::request( 'send/raw', $args );

		if ( is_wp_error( $response ) ) {

			// Throttled. The email is not sent, and what is sent after it should know to leave the MTA alone for a while.
			if ( 'mailhawk_rate_limited' === $response->get_error_code() ) {
				self::$last_rate_limited = true;
				$data = (array) $response->get_error_data();
				set_mailhawk_is_throttled( $data['retry_after'] ?? 0 );
			}

			return $response;
		}

		// Email is going through again
		if ( mailhawk_is_throttled() ) {
			clear_mailhawk_is_throttled();
		}

		return sanitize_text_field( $response->data->message_id );
	}

}