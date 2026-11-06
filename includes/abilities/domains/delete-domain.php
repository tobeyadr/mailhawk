<?php

namespace MailHawk\Abilities\Domains;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Api\Postal\Domains as Postal_Domains;
use function MailHawk\get_default_from_email_address;
use function MailHawk\get_email_address_hostname;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove a domain from the MailHawk account
 */
class Delete_Domain extends Ability {

	protected const NAME     = 'mailhawk/delete-domain';
	protected const CATEGORY = 'mailhawk-domains';

	protected const DESTRUCTIVE = true;
	protected const IDEMPOTENT  = false;

	protected function get_args(): array {

		return [
			'label'       => __( 'Delete MailHawk Domain', 'mailhawk' ),
			'description' => __( 'Remove a domain from the MailHawk account. This is not undone: the domain has to be registered again and its DNS records verified again before email from it is trusted. Domains belong to the account, not to this site, so every other site connected to the same account loses the domain too. Refused for the domain of the default From address, change that first. Only do this when asked to, and say the domain again in confirm.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'domain', 'confirm' ],
				'properties'           => [
					'domain'  => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The domain to remove, see mailhawk/list-domains.', 'mailhawk' ),
					],
					'confirm' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The same domain again, to make sure this is the one meant.', 'mailhawk' ),
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'deleted' => [ 'type' => 'boolean' ],
					'domain'  => [ 'type' => 'string' ],
				],
				'required'   => [ 'deleted', 'domain' ],
			],
		];
	}

	public function __invoke( $input ) {

		$connection = $this->check_connection();

		if ( $connection ) {
			return $connection;
		}

		$input = $this->input( $input );
		$name  = Formatter::domain_name( $input['domain'] ?? '' );

		if ( ! Formatter::is_valid_domain_name( $name ) ) {
			return $this->error( 'invalid_domain', __( 'That is not a domain name.', 'mailhawk' ) );
		}

		if ( Formatter::domain_name( $input['confirm'] ?? '' ) !== $name ) {
			return $this->error( 'confirmation_mismatch', __( 'The confirmation is not the domain. Nothing was deleted.', 'mailhawk' ) );
		}

		// Email from the site would be rejected from the moment the domain is gone
		$from = get_default_from_email_address();

		if ( $from && strtolower( (string) get_email_address_hostname( (string) $from ) ) === $name ) {
			return $this->error( 'domain_in_use', __( 'That is the domain of the default From address, so the email of the site would stop being accepted. Change the default From address first, see mailhawk/update-settings.', 'mailhawk' ), 409 );
		}

		$response = Postal_Domains::delete( $name );

		if ( is_wp_error( $response ) ) {
			return $this->service_error( $response );
		}

		return [ 'deleted' => true, 'domain' => $name ];
	}
}
