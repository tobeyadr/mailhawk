<?php

namespace MailHawk\Abilities\Domains;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Api\Postal\Domains as Postal_Domains;
use function MailHawk\is_free_email_provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register a domain to send from. The domains of an account are limited by its plan.
 */
class Add_Domain extends Ability {

	protected const NAME     = 'mailhawk/add-domain';
	protected const CATEGORY = 'mailhawk-domains';

	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		$schema = Formatter::dns_schema();

		$schema['properties']['created'] = [
			'type'        => 'boolean',
			'description' => __( 'False when the domain was registered already.', 'mailhawk' ),
		];

		$schema['required'][] = 'created';

		return [
			'label'       => __( 'Add MailHawk Domain', 'mailhawk' ),
			'description' => __( 'Register a domain with the MailHawk account so email can be sent from it, and get the DNS records to add for it. Adding one that is registered already is not an error. The number of domains is limited by the plan, and the domain belongs to the account, so every site connected to it sees it. Addresses on free providers like gmail.com and domains that can not exist on the internet are refused, nobody can add DNS records to those.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'domain' ],
				'properties'           => [
					'domain' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The domain to send from, for example example.com. A www in front is dropped.', 'mailhawk' ),
					],
				],
			],

			'output_schema' => $schema,
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

		/**
		 * The ends of a domain name that only exist on a private network or in documentation
		 *
		 * @param string[] $tlds
		 */
		$reserved = (array) apply_filters( 'mailhawk/abilities/reserved_tlds', [ 'local', 'localhost', 'test', 'invalid', 'example', 'lan', 'internal' ] );

		if ( in_array( substr( strrchr( $name, '.' ), 1 ), $reserved, true ) ) {
			return $this->error( 'reserved_domain', __( 'That domain can not exist on the internet, so its DNS records could never be added. It would only use up one of the domains of the plan.', 'mailhawk' ) );
		}

		if ( is_free_email_provider( 'wp@' . $name ) ) {
			return $this->error( 'free_provider', __( 'That is a free email provider. Its DNS records can not be changed, so email from it could not be authenticated. Use a domain you own.', 'mailhawk' ) );
		}

		$created  = true;
		$response = Postal_Domains::create( $name );

		if ( is_wp_error( $response ) ) {

			// Registered already is what was wanted
			if ( 'DomainNameExists' !== $response->get_error_code() ) {
				return $this->service_error( $response );
			}

			$created = false;
		}

		$domain = Postal_Domains::query( $name );

		if ( is_wp_error( $domain ) ) {
			return $this->service_error( $domain );
		}

		return array_merge( [ 'domain' => Formatter::domain( $domain ), 'created' => $created ], Formatter::dns_records( $domain ) );
	}
}
