<?php

namespace MailHawk;

if ( ! class_exists( '\PHPMailer' ) ) {
	require_once ABSPATH . WPINC . '/class-phpmailer.php';
	require_once ABSPATH . WPINC . '/class-smtp.php';
}

class Hawk_Mailer extends \PHPMailer {

	/**
	 * Create a message and send it.
	 * Uses the sending method specified by $Mailer.
	 * @throws \phpmailerException
	 * @return boolean false on error - See the ErrorInfo property for details of the error.
	 */
	public function send() {

		if ( ! $this->preSend() ) {
			return false;
		}

		$message = $this->getSentMIMEMessage();

		$response = Api_Helper::instance()->send_raw_email( $this->From, $this->getToAddresses(), $message );

		if ( is_wp_error( $response ) ) {
			$exc = new \phpmailerException( $response->get_error_message(), self::STOP_CRITICAL );

			$this->mailHeader = '';
			$this->setError( $exc->getMessage() );

			if ( $this->exceptions ) {
				throw $exc;
			}

			return false;
		}

		return true;

	}
}