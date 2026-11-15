<?php

namespace MailHawk;

use MailHawk\Api\Postal\Messages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Undoes the bounces that versions before 1.5.1 recorded for emails the MTA gave up on for reasons of its own.
 *
 * After a day of temporary failures that were not about the recipient (no answer, a fault at the provider, a rate
 * limit), the MTA gives up on an email. Versions up to 1.5.0 took that for a bounce: the address got a bounce in the
 * recipients table, which counts towards quarantine, and Groundhogg marked the contact Hard Bounced, which stops its
 * marketing email. 1.3.x did so even for emails another site on the same MailHawk server sent. On 14 and 15 November
 * 2026 an outage at MailHawk made that happen for working addresses on many sites.
 *
 * Once, in the background, after the update to 1.5.4: ask the MTA which emails of this server it gave up on, take those
 * bounces back out of the recipients table, and put back a contact Groundhogg marked Hard Bounced at that moment. Its
 * earlier status is not kept anywhere, so the contact becomes Unsubscribed if it ever unsubscribed and Unconfirmed
 * otherwise, and gets a note that says why. A contact whose status changed at any other time is left alone.
 */
class Give_Up_Repair {

	const HOOK = 'mailhawk_give_up_repair';

	/**
	 * What the repair did, or why it has not yet
	 */
	const OPTION = 'mailhawk_give_up_repair';

	/**
	 * The MTA keeps no more than this, and nothing before the first give-up matters
	 */
	const MAX_DAYS = 45;
	const FIRST_GIVE_UP = 1793491200; // 2026-11-01 00:00 UTC, before the MTA started giving up this way

	/**
	 * How close to a give-up a contact's change to Hard Bounced has to be to have been caused by it. Webhooks can be
	 * batched for a few minutes, and a site's clock can be off.
	 */
	const BEFORE = 15 * MINUTE_IN_SECONDS;
	const AFTER = 6 * HOUR_IN_SECONDS;

	/**
	 * Tries before giving up on an MTA that does not answer
	 */
	const MAX_TRIES = 12;

