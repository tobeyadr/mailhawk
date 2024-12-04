<?php

namespace MailHawk;

use MailHawk\Api\Postal\Webhooks;

class Updater extends \MailHawk\Utils\Updater{

	protected function get_updater_name() {
		return MAILHAWK_TEXT_DOMAIN;
	}

	/**
	 * The available updates
	 *
	 * @return string[]
	 */
	protected function get_available_updates() {
		return [
			'1.0.1.1',
			'1.1.1',
			'1.3.1'
		];
	}

	/**
	 * Create the email log
	 */
	public function version_1_0_1_1(){
		Plugin::instance()->log->create_table();
	}

	/**
	 * Update webhook
	 */
	public function version_1_1_1(){

		Webhooks::update( get_rest_api_webhook_listener_uri(), false, [
			'MessageDeliveryFailed',
			'MessageBounced',
		] );

	}

	/**
	 * Add the altbody column
	 */
	public function version_1_3_1(){
		Plugin::instance()->log->create_table();
	}
}

