<?php

namespace MailHawk\Abilities\Email;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Classes\Email_Log_Item;
use MailHawk\Plugin;
use WP_Error;
use function MailHawk\assess_risk;
use function MailHawk\get_quarantine_threshold;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send an email from the log again, the Retry and Resend links of the log screen
 */
class Retry_Email extends Ability {

	protected const NAME     = 'mailhawk/retry-email';
	protected const CATEGORY = 'mailhawk-email';

	protected const DESTRUCTIVE = true;
	protected const IDEMPOTENT  = false;

	protected function get_args(): array {

		return [
			'label'       => __( 'Retry MailHawk Email', 'mailhawk' ),
			'description' => __( 'Send an email from the log again to the same recipients: a failed, bounced or soft failed one that should have arrived, or one that was sent already and has to be sent once more. Whoever it was sent to gets it again, so a delivered email is a duplicate. An email held for review is not retried, release it with mailhawk/release-email. Neither is one to a recipient who would be held for review now, like someone who reported spam or an address that keeps bouncing: sending again skips that check.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the email in the log, see mailhawk/list-emails.', 'mailhawk' ),
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'retried' => [ 'type' => 'boolean' ],
					'email'   => Formatter::email_schema(),
				],
				'required'   => [ 'retried', 'email' ],
			],
		];
	}

	public function __invoke( $input ) {

		$connection = $this->check_connection();

		if ( $connection ) {
			return $connection;
		}

		$id   = absint( $this->input( $input )['id'] ?? 0 );
		$item = new Email_Log_Item( $id );

		if ( ! $item->exists() ) {
			return $this->error( 'email_not_found', __( 'There is no email with that ID in the log. It may have been deleted when the log was cleaned up.', 'mailhawk' ), 404 );
		}

		if ( $item->is_quarantined() ) {
			return $this->error( 'email_held', __( 'That email is held for review. Release it with mailhawk/release-email, or reject it with mailhawk/reject-email.', 'mailhawk' ), 409 );
		}

		// Sending an email from the log again skips the review that new email gets. It must not be a way around it for
		// somebody who has bounced or reported spam since, they are what that review protects the sending IPs from.
		foreach ( (array) $item->recipients as $recipient ) {

			$address = Plugin::instance()->recipients->normalize( (string) $recipient );

			if ( $address && assess_risk( $address ) >= get_quarantine_threshold() ) {
				return $this->error( 'recipient_at_risk', sprintf( __( 'Email to %s would be held for review now, so it can not be sent again from here. A person reported spam or the address keeps bouncing.', 'mailhawk' ), $address ), 409 );
			}
		}

		$failure = null;

		$catch = function ( $error ) use ( &$failure ) {
			$failure = $error;
		};

		add_action( 'wp_mail_failed', $catch );

		try {
			$sent = $item->retry();
		} catch ( \Exception $e ) {
			$sent    = false;
			$failure = new WP_Error( 'mailhawk_retry_failed', $e->getMessage() );
		} finally {
			remove_action( 'wp_mail_failed', $catch );
		}

		if ( ! $sent ) {
			return new WP_Error( 'mailhawk_retry_failed', $failure instanceof WP_Error ? $failure->get_error_message() : __( 'The email could not be sent.', 'mailhawk' ), [ 'status' => 502 ] );
		}

		return [
			'retried' => true,
			'email'   => Formatter::email( new Email_Log_Item( $id ) ),
		];
	}
}
