<?php

namespace MailHawk;

use MailHawk\Api\Postal\Send;
use MailHawk\PHPMailer\Exception;
use MailHawk\PHPMailer\PHPMailer;

class Hawk_Mailer extends PHPMailer {

	public function clearAltBody(){
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
	 * Maybe add/update log data
	 *
	 * @param array $log_data
	 *
	 * @return bool|int
	 */
	public static function add_log( $log_data = [] ) {
		if ( ! self::$log_item_id ) {
			return Plugin::instance()->log->add( $log_data );
		} else {
			return Plugin::instance()->log->update( self::$log_item_id, $log_data );
		}
	}

	/**
	 * Create a message and send it.
	 * Uses the sending method specified by $Mailer.
	 * @return boolean false on error - See the ErrorInfo property for details of the error.
	 * @throws Exception
	 */
	public function send() {

		if ( ! $this->preSend() ) {
			return false;
		}

		$message    = $this->getSentMIMEMessage();

		$recipients = array_keys( $this->all_recipients );

		$msg_id = Send::raw( $this->From, $recipients, $message );

		$headers = [
			[ 'Content-Type', $this->ContentType ],
			[ 'From', sprintf( "%s <%s>", $this->FromName, $this->From ) ],
		];

		if ( $this->Sender ){
			$headers[] = [ 'Sender', $this->Sender ];
		}

		$headers = array_merge( $headers, $this->CustomHeader );

		$log_data = [
			'recipients'    => $recipients,
			'from_address'  => $this->From,
			'subject'       => $this->Subject,
			'content'       => $this->Body,
			'headers'       => $headers,
			'raw'           => $message,
			'error_code'    => '',
			'error_message' => '',
			'msg_id'        => ''
		];

		if ( is_wp_error( $msg_id ) ) {

			$exc = new Exception( $msg_id->get_error_message(), self::STOP_CRITICAL );

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

		self::add_log( $log_data );

		return true;

	}
}