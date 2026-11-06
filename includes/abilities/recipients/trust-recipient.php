<?php

namespace MailHawk\Abilities\Recipients;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trust an address so email to it is never held for review
 */
class Trust_Recipient extends Ability {

	protected const NAME     = 'mailhawk/trust-recipient';
	protected const CATEGORY = 'mailhawk-recipients';

	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Trust MailHawk Recipient', 'mailhawk' ),
			'description' => __( 'Trust an email address so email to it is never held for review. A bounce takes the trust away again. Refused for a person who reported email from the site as spam, they asked not to be emailed. Trusting an address that is trusted already is not an error.', 'mailhawk' ),

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
					'trusted'   => [ 'type' => 'boolean' ],
					'recipient' => Formatter::recipient_schema(),
				],
				'required'   => [ 'trusted', 'recipient' ],
			],
		];
	}

	public function __invoke( $input ) {

		$recipients = Plugin::instance()->recipients;
		$email      = $recipients->normalize( $this->input( $input )['email'] ?? '' );

		if ( ! $email ) {
			return $this->error( 'invalid_email', __( 'That is not an email address.', 'mailhawk' ) );
		}

		if ( ! $recipients->is_ready() ) {
			return $this->error( 'table_missing', __( 'The recipients table has not been created yet. Run the database update from the notice on the Plugins or Dashboard screen.', 'mailhawk' ), 409 );
		}

		$row = $recipients->get_by_email( $email );

		if ( $row && absint( $row->complaints ) > 0 ) {
			return $this->error( 'reported_spam', __( 'That person reported email from this site as spam, so the address can not be trusted.', 'mailhawk' ), 409 );
		}

		if ( ! $recipients->trust( $email, 'manual' ) ) {
			return $this->error( 'trust_failed', __( 'The address could not be trusted.', 'mailhawk' ), 500 );
		}

		return [
			'trusted'   => true,
			'recipient' => Formatter::recipient( $recipients->get_by_email( $email ) ),
		];
	}
}
