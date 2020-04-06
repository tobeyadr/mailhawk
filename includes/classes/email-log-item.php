<?php

namespace MailHawk\Classes;

use MailHawk\DB\DB;
use MailHawk\Hawk_Mailer;
use MailHawk\Plugin;

class Email_Log_Item extends Base_Object {

	/**
	 * Handle post setup actions...
	 */
	protected function post_setup() {

		$int_props = [
			'retries'
		];

		foreach ( $int_props as $prop ){
			$this->$prop = intval( $this->$prop );
		}

	}

	/**
	 * Get the log DB
	 *
	 * @return DB|\MailHawk\DB\Email_Log
	 */
	protected function get_db() {
		return Plugin::instance()->log;
	}

	/**
	 * Retry to send the email.
	 *
	 * @return bool
	 */
	public function retry() {

		// Compile headers!
		$headers = [];

		foreach ( $this->headers as $header ){
			$headers[] = sprintf( "%s: %s\n", $header[0], $header[1] );
		}

		Hawk_Mailer::set_log_item_id( $this->get_id() );

		add_action( 'wp_mail_failed', [ $this, 'catch_mail_error' ] );

		// Mail this thing!
		$result = mailhawk_mail( $this->recipients, $this->subject, $this->content, $headers );

		if ( ! $result ){
			// update retries

			$this->update( [
				'retries' => $this->retries + 1,
			] );
		}

		return $result;
	}

	/**
	 * @var \WP_Error
	 */
	public $error;

	/**
	 * Catch retry failed error
	 *
	 * @param $error \WP_Error
	 */
	public function catch_mail_error( $error ) {
		$this->error = $error;
	}

}
