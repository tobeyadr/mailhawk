<?php

namespace MailHawk;

use MailHawk\Api\Postal\Webhooks;

class Updater extends \MailHawk\Utils\Updater{

	protected function get_updater_name() {
		return MAILHAWK_TEXT_DOMAIN;
	}

	protected function get_plugin_file() {
		return MAILHAWK__FILE__;
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
			'1.3.1',
			'1.4.0',
			'1.5.0',
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

	/**
	 * Subscribe to spam complaints, and have the MTA send webhook events in batches. Add the recipients table, trusted
	 * addresses and the bounces and complaints reported for them, and drop the old email addresses table, which
	 * nothing has read or written for a long time
	 */
	public function version_1_4_0(){
		Webhooks::update( get_rest_api_webhook_listener_uri(), false, get_webhook_events(), get_webhook_batch_interval() );

		Plugin::instance()->recipients->create_table();

		global $wpdb;

		$table = ( is_mailhawk_network_active() ? $wpdb->base_prefix : $wpdb->prefix ) . 'mh_email_addresses';

		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		delete_option( $table . '_db_version' );
	}

	/**
	 * Remove what the usage telemetry left behind. The site no longer counts or reports its usage, and the warnings
	 * about nearing the monthly limit come from MailHawk itself.
	 */
	public function version_1_5_0(){

		// Scheduled per site, so cleared on each site as it updates
		wp_clear_scheduled_hook( 'mailhawk/monthly' );

		// The telemetry kept these with update_core_option(), which is the main site on a network
		foreach ( [ 'mailhawk_sent_this_month', 'mailhawk_warnings_this_month', 'mailhawk_monthly_email_limit' ] as $option ) {

			if ( is_mailhawk_network_active() ) {
				delete_blog_option( get_main_site_id(), $option );
			} else {
				delete_option( $option );
			}
		}
	}
}

