<?php

namespace MailHawk;

use Groundhogg\Classes\Activity;
use MailHawk\DB\Recipients;
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

			// At most one notice a day, if one went out recently the next waits until a day after it
			$last_sent = absint( get_option( 'mailhawk_quarantine_notice_sent' ) );
			$send_at   = max( time() + ( 10 * MINUTE_IN_SECONDS ), $last_sent + DAY_IN_SECONDS );

			wp_schedule_single_event( $send_at, 'mailhawk/send_quarantine_notice' );
		}
	}

	/**
	 * What the administrator needs to know about, and act on, for the quarantine
	 *
	 * @param int $expiring_days how soon a held email is auto-rejected to count it as expiring
	 * @param int $limit         how many held emails to list, the ones closest to being rejected first
	 *
	 * @return array
	 */
	public static function get_report( $expiring_days = 7, $limit = 5 ) {

		global $wpdb;

		$log        = Plugin::instance()->log->get_table_name();
		$recipients = Plugin::instance()->recipients;
		$retention  = get_quarantine_retention_days();

		// Held emails are rejected once they are older than the retention, so they expire soonest when they are the oldest
		$expiring_before = get_log_cutoff( max( $retention - absint( $expiring_days ), 0 ) );

		$report = [
			'waiting'        => absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$log} WHERE `status` = 'quarantine'" ) ),
			'expiring'       => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE `status` = 'quarantine' AND `date_sent` <= %s", $expiring_before ) ) ),
			'expiring_days'  => absint( $expiring_days ),
			'retention_days' => $retention,
			'items'          => [],
			'blocked'        => 0,
			'bouncing'       => 0,
			'trusted'        => 0,
		];

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `ID`, `subject`, `recipients`, `date_sent` FROM {$log} WHERE `status` = 'quarantine' ORDER BY `date_sent` ASC LIMIT %d", absint( $limit ) ) );

		foreach ( (array) $rows as $row ) {

			// date_sent is the time of the site
			$held_at = ( new \DateTime( $row->date_sent, wp_timezone() ) )->getTimestamp();

			$report['items'][] = [
				'id'         => absint( $row->ID ),
				'subject'    => $row->subject,
				'recipients' => array_values( array_filter( (array) maybe_unserialize( $row->recipients ), 'is_string' ) ),
				'held_at'    => $held_at,
				'expires_at' => $held_at + ( $retention * DAY_IN_SECONDS ),
			];
		}

		if ( $recipients->is_ready() ) {

			$table       = $recipients->get_table_name();
			$memory_days = absint( apply_filters( 'mailhawk/bounce_memory_days', 90 ) );

			$report['blocked']  = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE `status` = %s", Recipients::BLOCKED ) ) );
			$report['trusted']  = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE `status` = %s", Recipients::TRUSTED ) ) );
			// Only the bounces that still add risk
			$report['bouncing'] = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE `bounces` > 0 AND `status` != %s AND `last_bounce` >= %s", Recipients::BLOCKED, date( 'Y-m-d H:i:s', strtotime( $memory_days . ' days ago' ) ) ) ) );
		}

		return $report;
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

		if ( ! $sent ) {
			// The timestamp is left alone so the next quarantined email schedules another attempt
			do_action( 'mailhawk/quarantine_notice_failed', $count_quarantined );

			return;
		}

		update_option( 'mailhawk_quarantine_notice_sent', time(), false );
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
		if ( ! is_a_contact( $contact ) ) {
			return $risk;
		}

		$recent_risk_eval = $contact->get_meta( 'mailhawk_risk_factor' );
		$evaluated_at     = $contact->get_meta( 'mailhawk_risk_factor_time' );

		// Missing meta can come back as false or an empty string, and the evaluation goes stale as engagement changes
		if ( is_numeric( $recent_risk_eval ) && is_numeric( $evaluated_at ) && intval( $evaluated_at ) > time() - absint( apply_filters( 'mailhawk/risk_factor_cache_seconds', WEEK_IN_SECONDS ) ) ) {
			return $risk + intval( $recent_risk_eval );
		}

		// A new contact hasn't had the chance to engage yet, so no engagement isn't held against them
		$grace_days = absint( apply_filters( 'mailhawk/new_contact_grace_days', 30 ) );
		$is_new     = $grace_days > 0 && $contact->get_date_created( true )->getTimestamp() > strtotime( $grace_days . ' days ago' );

		$risk_factor = 0;

		$rules = [
			// Name & email match lowers the risk, a generic role inbox raises it
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

				// Role addresses are shared inboxes, which collect more spam complaints and traps than a person's own
				if ( preg_match( sprintf( '/^(?:%s)(?:\.|@)/i', implode( '|', $inboxes ) ), $contact->get_email() ) ) {
					return false;
				}

				$fname = $contact->get_first_name();
				$lname = $contact->get_last_name();
				$parts = explode( '@', $contact->get_email() );

				// Not a well formed address, no signal either way
				if ( count( $parts ) !== 2 ) {
					return null;
				}

				$local_part = $parts[0];

				// Email address name contains "+" which is a testing email
				if ( get_email_hostname( $contact->get_email() ) === 'gmail.com' && str_contains( $local_part, '+' ) ) {
					return true;
				}

				// Only letters, remove numbers, periods, and hyphens
				$email_name = preg_replace( '/[^a-zA-Z]/', '', $local_part );

				// Nothing left to compare, no signal either way
				if ( $email_name === '' ) {
					return null;
				}

				// no first or last
				if ( ! $fname && ! $lname ) {
					return false;
				}

				// the email name is in the first or last
				// Handles "don@example.com" with first name "Donald"
				if ( ( $fname && stripos( $fname, $email_name ) !== false ) || ( $lname && stripos( $lname, $email_name ) !== false ) ) {
					return true;
				}

				// the first or last name is in the email name
				foreach ( [ $fname, $lname ] as $name ) {
					if ( $name && stripos( $email_name, $name ) !== false ) {
						return true;
					}
				}

				// Initials in order, but not necessarily adjacent
				if ( $fname && $lname && preg_match( sprintf( '/%s.*%s/i', preg_quote( substr( $fname, 0, 1 ), '/' ), preg_quote( substr( $lname, 0, 1 ), '/' ) ), $email_name ) ) {
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
			function () use ( $contact, $is_new ) {
				$opened = get_db( 'activity' )->exists( [
					'activity_type' => Activity::EMAIL_OPENED,
					'contact_id'    => $contact->get_id(),
					'after'         => strtotime( '60 days ago' ),
				] );

				return $opened ?: ( $is_new ? null : false );
			},

			// Has recent click activity
			function () use ( $contact, $is_new ) {
				$clicked = get_db( 'activity' )->exists( [
					'activity_type' => Activity::EMAIL_CLICKED,
					'contact_id'    => $contact->get_id(),
					'after'         => strtotime( '30 days ago' ),
				] );

				return $clicked ? - 2 : ( $is_new ? 0 : 1 );
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
		$contact->update_meta( 'mailhawk_risk_factor_time', time() );

		return $risk + $risk_factor;
	}
}
