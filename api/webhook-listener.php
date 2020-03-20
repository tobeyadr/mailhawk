<?php

namespace MailHawk\Api;

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

			case 'MessageBounced':

				$to_address = sanitize_email( $payload->original_message->to );

				// do something
				if ( ! is_email( $to_address ) ) {
					return new \WP_Error( 'invalid_email', 'The provided email address is invalid.' );
				}

				Plugin::instance()->emails->add( [
					'email'  => $to_address,
					'status' => 'bounced'
				] );

				break;
			case 'MessageDeliveryFailed':

				$to_address = sanitize_email( $payload->message->to );

				// do something
				if ( ! is_email( $to_address ) ) {
					return new \WP_Error( 'invalid_email', 'The provided email address is invalid.' );
				}

				Plugin::instance()->emails->add( [
					'email'  => $to_address,
					'status' => 'failed'
				] );

				break;
			case 'DomainDNSError':

				break;
		endswitch;

		return new WP_REST_Response( [ 'success' => true ] );

	}

}
