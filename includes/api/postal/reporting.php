<?php

namespace MailHawk\Api\Postal;

class Reporting extends Postal {

	/**
	 * Query the reporting...
	 *
	 * @param int $duration
	 * @param string $type
	 *
	 * @return object|\WP_Error
	 */
	public static function query( $duration = 7, $type = 'yearly' ) {

		$response = self::request( 'reporting/query', [
			'duration' => $duration,
			'type'     => $type,
		] );

		if ( is_wp_error( $response ) ){
			return $response;
		}

		return $response->data;
	}

	/**
	 * Get the server limits and usage.
	 *
	 * @return array|\WP_Error
	 */
	public static function limits() {

		$response = self::request( 'reporting/limits', [
			'foo' => 'bar',
		] );

		if ( is_wp_error( $response ) ){
			return $response;
		}

		return (array) $response->data;
	}

}
