<?php

namespace MailHawk;

use Groundhogg\Classes\Activity;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;
use function Groundhogg\is_a_contact;

class Quarantine {

	public function __construct() {
		add_action( 'mailhawk/send_quarantine_notice', [ $this, 'send_quarantine_notice' ] );
		add_filter( 'mailhawk/assess_risk', [ $this, 'groundhogg_filter_risk_assessment' ], 10, 2 );
	}

	/**
	 * Adds the cron event to send an email warning that emails are quarantined
	 * Sends 10 mins in the future
	 *
	 * @return void
	 */
	public static function schedule_quarantine_notice() {
		if ( ! wp_next_scheduled( 'mailhawk/send_quarantine_notice' ) ) {
			wp_schedule_single_event( time() + ( 10 * MINUTE_IN_SECONDS ), 'mailhawk/send_quarantine_notice' );
		}
	}

	/**
	 * Sends an admin notice notifying that there are emails in quarantine and action is required
	 *
	 * @return void
	 */
	public function send_quarantine_notice() {

		$count_quarantined = \MailHawk\Plugin::instance()->log->count( [
			'status' => 'quarantine'
		] );

		// Someone must have take care of it already
		if ( $count_quarantined === 0 ) {
			return;
		}

		$subject = sprintf( _n( 'Action Required: %s email quarantined on %s', 'Action Required: %s emails quarantined on %s', $count_quarantined, 'mailhawk' ), number_format_i18n( $count_quarantined ), home_url_no_scheme() );

		ob_start();

		include __DIR__ . '/../templates/emails/quarantine-notice.php';

		$content = ob_get_clean();

		$sent = wp_mail( get_bloginfo( 'admin_email' ), $subject, $content, [
			'Content-Type: text/html',
			sprintf( 'From: MailHawk Plugin <%s>', get_default_from_email_address() )
		] );

		// todo handle send failure?
	}

	/**
	 * Additional rules to assess risk of sending an email.
	 *
	 * businessname@gmail.com
	 * adrian.tobey@gmail.com
	 * theboss@gmail.com
	 *
	 * @param int    $risk
	 * @param string $email_address
	 *
	 * @return int
	 */
	public function groundhogg_filter_risk_assessment( int $risk, string $email_address ): int {

		// Groundhogg is not installed
		if ( ! is_groundhogg_active() ) {
			return $risk;
		}

		$contact = get_contactdata( $email_address );

		// Contact record for the given email is not available
		if ( ! is_a_contact( $email_address ) ) {
			return $risk;
		}

		$recent_risk_eval = $contact->get_meta( 'mailhawk_risk_factor' );

		if ( $recent_risk_eval !== false ) {
			return $risk + intval( $recent_risk_eval );
		}

		$risk_factor = 0;

		$rules = [
			// Name & email match or it's a generic inbox
			function () use ( $contact ) {

				$inboxes = [
					'help',
					'helpdesk',
					'info',
					'hello',
					'billing',
					'feedback',
					'sales',
					'contact',
					'support',
					'hr',
					'tech',
					'techsupport',
					'media',
					'admin',
					'events',
					'careers',
					'press',
					'marketing',
					'donations',
					'team',
				];

				if ( preg_match( sprintf( '/^(?:%s)(?:\.|@)/i', implode( '|', $inboxes ) ), $contact->get_email() ) ) {
					return true;
				}

				$fname      = $contact->get_first_name();
				$lname      = $contact->get_last_name();
				$email_name = explode( '@', $contact->get_email() )[1];

				// Email address name contains "+" which is a testing email
				if ( get_email_hostname( $contact->get_email() ) === 'gmail.com' && str_contains( $email_name, '+' ) ) {
					return true;
				}

				// Only letters, remove numbers, periods, and hyphens
				$email_name = preg_replace( '/[^a-zA-Z]/', '', $email_name );

				// no first or last
				if ( ! $fname && ! $lname ) {
					return false;
				}

				// the email name is in the first or last
				// Handles "don@example.com" with first name "Donald"
				if ( preg_match( "/$email_name/i", $fname ) || preg_match( "/$email_name/i", $lname ) ) {
					return true;
				}

				$patterns = [
					$fname,
					$lname,
					// Initials in order, but not necessarily adjacent
					substr( $fname, 0, 1 ) . '.*' . substr( $lname, 0, 1 ) . '.*',
				];

				if ( preg_match( sprintf( '/%s/i', implode( '|', $patterns ) ), $email_name ) ) {
					return true;
				}

				if ( strlen( $email_name ) > strlen( $fname ) ) {
					similar_text( $email_name, $fname, $per );
				} else {
					similar_text( $fname, $email_name, $per );
				}

				if ( $per > 50 ) {
					return true;
				}

				return false;
			},

			// Has recent page visits
			function () use ( $contact ) {
				return get_db( 'page_visits' )->exists( [
					'contact_id' => $contact->get_id(),
					'after'      => strtotime( '30 days ago' ),
				] ) ? - 1 : 0;
			},

			// Has recent open activity
			function () use ( $contact ) {
				return get_db( 'activity' )->exists( [
					'activity_type' => Activity::EMAIL_OPENED,
					'contact_id'    => $contact->get_id(),
					'after'         => strtotime( '60 days ago' ),
				] );
			},

			// Has recent click activity
			function () use ( $contact ) {
				return get_db( 'activity' )->exists( [
					'activity_type' => Activity::EMAIL_CLICKED,
					'contact_id'    => $contact->get_id(),
					'after'         => strtotime( '30 days ago' ),
				] ) ? - 2 : 1;
			},
		];

		foreach ( $rules as $rule ) {

			$result = call_user_func( $rule );

			if ( $result === true ) {
				$risk_factor -= 1;
			} else if ( $result === false ) {
				$risk_factor += 1;
			} else if ( is_int( $result ) ) {
				$risk_factor += $result;
			}

			// Null or 0 can be returned as neutral risk factor
		}

		$contact->update_meta( 'mailhawk_risk_factor', $risk_factor );

		return $risk + $risk_factor;
	}
}