	public function __construct() {
		add_action( self::HOOK, [ $this, 'run' ] );

		// The update routine only runs when an admin visits, and a site that updates itself may not see one for a while
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	/**
	 * Schedule the repair until it has run
	 */
	public function maybe_schedule() {
		if ( ! self::get_result() ) {
			self::schedule();
		}
	}

	/**
	 * Run it soon, in the background. Called by the update to 1.5.4.
	 *
	 * @param int $delay seconds
	 */
	public static function schedule( $delay = MINUTE_IN_SECONDS ) {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	/**
	 * @return array what the last run did
	 */
	public static function get_result() {
		$result = get_option( self::OPTION, [] );

		return is_array( $result ) ? $result : [];
	}

	public function run() {

		$result = self::get_result();

		if ( ! empty( $result['done'] ) ) {
			return;
		}

		$tries = absint( $result['tries'] ?? 0 ) + 1;

		// Nothing was sent through MailHawk from here, or nothing can be asked
		if ( ! mailhawk_is_connected() ) {
			update_option( self::OPTION, [ 'done' => time(), 'skipped' => 'not connected' ], false );

			return;
		}

		$since    = max( self::FIRST_GIVE_UP, time() - self::MAX_DAYS * DAY_IN_SECONDS + HOUR_IN_SECONDS );
		$given_up = Messages::given_up( $since );

		if ( is_wp_error( $given_up ) ) {

			$retry = $tries < self::MAX_TRIES && 'mailhawk_bad_request' !== $given_up->get_error_code();

			update_option( self::OPTION, [
				'done'  => $retry ? 0 : time(),
				'tries' => $tries,
				'error' => $given_up->get_error_code() . ': ' . $given_up->get_error_message(),
			], false );

			if ( $retry ) {
				// An MTA that does not have the list yet is given longer
				self::schedule( 'mailhawk_unsupported' === $given_up->get_error_code() ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
			}

			return;
		}

		$result = array_merge( [ 'done' => time(), 'tries' => $tries ], $this->repair( $given_up ) );

		update_option( self::OPTION, $result, false );

		/**
		 * After the bounces of give-ups were undone
		 *
		 * @param array $result addresses, bounces_removed, contacts_restored
		 */
		do_action( 'mailhawk/give_up_repair/done', $result );
	}

	/**
	 * @param array $given_up [ rcpt_to, message_id, time ] as Messages::given_up() gives them
	 *
	 * @return array addresses, bounces_removed, contacts_restored
	 */
	public function repair( array $given_up ) {

		$by_address = [];

		foreach ( $given_up as $entry ) {
			$address = $entry['rcpt_to'];

			if ( ! isset( $by_address[ $address ] ) ) {
				$by_address[ $address ] = [];
			}

			$by_address[ $address ][] = absint( $entry['time'] );
		}

		$bounces  = 0;
		$contacts = 0;

		foreach ( $by_address as $address => $times ) {
			$bounces  += $this->remove_bounces( $address, count( $times ) );
			$contacts += $this->restore_contact( $address, $times ) ? 1 : 0;
		}

		return [
			'addresses'         => count( $by_address ),
			'bounces_removed'   => $bounces,
			'contacts_restored' => $contacts,
		];
	}

	/**
	 * Take up to $count bounces off the address in the recipients table.
	 *
	 * @param string $address
	 * @param int    $count
	 *
	 * @return int how many were taken off
	 */
	protected function remove_bounces( $address, $count ) {

		$recipients = Plugin::instance()->recipients;
		$row        = $recipients->get_by_email( $address );

		if ( ! $row || ! absint( $row->bounces ) ) {
			return 0;
		}

		$removed = min( absint( $row->bounces ), $count );
		$left    = absint( $row->bounces ) - $removed;

		$recipients->update( $row->ID, [
			'bounces'      => $left,
			'last_bounce'  => $left ? $row->last_bounce : '0000-00-00 00:00:00',
			'date_updated' => current_time( 'mysql' ),
		] );

		return $removed;
	}

	/**
	 * Put back a Groundhogg contact that was marked Hard Bounced because of a give-up.
	 *
	 * @param string $address
	 * @param int[]  $times when the MTA gave up on emails to it
	 *
	 * @return bool whether it was put back
	 */
	protected function restore_contact( $address, array $times ) {

		if ( ! function_exists( '\Groundhogg\get_contactdata' ) || ! class_exists( '\Groundhogg\Preferences' ) ) {
			return false;
		}

		$contact = \Groundhogg\get_contactdata( $address );

		if ( ! $contact || ! $contact->exists() || $contact->get_optin_status() !== \Groundhogg\Preferences::HARD_BOUNCE ) {
			return false;
		}

		// Groundhogg keeps the time of the change in the site's time zone
		$changed = $contact->date_optin_status_changed ? strtotime( get_gmt_from_date( $contact->date_optin_status_changed ) . ' UTC' ) : 0;

		if ( ! $changed || ! $this->caused_by_give_up( $changed, $times ) ) {
			return false;
		}

		$unsubscribed = \Groundhogg\get_db( 'activity' )->count( [
			'contact_id'    => $contact->get_id(),
			'activity_type' => 'unsubscribed',
		] );

		$status = $unsubscribed ? \Groundhogg\Preferences::UNSUBSCRIBED : \Groundhogg\Preferences::UNCONFIRMED;

		$contact->change_marketing_preference( $status );

		$contact->add_note( sprintf(
			/* translators: 1: date, 2: the status the contact has now */
			__( 'MailHawk set this contact back to %2$s. It was marked Hard Bounced on %1$s because MailHawk could not deliver an email for a day, not because the address bounced. Its status from before that is not known.', 'mailhawk' ),
			date_i18n( get_option( 'date_format' ), $changed ),
			$unsubscribed ? __( 'Unsubscribed', 'mailhawk' ) : __( 'Unconfirmed', 'mailhawk' )
		) );

		return true;
	}

	/**
	 * @param int   $changed when the contact became Hard Bounced
	 * @param int[] $times   when the MTA gave up on emails to it
	 *
	 * @return bool
	 */
	public function caused_by_give_up( $changed, array $times ) {
		foreach ( $times as $time ) {
			if ( $changed >= $time - self::BEFORE && $changed <= $time + self::AFTER ) {
				return true;
			}
		}

		return false;
	}
}
