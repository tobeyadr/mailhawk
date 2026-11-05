<?php

namespace MailHawk;

use Groundhogg\Preferences;
use function Groundhogg\get_contactdata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles spam complaints (feedback loop reports) passed on by the MTA.
 *
 * The MTA has already put the address on the suppression list by the time we hear about it. Here we
 * stop Groundhogg mailing the contact and tell the site owner, in one digest rather than one email
 * per complaint.
 */
class Spam_Complaints {

	const OPTION = 'mailhawk_pending_spam_complaints';

	/**
	 * The most complaints held for a single digest
	 */
	const MAX_PENDING = 200;

	public function __construct() {
		add_action( 'mailhawk/spam_complaint', [ $this, 'handle_complaint' ], 10, 3 );
		add_action( 'mailhawk/send_complaint_digest', [ $this, 'send_digest' ] );
	}

	/**
	 * @param string $email_address
	 * @param string $msg_id
	 * @param array  $complaint
	 *
	 * @return void
	 */
	public function handle_complaint( $email_address, $msg_id, $complaint = [] ) {

		$this->maybe_mark_contact_complained( $email_address );
		$this->queue_notice( $email_address, $msg_id, $complaint );
	}

	/**
	 * Stop Groundhogg mailing the contact
	 *
	 * @param string $email_address
	 *
	 * @return void
	 */
	protected function maybe_mark_contact_complained( $email_address ) {

		if ( ! is_groundhogg_active() ) {
			return;
		}

		$contact = get_contactdata( $email_address );

		if ( ! $contact || ! $contact->exists() ) {
			return;
		}

		$contact->change_marketing_preference( Preferences::COMPLAINED );
	}

	/**
	 * Hold the complaint and schedule the digest to the site owner
	 *
	 * @param string $email_address
	 * @param string $msg_id
	 * @param array  $complaint
	 *
	 * @return void
	 */
	protected function queue_notice( $email_address, $msg_id, $complaint ) {

		$pending = get_option( self::OPTION, [] );

		if ( ! is_array( $pending ) ) {
			$pending = [];
		}

		$pending[] = [
			'email'    => $email_address,
			'msg_id'   => $msg_id,
			'reporter' => sanitize_text_field( $complaint['reported_by'] ?? '' ),
			'time'     => time(),
		];

		// Keep the most recent if a huge number arrive before the digest goes out
		$pending = array_slice( $pending, - self::MAX_PENDING );

		update_option( self::OPTION, $pending, false );

		if ( ! wp_next_scheduled( 'mailhawk/send_complaint_digest' ) ) {
			wp_schedule_single_event( time() + ( 10 * MINUTE_IN_SECONDS ), 'mailhawk/send_complaint_digest' );
		}
	}

	/**
	 * Email the site owner the complaints received since the last digest
	 *
	 * @return void
	 */
	public function send_digest() {

		$pending = get_option( self::OPTION, [] );

		// Clear first so a complaint arriving while we send is not lost or sent twice
		delete_option( self::OPTION );

		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}

		$count = count( $pending );

		$subject = sprintf( _n( 'Spam complaint: %s recipient reported your email on %s', 'Spam complaints: %s recipients reported your email on %s', $count, 'mailhawk' ), number_format_i18n( $count ), home_url_no_scheme() );

		$rows = '';

		foreach ( $pending as $complaint ) {

			$log_item = ! empty( $complaint['msg_id'] ) ? Plugin::instance()->log->get_by( 'msg_id', $complaint['msg_id'] ) : false;

			$rows .= sprintf(
				'<tr><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 0">%s</td></tr>',
				esc_html( $complaint['email'] ),
				esc_html( $log_item && ! empty( $log_item->subject ) ? $log_item->subject : '—' ),
				esc_html( $complaint['reporter'] ?: '—' )
			);
		}

		$content = '<p>' . esc_html( _n( 'A recipient marked an email from your site as spam.', 'Recipients marked emails from your site as spam.', $count, 'mailhawk' ) ) . '</p>'
		           . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;text-align:left">'
		           . '<tr><th style="padding:4px 12px 4px 0">' . esc_html__( 'Recipient', 'mailhawk' ) . '</th>'
		           . '<th style="padding:4px 12px 4px 0">' . esc_html__( 'Subject', 'mailhawk' ) . '</th>'
		           . '<th style="padding:4px 0">' . esc_html__( 'Reported by', 'mailhawk' ) . '</th></tr>'
		           . $rows . '</table>'
		           . '<p>' . esc_html__( 'MailHawk will not send to these addresses again. You do not need to do anything, but it is worth checking that these people agreed to receive your email, and that every email has an easy way to unsubscribe. Repeated complaints damage your sending reputation.', 'mailhawk' ) . '</p>';

		wp_mail( get_bloginfo( 'admin_email' ), $subject, $content, [
			'Content-Type: text/html',
			sprintf( 'From: MailHawk Plugin <%s>', get_default_from_email_address() )
		] );
	}

}
