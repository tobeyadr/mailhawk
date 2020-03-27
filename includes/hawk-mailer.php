<?php

namespace MailHawk;

use MailHawk\Api\Postal\Send;

if ( ! class_exists( '\PHPMailer' ) ) {
	require_once ABSPATH . WPINC . '/class-phpmailer.php';
	require_once ABSPATH . WPINC . '/class-smtp.php';
}

class Hawk_Mailer extends \PHPMailer {

	/**
	 * Create a message and send it.
	 * Uses the sending method specified by $Mailer.
	 * @return boolean false on error - See the ErrorInfo property for details of the error.
	 * @throws \phpmailerException
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
			[ 'Sender', $this->Sender ],
		];

		$headers = array_merge( $headers, $this->CustomHeader );

		$log_data = [
			'recipients'   => $recipients,
			'from_address' => $this->From,
			'subject'      => $this->Subject,
			'content'      => $this->Body,
			'headers'      => $headers,
			'raw'          => $message,
		];

		if ( is_wp_error( $msg_id ) ) {

			$exc = new \phpmailerException( $msg_id->get_error_message(), self::STOP_CRITICAL );

			$this->mailHeader = '';
			$this->setError( $exc->getMessage() );

			if ( $this->exceptions ) {

				$log_data['status']        = 'failed';
				$log_data['error']         = $msg_id->get_error_code();
				$log_data['error_message'] = $msg_id->get_error_message();

				Plugin::instance()->log->add( $log_data );

				throw $exc;
			}

			return false;
		}

		$log_data['status'] = 'sent';
		$log_data['msg_id'] = is_string( $msg_id ) ?: '';

		Plugin::instance()->log->add( $log_data );

		return true;

	}
}