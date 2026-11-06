<?php

namespace MailHawk\Abilities\Settings;

use MailHawk\Abilities\Ability;
use function MailHawk\build_site_email;
use function MailHawk\get_email_retry_attempts;
use function MailHawk\get_log_retention_days;
use function MailHawk\get_quarantine_retention_days;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The settings that are safe to read: the sender defaults and the email log. The credentials that connect the site to
 * MailHawk are not among them and are never available.
 */
class Get_Settings extends Ability {

	protected const NAME     = 'mailhawk/get-settings';
	protected const CATEGORY = 'mailhawk-settings';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	/**
	 * What the settings are now
	 *
	 * @return array
	 */
	public static function current(): array {
		return [
			'from_name'                 => (string) get_option( 'mailhawk_default_from_name', get_bloginfo( 'name' ) ),
			'from_email'                => (string) get_option( 'mailhawk_default_from_email_address', build_site_email( site_url(), 'wordpress' ) ),
			'email_logging_enabled'     => ! get_option( 'mailhawk_disable_email_logging' ),
			'log_retention_days'        => get_log_retention_days(),
			'quarantine_retention_days' => get_quarantine_retention_days(),
			'retry_failed_emails'       => (bool) get_option( 'mailhawk_retry_failed_emails' ),
			'failed_email_retries'      => get_email_retry_attempts(),
		];
	}

	/**
	 * @return array
	 */
	public static function schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'from_name'                 => [
					'type'        => 'string',
					'description' => __( 'The name emails come from when the email does not say. Only has an effect when the From name is left to WordPress.', 'mailhawk' ),
				],
				'from_email'                => [
					'type'        => 'string',
					'description' => __( 'The address emails come from when the email does not say. It has to be on an authenticated domain.', 'mailhawk' ),
				],
				'email_logging_enabled'     => [
					'type'        => 'boolean',
					'description' => __( 'Whether sent email is logged. Emails held for review are logged either way.', 'mailhawk' ),
				],
				'log_retention_days'        => [
					'type'        => 'integer',
					'description' => __( 'Log entries older than this many days are deleted.', 'mailhawk' ),
				],
				'quarantine_retention_days' => [
					'type'        => 'integer',
					'description' => __( 'Emails held for review are deleted after this many days. Never shorter than the log retention, and not a setting.', 'mailhawk' ),
				],
				'retry_failed_emails'       => [
					'type'        => 'boolean',
					'description' => __( 'Whether failed emails are sent again automatically.', 'mailhawk' ),
				],
				'failed_email_retries'      => [
					'type'        => 'integer',
					'description' => __( 'How many times a failed email is sent again before it stays failed.', 'mailhawk' ),
				],
			],
			'required'   => [ 'from_name', 'from_email', 'email_logging_enabled', 'log_retention_days', 'quarantine_retention_days', 'retry_failed_emails', 'failed_email_retries' ],
		];
	}

	protected function get_args(): array {

		return [
			'label'       => __( 'Get MailHawk Settings', 'mailhawk' ),
			'description' => __( 'The sender defaults and the email log settings of MailHawk. The API key and license token that connect the site are never returned.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [],
			],

			'output_schema' => self::schema(),
		];
	}

	public function __invoke( $input ) {
		return self::current();
	}
}
