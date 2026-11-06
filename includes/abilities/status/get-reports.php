<?php

namespace MailHawk\Abilities\Status;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Api\Postal\Reporting;
use function MailHawk\overview_ranges;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The numbers on the Overview screen: sent, bounces, complaints and delivery rate for a range, next to the range
 * before it, and the usage of the plan.
 */
class Get_Reports extends Ability {

	protected const NAME     = 'mailhawk/get-reports';
	protected const CATEGORY = 'mailhawk-status';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		$totals = [
			'type'       => 'object',
			'properties' => [
				'sent'          => [ 'type' => 'integer' ],
				'bounces'       => [ 'type' => 'integer' ],
				'complaints'    => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Null when the MailHawk server does not report spam complaints.', 'mailhawk' ),
				],
				'bounce_rate'   => [
					'type'        => [ 'number', 'null' ],
					'description' => __( 'Bounces as a percentage of what was sent. Null when nothing was sent.', 'mailhawk' ),
				],
				'delivery_rate' => [
					'type'        => 'number',
					'description' => __( 'The percentage of what was sent that did not bounce, 100 when nothing was sent.', 'mailhawk' ),
				],
			],
			'required'   => [ 'sent', 'bounces', 'complaints', 'bounce_rate', 'delivery_rate' ],
		];

		return [
			'label'       => __( 'Get MailHawk Reports', 'mailhawk' ),
			'description' => __( 'How much email was sent, bounced and reported as spam over today, the last 7, 14 or 30 days, the same numbers as the Overview screen. Includes the range just before it to compare with, a series to chart, and how much of the monthly allowance is used.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [
					'range' => [
						'type'        => [ 'string', 'integer' ],
						'enum'        => [ 'today', '7', '14', '30', 7, 14, 30 ],
						'default'     => '7',
						'description' => __( 'today (by the hour), or the number of days (by the day).', 'mailhawk' ),
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'range'    => [ 'type' => 'string' ],
					'interval' => [
						'type' => 'string',
						'enum' => [ 'hourly', 'daily' ],
					],
					'current'  => $totals,
					'previous' => $totals,
					'change'   => [
						'type'        => 'object',
						'description' => __( 'The change from the previous range, as a percentage. Null when the previous range had none to compare with.', 'mailhawk' ),
						'properties'  => [
							'sent'       => [ 'type' => [ 'number', 'null' ] ],
							'bounces'    => [ 'type' => [ 'number', 'null' ] ],
							'complaints' => [ 'type' => [ 'number', 'null' ] ],
						],
					],
					'series'   => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'label'      => [ 'type' => 'string' ],
								'sent'       => [ 'type' => 'integer' ],
								'bounces'    => [ 'type' => 'integer' ],
								'complaints' => [ 'type' => [ 'integer', 'null' ] ],
							],
							'required'   => [ 'label', 'sent', 'bounces', 'complaints' ],
						],
					],
					'usage'    => Formatter::usage_schema(),
				],
				'required'   => [ 'range', 'interval', 'current', 'previous', 'change', 'series', 'usage' ],
			],
		];
	}

	/**
	 * @param object[]   $data
	 * @param bool       $has_complaints
	 *
	 * @return array
	 */
	protected function totals( array $data, bool $has_complaints ): array {

		$sent       = 0;
		$bounces    = 0;
		$complaints = 0;

		foreach ( $data as $datum ) {
			$sent       += absint( $datum->outgoing ?? 0 );
			$bounces    += absint( $datum->bounces ?? 0 );
			$complaints += $has_complaints ? absint( $datum->complaints ?? 0 ) : 0;
		}

		$bounce_rate = $sent > 0 ? round( $bounces / $sent * 100, 2 ) : null;

		return [
			'sent'          => $sent,
			'bounces'       => $bounces,
			'complaints'    => $has_complaints ? $complaints : null,
			'bounce_rate'   => $bounce_rate,
			// Bounces are part of what was sent, the MTA counts every message it accepts as sent
			'delivery_rate' => null === $bounce_rate ? 100.0 : round( 100 - $bounce_rate, 2 ),
		];
	}

	/**
	 * @param int|null $current
	 * @param int|null $previous
	 *
	 * @return float|null
	 */
	protected function change( $current, $previous ): ?float {
		return $previous ? round( ( $current - $previous ) / $previous * 100, 1 ) : null;
	}

	public function __invoke( $input ) {

		$connection = $this->check_connection();

		if ( $connection ) {
			return $connection;
		}

		$input = $this->input( $input );
		$key   = (string) ( $input['range'] ?? '7' );
		$range = overview_ranges()[ $key ] ?? null;

		if ( ! $range ) {
			return $this->error( 'invalid_range', __( 'The range has to be today, 7, 14 or 30.', 'mailhawk' ) );
		}

		$periods = $range['periods'];

		$data = Reporting::query( $periods * 2, $range['type'] );

		if ( is_wp_error( $data ) ) {
			return $this->service_error( $data );
		}

		if ( ! isset( $data->graph_data ) || ! is_array( $data->graph_data ) ) {
			return $this->error( 'no_data', __( 'MailHawk did not return any numbers.', 'mailhawk' ), 502 );
		}

		// The usage is a second request, the numbers are worth having without it
		$limits = Reporting::limits();

		$current  = array_slice( $data->graph_data, - $periods );
		$previous = array_slice( $data->graph_data, 0, - $periods );

		// Older MTA builds don't report complaints, which is not the same as none
		$has_complaints = isset( $data->graph_data[0]->complaints );

		$current_totals  = $this->totals( $current, $has_complaints );
		$previous_totals = $this->totals( $previous, $has_complaints );

		// Labelled back from the last period, the way the Overview does it
		$hourly = 'hourly' === $range['type'];
		$time   = strtotime( (string) ( $data->last_date ?? '' ) ) ?: time();
		$labels = [];

		for ( $i = 0; $i < count( $current ); $i ++ ) {
			$labels[] = wp_date( $hourly ? 'Y-m-d H:i' : 'Y-m-d', $time );
			$time     -= $hourly ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		}

		$labels = array_reverse( $labels );
		$series = [];

		foreach ( $current as $i => $datum ) {
			$series[] = [
				'label'      => $labels[ $i ],
				'sent'       => absint( $datum->outgoing ?? 0 ),
				'bounces'    => absint( $datum->bounces ?? 0 ),
				'complaints' => $has_complaints ? absint( $datum->complaints ?? 0 ) : null,
			];
		}

		return [
			'range'    => $key,
			'interval' => $range['type'],
			'current'  => $current_totals,
			'previous' => $previous_totals,
			'change'   => [
				'sent'       => $this->change( $current_totals['sent'], $previous_totals['sent'] ),
				'bounces'    => $this->change( $current_totals['bounces'], $previous_totals['bounces'] ),
				'complaints' => $has_complaints ? $this->change( $current_totals['complaints'], $previous_totals['complaints'] ) : null,
			],
			'series'   => $series,
			'usage'    => Formatter::usage( $limits ),
		];
	}
}
