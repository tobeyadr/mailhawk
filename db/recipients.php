<?php

namespace MailHawk\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What MailHawk has learned about the people it sends to.
 *
 * A row exists for an address once an admin has trusted it, or once the MTA reported a hard bounce or a spam
 * complaint for it. The quarantine system reads it through assess_risk(), so repeated sending to dead or unwilling
 * addresses gets held for review instead of hurting the sender reputation.
 */
class Recipients extends DB {

	/**
	 * An admin vouched for the address, it is never quarantined
	 */
	const TRUSTED = 'trusted';

	/**
	 * The recipient reported a message as spam, mail to the address is always held
	 */
	const BLOCKED = 'blocked';

	/**
	 * The longest address that fits the indexed column
	 */
	const MAX_EMAIL_LENGTH = 191;

	/**
	 * @var bool|null whether the table exists, null until checked
	 */
	protected $ready = null;

	public function get_db_suffix() {
		return 'mh_recipients';
	}

	public function get_primary_key() {
		return 'ID';
	}

	public function get_db_version() {
		return '1.0';
	}

	public function get_object_type() {
		return 'recipient';
	}

	public function get_date_key() {
		return 'date_updated';
	}

	/**
	 * Learn from the webhook feedback from the MTA
	 */
	protected function add_additional_actions() {
		add_action( 'mailhawk/bounced', [ $this, 'handle_bounce' ], 10, 2 );
		add_action( 'mailhawk/spam_complaint', [ $this, 'handle_complaint' ], 10, 3 );
	}

	/**
	 * Whether the table exists. A site that has upgraded the plugin but not run the database update yet must not
	 * fail to send, or to process a webhook, because of it.
	 *
	 * @return bool
	 */
	public function is_ready() {

		if ( $this->ready === null ) {
			$this->ready = $this->installed();
		}

		return $this->ready;
	}

	/**
	 * Lowercase and validate an address
	 *
	 * @param string $email
	 *
	 * @return string|false false if it can't be stored
	 */
	public function normalize( $email ) {

		$email = strtolower( trim( sanitize_email( (string) $email ) ) );

		if ( ! is_email( $email ) || strlen( $email ) > self::MAX_EMAIL_LENGTH ) {
			return false;
		}

		return $email;
	}

	/**
	 * @param string $email
	 *
	 * @return object|false
	 */
	public function get_by_email( $email ) {

		$email = $this->normalize( $email );

		if ( ! $email || ! $this->is_ready() ) {
			return false;
		}

		$row = $this->get_by( 'email', $email );

		return $row ?: false;
	}

	/**
	 * Whether the address reported email as spam. That record is kept: forgetting it would put the address back among
	 * the ones that can be emailed and trusted.
	 *
	 * @param object|null $row a row of the recipients table
	 *
	 * @return bool
	 */
	public function has_reported_spam( $row ) {
		return is_object( $row ) && ( absint( $row->complaints ?? 0 ) > 0 || ( $row->status ?? '' ) === self::BLOCKED );
	}

	/**
	 * Trust an address so mail to it is never quarantined. Refused for an address that has reported spam.
	 *
	 * @param string $email
	 * @param string $source how it came to be trusted, release|manual
	 *
	 * @return bool
	 */
	public function trust( $email, $source = 'release' ) {

		$email = $this->normalize( $email );

		if ( ! $email || ! $this->is_ready() ) {
			return false;
		}

		$row = $this->get_by_email( $email );

		// Someone who reported spam has said they don't want mail from this site
		if ( $this->has_reported_spam( $row ) ) {
			return false;
		}

		if ( $row && $row->status === self::TRUSTED ) {
			return true;
		}

		$data = [
			'status'       => self::TRUSTED,
			'source'       => sanitize_key( $source ),
			'date_updated' => current_time( 'mysql' ),
		];

		if ( $row ) {
			return $this->update( $row->ID, $data );
		}

		return (bool) $this->insert( array_merge( $data, [ 'email' => $email ] ) );
	}

	/**
	 * The risk added by hard bounces. A bounce is forgotten after a while because addresses get fixed, and each
	 * counts for less than a quarantine on its own, so one bounce adds to the doubt and repeated ones hold the mail.
	 *
	 * @param object $row
	 *
	 * @return int
	 */
	public function get_bounce_risk( $row ) {

		$bounces = absint( $row->bounces );

		if ( ! $bounces ) {
			return 0;
		}

		$last_bounce = strtotime( $row->last_bounce );
		$memory_days = absint( apply_filters( 'mailhawk/bounce_memory_days', 90 ) );

		if ( ! $last_bounce || $last_bounce < strtotime( $memory_days . ' days ago' ) ) {
			return 0;
		}

		$weight = intval( apply_filters( 'mailhawk/hard_bounce_risk', 3 ) );

		return min( $bounces, 3 ) * $weight;
	}

