<?php

namespace MailHawk\Api\Postal;

class Send extends Postal {

	public static function raw( $mail_from = '', $to = [], $data = '' ) {

		$args = [
			'mail_from' => $mail_from,
			'rcpt_to'   => array_filter( array_values( $to ) ),
			'data'      => base64_encode( $data )
		];

		$response = self::request( 'send/raw', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

}