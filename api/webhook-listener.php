<?php

namespace MailHawk\Api;

use MailHawk\Plugin;
use MailHawk\Utils\Signature_Verifier;
use WP_REST_Response;
use WP_REST_Server;
use function MailHawk\get_webhook_batch_interval;

class Webhook_Listener {

	/**
	 * @var Signature_Verifier
	 */
	protected $verifier;

	public function __construct() {

		$this->verifier = new Signature_Verifier();

		register_rest_route( 'mailhawk', '/listen', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => [ $this, 'verify' ],
				'callback'            => [ $this, 'process' ],
			]
		] );
	}

	/**
	 * Verify the request is from MailHawk
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return bool|\WP_Error
	 */
	public function verify( \WP_REST_Request $request ) {

		$body      = file_get_contents( 'php://input' );
		$signature = (string) $request->get_header( 'x_postal_signature_2' );

		// A request without a signature is not from the MTA
		if ( $signature === '' ) {
			return new \WP_Error( 'error', 'Unable to verify request.' );
		}

		// SHA256
		$verified = $this->verifier->verify( $body, $signature );

		if ( ! $verified ) {
			return new \WP_Error( 'error', 'Unable to verify request.' );
		}

		return true;
	}

	/**
	 * Handle the webhook events...
	 *
	 * The MTA sends either a single event, or an event of type Batch which carries a list of events
	 * (see the batch_interval setting of the webhook).
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_Error|WP_REST_Response
	 */
	public function process( \WP_REST_Request $request ) {

		$event_type = $request->get_param( 'event' );
		$payload    = $request->get_param( 'payload' );

		if ( $event_type !== 'Batch' ) {

			$result = $this->handle_event( $event_type, $payload );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$response = new WP_REST_Response( [ 'success' => true ] );

			// This site can take batches. An MTA that sees this switches the webhook to batched delivery, which is how
			// webhooks created before batching existed catch up. One that doesn't know the header ignores it.
			$interval = get_webhook_batch_interval();

			if ( $interval > 0 ) {
				$response->header( 'X-MailHawk-Accept-Batching', (string) $interval );
			}

			return $response;
		}

		$events = is_array( $payload ) && isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : [];

		$processed = 0;

		foreach ( $events as $event ) {

			if ( ! is_array( $event ) || empty( $event['event'] ) ) {
				continue;
			}

			// A bad event must not fail the whole batch, or the MTA will send all of it again
			$result = $this->handle_event( $event['event'], $event['payload'] ?? [] );

			if ( ! is_wp_error( $result ) ) {
				$processed ++;
			}
		}

		return new WP_REST_Response( [
			'success'   => true,
			'received'  => count( $events ),
			'processed' => $processed,
		] );
	}

	/**
	 * Handle a single event
	 *
	 * @param string $event_type
	 * @param array  $payload
	 *
	 * @return true|\WP_Error
	 */
	protected function handle_event( $event_type, $payload ) {

		if ( ! is_array( $payload ) ) {
			$payload = [];
		}

		switch ( $event_type ):

			case 'MessageSent':
				// Get the message ID
				$msg_id = sanitize_text_field( $payload['message']['message_id'] );

				// If the message was sent from this site, update the status to sent
				if ( Plugin::instance()->log->exists( [ 'msg_id' => $msg_id ] ) ) {

					$log = [
						'status'        => 'delivered',
						'error_code'    => '',
						'error_message' => '',
					];

					Plugin::instance()->log->update( null, $log, [ 'msg_id' => $msg_id ] );
				}

				break;
			case 'MessageBounced':

				// Get the original recipient address
				$to_address = sanitize_email( $payload['original_message']['to'] );

				// do something
				if ( ! is_email( $to_address ) ) {
					return new \WP_Error( 'invalid_email', 'The provided email address is invalid.' );
				}

				// Get the message ID
				$msg_id = sanitize_text_field( $payload['original_message']['message_id'] );

				// If the message was sent from this site, update the status to bounced
				if ( Plugin::instance()->log->exists( [ 'msg_id' => $msg_id ] ) ) {

					$log = [
						'status'        => 'bounced',
						'error_code'    => 'Bounced',
						'error_message' => __( 'This email could not be delivered and bounced back.', 'mailhawk' )
					];

					Plugin::instance()->log->update( null, $log, [ 'msg_id' => $msg_id ] );
				}

				/**
				 * Message bounced
				 *
				 * @param $to_address string email recipient
				 * @param $msg_id     string the msg_id of the email
				 */
				do_action( 'mailhawk/bounced', $to_address, $msg_id );

				break;
			case 'MessageDeliveryFailed':

				// Get the message ID
				$msg_id    = sanitize_text_field( $payload['message']['message_id'] );
				$error_msg = sanitize_text_field( $payload['details'] );

				// If the message was sent from this site, update the status to failed
				if ( Plugin::instance()->log->exists( [ 'msg_id' => $msg_id ] ) ) {

					$log = [
						'status'        => 'failed',
						'error_code'    => 'MessageDeliveryFailed',
						'error_message' => $error_msg
					];

					Plugin::instance()->log->update( null, $log, [ 'msg_id' => $msg_id ] );
				}

				if ( $payload['status'] === 'HardFail' ){

					// Get the recipient address
					$to_address = sanitize_email( $payload['message']['to'] );

					/**
					 * Message bounced
					 *
					 * @param $to_address string email recipient
					 * @param $msg_id     string the msg_id of the email
					 */
					do_action( 'mailhawk/bounced', $to_address, $msg_id );
				}

				break;
			case 'MessageDelayed':

				// Get the message ID
				$msg_id = sanitize_text_field( $payload['message']['message_id'] );

				// If the message was sent from this site, update the status to failed
				if ( Plugin::instance()->log->exists( [ 'msg_id' => $msg_id ] ) ) {

					$log = [
						'status'        => strtolower( sanitize_text_field( $payload['status'] ) ),
						'error_code'    => 'MessageDelayed',
						'error_message' => sanitize_text_field( $payload['details'] )
					];

					Plugin::instance()->log->update( null, $log, [ 'msg_id' => $msg_id ] );
				}

				break;
			case 'MessageSpamComplaint':

				// Get the recipient address
				$to_address = sanitize_email( $payload['message']['to'] ?? '' );

				if ( ! is_email( $to_address ) ) {
					return new \WP_Error( 'invalid_email', 'The provided email address is invalid.' );
				}

				$msg_id = sanitize_text_field( $payload['message']['message_id'] ?? '' );

				// If the message was sent from this site, mark it as complained about
				if ( $msg_id && Plugin::instance()->log->exists( [ 'msg_id' => $msg_id ] ) ) {

					$log = [
						'status'        => 'complained',
						'error_code'    => 'SpamComplaint',
						'error_message' => __( 'The recipient reported this email as spam.', 'mailhawk' )
					];

					Plugin::instance()->log->update( null, $log, [ 'msg_id' => $msg_id ] );
				}

				$complaint = is_array( $payload['complaint'] ?? null ) ? $payload['complaint'] : [];

				/**
				 * A recipient reported an email as spam
				 *
				 * @param $to_address string email recipient
				 * @param $msg_id     string the msg_id of the email
				 * @param $complaint  array details about the report (feedback_type, reported_by, source_ip, arrival_date, received_at)
				 */
				do_action( 'mailhawk/spam_complaint', $to_address, $msg_id, $complaint );

				break;
			case 'DomainDNSError':

				break;
		endswitch;

		return true;
	}

}
