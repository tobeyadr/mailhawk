<?php

namespace MailHawk;

use MailHawk\Api\Postal\Send;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer as PHPMailer;

if ( ! class_exists( '\PHPMailer' ) ) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
}


class Hawk_Mailer extends PHPMailer {

	public function clearAltBody() {
		$this->AltBody = '';
	}

	/**
	 * A log item ID, can be sent if retrying an email.
	 *
	 * @var bool|int
	 */
	protected static $log_item_id = false;

	/**
	 * Set the log item ID
	 *
	 * @param $id
	 */
	public static function set_log_item_id( $id ) {
		self::$log_item_id = absint( $id );
	}

	/**
	 * Clear the log item ID so the next email is treated as a new one
	 */
	public static function reset_log_item_id() {
		self::$log_item_id = false;
	}

	/**
	 * Maybe add/update log data
	 *
	 * @param array $log_data
	 *
	 * @return bool|int
	 */
	public static function add_log( $log_data = [], $force = false ) {

		// Logging is disabled, but an existing row (a release or a retry) must still be updated or a released email stays quarantined
		if ( ! $force && ! self::$log_item_id && get_option( 'mailhawk_disable_email_logging' ) ) {
			return false;
		}

		if ( ! self::$log_item_id ) {
			return Plugin::instance()->log->add( $log_data );
		} else {
			return Plugin::instance()->log->update( self::$log_item_id, $log_data );
		}
	}

	/**
	 * Create a message and send it.
	 * Uses the sending method specified by $Mailer.
	 * @throws PHPMailerException
	 * @return boolean false on error - See the ErrorInfo property for details of the error.
	 */
	public function send() {

		if ( ! $this->preSend() ) {
			return false;
		}

		$message    = $this->getSentMIMEMessage();
		$recipients = array_keys( $this->all_recipients );
		$held       = [];

		// If a log item ID is set, we're releasing the message
		if ( ! self::$log_item_id ) {

			// 5 is an automatic quarantine by default
			$threshold = intval( apply_filters( 'mailhawk/quarantine_threshold', 5 ) );

			// Check the risk factor of the recipients, only the risky ones are held back
			foreach ( $recipients as $recipient ) {
				if ( assess_risk( $recipient ) >= $threshold ) {
					$held[] = $recipient;
				}
			}

			if ( ! empty( $held ) ) {
				// Schedule the quarantine notification
				Quarantine::schedule_quarantine_notice();
			}
		}

		$headers = [
			[ 'Content-Type', $this->ContentType ],
			[ 'From', sprintf( "%s <%s>", $this->FromName, $this->From ) ],
		];

		if ( $this->Sender ) {
			$headers[] = [ 'Sender', $this->Sender ];
		}

		$headers = array_merge( $headers, $this->CustomHeader );

		$log_data = [
			'recipients'    => $recipients,
			'from_address'  => $this->From,
			'subject'       => $this->Subject,
			'content'       => $this->Body,
			'altbody'       => $this->AltBody,
			'headers'       => $headers,
			'error_code'    => '',
			'error_message' => '',
			'msg_id'        => ''
		];

		// Some or all of the recipients are being quarantined...
		if ( ! empty( $held ) ) {

			$held_log_data = array_merge( $log_data, [
				'recipients'    => $held,
				'status'        => 'quarantine',
				'error_code'    => 'held',
				'error_message' => 'Held for review.',
			] );

			// If the hold can't be recorded the email would be lost without anyone knowing, so fail instead
			if ( ! self::add_log( $held_log_data, true ) ) {

				$error = new PHPMailerException( 'Could not hold this email for review, it was not sent.', self::STOP_CRITICAL );

				$this->mailHeader = '';
				$this->setError( $error->getMessage() );

				if ( $this->exceptions ) {
					throw $error;
				}

				return false;
			}

			$recipients = array_values( array_diff( $recipients, $held ) );

			// Everyone was held, nothing to send now
			if ( empty( $recipients ) ) {
				return true;
			}

			// The rest of the recipients are sent as normal
			$log_data['recipients'] = $recipients;
		}

		$msg_id = Send::raw( $this->From, $recipients, $message );

		if ( is_wp_error( $msg_id ) ) {

			$exc = new PHPMailerException( $msg_id->get_error_message(), self::STOP_CRITICAL );

			$this->mailHeader = '';
			$this->setError( $exc->getMessage() );

			if ( $this->exceptions ) {

				$log_data['status']        = 'failed';
				$log_data['error_code']    = $msg_id->get_error_code();
				$log_data['error_message'] = $msg_id->get_error_message();

				self::add_log( $log_data );

				throw $exc;
			}

			return false;
		}

		$log_data['status'] = 'sent';
		$log_data['msg_id'] = is_string( $msg_id ) ? $msg_id : '';

		if ( is_groundhogg_active() ) {
			\Groundhogg_Email_Services::set_message_id( $msg_id );
		}

		self::add_log( $log_data );

		return true;

	}
}
