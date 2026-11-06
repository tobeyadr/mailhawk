<?php

namespace MailHawk\Abilities\Quarantine;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Classes\Email_Log_Item;
use MailHawk\DB\Recipients;
use MailHawk\Plugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send an email that was held for review, optionally trusting the people it goes to
 */
class Release_Email extends Ability {

	protected const NAME     = 'mailhawk/release-email';
	protected const CATEGORY = 'mailhawk-quarantine';

	protected const DESTRUCTIVE = true;
	protected const IDEMPOTENT  = false;

	protected function get_args(): array {

		return [
			'label'       => __( 'Release MailHawk Email', 'mailhawk' ),
			'description' => __( 'Send an email that was held for review. It goes out right away and can not be called back, so check the recipients and what it says first (mailhawk/get-email). With trust_recipients the people it goes to are trusted, so email to them is not held again unless they bounce. Anyone who reported email as spam is released to but never trusted. Releasing an email that was released already does nothing and is an error.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'                => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the held email, see mailhawk/get-quarantine-report or mailhawk/list-emails with the status quarantine.', 'mailhawk' ),
					],
					'trust_recipients' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'released'    => [ 'type' => 'boolean' ],
					'email'       => Formatter::email_schema(),
					'trusted'     => [
						'type'        => 'array',
						'description' => __( 'The addresses that are trusted now.', 'mailhawk' ),
						'items'       => [ 'type' => 'string' ],
					],
					'not_trusted' => [
						'type'        => 'array',
						'description' => __( 'The addresses that could not be trusted because they reported email as spam.', 'mailhawk' ),
						'items'       => [ 'type' => 'string' ],
					],
				],
				'required'   => [ 'released', 'email', 'trusted', 'not_trusted' ],
			],
		];
	}

	public function __invoke( $input ) {

		$connection = $this->check_connection();

		if ( $connection ) {
			return $connection;
		}

		$input = $this->input( $input );
		$id    = absint( $input['id'] ?? 0 );
		$item  = new Email_Log_Item( $id );

		if ( ! $item->exists() ) {
			return $this->error( 'email_not_found', __( 'There is no email with that ID in the log.', 'mailhawk' ), 404 );
		}

		if ( ! $item->is_quarantined() ) {
			return $this->error( 'not_held', __( 'That email is not held for review. It was released or rejected already.', 'mailhawk' ), 409 );
		}

		$trust   = ! empty( $input['trust_recipients'] );
		$failure = null;

		$catch = function ( $error ) use ( &$failure ) {
			$failure = $error;
		};

		add_action( 'wp_mail_failed', $catch );

		try {
			$released = $trust ? $item->release_and_trust() : $item->release();
		} catch ( \Exception $e ) {
			$released = false;
			$failure  = new WP_Error( 'mailhawk_release_failed', $e->getMessage() );
		} finally {
			remove_action( 'wp_mail_failed', $catch );
		}

		if ( ! $released ) {
			$reason = $failure instanceof WP_Error ? $failure->get_error_message() : __( 'The email could not be sent.', 'mailhawk' );

			// Sending it again is what marks it as failed, so it is no longer held
			return new WP_Error( 'mailhawk_release_failed', $reason . ' ' . __( 'The email is no longer held, it is marked as failed. Use mailhawk/retry-email to try again.', 'mailhawk' ), [ 'status' => 502 ] );
		}

		$trusted     = [];
		$not_trusted = [];

		if ( $trust ) {

			$recipients = Plugin::instance()->recipients;

			foreach ( (array) $item->recipients as $recipient ) {

				$address = $recipients->normalize( $recipient );
				$row     = $address ? $recipients->get_by_email( $address ) : false;

				if ( $row && Recipients::TRUSTED === $row->status ) {
					$trusted[] = $address;
				} else if ( $address ) {
					$not_trusted[] = $address;
				}
			}
		}

		return [
			'released'    => true,
			'email'       => Formatter::email( new Email_Log_Item( $id ) ),
			'trusted'     => $trusted,
			'not_trusted' => $not_trusted,
		];
	}
}
