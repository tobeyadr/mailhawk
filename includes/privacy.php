<?php

namespace MailHawk;

use MailHawk\DB\Recipients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MailHawk in WordPress' privacy tools: exporting and erasing a person's data, and the text for the privacy policy.
 *
 * What is exported is what is kept about the person: the emails in the log that were sent to them, and what has been
 * learned about their address. The other recipients of an email are not theirs to see, so they are left out.
 */
class Privacy {

	/**
	 * How many log entries are looked at in one go
	 */
	const BATCH = 100;

	/**
	 * The most of an email's text that is exported, the log has the rest
	 */
	const EXPORT_CONTENT_LENGTH = 2000;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_erasers' ] );
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	/**
	 * @param array $exporters
	 *
	 * @return array
	 */
	public function register_exporters( $exporters ) {

		$exporters['mailhawk-email-log'] = [
			'exporter_friendly_name' => __( 'MailHawk email log', 'mailhawk' ),
			'callback'               => [ $this, 'export_email_log' ],
		];

		$exporters['mailhawk-recipients'] = [
			'exporter_friendly_name' => __( 'MailHawk recipients', 'mailhawk' ),
			'callback'               => [ $this, 'export_recipient' ],
		];

		return $exporters;
	}

	/**
	 * The emails in the log that were sent to this person, in batches. Emails that went to others as well are included,
	 * with only this person as the recipient.
	 *
	 * @param string $email_address
	 * @param int    $page
	 *
	 * @return array
	 */
	public function export_email_log( $email_address, $page = 1 ) {

		global $wpdb;

		$response = [ 'data' => [], 'done' => true ];

		$email = Plugin::instance()->recipients->normalize( $email_address );

		if ( ! $email ) {
			return $response;
		}

		$table = Plugin::instance()->log->get_table_name();
		$like  = '%' . $wpdb->esc_like( $email ) . '%';
		$page  = max( 1, absint( $page ) );

		// Nothing is removed while exporting, so the page can be where the batch starts
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, recipients, from_address, subject, content, status, msg_id, error_message, date_sent FROM {$table} WHERE recipients LIKE %s ORDER BY ID ASC LIMIT %d OFFSET %d",
			$like,
			self::BATCH,
			( $page - 1 ) * self::BATCH
		) );

		foreach ( (array) $rows as $row ) {

			// The address was only part of another one
			if ( ! in_array( $email, $this->get_addresses( $row->recipients ), true ) ) {
				continue;
			}

			// Paragraphs and lines end with a space, so the words around them are not joined
			$text = preg_replace( '/<\/(p|div|tr|li|h[1-6])>|<br\s*\/?>/i', ' ', (string) $row->content );
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

			if ( mb_strlen( $text ) > self::EXPORT_CONTENT_LENGTH ) {
				$text = mb_substr( $text, 0, self::EXPORT_CONTENT_LENGTH ) . '…';
			}

			$data = [
				[ 'name' => __( 'Sent to', 'mailhawk' ), 'value' => $email ],
				[ 'name' => __( 'Sent from', 'mailhawk' ), 'value' => (string) $row->from_address ],
				[ 'name' => __( 'Subject', 'mailhawk' ), 'value' => (string) $row->subject ],
				[ 'name' => __( 'Date', 'mailhawk' ), 'value' => (string) $row->date_sent ],
				[ 'name' => __( 'Status', 'mailhawk' ), 'value' => (string) $row->status ],
				[ 'name' => __( 'Content', 'mailhawk' ), 'value' => $text ],
			];

			if ( $row->msg_id ) {
				$data[] = [ 'name' => __( 'Message ID', 'mailhawk' ), 'value' => (string) $row->msg_id ];
			}

			if ( $row->error_message ) {
				$data[] = [ 'name' => __( 'Error', 'mailhawk' ), 'value' => (string) $row->error_message ];
			}

			$response['data'][] = [
				'group_id'          => 'mailhawk-email-log',
				'group_label'       => __( 'Emails sent through MailHawk', 'mailhawk' ),
				'group_description' => __( 'The emails this site sent to the address, as kept in the MailHawk log.', 'mailhawk' ),
				'item_id'           => 'mailhawk-email-' . absint( $row->ID ),
				'data'              => $data,
			];
		}

		$response['done'] = count( (array) $rows ) < self::BATCH;

		return $response;
	}

	/**
	 * What MailHawk has learned about the address
	 *
	 * @param string $email_address
	 * @param int    $page
	 *
	 * @return array
	 */
	public function export_recipient( $email_address, $page = 1 ) {

		$response = [ 'data' => [], 'done' => true ];

		$row = Plugin::instance()->recipients->get_by_email( $email_address );

		if ( ! $row ) {
			return $response;
		}

		if ( Recipients::TRUSTED === $row->status ) {
			$status = __( 'Trusted', 'mailhawk' );
		} else if ( Recipients::BLOCKED === $row->status ) {
			$status = __( 'Reported email from this site as spam', 'mailhawk' );
		} else {
			$status = __( 'Some email to the address bounced', 'mailhawk' );
		}

		$data = [
			[ 'name' => __( 'Email address', 'mailhawk' ), 'value' => (string) $row->email ],
			[ 'name' => __( 'Status', 'mailhawk' ), 'value' => $status ],
			[ 'name' => __( 'Bounces', 'mailhawk' ), 'value' => (string) absint( $row->bounces ) ],
			[ 'name' => __( 'Spam reports', 'mailhawk' ), 'value' => (string) absint( $row->complaints ) ],
			[ 'name' => __( 'Added', 'mailhawk' ), 'value' => (string) $row->date_added ],
		];

		if ( $row->last_bounce && '0000-00-00 00:00:00' !== $row->last_bounce ) {
			$data[] = [ 'name' => __( 'Last bounce', 'mailhawk' ), 'value' => (string) $row->last_bounce ];
		}

		$response['data'][] = [
			'group_id'          => 'mailhawk-recipients',
			'group_label'       => __( 'MailHawk recipient record', 'mailhawk' ),
			'group_description' => __( 'What MailHawk remembers about the address to avoid sending email that bounces or is not wanted.', 'mailhawk' ),
			'item_id'           => 'mailhawk-recipient-' . absint( $row->ID ),
			'data'              => $data,
		];

		return $response;
	}

	/**
	 * @param array $erasers
	 *
	 * @return array
	 */
	public function register_erasers( $erasers ) {

		$erasers['mailhawk-email-log'] = [
			'eraser_friendly_name' => __( 'MailHawk email log', 'mailhawk' ),
			'callback'             => [ $this, 'erase_email_log' ],
		];

		$erasers['mailhawk-recipients'] = [
			'eraser_friendly_name' => __( 'MailHawk recipients', 'mailhawk' ),
			'callback'             => [ $this, 'erase_recipient' ],
		];

		return $erasers;
	}

	/**
	 * The addresses an email in the log was sent to, lowercase and without names
	 *
	 * @param mixed $recipients what the log holds, normally a serialized array of addresses
	 *
	 * @return string[]
	 */
	protected function get_addresses( $recipients ) {

		$addresses = [];

		foreach ( (array) maybe_unserialize( $recipients ) as $recipient ) {

			if ( ! is_string( $recipient ) ) {
				continue;
			}

			// "Name <address>" is the same address
			if ( preg_match( '/<([^>]+)>/', $recipient, $matches ) ) {
				$recipient = $matches[1];
			}

			$address = Plugin::instance()->recipients->normalize( $recipient );

			if ( $address ) {
				$addresses[] = $address;
			}
		}

		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Erase the emails in the log that were sent to nobody but this person. An email that went to others as well is
	 * kept, it is theirs too, and the log is purged on its own after a while.
	 *
	 * @param string $email_address
	 * @param int    $page
	 *
	 * @return array
	 */
	public function erase_email_log( $email_address, $page = 1 ) {

		global $wpdb;

		$response = [
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];

		$email = Plugin::instance()->recipients->normalize( $email_address );

		if ( ! $email ) {
			return $response;
		}

		// Removing entries moves the ones after them up, so the place reached is remembered by entry instead of by page
		$state_key = 'mailhawk_erase_' . md5( $email );
		$state     = $page > 1 ? get_transient( $state_key ) : false;

		if ( ! is_array( $state ) ) {
			$state = [ 'last_id' => 0, 'retained' => 0 ];
		}

		$table = Plugin::instance()->log->get_table_name();
		$like  = '%' . $wpdb->esc_like( $email ) . '%';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, recipients FROM {$table} WHERE ID > %d AND recipients LIKE %s ORDER BY ID ASC LIMIT %d",
			$state['last_id'],
			$like,
			self::BATCH
		) );

		foreach ( (array) $rows as $row ) {

			$state['last_id'] = absint( $row->ID );

			$addresses = $this->get_addresses( $row->recipients );

			// The address was only part of another one
			if ( ! in_array( $email, $addresses, true ) ) {
				continue;
			}

			if ( count( $addresses ) > 1 ) {
				$state['retained'] ++;
				$response['items_retained'] = true;
				continue;
			}

			if ( Plugin::instance()->log->delete( $row->ID ) ) {
				$response['items_removed'] = true;
			}
		}

		$response['done'] = count( (array) $rows ) < self::BATCH;

		if ( ! $response['done'] ) {
			set_transient( $state_key, $state, HOUR_IN_SECONDS );

			return $response;
		}

		delete_transient( $state_key );

		if ( $state['retained'] > 0 ) {
			$response['items_retained'] = true;
			$response['messages'][]     = sprintf(
				_n(
					'%1$s email in the MailHawk log was also sent to other people, so it was kept. The log is purged automatically after %2$s days.',
					'%1$s emails in the MailHawk log were also sent to other people, so they were kept. The log is purged automatically after %2$s days.',
					$state['retained'],
					'mailhawk'
				),
				number_format_i18n( $state['retained'] ),
				number_format_i18n( get_log_retention_days() )
			);
		}

		return $response;
	}

	/**
	 * Erase what MailHawk has learned about the address. Someone who reported our email as spam stays on the list,
	 * forgetting them would mean emailing them again.
	 *
	 * @param string $email_address
	 * @param int    $page
	 *
	 * @return array
	 */
	public function erase_recipient( $email_address, $page = 1 ) {

		$response = [
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];

		$recipients = Plugin::instance()->recipients;
		$row        = $recipients->get_by_email( $email_address );

		if ( ! $row ) {
			return $response;
		}

		if ( Recipients::BLOCKED === $row->status ) {
			$response['items_retained'] = true;
			$response['messages'][]     = __( 'MailHawk kept the record that this address reported email as spam, so that it is not emailed again.', 'mailhawk' );

			return $response;
		}

		$response['items_removed'] = (bool) $recipients->delete( $row->ID );

		return $response;
	}

	/**
	 * Suggested text for the privacy policy, under Settings, Privacy
	 *
	 * @return void
	 */
	public function add_policy_content() {

		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<div class="wp-suggested-text">';

		$content .= '<h2>' . __( 'Email', 'mailhawk' ) . '</h2>';
		$content .= '<p class="privacy-policy-tutorial">' . __( 'MailHawk delivers the email this site sends. This is a suggestion for what to tell your visitors, check that it matches how you use it.', 'mailhawk' ) . '</p>';

		$content .= '<p>' . __( 'Email sent from this site is delivered through the MailHawk service (mailhawk.io). To deliver it, MailHawk receives each email, including the recipients, the subject and the content, and reports back whether it was delivered, bounced, or was reported as spam.', 'mailhawk' ) . '</p>';

		if ( get_option( 'mailhawk_disable_email_logging' ) ) {
			$content .= '<p>' . __( 'This site does not keep a log of the email it sends.', 'mailhawk' ) . '</p>';
		} else {
			$content .= '<p>' . sprintf(
					__( 'This site keeps a log of the email it sends, with the recipients, the subject and the content of each email. Entries are deleted after %1$s days, and emails held back for review after %2$s days.', 'mailhawk' ),
					number_format_i18n( get_log_retention_days() ),
					number_format_i18n( get_quarantine_retention_days() )
				) . '</p>';
		}

		$content .= '<p>' . __( 'To avoid emailing people who do not want it, this site remembers email addresses that bounce or report an email as spam, and addresses it was told to trust. For an address that reported spam this is kept even when the person asks for their data to be erased, so that they are not emailed again.', 'mailhawk' ) . '</p>';

		$content .= '</div>';

		wp_add_privacy_policy_content( 'MailHawk', wp_kses_post( $content ) );
	}
}
