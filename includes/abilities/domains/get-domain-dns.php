<?php

namespace MailHawk\Abilities\Domains;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Api\Postal\Domains as Postal_Domains;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The DNS records a domain needs, the table on the DNS screen of the domain
 */
class Get_Domain_Dns extends Ability {

	protected const NAME     = 'mailhawk/get-domain-dns';
	protected const CATEGORY = 'mailhawk-domains';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get MailHawk Domain DNS Records', 'mailhawk' ),
			'description' => __( 'The DNS records to add for a registered domain so email from it is trusted: a DKIM TXT record, a return path CNAME and an SPF TXT record, each with its name, value and whether it is verified yet. If the domain already has an SPF record the spf entry is that record with MailHawk added, and it has to replace the old one because a domain can only have one. Add them at the DNS host of the domain, then run mailhawk/check-domain-dns.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'domain' ],
				'properties'           => [
					'domain' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'A domain registered with MailHawk, see mailhawk/list-domains.', 'mailhawk' ),
					],
				],
			],

			'output_schema' => Formatter::dns_schema(),
		];
	}

	public function __invoke( $input ) {

		$connection = $this->check_connection();

		if ( $connection ) {
			return $connection;
		}

		$name = Formatter::domain_name( $this->input( $input )['domain'] ?? '' );

		if ( ! Formatter::is_valid_domain_name( $name ) ) {
			return $this->error( 'invalid_domain', __( 'That is not a domain name.', 'mailhawk' ) );
		}

		$domain = Postal_Domains::query( $name );

		if ( is_wp_error( $domain ) ) {
			return $this->service_error( $domain );
		}

		return array_merge( [ 'domain' => Formatter::domain( $domain ) ], Formatter::dns_records( $domain ) );
	}
}
