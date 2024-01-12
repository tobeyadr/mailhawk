<?php

namespace MailHawk\Classes;

use MailHawk\DB\DB;
use MailHawk\Hawk_Mailer;
use MailHawk\Plugin;

class Email_Log_Item extends Base_Object {

	/**
	 * Just deletes the log item
	 *
	 * @return bool
	 */
	public function reject(): bool {
		return Plugin::instance()->log->delete( $this->ID );
	}

	/**
	 * Handle post setup actions...
	 */
	protected function post_setup() {

		$int_props = [
			'retries'
		];

		foreach ( $int_props as $prop ) {
			$this->$prop = intval( $this->$prop );
		}

	}

	/**
	 * Get the from name from the email headers
	 *
	 * @return string
	 */
	public function get_from_header(): string {

		foreach ( $this->headers as $header ) {
			if ( $header[0] === 'From' ) {
				return $header[1];
			}
		}

		return '<' . $this->from_address . '>';
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
	 * @throws \PHPMailer\PHPMailer\Exception
	 * @return bool
	 */
	public function retry() {

		// Compile headers!
		$headers = [];

		foreach ( $this->headers as $header ) {
			$headers[] = sprintf( "%s: %s\n", $header[0], $header[1] );
		}

		Hawk_Mailer::set_log_item_id( $this->get_id() );

		add_action( 'wp_mail_failed', [ $this, 'catch_mail_error' ] );

		// Mail this thing!
		$result = mailhawk_mail( $this->recipients, $this->subject, $this->content, $headers );

		if ( ! $result ) {
			// update retries

			$this->update( [
				'retries' => $this->retries + 1,
			] );
		}

		return $result;
	}

	/**
	 * @return mixed|string
	 */
	public function get_content_type() {
		foreach ( $this->headers as $header ) {
			if ( $header[0] === 'Content-Type' ) {
				return $header[1];
			}
		}

		return 'text/plain';
	}

	public function is_quarantined() {
		return $this->status === 'quarantine';
	}

	public function is_plain_text() {
		return $this->get_content_type() === 'text/plain';
	}

	public function get_preview_content() {
		if ( $this->is_plain_text() ) {
			return wpautop( esc_html( $this->content ) );
		}

		return $this->content;
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

	function get_date_sent() {
		return new \DateTime( $this->date_sent, wp_timezone() );
	}

}
