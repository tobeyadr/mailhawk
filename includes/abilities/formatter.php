<?php

namespace MailHawk\Abilities;

use MailHawk\DB\Recipients;
use function MailHawk\get_email_status_pretty_name;
use function MailHawk\get_spf_record;
use function MailHawk\is_domain_authenticated;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the abilities return, and the schemas that describe it, in one place so the two can not drift apart.
 * An output that does not match its schema is rejected by WordPress.
 */
class Formatter {

	/**
	 * Where the CNAME for the return path points
	 */
	const RETURN_PATH_TARGET = 'rp.mta01.mailhawk.io';

	/**
	 * A domain from the MTA
	 *
	 * @param object $domain
	 *
	 * @return array
	 */
	public static function domain( $domain ): array {
		return [
			'name'          => (string) ( $domain->name ?? '' ),
			'authenticated' => is_domain_authenticated( $domain ),
			'spf'           => isset( $domain->spf->spf_status ) ? (string) $domain->spf->spf_status : null,
			'dkim'          => isset( $domain->dkim->dkim_status ) ? (string) $domain->dkim->dkim_status : null,
			'return_path'   => isset( $domain->return_path->return_path_status ) ? (string) $domain->return_path->return_path_status : null,
		];
	}

	/**
	 * @return array
	 */
	public static function domain_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name'          => [ 'type' => 'string' ],
				'authenticated' => [
					'type'        => 'boolean',
					'description' => __( 'True when the SPF and DKIM records are both verified, which is what makes email from the domain trusted.', 'mailhawk' ),
				],
				'spf'           => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'The state of the SPF record, "OK" when verified.', 'mailhawk' ),
				],
				'dkim'          => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'The state of the DKIM record, "OK" when verified.', 'mailhawk' ),
				],
				'return_path'   => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'The state of the return path CNAME, "OK" when verified.', 'mailhawk' ),
				],
			],
			'required'   => [ 'name', 'authenticated', 'spf', 'dkim', 'return_path' ],
		];
	}

	/**
	 * The monthly sending allowance, from the limits the MTA reports
	 *
	 * @param mixed $limits the result of Reporting::limits()
	 *
	 * @return array|null null when the MTA did not say
	 */
	public static function usage( $limits ): ?array {

		if ( ! is_array( $limits ) ) {
			return null;
		}

		foreach ( $limits as $limit ) {

			if ( ! is_object( $limit ) || ( $limit->type ?? '' ) !== 'monthly_send_limit' ) {
				continue;
			}

			$used   = absint( $limit->usage ?? 0 );
			$max    = absint( $limit->limit ?? 0 );
			$resets = ! empty( $limit->resets_at ) ? strtotime( (string) $limit->resets_at ) : false;

			return [
				'used'      => $used,
				'limit'     => $max,
				'remaining' => max( $max - $used, 0 ),
				'percent'   => $max > 0 ? round( $used / $max * 100, 1 ) : 0.0,
				'resets_at' => $resets ? gmdate( 'c', $resets ) : null,
			];
		}

		return null;
	}

	/**
	 * @return array
	 */
	public static function usage_schema(): array {
		return [
			'type'        => [ 'object', 'null' ],
			'description' => __( 'The monthly sending allowance of the plan. Null when MailHawk could not be reached.', 'mailhawk' ),
			'properties'  => [
				'used'      => [ 'type' => 'integer' ],
				'limit'     => [ 'type' => 'integer' ],
				'remaining' => [ 'type' => 'integer' ],
				'percent'   => [ 'type' => 'number' ],
				'resets_at' => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'When the allowance starts over, as an ISO 8601 date in UTC.', 'mailhawk' ),
				],
			],
		];
	}

	/**
	 * The name of a domain from whatever was given for it: a bare domain, a URL, or a domain with www in front.
	 *
	 * @param mixed $input
	 *
	 * @return string lowercase, may still not be a valid domain
	 */
	public static function domain_name( $input ): string {

		$name = strtolower( trim( (string) $input ) );
		$name = preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $name );
		$name = preg_replace( '#[/?\#:].*$#', '', $name );
		$name = preg_replace( '#^www\.#', '', $name );

		return rtrim( $name, '.' );
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public static function is_valid_domain_name( string $name ): bool {
		return (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9\-]{1,59})$/', $name );
	}

	/**
	 * The DNS records a domain needs, the way the DNS screen shows them
	 *
	 * @param object $domain a domain from the MTA
	 *
	 * @return array{records: array, existing_spf_record: string|null}
	 */
	public static function dns_records( $domain ): array {

		$spf_status = (string) ( $domain->spf->spf_status ?? '' );
		$spf_record = (string) ( $domain->spf->spf_record ?? '' );
		$existing   = null;

		// The domain may have an SPF record already, only one is allowed so MailHawk is added to it
		if ( 'OK' !== $spf_status ) {

			/**
			 * The SPF record the domain has now, from DNS
			 *
			 * @param string|false $record
			 * @param string       $domain
			 */
			$found = apply_filters( 'mailhawk/abilities/existing_spf_record', get_spf_record( (string) $domain->name ), (string) $domain->name );

			if ( ! empty( $found ) ) {

				$existing = (string) $found;

				if ( preg_match( '/include:[^ ]+/', $spf_record, $matches ) ) {

					$include = apply_filters( 'mailhawk/spf_include', $matches[0] );

					$spf_record = false !== strpos( $existing, $include ) ? $existing : preg_replace( '/([^ ]+$)/', $include . ' $1', $existing );
				}
			}
		}

		return [
			'records'             => [
				[
					'purpose'  => 'dkim',
					'type'     => 'TXT',
					'name'     => strtolower( (string) ( $domain->dkim->dkim_record_name ?? '' ) ),
					'value'    => (string) ( $domain->dkim->dkim_record ?? '' ),
					'ttl'      => 3600,
					'status'   => isset( $domain->dkim->dkim_status ) ? (string) $domain->dkim->dkim_status : null,
					'verified' => ( $domain->dkim->dkim_status ?? '' ) === 'OK',
				],
				[
					'purpose'  => 'return_path',
					'type'     => 'CNAME',
					'name'     => strtolower( (string) ( $domain->return_path->return_path_domain ?? '' ) ),
					'value'    => self::RETURN_PATH_TARGET,
					'ttl'      => 3600,
					'status'   => isset( $domain->return_path->return_path_status ) ? (string) $domain->return_path->return_path_status : null,
					'verified' => ( $domain->return_path->return_path_status ?? '' ) === 'OK',
				],
				[
					'purpose'  => 'spf',
					'type'     => 'TXT',
					'name'     => '@',
					'value'    => $spf_record,
					'ttl'      => 3600,
					'status'   => '' !== $spf_status ? $spf_status : null,
					'verified' => 'OK' === $spf_status,
				],
			],
			'existing_spf_record' => $existing,
		];
	}

	/**
	 * @return array
	 */
	public static function dns_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'domain'              => self::domain_schema(),
				'records'             => [
					'type'        => 'array',
					'description' => __( 'The records to add at the DNS host of the domain, as they are shown on the MailHawk DNS screen. A name of @ is the domain itself.', 'mailhawk' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'purpose'  => [
								'type' => 'string',
								'enum' => [ 'dkim', 'return_path', 'spf' ],
							],
							'type'     => [
								'type' => 'string',
								'enum' => [ 'TXT', 'CNAME' ],
							],
							'name'     => [ 'type' => 'string' ],
							'value'    => [ 'type' => 'string' ],
							'ttl'      => [ 'type' => 'integer' ],
							'status'   => [ 'type' => [ 'string', 'null' ] ],
							'verified' => [ 'type' => 'boolean' ],
						],
						'required'   => [ 'purpose', 'type', 'name', 'value', 'ttl', 'status', 'verified' ],
					],
				],
				'existing_spf_record' => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'The SPF record the domain has now, when it has one. The spf entry in records is that record with MailHawk added, replace it, a domain can only have one SPF record.', 'mailhawk' ),
				],
			],
			'required'   => [ 'domain', 'records', 'existing_spf_record' ],
		];
	}

	/**
	 * The ways to act on an email in the log, by its status
	 *
	 * @param string $status
	 *
	 * @return string[] ability names
	 */
	public static function email_actions( string $status ): array {

		switch ( $status ) {
			case 'sent':
			case 'delivered':
			case 'failed':
			case 'bounced':
			case 'softfail':
				return [ 'mailhawk/retry-email' ];
			case 'quarantine':
				return [ 'mailhawk/release-email', 'mailhawk/reject-email' ];
		}

		return [];
	}

	/**
	 * An email in the log, without what it said
	 *
	 * @param object $row a log row or an Email_Log_Item
	 *
	 * @return array
	 */
	public static function email( $row ): array {

		$status = (string) $row->status;
		$label  = get_email_status_pretty_name( $status );

		return [
			'id'            => absint( $row->ID ),
			'subject'       => (string) $row->subject,
			'recipients'    => array_values( array_filter( (array) maybe_unserialize( $row->recipients ), 'is_string' ) ),
			'from_address'  => (string) $row->from_address,
			'status'        => $status,
			'status_label'  => $label ? (string) $label : ucfirst( $status ),
			'error_code'    => (string) $row->error_code,
			'error_message' => (string) $row->error_message,
			'retries'       => absint( $row->retries ),
			'date_sent'     => (string) $row->date_sent,
			'message_id'    => (string) $row->msg_id,
			'actions'       => self::email_actions( $status ),
		];
	}

	/**
	 * @return array
	 */
	public static function email_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'            => [ 'type' => 'integer' ],
				'subject'       => [ 'type' => 'string' ],
				'recipients'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'from_address'  => [ 'type' => 'string' ],
				'status'        => [
					'type'        => 'string',
					'description' => __( 'sent, delivered, failed, bounced, softfail, complained or quarantine (held for review).', 'mailhawk' ),
				],
				'status_label'  => [ 'type' => 'string' ],
				'error_code'    => [ 'type' => 'string' ],
				'error_message' => [ 'type' => 'string' ],
				'retries'       => [ 'type' => 'integer' ],
				'date_sent'     => [
					'type'        => 'string',
					'description' => __( 'When it was sent or held, in the time zone of the site.', 'mailhawk' ),
				],
				'message_id'    => [ 'type' => 'string' ],
				'actions'       => [
					'type'        => 'array',
					'description' => __( 'The abilities that can act on this email in its current status.', 'mailhawk' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'id', 'subject', 'recipients', 'from_address', 'status', 'status_label', 'error_code', 'error_message', 'retries', 'date_sent', 'message_id', 'actions' ],
		];
	}

	/**
	 * What MailHawk knows about an address
	 *
	 * @param object $row a row of the recipients table
	 *
	 * @return array
	 */
	public static function recipient( $row ): array {

		switch ( $row->status ) {
			case Recipients::TRUSTED:
				$status = 'trusted';
				break;
			case Recipients::BLOCKED:
				$status = 'blocked';
				break;
			default:
				$status = 'watching';
				break;
		}

		$has_bounced = ! empty( $row->last_bounce ) && 0 !== strpos( $row->last_bounce, '0000' );

		return [
			'id'           => absint( $row->ID ),
			'email'        => (string) $row->email,
			'status'       => $status,
			'bounces'      => absint( $row->bounces ),
			'complaints'   => absint( $row->complaints ),
			'last_bounce'  => $has_bounced ? (string) $row->last_bounce : null,
			'source'       => (string) $row->source,
			'date_added'   => (string) $row->date_added,
			'date_updated' => (string) $row->date_updated,
		];
	}

	/**
	 * @return array
	 */
	public static function recipient_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'email'        => [ 'type' => 'string' ],
				'status'       => [
					'type'        => 'string',
					'enum'        => [ 'trusted', 'watching', 'blocked' ],
					'description' => __( 'trusted: never held for review. watching: has bounced, so mail to it is held when it keeps bouncing. blocked: reported email as spam, mail to it is always held.', 'mailhawk' ),
				],
				'bounces'      => [ 'type' => 'integer' ],
				'complaints'   => [
					'type'        => 'integer',
					'description' => __( 'How many times the person reported email from this site as spam.', 'mailhawk' ),
				],
				'last_bounce'  => [ 'type' => [ 'string', 'null' ] ],
				'source'       => [
					'type'        => 'string',
					'description' => __( 'How it came to be here: manual, release (trusted when an email was released) or webhook (reported by the MTA).', 'mailhawk' ),
				],
				'date_added'   => [ 'type' => 'string' ],
				'date_updated' => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'email', 'status', 'bounces', 'complaints', 'last_bounce', 'source', 'date_added', 'date_updated' ],
		];
	}
}
