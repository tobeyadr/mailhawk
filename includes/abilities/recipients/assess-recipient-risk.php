<?php

namespace MailHawk\Abilities\Recipients;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\DB\Recipients;
use MailHawk\Plugin;
use function MailHawk\assess_risk;
use function MailHawk\get_email_address_hostname;
use function MailHawk\get_inbox_risk;
use function MailHawk\get_quarantine_threshold;
use function MailHawk\is_free_email_provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether email to an address would be held for review, and why
 */
class Assess_Recipient_Risk extends Ability {

	protected const NAME     = 'mailhawk/assess-recipient-risk';
	protected const CATEGORY = 'mailhawk-recipients';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		$recipient         = Formatter::recipient_schema();
		$recipient['type'] = [ 'object', 'null' ];

		return [
			'label'       => __( 'Assess MailHawk Recipient Risk', 'mailhawk' ),
			'description' => __( 'Whether an email to this address would be held for review right now, with the score and what it is made of: a record of bounces or spam reports, a free email provider, a user of the site, and with Groundhogg the engagement of the contact. An email is held when the score reaches the threshold. Nothing is sent.', 'mailhawk' ),

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
					'email'     => [ 'type' => 'string' ],
					'risk'      => [ 'type' => 'integer' ],
					'threshold' => [ 'type' => 'integer' ],
					'held'      => [
						'type'        => 'boolean',
						'description' => __( 'True when email to this address is held for review.', 'mailhawk' ),
					],
					'factors'   => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'code'    => [ 'type' => 'string' ],
								'points'  => [ 'type' => 'integer' ],
								'message' => [ 'type' => 'string' ],
							],
							'required'   => [ 'code', 'points', 'message' ],
						],
					],
					'recipient' => $recipient,
				],
				'required'   => [ 'email', 'risk', 'threshold', 'held', 'factors', 'recipient' ],
			],
		];
	}

	public function __invoke( $input ) {

		$recipients = Plugin::instance()->recipients;
		$email      = $recipients->normalize( $this->input( $input )['email'] ?? '' );

		if ( ! $email ) {
			return $this->error( 'invalid_email', __( 'That is not an email address.', 'mailhawk' ) );
		}

		$risk      = assess_risk( $email );
		$threshold = get_quarantine_threshold();
		$row       = $recipients->get_by_email( $email );

		// The same steps assess_risk() takes, said out loud. Whatever is left over comes from the rules filtered in.
		$factors = [];
		$add     = function ( $code, $points, $message ) use ( &$factors ) {
			$factors[] = [ 'code' => $code, 'points' => $points, 'message' => $message ];
		};

		if ( str_contains( $email, '+test-quarantine@' ) ) {

			$add( 'test_address', $risk, __( 'This is the address used to test the quarantine, email to it is always held.', 'mailhawk' ) );

		} else if ( $email === get_option( 'admin_email' ) ) {

			$add( 'admin', 0, __( 'This is the admin email address of the site.', 'mailhawk' ) );

		} else if ( get_email_address_hostname( $email ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {

			$add( 'site_domain', 0, __( 'This address is on the domain of the site.', 'mailhawk' ) );

		} else if ( $row && Recipients::TRUSTED === $row->status ) {

			$add( 'trusted', 0, __( 'An administrator trusted this address, email to it is never held.', 'mailhawk' ) );

		} else if ( $row && Recipients::BLOCKED === $row->status ) {

			$add( 'reported_spam', $risk, __( 'The person reported email from this site as spam, email to them is always held.', 'mailhawk' ) );

		} else {

			$known = 0;

			if ( $row && $bounce_risk = $recipients->get_bounce_risk( $row ) ) {
				$known += $bounce_risk;
				$add( 'bounces', $bounce_risk, sprintf( _n( 'This address bounced %s time lately.', 'This address bounced %s times lately.', absint( $row->bounces ), 'mailhawk' ), number_format_i18n( absint( $row->bounces ) ) ) );
			}

			if ( is_free_email_provider( $email ) && $inbox_risk = get_inbox_risk( $email ) ) {
				$known += $inbox_risk;
				$add( 'free_provider', $inbox_risk, __( 'This address is on a free email provider.', 'mailhawk' ) );
			}

			if ( email_exists( $email ) ) {
				$known --;
				$add( 'site_user', - 1, __( 'This address belongs to a user of the site.', 'mailhawk' ) );
			}

			if ( $risk !== $known ) {
				$add( 'other_rules', $risk - $known, __( 'Other rules add to the score, for example the engagement of the contact in Groundhogg.', 'mailhawk' ) );
			}
		}

		return [
			'email'     => $email,
			'risk'      => $risk,
			'threshold' => $threshold,
			'held'      => $risk >= $threshold,
			'factors'   => $factors,
			'recipient' => $row ? Formatter::recipient( $row ) : null,
		];
	}
}
