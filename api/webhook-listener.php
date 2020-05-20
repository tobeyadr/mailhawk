<?php

namespace MailHawk\Api;

use MailHawk\Classes\Email_Log_Item;
use MailHawk\Plugin;
use MailHawk\Utils\Signature_Verifier;
use WP_REST_Response;
use WP_REST_Server;

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
		$signature = $_SERVER['HTTP_X_POSTAL_SIGNATURE'];

		$verified = $this->verifier->verify( $body, $signature );

		if ( ! $verified ) {
			return new \WP_Error( 'error', 'Unable to verify request.' );
		}

		return true;
	}

	/**
	 * Handle the webhook events...
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_Error|WP_REST_Response
	 */
	public function process( \WP_REST_Request $request ) {

		$event_type = $request->get_param( 'event' );
		$payload    = $request->get_param( 'payload' );

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

				// Add email address to blacklist
				Plugin::instance()->emails->add( [
					'email'  => $to_address,
					'status' => 'bounced'
				] );

				// Get the message ID
				$msg_id = sanitize_text_field( $payload['original_message']['message_id'] );

				// If the message was sent from this site, update the status to bounced
				if ( Plugin::instance()->log->exists( [ 'msg_id' => $msg_id ] ) ) {

					$log = [
						'status'        => 'bounced',
						'error_code'    => 'bounced',
						'error_message' => __( 'This email could not be delivered and bounced back.', 'mailhawk' )
					];

					Plugin::instance()->log->update( null, $log, [ 'msg_id' => $msg_id ] );
				}

				/**
				 * Message bounced
				 *
				 * @param $to_address string email recipient
				 * @param $msg_id string the msg_id of the email
				 */
				do_action( 'mailhawk/bounced', $to_address, $msg_id );

				break;
			case 'MessageDeliveryFailed':

				// Get the message ID
				$msg_id = sanitize_text_field( $payload['message']['message_id'] );

				// If the message was sent from this site, update the status to failed
				if ( Plugin::instance()->log->exists( [ 'msg_id' => $msg_id ] ) ) {

					$log = [
						'status'        => 'failed',
						'error_code'    => 'MessageDeliveryFailed',
						'error_message' => __( 'This email could not be delivered.', 'mailhawk' )
					];

					Plugin::instance()->log->update( null, $log, [ 'msg_id' => $msg_id ] );
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
			case 'DomainDNSError':

				break;
		endswitch;

		return new WP_REST_Response( [ 'success' => true ] );

	}

}
