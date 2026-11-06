<?php

namespace MailHawk\Api;

use MailHawk\Plugin;
use MailHawk\Utils\Signature_Verifier;
use WP_REST_Response;
use WP_REST_Server;
use function MailHawk\get_webhook_batch_interval;

class Webhook_Listener {

	/**
	 * How far an email has got, the further one wins. Events are not delivered in the order they happened: they are
	 * batched, and the MTA sends them again when this site was slow to answer. A delay that arrives after the delivery
	 * must not make a delivered email look held up, and a delivery must not hide a bounce or a spam report.
	 *
	 * @var int[]
	 */
	const DELIVERY_STAGES = [
		'sent'       => 0,
		'softfail'   => 1,
		'delivered'  => 2,
		'failed'     => 3,
		'bounced'    => 4,
		'complained' => 5,
	];

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
	 * Put what the MTA reported on the email in the log, when it is further along than what the log says
	 *
	 * @param string $msg_id        the ID the MTA gave the email
	 * @param string $status        one of the keys of DELIVERY_STAGES
	 * @param string $error_code
	 * @param string $error_message
	 *
	 * @return bool|null see advance_delivery()
	 */
	protected function record_delivery( $msg_id, $status, $error_code, $error_message ) {
		return self::advance_delivery( $msg_id, $status, $error_code, $error_message );
	}

	/**
	 * The same, for anything that learns what became of an email other than from an event, like the sync that catches up
	 * on events that never arrived. The rule is the same for both: the further along status wins.
	 *
	 * @param string $msg_id
	 * @param string $status
	 * @param string $error_code
	 * @param string $error_message
	 *
	 * @return bool|null true when the email got further than it was, so this is the one to act on it, like announcing a
	 *                   bounce. False when it did not: it was as far along already, or further, or somebody else moved it
	 *                   on first. Null when there is no entry in the log to tell by, because the email was not logged or
	 *                   the entry is gone.
	 */
	public static function advance_delivery( $msg_id, $status, $error_code, $error_message ) {

		global $wpdb;

		if ( '' === $msg_id ) {
			return null;
		}

		$log = Plugin::instance()->log;
		$row = $log->get_by( 'msg_id', $msg_id );

		// Not sent from this site, or not in the log any more
		if ( ! $row ) {
			return null;
		}

		$current = self::DELIVERY_STAGES[ $row->status ] ?? 0;
		$next    = self::DELIVERY_STAGES[ $status ];

		if ( $next < $current ) {
			return false;
		}

		// Only if the email is still where it was when it was read. Of two requests that read it at the same time, such as
		// an event and the sync, only one changes it, and that one is the one that gets to say it moved on.
		$changed = $wpdb->update( $log->get_table_name(), [
			'status'        => $status,
			'error_code'    => $error_code,
			'error_message' => $error_message,
		], [
			'ID'     => $row->ID,
			'status' => $row->status,
		] );

		if ( ! $changed ) {
			return false;
		}

		$log->set_last_changed();

		return $next > $current;
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
				$msg_id = sanitize_text_field( $payload['message']['message_id'] ?? '' );

				// If the message was sent from this site, update the status to delivered
				$this->record_delivery( $msg_id, 'delivered', '', '' );

				break;
			case 'MessageBounced':

				// Get the original recipient address
				$to_address = sanitize_email( $payload['original_message']['to'] ?? '' );

				// do something
				if ( ! is_email( $to_address ) ) {
					return new \WP_Error( 'invalid_email', 'The provided email address is invalid.' );
				}

				// Get the message ID
				$msg_id = sanitize_text_field( $payload['original_message']['message_id'] ?? '' );

				// If the message was sent from this site, update the status to bounced
				$moved = $this->record_delivery( $msg_id, 'bounced', 'Bounced', __( 'This email could not be delivered and bounced back.', 'mailhawk' ) );

				// Only if the sync, or an event sent twice, has not got to it first
				if ( false !== $moved ) {

					/**
					 * Message bounced
					 *
					 * @param $to_address string email recipient
					 * @param $msg_id     string the msg_id of the email
					 */
					do_action( 'mailhawk/bounced', $to_address, $msg_id );
				}

				break;
			case 'MessageDeliveryFailed':

				// Get the message ID
				$msg_id    = sanitize_text_field( $payload['message']['message_id'] ?? '' );
				$error_msg = sanitize_text_field( $payload['details'] ?? '' );

				$hard = ( $payload['status'] ?? '' ) === 'HardFail';

				// If the message was sent from this site, update the status to failed. A soft failure is not the end of it, the
				// MTA tries again, so it only holds it up. If it was failed for good, it can still be, and that is what is announced.
				$moved = $this->record_delivery( $msg_id, $hard ? 'failed' : 'softfail', 'MessageDeliveryFailed', $error_msg );

				// Only if the sync, or an event sent twice, has not got to it first
				if ( $hard && false !== $moved ) {

					// Get the recipient address
					$to_address = sanitize_email( $payload['message']['to'] ?? '' );

					/** This action is documented above */
					do_action( 'mailhawk/bounced', $to_address, $msg_id );
				}

				break;
			case 'MessageDelayed':

				// Get the message ID
				$msg_id = sanitize_text_field( $payload['message']['message_id'] ?? '' );

				// The MTA is still trying, so the email is only held up, whatever else the MTA calls it
				$this->record_delivery( $msg_id, 'softfail', 'MessageDelayed', sanitize_text_field( $payload['details'] ?? '' ) );

				break;
			case 'MessageSpamComplaint':

				// Get the recipient address
				$to_address = sanitize_email( $payload['message']['to'] ?? '' );

				if ( ! is_email( $to_address ) ) {
					return new \WP_Error( 'invalid_email', 'The provided email address is invalid.' );
				}

				$msg_id = sanitize_text_field( $payload['message']['message_id'] ?? '' );

				// If the message was sent from this site, mark it as complained about
				$this->record_delivery( $msg_id, 'complained', 'SpamComplaint', __( 'The recipient reported this email as spam.', 'mailhawk' ) );

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
