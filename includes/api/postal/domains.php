<?php

namespace MailHawk\Api\Postal;

class Domains extends Postal {

	/**
	 * Create a new domain
	 *
	 * @param $domain
	 *
	 * @return object|\WP_Error
	 */
	public static function create( $domain ) {

		$response = self::request( 'domains/create', [
			'name' => $domain
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->data;
	}

	/**
	 * Delete a domain
	 *
	 * @param $domain
	 *
	 * @return object|\WP_Error
	 */
	public static function delete( $domain ) {
		$response = self::request( 'domains/delete', [
			'name' => $domain
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->data;
	}

	/**
	 * Check the status of a domain
	 *
	 * @param $domain
	 *
	 * @return object|\WP_Error
	 */
	public static function check( $domain ) {
		$response = self::request( 'domains/check', [
			'name' => $domain
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->data;
	}

	/**
	 * Query a single domain
	 *
	 * @param $domain
	 *
	 * @return object|\WP_Error
	 */
	public static function query( $domain ) {
		$response = self::request( 'domains/query', [
			'name' => $domain
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->data;
	}

	/**
	 * Query all domains
	 *
	 * @return object|\WP_Error
	 */
	public static function query_all() {
		$response = self::request( 'domains/query', [
			'domain' => '',
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->data;
	}

	/**
	 * Only return domains which have been fully verified...
	 *
	 * @return array|bool
	 */
	public static function get_verified() {


		$domains = self::query_all();

		if ( is_wp_error( $domains ) || empty( $domains ) ) {
			return false;
		}

		$verified = [];

		foreach ( $domains as $domain ) {
			if ( $domain->spf->spf_status === 'OK' && $domain->dkim->dkim_status === 'OK' ) {
				$verified[] = $domain;
			}
		}

		return $verified;

	}


}