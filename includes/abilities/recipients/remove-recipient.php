<?php

namespace MailHawk\Abilities\Recipients;

use MailHawk\Abilities\Ability;
use MailHawk\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forget what MailHawk has learned about an address
 */
class Remove_Recipient extends Ability {

	protected const NAME     = 'mailhawk/remove-recipient';
	protected const CATEGORY = 'mailhawk-recipients';

	protected const DESTRUCTIVE = true;
	protected const IDEMPOTENT  = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Remove MailHawk Recipient', 'mailhawk' ),
			'description' => __( 'Forget what MailHawk knows about an address: that it was trusted, or that it bounced. It is treated like any other address afterwards, so email to a bouncing one is no longer held. Refused for a person who reported email from the site as spam, forgetting that would make MailHawk willing to email someone who asked not to be emailed. Removing an address that has no record is not an error.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'email' ],
				'properties'           => [
					'email' => [
						'type'      => 'string',
						'minLength' => 1,
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'removed' => [
						'type'        => 'boolean',
						'description' => __( 'False when MailHawk had no record of the address.', 'mailhawk' ),
					],
					'email'   => [ 'type' => 'string' ],
				],
				'required'   => [ 'removed', 'email' ],
			],
		];
	}

	public function __invoke( $input ) {

		$recipients = Plugin::instance()->recipients;
		$email      = $recipients->normalize( $this->input( $input )['email'] ?? '' );

		if ( ! $email ) {
			return $this->error( 'invalid_email', __( 'That is not an email address.', 'mailhawk' ) );
		}

		$row = $recipients->get_by_email( $email );

		if ( ! $row ) {
			return [ 'removed' => false, 'email' => $email ];
		}

		if ( $recipients->has_reported_spam( $row ) ) {
			return $this->error( 'reported_spam', __( 'That person reported email from this site as spam. The record is kept so they are not emailed again.', 'mailhawk' ), 409 );
		}

		if ( ! $recipients->delete( $row->ID ) ) {
			return $this->error( 'remove_failed', __( 'The address could not be removed.', 'mailhawk' ), 500 );
		}

		return [ 'removed' => true, 'email' => $email ];
	}
}
