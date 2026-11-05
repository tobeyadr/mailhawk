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
	 * @param int|null $batch_interval seconds between batched deliveries (60 - 86400), null for one request per event
	 *
	 * @return object|\WP_Error
	 */
	public static function create( $name, $url, $all = true, $events = [], $batch_interval = null ) {

		$args = [
			'name'       => $name,
			'url'        => $url,
			'all_events' => $all ? 1 : 0,
			'events'     => $events
		];

		// Older MTA builds ignore this, so only send it when it is wanted
		if ( $batch_interval !== null ) {
			$args['batch_interval'] = absint( $batch_interval );
		}

		$response = self::request( 'webhooks/create', $args );

		return $response;
	}

	/**
	 * Update webhook events.
	 *
	 * @param $url
	 * @param $all
	 * @param $events
	 * @param int|null $batch_interval seconds between batched deliveries (60 - 86400), 0 to turn batching off, null to leave it as it is
	 *
	 * @return object|\WP_Error
	 */
	public static function update( $url, $all, $events, $batch_interval = null ) {

		$args = [
			'url'        => $url,
			'all_events' => $all ? 1 : 0,
			'events'     => $events
		];

		if ( $batch_interval !== null ) {
			$args['batch_interval'] = absint( $batch_interval );
		}

		$response = self::request( 'webhooks/update', $args );

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