	/**
	 * A hard bounce for the address. Bounces taken from a trusted address withdraw the trust.
	 *
	 * @param string $email
	 *
	 * @return void
	 */
	public function record_bounce( $email ) {

		$email = $this->normalize( $email );

		if ( ! $email || ! $this->is_ready() ) {
			return;
		}

		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$this->table_name} ( email, status, source, bounces, last_bounce, date_added, date_updated )
			VALUES ( %s, '', 'webhook', 1, %s, %s, %s )
			ON DUPLICATE KEY UPDATE bounces = bounces + 1, last_bounce = VALUES( last_bounce ), date_updated = VALUES( date_updated ),
			status = IF( status = %s, '', status )",
			$email, $now, $now, $now, self::TRUSTED
		) );

		$this->set_last_changed();
	}

	/**
	 * A spam complaint for the address. Always blocks it and withdraws any trust.
	 *
	 * @param string $email
	 *
	 * @return void
	 */
	public function record_complaint( $email ) {

		$email = $this->normalize( $email );

		if ( ! $email || ! $this->is_ready() ) {
			return;
		}

		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$this->table_name} ( email, status, source, complaints, date_added, date_updated )
			VALUES ( %s, %s, 'webhook', 1, %s, %s )
			ON DUPLICATE KEY UPDATE complaints = complaints + 1, status = VALUES( status ), date_updated = VALUES( date_updated )",
			$email, self::BLOCKED, $now, $now
		) );

		$this->set_last_changed();
	}

	/**
	 * The MTA can deliver the same event more than once, and a bounce is reported by more than one event
	 *
	 * @param string $type
	 * @param string $email
	 * @param string $msg_id
	 *
	 * @return bool true if this was already counted
	 */
	protected function already_counted( $type, $email, $msg_id ) {

		if ( $msg_id === '' ) {
			return false;
		}

		$key = 'mailhawk_recipient_' . md5( $type . '|' . $email . '|' . $msg_id );

		if ( get_transient( $key ) ) {
			return true;
		}

		set_transient( $key, 1, DAY_IN_SECONDS );

		return false;
	}

	/**
	 * @param string $email
	 * @param string $msg_id
	 *
	 * @return void
	 */
	public function handle_bounce( $email, $msg_id = '' ) {

		$email = $this->normalize( $email );

		if ( ! $email || $this->already_counted( 'bounce', $email, (string) $msg_id ) ) {
			return;
		}

		$this->record_bounce( $email );
	}

	/**
	 * @param string $email
	 * @param string $msg_id
	 * @param array  $complaint
	 *
	 * @return void
	 */
	public function handle_complaint( $email, $msg_id = '', $complaint = [] ) {

		$email = $this->normalize( $email );

		if ( ! $email || $this->already_counted( 'complaint', $email, (string) $msg_id ) ) {
			return;
		}

		$this->record_complaint( $email );
	}

	/**
	 * Get columns and formats
	 *
	 * @return array
	 */
	public function get_columns() {
		return [
			'ID'           => '%d',
			'email'        => '%s',
			'status'       => '%s',
			'source'       => '%s',
			'bounces'      => '%d',
			'complaints'   => '%d',
			'last_bounce'  => '%s',
			'date_added'   => '%s',
			'date_updated' => '%s',
		];
	}

	/**
	 * @return array
	 */
	public function get_column_defaults() {
		return [
			'ID'           => 0,
			'email'        => '',
			'status'       => '',
			'source'       => '',
			'bounces'      => 0,
			'complaints'   => 0,
			'last_bounce'  => '0000-00-00 00:00:00',
			'date_added'   => current_time( 'mysql' ),
			'date_updated' => current_time( 'mysql' ),
		];
	}

	public function create_table() {

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$sql = "CREATE TABLE " . $this->table_name . " (
		ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email varchar(191) NOT NULL,
		status varchar(20) NOT NULL DEFAULT '',
		source varchar(20) NOT NULL DEFAULT '',
		bounces int(10) unsigned NOT NULL DEFAULT 0,
		complaints int(10) unsigned NOT NULL DEFAULT 0,
		last_bounce datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		date_updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		PRIMARY KEY (ID),
		UNIQUE KEY email (email),
		KEY status (status),
		KEY date_updated (date_updated)
		) {$this->get_charset_collate()};";

		dbDelta( $sql );

		update_option( $this->table_name . '_db_version', $this->version );

		// Check again, the table may only just have been created
		$this->ready = null;
	}
}
