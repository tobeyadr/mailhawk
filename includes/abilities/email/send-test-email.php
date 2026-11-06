<?php

namespace MailHawk\Abilities\Email;

use MailHawk\Abilities\Ability;
use MailHawk\Plugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send a test email through MailHawk, the Test screen without the choice of what it says. The subject and text are
 * fixed on purpose: an ability that sent any text to any address would be a way to send spam from the account.
 */
class Send_Test_Email extends Ability {

	protected const NAME     = 'mailhawk/send-test-email';
	protected const CATEGORY = 'mailhawk-email';

	protected const DESTRUCTIVE = true;
	protected const IDEMPOTENT  = false;

	protected function get_args(): array {

		return [
			'label'       => __( 'Send MailHawk Test Email', 'mailhawk' ),
			'description' => __( 'Send a short test email through MailHawk to check that email is delivered end to end. The subject and text are fixed, only the address can be chosen, and it is the address of the current user when left out. Like any other email it is held for review if the address is risky, in which case held is true and mailhawk/release-email sends it.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [
					'to' => [
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'The address to send the test to. The email address of the current user when left out.', 'mailhawk' ),
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'sent'    => [
						'type'        => 'boolean',
						'description' => __( 'True when MailHawk accepted the email for delivery. False when it was held for review. A failure is returned as an error.', 'mailhawk' ),
					],
					'held'    => [ 'type' => 'boolean' ],
					'to'      => [ 'type' => 'string' ],
					'subject' => [ 'type' => 'string' ],
					'log_id'  => [
						'type'        => [ 'integer', 'null' ],
						'description' => __( 'The ID of the email in the log, null when logging is turned off.', 'mailhawk' ),
					],
					'status'  => [ 'type' => [ 'string', 'null' ] ],
				],
				'required'   => [ 'sent', 'held', 'to', 'subject', 'log_id', 'status' ],
			],
		];
	}

	public function __invoke( $input ) {

		global $wpdb;

		$connection = $this->check_connection();

		if ( $connection ) {
			return $connection;
		}

		$to = sanitize_email( (string) ( $this->input( $input )['to'] ?? '' ) );

		if ( '' === $to ) {
			$to = wp_get_current_user()->user_email;
		}

		if ( ! is_email( $to ) ) {
			return $this->error( 'invalid_email', __( 'That is not an email address.', 'mailhawk' ) );
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( __( 'MailHawk test email from %s', 'mailhawk' ), $site );
		$message = sprintf( __( "This is a test email from %s, sent through MailHawk to check that email is delivered.\n\nYou can ignore it.", 'mailhawk' ), $site );

		$table = Plugin::instance()->log->get_table_name();
		$last  = absint( $wpdb->get_var( "SELECT MAX(`ID`) FROM {$table}" ) );

		$failure = null;

		$catch = function ( $error ) use ( &$failure ) {
			$failure = $error;
		};

		add_action( 'wp_mail_failed', $catch );

		// Straight to MailHawk's mailer, which is what wp_mail() is while the site is connected. A test that went through
		// whatever else is hooked in would not say anything about MailHawk.
		try {
			$sent = mailhawk_mail( $to, $subject, $message );
		} catch ( \Exception $e ) {
			$sent    = false;
			$failure = new WP_Error( 'mailhawk_test_failed', $e->getMessage() );
		} finally {
			remove_action( 'wp_mail_failed', $catch );
		}

		if ( ! $sent ) {
			return new WP_Error( 'mailhawk_test_failed', $failure instanceof WP_Error ? $failure->get_error_message() : __( 'The test email could not be sent.', 'mailhawk' ), [ 'status' => 502 ] );
		}

		// The log says whether it went out or was held, when there is one
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT `ID`, `status` FROM {$table} WHERE `ID` > %d AND `subject` = %s ORDER BY `ID` DESC LIMIT 1", $last, $subject ) );
		$held = $row && 'quarantine' === $row->status;

		return [
			'sent'    => ! $held,
			'held'    => $held,
			'to'      => $to,
			'subject' => $subject,
			'log_id'  => $row ? absint( $row->ID ) : null,
			'status'  => $row ? (string) $row->status : null,
		];
	}
}
