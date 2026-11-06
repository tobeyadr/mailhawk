<?php

namespace MailHawk\Abilities\Email;

use DateTimeImmutable;
use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search the log of the email the site sent. What the emails said is left out, it is personal data and a long list of
 * it is useless, mailhawk/get-email has it for one email.
 */
class List_Emails extends Ability {

	protected const NAME     = 'mailhawk/list-emails';
	protected const CATEGORY = 'mailhawk-email';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List MailHawk Emails', 'mailhawk' ),
			'description' => __( 'Search the log of email sent by the site, newest first, by status, recipient, subject or date. Each entry has its status, the error when it failed, and the abilities that can act on it. The log is deleted after the retention period set in the settings, and is empty when logging is turned off (emails held for review are always kept). What an email said is not included, use mailhawk/get-email for that.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [
					'status'    => [
						'type'        => 'string',
						'enum'        => [ 'sent', 'delivered', 'failed', 'bounced', 'softfail', 'complained', 'quarantine' ],
						'description' => __( 'Only emails with this status. failed means it never reached MailHawk, bounced and softfail were reported back later, quarantine is held for review.', 'mailhawk' ),
					],
					'recipient' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Only emails sent to an address that contains this, for example an address or a domain.', 'mailhawk' ),
					],
					'subject'   => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Only emails with a subject that contains this.', 'mailhawk' ),
					],
					'after'     => [
						'type'        => 'string',
						'description' => __( 'Only emails sent at or after this date or date and time, in the time zone of the site, for example 2025-06-01 or 2025-06-01 14:30.', 'mailhawk' ),
					],
					'before'    => [
						'type'        => 'string',
						'description' => __( 'Only emails sent at or before this date or date and time. A date alone includes that whole day.', 'mailhawk' ),
					],
					'order'     => [
						'type'    => 'string',
						'enum'    => [ 'desc', 'asc' ],
						'default' => 'desc',
					],
					'limit'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					],
					'offset'    => [
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'emails' => [
						'type'  => 'array',
						'items' => Formatter::email_schema(),
					],
					'total'  => [
						'type'        => 'integer',
						'description' => __( 'How many emails match, whatever the limit and offset.', 'mailhawk' ),
					],
					'limit'  => [ 'type' => 'integer' ],
					'offset' => [ 'type' => 'integer' ],
				],
				'required'   => [ 'emails', 'total', 'limit', 'offset' ],
			],
		];
	}

	/**
	 * A date or a date and time in the time zone of the site, the way the log stores them
	 *
	 * @param string $value
	 * @param bool   $end_of_day a date alone means the end of that day
	 *
	 * @return string|null null when it is not a date
	 */
	protected function parse_date( string $value, bool $end_of_day ) {

		$value = trim( $value );

		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}

		if ( $end_of_day && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$date = $date->setTime( 23, 59, 59 );
		}

		return $date->format( 'Y-m-d H:i:s' );
	}

	public function __invoke( $input ) {

		global $wpdb;

		$input  = $this->input( $input );
		$table  = Plugin::instance()->log->get_table_name();
		$limit  = max( 1, min( 100, absint( $input['limit'] ?? 20 ) ) );
		$offset = absint( $input['offset'] ?? 0 );
		$order  = 'asc' === strtolower( (string) ( $input['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';

		$where = [];
		$args  = [];

		if ( ! empty( $input['status'] ) ) {
			$where[] = '`status` = %s';
			$args[]  = (string) $input['status'];
		}

		if ( ! empty( $input['recipient'] ) ) {
			$where[] = '`recipients` LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( (string) $input['recipient'] ) . '%';
		}

		if ( ! empty( $input['subject'] ) ) {
			$where[] = '`subject` LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( (string) $input['subject'] ) . '%';
		}

		foreach ( [ 'after' => '>=', 'before' => '<=' ] as $key => $compare ) {

			if ( empty( $input[ $key ] ) ) {
				continue;
			}

			$date = $this->parse_date( (string) $input[ $key ], 'before' === $key );

			if ( null === $date ) {
				return $this->error( 'invalid_date', sprintf( __( '%s is not a date. Use a form like 2025-06-01 or 2025-06-01 14:30.', 'mailhawk' ), $key ) );
			}

			$where[] = "`date_sent` {$compare} %s";
			$args[]  = $date;
		}

		$clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$clause}";
		$total     = absint( $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ) );

		$sql  = "SELECT `ID`, `recipients`, `from_address`, `subject`, `status`, `error_code`, `error_message`, `retries`, `date_sent`, `msg_id` FROM {$table} {$clause} ORDER BY `date_sent` {$order}, `ID` {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $limit, $offset ] ) ) );

		return [
			'emails' => array_map( [ Formatter::class, 'email' ], (array) $rows ),
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		];
	}
}
