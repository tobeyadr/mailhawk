<?php

namespace MailHawk\Api\Postal;

use function MailHawk\get_mailhawk_api_key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the MTA knows about emails that were sent through it, so the log can catch up on events that never arrived.
 */
class Messages extends Postal {

	/**
	 * Most message IDs the MTA takes in one request
	 */
	const MAX_IDS = 100;

	/**
	 * What a recipient's status can be, as the MTA reports it
	 */
	const STATUSES = [ 'queued', 'delayed', 'delivered', 'failed', 'held', 'bounced' ];

	/**
	 * Ask the MTA how the emails with these IDs are doing.
	 *
	 * Only the IDs are sent. The result has every ID the MTA answered for as a key, and for each: whether the MTA still has
	 * the email, and the status of each of its recipients ( rcpt_to, status, time, details ).
	 *
	 * Failures are errors with a code that says what to do next:
	 *  - mailhawk_unsupported:  this MTA does not have the endpoint (yet), do not ask again for a good while
	 *  - mailhawk_rate_limited: too many requests, data[ 'retry_after' ] is the seconds to wait
	 *  - mailhawk_rejected:     the key was not accepted, or the server is suspended
	 *  - mailhawk_bad_request:  what was asked was not valid, which is a bug here and not worth a retry
	 *  - mailhawk_unavailable:  no answer, or not one that makes sense
	 *
	 * @param string[] $message_ids between 1 and MAX_IDS IDs, as the MTA gave them
	 *
	 * @return array|\WP_Error
	 */
	public static function statuses( array $message_ids ) {

		$ids = [];

		foreach ( $message_ids as $id ) {
			if ( is_string( $id ) && '' !== $id ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( empty( $ids ) || count( $ids ) > self::MAX_IDS ) {
			return new \WP_Error( 'mailhawk_bad_request', 'Between 1 and ' . self::MAX_IDS . ' message IDs are needed.' );
		}

		if ( empty( self::$api_key ) ) {

			$api_key = get_mailhawk_api_key();

			if ( ! $api_key ) {
				return new \WP_Error( 'mailhawk_rejected', __( 'Invalid API key.', 'mailhawk' ) );
			}

			self::set_api_key( $api_key );
		}

		// Short, this runs in the background and the MTA answers it from one query
		$response = wp_remote_post( self::$server_url . '/api/v1/messages/statuses', [
			'timeout'     => 8,
			'method'      => 'POST',
			'headers'     => [
				'Content-Type'     => 'application/json; charset=utf-8',
				'X-Server-API-Key' => self::$api_key,
			],
			'body'        => wp_json_encode( [ 'message_ids' => $ids ] ),
			'data_format' => 'body',
			'sslverify'   => true,
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'mailhawk_unavailable', $response->get_error_message() );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $json ) && is_array( $json['data'] ?? null ) ? $json['data'] : [];

		if ( 429 === $http ) {

			$retry_after = absint( wp_remote_retrieve_header( $response, 'retry-after' ) );

			if ( ! $retry_after ) {
				$retry_after = absint( $data['retry_after'] ?? 0 );
			}

			return new \WP_Error( 'mailhawk_rate_limited', 'Too many requests.', [ 'retry_after' => $retry_after ? $retry_after : MINUTE_IN_SECONDS ] );
		}

		// An MTA that does not know the action
		if ( 404 === $http ) {
			return new \WP_Error( 'mailhawk_unsupported', 'This MTA can not report the status of emails.' );
		}

		if ( $http >= 400 || ! is_array( $json ) ) {
			return new \WP_Error( 'mailhawk_unavailable', 'The MTA did not answer with a status (HTTP ' . $http . ').' );
		}

		switch ( $json['status'] ?? '' ) {

			case 'success':
				return self::normalize( $data['messages'] ?? null, $ids );

			case 'parameter-error':
				return new \WP_Error( 'mailhawk_bad_request', sanitize_text_field( (string) ( $data['message'] ?? 'The MTA did not accept the request.' ) ) );

			case 'error':

				$code = sanitize_text_field( (string) ( $data['code'] ?? '' ) );

				if ( 'InvalidAPIRequest' === $code ) {
					return new \WP_Error( 'mailhawk_unsupported', 'This MTA can not report the status of emails.' );
				}

				if ( 'RateLimitExceeded' === $code ) {
					return new \WP_Error( 'mailhawk_rate_limited', 'Too many requests.', [ 'retry_after' => absint( $data['retry_after'] ?? 0 ) ?: MINUTE_IN_SECONDS ] );
				}

				if ( in_array( $code, [ 'AccessDenied', 'InvalidServerAPIKey', 'ServerSuspended' ], true ) ) {
					return new \WP_Error( 'mailhawk_rejected', $code );
				}

				return new \WP_Error( 'mailhawk_unavailable', $code ? $code : 'The MTA reported an error.' );
		}

		return new \WP_Error( 'mailhawk_unavailable', 'The MTA did not answer with a status.' );
	}

	/**
	 * Keep from the answer only what was asked about, and only in the shape that is used.
	 *
	 * @param mixed    $messages what the MTA gave for the messages
	 * @param string[] $ids      what was asked
	 *
	 * @return array|\WP_Error
	 */
	protected static function normalize( $messages, array $ids ) {

		if ( ! is_array( $messages ) ) {
			return new \WP_Error( 'mailhawk_unavailable', 'The MTA did not answer with the messages.' );
		}

		$result = [];

		foreach ( $ids as $id ) {

			if ( ! isset( $messages[ $id ] ) || ! is_array( $messages[ $id ] ) ) {
				continue;
			}

			$message = $messages[ $id ];
			$found   = ! empty( $message['found'] );

			$recipients = [];
			$entries    = $found && is_array( $message['recipients'] ?? null ) ? $message['recipients'] : [];

			foreach ( $entries as $recipient ) {

				if ( ! is_array( $recipient ) || ! in_array( $recipient['status'] ?? '', self::STATUSES, true ) ) {
					continue;
				}

				$recipients[] = [
					'rcpt_to' => strtolower( trim( sanitize_text_field( (string) ( $recipient['rcpt_to'] ?? '' ) ) ) ),
					'status'  => $recipient['status'],
					'time'    => absint( $recipient['time'] ?? 0 ),
					'details' => substr( sanitize_text_field( (string) ( $recipient['details'] ?? '' ) ), 0, 500 ),
				];
			}

			$result[ $id ] = [
				'found'      => $found,
				'recipients' => $recipients,
			];
		}

		return $result;
	}
}
