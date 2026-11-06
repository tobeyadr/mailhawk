<?php

namespace MailHawk\Abilities\Domains;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Api\Postal\Domains as Postal_Domains;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The domains registered with the MailHawk account, and which of them can send trusted email
 */
class List_Domains extends Ability {

	protected const NAME     = 'mailhawk/list-domains';
	protected const CATEGORY = 'mailhawk-domains';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List MailHawk Domains', 'mailhawk' ),
			'description' => __( 'List the domains registered with the MailHawk account, with the state of the SPF, DKIM and return path records of each and whether the domain is authenticated, which is what it takes for email from it to be trusted. The domains belong to the account, so every site connected to the same account sees the same ones. Use mailhawk/get-domain-dns to get the records a domain still needs.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'domains' => [
						'type'  => 'array',
						'items' => Formatter::domain_schema(),
					],
				],
				'required'   => [ 'domains' ],
			],
		];
	}

	public function __invoke( $input ) {

		$connection = $this->check_connection();

		if ( $connection ) {
			return $connection;
		}

		$domains = Postal_Domains::query_all();

		if ( is_wp_error( $domains ) ) {
			return $this->service_error( $domains );
		}

		$list = array_map( [ Formatter::class, 'domain' ], is_array( $domains ) ? $domains : [] );

		usort( $list, function ( $a, $b ) {
			return strcmp( $a['name'], $b['name'] );
		} );

		return [ 'domains' => $list ];
	}
}
