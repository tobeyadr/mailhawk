<?php

namespace MailHawk\Abilities\Status;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Api\Postal\Domains;
use MailHawk\Api\Postal\Reporting;
use MailHawk\Plugin;
use function MailHawk\get_mailhawk_suspension;
use function MailHawk\get_mailhawk_webhook_error;
use function MailHawk\get_sending_health;
use function MailHawk\is_domain_authenticated;
use function MailHawk\is_groundhogg_active;
use function MailHawk\is_mailhawk_setup_incomplete;
use function MailHawk\mailhawk_account_url;
use function MailHawk\mailhawk_is_connected;
use function MailHawk\mailhawk_is_suspended;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The first thing to ask: is MailHawk set up, is it sending well, and what needs attention. Everything on the
 * Overview screen that says something is wrong, in one answer.
 */
class Get_Status extends Ability {

	protected const NAME     = 'mailhawk/get-status';
	protected const CATEGORY = 'mailhawk-status';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	/**
	 * Fewer sends than this say too little to judge the rates by
	 */
	const MIN_SENT_FOR_RATES = 20;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get MailHawk Status', 'mailhawk' ),
			'description' => __( 'Whether MailHawk is connected, suspended or still being set up, whether the webhook that reports bounces and spam complaints is registered, how much of the monthly allowance is used, whether any domain is authenticated, how the last 30 days of sending went, and how many emails are held for review. Start here to find out if email from the site is healthy. The issues list says what needs attention, each with a code.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [
					'refresh' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Work the sending numbers out again instead of using the ones kept for a few hours.', 'mailhawk' ),
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'version'           => [ 'type' => 'string' ],
					'connected'         => [ 'type' => 'boolean' ],
					'suspended'         => [ 'type' => 'boolean' ],
					'suspension'        => [
						'type'        => [ 'object', 'null' ],
						'description' => __( 'What MailHawk said about the suspension. Null when the account is not suspended.', 'mailhawk' ),
						'properties'  => [
							'reason'       => [
								'type'        => [ 'string', 'null' ],
								'description' => __( 'Why the account is suspended, as MailHawk wrote it. Null when MailHawk did not say.', 'mailhawk' ),
							],
							'suspended_at' => [
								'type'        => [ 'string', 'null' ],
								'description' => __( 'When it was suspended, ISO 8601 in UTC. Null when MailHawk did not say.', 'mailhawk' ),
							],
							'scope'        => [
								'type'        => 'string',
								'enum'        => [ 'server', 'organization' ],
								'description' => __( 'organization when every site of the MailHawk account is suspended, not only this one.', 'mailhawk' ),
							],
							'next_steps'   => [
								'type'        => 'string',
								'description' => __( 'The MailHawk account page, which says what to do: renew, or send an incident report. A person has to sign in there.', 'mailhawk' ),
							],
						],
					],
					'setup_complete'    => [
						'type'        => 'boolean',
						'description' => __( 'False when MailHawk is connected but the sending address and domains were never chosen.', 'mailhawk' ),
					],
					'webhook_error'     => [
						'type'        => 'string',
						'description' => __( 'Why the webhook could not be registered, empty when it is. Without it bounces and spam complaints never reach the site.', 'mailhawk' ),
					],
					'ready_to_send'     => [
						'type'        => 'boolean',
						'description' => __( 'Connected, not suspended, set up, and with at least one authenticated domain.', 'mailhawk' ),
					],
					'usage'             => Formatter::usage_schema(),
					'domains'           => [
						'type'        => [ 'object', 'null' ],
						'description' => __( 'Null when MailHawk could not be reached.', 'mailhawk' ),
						'properties'  => [
							'total'           => [ 'type' => 'integer' ],
							'authenticated'   => [ 'type' => 'integer' ],
							'unauthenticated' => [
								'type'        => 'array',
								'description' => __( 'The domains that still need DNS records, see mailhawk/get-domain-dns.', 'mailhawk' ),
								'items'       => [ 'type' => 'string' ],
							],
						],
					],
					'sending'           => [
						'type'        => [ 'object', 'null' ],
						'description' => __( 'The last 30 days. Null when MailHawk could not be reached.', 'mailhawk' ),
						'properties'  => [
							'period_days'    => [ 'type' => 'integer' ],
							'sent'           => [ 'type' => 'integer' ],
							'bounces'        => [ 'type' => 'integer' ],
							'complaints'     => [ 'type' => 'integer' ],
							'bounce_rate'    => [
								'type'        => [ 'number', 'null' ],
								'description' => __( 'Bounces as a percentage of what was sent. Null when nothing was sent.', 'mailhawk' ),
							],
							'complaint_rate' => [ 'type' => [ 'number', 'null' ] ],
							'failed'         => [
								'type'        => 'integer',
								'description' => __( 'Emails that failed before they reached MailHawk, from the log of this site.', 'mailhawk' ),
							],
							'failed_rate'    => [ 'type' => [ 'number', 'null' ] ],
						],
					],
					'emails_held'       => [
						'type'        => 'integer',
						'description' => __( 'Emails in quarantine waiting to be released or rejected, see mailhawk/get-quarantine-report.', 'mailhawk' ),
					],
					'groundhogg_active' => [ 'type' => 'boolean' ],
					'issues'            => [
						'type'        => 'array',
						'description' => __( 'What needs attention. Empty when all is well.', 'mailhawk' ),
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'code'    => [
									'type' => 'string',
									'enum' => [ 'not_connected', 'suspended', 'setup_incomplete', 'webhook_not_registered', 'no_authenticated_domain', 'usage_high', 'emails_held', 'high_bounce_rate', 'spam_complaints' ],
								],
								'message' => [ 'type' => 'string' ],
							],
							'required'   => [ 'code', 'message' ],
						],
					],
				],
				'required'   => [ 'version', 'connected', 'suspended', 'suspension', 'setup_complete', 'webhook_error', 'ready_to_send', 'usage', 'domains', 'sending', 'emails_held', 'groundhogg_active', 'issues' ],
			],
		];
	}

	/**
	 * @param float|int $value
	 * @param float|int $of
	 *
	 * @return float|null
	 */
	protected function rate( $value, $of ): ?float {
		return $of > 0 ? round( $value / $of * 100, 2 ) : null;
	}

	public function __invoke( $input ) {

		$input     = $this->input( $input );
		$connected = mailhawk_is_connected();
		$suspended = mailhawk_is_suspended();
		$complete  = ! is_mailhawk_setup_incomplete();
		$webhook   = get_mailhawk_webhook_error();

		$usage   = null;
		$domains = null;
		$sending = null;

		// Nothing can be asked of the service before it is connected, or while it will not answer
		if ( $connected && ! $suspended ) {

			$usage = Formatter::usage( Reporting::limits() );

			$all = Domains::query_all();

			if ( is_array( $all ) ) {

				$domains = [ 'total' => count( $all ), 'authenticated' => 0, 'unauthenticated' => [] ];

				foreach ( $all as $domain ) {
					if ( is_domain_authenticated( $domain ) ) {
						$domains['authenticated'] ++;
					} else {
						$domains['unauthenticated'][] = (string) $domain->name;
					}
				}
			}

			$health = get_sending_health( ! empty( $input['refresh'] ) );

			if ( $health ) {
				$sending = [
					'period_days'    => 30,
					'sent'           => $health['sent'],
					'bounces'        => $health['bounces'],
					'complaints'     => $health['complaints'],
					'bounce_rate'    => $this->rate( $health['bounces'], $health['sent'] ),
					'complaint_rate' => $this->rate( $health['complaints'], $health['sent'] ),
					'failed'         => $health['failed'],
					'failed_rate'    => $this->rate( $health['failed'], $health['logged'] ),
				];
			}
		}

		$held = absint( Plugin::instance()->log->count( [ 'status' => 'quarantine' ] ) );

		$issues = [];

		if ( ! $connected ) {
			$issues[] = [ 'code' => 'not_connected', 'message' => __( 'MailHawk is not connected to an account, so email is sent with the default WordPress mailer. Connecting needs a person to sign in from Tools, MailHawk.', 'mailhawk' ) ];
		}

		$suspension = null;

		if ( $suspended ) {

			$details    = get_mailhawk_suspension();
			$suspension = [
				'reason'       => $details['reason'] ? $details['reason'] : null,
				'suspended_at' => $details['suspended_at'] ? gmdate( 'Y-m-d\TH:i:s\Z', $details['suspended_at'] ) : null,
				'scope'        => $details['scope'],
				'next_steps'   => mailhawk_account_url(),
			];

			$message = $details['reason']
				/* translators: %s: the reason MailHawk gave for suspending the account */
				? sprintf( __( 'The MailHawk account is suspended: %s', 'mailhawk' ), $details['reason'] )
				: __( 'The MailHawk account is suspended.', 'mailhawk' );

			/* translators: %s: URL of the MailHawk account page */
			$issues[] = [ 'code' => 'suspended', 'message' => $message . ' ' . sprintf( __( 'What to do next is on the MailHawk account page, %s, where a person has to sign in.', 'mailhawk' ), mailhawk_account_url() ) ];
		}

		if ( $connected && ! $complete ) {
			$issues[] = [ 'code' => 'setup_incomplete', 'message' => __( 'MailHawk is connected but the setup is not finished: the sending address and domain were never registered.', 'mailhawk' ) ];
		}

		if ( $connected && '' !== $webhook ) {
			$issues[] = [ 'code' => 'webhook_not_registered', 'message' => sprintf( __( 'The webhook could not be registered, so bounces and spam complaints never reach the site: %s', 'mailhawk' ), $webhook ) ];
		}

		if ( $domains && 0 === $domains['authenticated'] ) {
			$issues[] = [ 'code' => 'no_authenticated_domain', 'message' => __( 'No domain has verified SPF and DKIM records yet. Get the records with mailhawk/get-domain-dns.', 'mailhawk' ) ];
		}

		if ( $usage && $usage['percent'] >= 90 ) {
			/* translators: 1: percentage of the monthly allowance used, 2: emails left */
			$issues[] = [ 'code' => 'usage_high', 'message' => sprintf( __( '%1$s%% of the monthly sending allowance is used, %2$s emails are left.', 'mailhawk' ), $usage['percent'], number_format_i18n( $usage['remaining'] ) ) ];
		}

		if ( $held > 0 ) {
			$issues[] = [ 'code' => 'emails_held', 'message' => sprintf( _n( '%s email is held for review.', '%s emails are held for review.', $held, 'mailhawk' ), number_format_i18n( $held ) ) ];
		}

		// The same limits the review request is held back by, but with fewer sends to go on
		if ( $sending && $sending['sent'] >= self::MIN_SENT_FOR_RATES ) {

			if ( $sending['bounce_rate'] > (float) apply_filters( 'mailhawk/review/max_bounce_rate', 5.0 ) ) {
				$issues[] = [ 'code' => 'high_bounce_rate', 'message' => sprintf( __( '%s%% of the email sent in the last 30 days bounced, which damages the reputation of the sender.', 'mailhawk' ), $sending['bounce_rate'] ) ];
			}

			if ( $sending['complaint_rate'] > (float) apply_filters( 'mailhawk/review/max_complaint_rate', 0.1 ) ) {
				$issues[] = [ 'code' => 'spam_complaints', 'message' => sprintf( __( '%s%% of the email sent in the last 30 days was reported as spam.', 'mailhawk' ), $sending['complaint_rate'] ) ];
			}
		}

		return [
			'version'           => MAILHAWK_VERSION,
			'connected'         => $connected,
			'suspended'         => $suspended,
			'suspension'        => $suspension,
			'setup_complete'    => $complete,
			'webhook_error'     => $webhook,
			'ready_to_send'     => $connected && ! $suspended && $complete && ( null === $domains || $domains['authenticated'] > 0 ),
			'usage'             => $usage,
			'domains'           => $domains,
			'sending'           => $sending,
			'emails_held'       => $held,
			'groundhogg_active' => is_groundhogg_active(),
			'issues'            => $issues,
		];
	}
}
