<?php

namespace MailHawk\Abilities\Quarantine;

use MailHawk\Abilities\Ability;
use MailHawk\Classes\Email_Log_Item;
use function MailHawk\is_groundhogg_active;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delete an email that was held for review without sending it
 */
class Reject_Email extends Ability {

	protected const NAME     = 'mailhawk/reject-email';
	protected const CATEGORY = 'mailhawk-quarantine';

	protected const DESTRUCTIVE = true;
	protected const IDEMPOTENT  = false;

	protected function get_args(): array {

		return [
			'label'       => __( 'Reject MailHawk Email', 'mailhawk' ),
			'description' => __( 'Delete an email that was held for review without sending it. This is not undone, the email is gone. Only a held email can be rejected. With unsubscribe_contacts, and Groundhogg active, the contacts it was addressed to are unsubscribed as well, which is the right call for an address that should not be emailed again.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'                   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the held email, see mailhawk/get-quarantine-report.', 'mailhawk' ),
					],
					'unsubscribe_contacts' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also unsubscribe the Groundhogg contacts it was addressed to.', 'mailhawk' ),
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'rejected'     => [ 'type' => 'boolean' ],
					'id'           => [ 'type' => 'integer' ],
					'unsubscribed' => [
						'type'        => 'integer',
						'description' => __( 'How many contacts were unsubscribed.', 'mailhawk' ),
					],
				],
				'required'   => [ 'rejected', 'id', 'unsubscribed' ],
			],
		];
	}

	public function __invoke( $input ) {

		$input = $this->input( $input );
		$id    = absint( $input['id'] ?? 0 );
		$item  = new Email_Log_Item( $id );

		if ( ! $item->exists() ) {
			return $this->error( 'email_not_found', __( 'There is no email with that ID in the log.', 'mailhawk' ), 404 );
		}

		if ( ! $item->is_quarantined() ) {
			return $this->error( 'not_held', __( 'That email is not held for review, so it can not be rejected.', 'mailhawk' ), 409 );
		}

		$unsubscribe = ! empty( $input['unsubscribe_contacts'] );

		// Asked before the email is deleted, so nothing is half done
		if ( $unsubscribe && ! is_groundhogg_active() ) {
			return $this->error( 'groundhogg_inactive', __( 'Contacts can only be unsubscribed when Groundhogg is active. Nothing was rejected.', 'mailhawk' ), 409 );
		}

		$recipients = (array) $item->recipients;

		if ( ! $item->reject() ) {
			return $this->error( 'reject_failed', __( 'The email could not be deleted.', 'mailhawk' ), 500 );
		}

		$unsubscribed = 0;

		if ( $unsubscribe ) {

			foreach ( $recipients as $recipient ) {

				$contact = \Groundhogg\get_contactdata( $recipient );

				if ( \Groundhogg\is_a_contact( $contact ) ) {
					$contact->unsubscribe();
					$unsubscribed ++;
				}
			}
		}

		return [
			'rejected'     => true,
			'id'           => $id,
			'unsubscribed' => $unsubscribed,
		];
	}
}
