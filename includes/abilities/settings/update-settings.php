<?php

namespace MailHawk\Abilities\Settings;

use MailHawk\Abilities\Ability;
use function MailHawk\is_free_email_provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change the sender defaults and the email log settings. Nothing that connects the site to MailHawk can be changed
 * here: the API key, the license token, the delete-everything switch and the network sender domain stay with the
 * Settings screen.
 */
class Update_Settings extends Ability {

	protected const NAME     = 'mailhawk/update-settings';
	protected const CATEGORY = 'mailhawk-settings';

	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Update MailHawk Settings', 'mailhawk' ),
			'description' => __( 'Change the default From name and address, and whether and for how long email is logged and failed emails are retried. Give only what should change. The From address has to be on a domain you own that is authenticated, see mailhawk/list-domains, otherwise the people it is sent to will not accept it; an address on a free provider like gmail.com is refused. A shorter log retention deletes older log entries at the next cleanup.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'minProperties'        => 1,
				'properties'           => [
					'from_name'             => [
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 150,
					],
					'from_email'            => [
						'type'   => 'string',
						'format' => 'email',
					],
					'email_logging_enabled' => [ 'type' => 'boolean' ],
					'log_retention_days'    => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 365,
					],
					'retry_failed_emails'   => [ 'type' => 'boolean' ],
					'failed_email_retries'  => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 10,
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'settings' => Get_Settings::schema(),
					'changed'  => [
						'type'        => 'array',
						'description' => __( 'The settings that now have a different value.', 'mailhawk' ),
						'items'       => [ 'type' => 'string' ],
					],
				],
				'required'   => [ 'settings', 'changed' ],
			],
		];
	}

	public function __invoke( $input ) {

		$input  = $this->input( $input );
		$before = Get_Settings::current();

		if ( empty( $input ) ) {
			return $this->error( 'no_changes', __( 'No settings to change were given.', 'mailhawk' ) );
		}

		$from_email = null;

		if ( isset( $input['from_email'] ) ) {

			$from_email = sanitize_email( (string) $input['from_email'] );

			if ( ! is_email( $from_email ) ) {
				return $this->error( 'invalid_email', __( 'The From address is not an email address.', 'mailhawk' ) );
			}

			// Everything is checked before anything is saved
			if ( is_free_email_provider( $from_email ) ) {
				return $this->error( 'free_provider', __( 'The From address can not be on a free email provider like gmail.com. The people it is sent to would reject it. Use an address on a domain you own.', 'mailhawk' ) );
			}
		}

		if ( isset( $input['from_name'] ) ) {

			$from_name = sanitize_text_field( (string) $input['from_name'] );

			if ( '' === $from_name ) {
				return $this->error( 'invalid_name', __( 'The From name can not be empty.', 'mailhawk' ) );
			}

			update_option( 'mailhawk_default_from_name', $from_name );
		}

		if ( null !== $from_email ) {
			update_option( 'mailhawk_default_from_email_address', $from_email );
		}

		if ( isset( $input['email_logging_enabled'] ) ) {
			update_option( 'mailhawk_disable_email_logging', $input['email_logging_enabled'] ? 0 : 1 );
		}

		if ( isset( $input['log_retention_days'] ) ) {
			update_option( 'mailhawk_log_retention_in_days', max( 1, min( 365, absint( $input['log_retention_days'] ) ) ) );
		}

		if ( isset( $input['retry_failed_emails'] ) ) {
			update_option( 'mailhawk_retry_failed_emails', $input['retry_failed_emails'] ? 1 : 0 );
		}

		if ( isset( $input['failed_email_retries'] ) ) {
			update_option( 'mailhawk_failed_email_retries', max( 1, min( 10, absint( $input['failed_email_retries'] ) ) ) );
		}

		$after   = Get_Settings::current();
		$changed = array_keys( array_diff_assoc( $after, $before ) );

		return [
			'settings' => $after,
			'changed'  => $changed,
		];
	}
}
