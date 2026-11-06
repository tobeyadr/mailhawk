<?php

namespace MailHawk\Abilities\Recipients;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\DB\Recipients;
use MailHawk\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What MailHawk has learned about the addresses it sends to, the Recipients screen
 */
class List_Recipients extends Ability {

	protected const NAME     = 'mailhawk/list-recipients';
	protected const CATEGORY = 'mailhawk-recipients';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List MailHawk Recipients', 'mailhawk' ),
			'description' => __( 'List the addresses MailHawk keeps a record of: those an administrator trusted, those that bounced (watching) and those that reported email from the site as spam (blocked). Addresses that never did any of that have no record. Use it to find out why email to an address was held.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [
					'status' => [
						'type'        => 'string',
						'enum'        => [ 'trusted', 'watching', 'blocked' ],
						'description' => __( 'Only addresses with this status.', 'mailhawk' ),
					],
					'search' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Only addresses that contain this.', 'mailhawk' ),
					],
					'limit'  => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					],
					'offset' => [
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'recipients' => [
						'type'  => 'array',
						'items' => Formatter::recipient_schema(),
					],
					'total'      => [ 'type' => 'integer' ],
					'limit'      => [ 'type' => 'integer' ],
					'offset'     => [ 'type' => 'integer' ],
				],
				'required'   => [ 'recipients', 'total', 'limit', 'offset' ],
			],
		];
	}

	public function __invoke( $input ) {

		global $wpdb;

		$db = Plugin::instance()->recipients;

		if ( ! $db->is_ready() ) {
			return $this->error( 'table_missing', __( 'The recipients table has not been created yet. Run the database update from the notice on the Plugins or Dashboard screen.', 'mailhawk' ), 409 );
		}

		$input  = $this->input( $input );
		$table  = $db->get_table_name();
		$limit  = max( 1, min( 200, absint( $input['limit'] ?? 50 ) ) );
		$offset = absint( $input['offset'] ?? 0 );

		$where = [];
		$args  = [];

		if ( ! empty( $input['status'] ) ) {

			// Watching is the absence of a status
			$statuses = [ 'trusted' => Recipients::TRUSTED, 'blocked' => Recipients::BLOCKED, 'watching' => '' ];

			$where[] = '`status` = %s';
			$args[]  = $statuses[ $input['status'] ] ?? '';
		}

		if ( ! empty( $input['search'] ) ) {
			$where[] = '`email` LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( strtolower( (string) $input['search'] ) ) . '%';
		}

		$clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$clause}";
		$total     = absint( $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} {$clause} ORDER BY `date_updated` DESC, `ID` DESC LIMIT %d OFFSET %d",
			array_merge( $args, [ $limit, $offset ] )
		) );

		return [
			'recipients' => array_map( [ Formatter::class, 'recipient' ], (array) $rows ),
			'total'      => $total,
			'limit'      => $limit,
			'offset'     => $offset,
		];
	}
}
