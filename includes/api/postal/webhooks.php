<?php

namespace MailHawk\Api\Postal;

class Webhooks extends Postal {

	/**
	 * Create a new webhook.
	 *
	 * @param       $name
	 * @param       $url
	 * @param bool  $all
	 * @param array $events
	 *
	 * @return object|\WP_Error
	 */
	public static function create( $name, $url, $all = true, $events = [] ) {

		$response = self::request( 'webhooks/create', [
			'name'       => $name,
			'url'        => $url,
			'all_events' => $all ? 1 : 0,
			'events'     => $events
		] );

		return $response;
	}

	/**
	 * Update webhook events.
	 *
	 * @param $url
	 * @param $all
	 * @param $events
	 *
	 * @return object|\WP_Error
	 */
	public static function update( $url, $all, $events ) {
		$response = self::request( 'webhooks/update', [
			'url'        => $url,
			'all_events' => $all ? 1 : 0,
			'events'     => $events
		] );

		return $response;
	}

	/**
	 * Delete a webhook
	 *
	 * @param $url
	 *
	 * @return object|\WP_Error
	 */
	public static function delete( $url ) {
		$response = self::request( 'webhooks/delete', [
			'url'        => $url
		] );

		return $response;
	}

}