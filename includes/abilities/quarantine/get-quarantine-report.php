<?php

namespace MailHawk\Abilities\Quarantine;

use MailHawk\Abilities\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What needs a person's decision in the quarantine, the report at the top of the Overview screen
 */
class Get_Quarantine_Report extends Ability {

	protected const NAME     = 'mailhawk/get-quarantine-report';
	protected const CATEGORY = 'mailhawk-quarantine';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get MailHawk Quarantine Report', 'mailhawk' ),
			'description' => __( 'The emails MailHawk held back for review because they were going to a risky address (one that keeps bouncing, reported email as spam, or looks unlikely to want it): how many are waiting, how many are close to being deleted, the ones closest to it, and how many addresses are trusted, bouncing or blocked. A held email is deleted for good after the quarantine retention period. Release or reject each one with mailhawk/release-email and mailhawk/reject-email.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [
					'expiring_days' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 365,
						'default'     => 7,
						'description' => __( 'A held email counts as expiring when it is deleted within this many days.', 'mailhawk' ),
					],
					'limit'         => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'default'     => 5,
						'description' => __( 'How many held emails to list, the ones closest to being deleted first.', 'mailhawk' ),
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'waiting'        => [ 'type' => 'integer' ],
					'expiring'       => [ 'type' => 'integer' ],
					'expiring_days'  => [ 'type' => 'integer' ],
					'retention_days' => [ 'type' => 'integer' ],
					'items'          => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'         => [ 'type' => 'integer' ],
								'subject'    => [ 'type' => 'string' ],
								'recipients' => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'held_at'    => [
									'type'        => 'string',
									'description' => __( 'In the time zone of the site.', 'mailhawk' ),
								],
								'expires_at' => [
									'type'        => 'string',
									'description' => __( 'When it is deleted if nobody decides, in the time zone of the site.', 'mailhawk' ),
								],
							],
							'required'   => [ 'id', 'subject', 'recipients', 'held_at', 'expires_at' ],
						],
					],
					'blocked'        => [
						'type'        => 'integer',
						'description' => __( 'Addresses that reported email as spam. Email to them is always held.', 'mailhawk' ),
					],
					'bouncing'       => [
						'type'        => 'integer',
						'description' => __( 'Addresses that bounced lately and still count against the next email to them.', 'mailhawk' ),
					],
					'trusted'        => [ 'type' => 'integer' ],
				],
				'required'   => [ 'waiting', 'expiring', 'expiring_days', 'retention_days', 'items', 'blocked', 'bouncing', 'trusted' ],
			],
		];
	}

	public function __invoke( $input ) {

		$input  = $this->input( $input );
		$report = \MailHawk\Quarantine::get_report(
			max( 1, min( 365, absint( $input['expiring_days'] ?? 7 ) ) ),
			max( 1, min( 50, absint( $input['limit'] ?? 5 ) ) )
		);

		// The report counts in seconds since the epoch, with the site's own time read as UTC
		foreach ( $report['items'] as $i => $item ) {
			$report['items'][ $i ]['held_at']    = gmdate( 'Y-m-d H:i:s', $item['held_at'] );
			$report['items'][ $i ]['expires_at'] = gmdate( 'Y-m-d H:i:s', $item['expires_at'] );
		}

		return $report;
	}
}
