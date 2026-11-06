<?php

namespace MailHawk\Abilities\Domains;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Api\Postal\Domains as Postal_Domains;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ask MailHawk to look at the DNS records of a domain now, the Verify button on the DNS screen
 */
class Check_Domain_Dns extends Ability {

	protected const NAME     = 'mailhawk/check-domain-dns';
	protected const CATEGORY = 'mailhawk-domains';

	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Check MailHawk Domain DNS', 'mailhawk' ),
			'description' => __( 'Have MailHawk look up the DNS records of a registered domain now and return what it found, as the Verify button does. New records can take up to 24 hours to spread, so a domain that is not verified right after the records were added is normal, check again in an hour or so.', 'mailhawk' ),

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

		$checked = Postal_Domains::check( $name );

		if ( is_wp_error( $checked ) ) {
			return $this->service_error( $checked );
		}

		// What the check found, asked for again so it is the state after it
		$domain = Postal_Domains::query( $name );

		if ( is_wp_error( $domain ) ) {
			return $this->service_error( $domain );
		}

		return array_merge( [ 'domain' => Formatter::domain( $domain ) ], Formatter::dns_records( $domain ) );
	}
}
