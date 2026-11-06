<?php

namespace MailHawk\Abilities;

use MailHawk\Abilities\Domains\Add_Domain;
use MailHawk\Abilities\Domains\Check_Domain_Dns;
use MailHawk\Abilities\Domains\Delete_Domain;
use MailHawk\Abilities\Domains\Get_Domain_Dns;
use MailHawk\Abilities\Domains\List_Domains;
use MailHawk\Abilities\Email\Get_Email;
use MailHawk\Abilities\Email\List_Emails;
use MailHawk\Abilities\Email\Retry_Email;
use MailHawk\Abilities\Email\Send_Test_Email;
use MailHawk\Abilities\Quarantine\Get_Quarantine_Report;
use MailHawk\Abilities\Quarantine\Reject_Email;
use MailHawk\Abilities\Quarantine\Release_Email;
use MailHawk\Abilities\Recipients\Assess_Recipient_Risk;
use MailHawk\Abilities\Recipients\List_Recipients;
use MailHawk\Abilities\Recipients\Remove_Recipient;
use MailHawk\Abilities\Recipients\Trust_Recipient;
use MailHawk\Abilities\Settings\Get_Settings;
use MailHawk\Abilities\Settings\Update_Settings;
use MailHawk\Abilities\Status\Get_Reports;
use MailHawk\Abilities\Status\Get_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers MailHawk's abilities with the WordPress Abilities API (WordPress 6.9 and later), which is how AI tools
 * find out what a plugin can do and ask it to do it. On older versions this does nothing.
 *
 * WordPress only accepts registrations while the wp_abilities_api_categories_init and wp_abilities_api_init actions
 * are running, so that is when this registers. Another plugin can add its own next to these on the
 * mailhawk/abilities/register_categories and mailhawk/abilities/register_abilities actions, and a site can turn them
 * all off with the mailhawk/abilities/enabled filter.
 */
class Abilities {

	/**
	 * Categories and ability classes already handed to WordPress, so none is registered twice
	 *
	 * @var array<string, true>
	 */
	protected static array $registered = [];

	public function __construct() {

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * @return bool
	 */
	protected function is_enabled(): bool {

		/**
		 * Turn MailHawk's abilities off
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'mailhawk/abilities/enabled', true );
	}

	/**
	 * Register an ability category, for MailHawk's own and for add-ons on the mailhawk/abilities/register_categories action
	 *
	 * @param string $slug
	 * @param array  $args label and description
	 *
	 * @return void
	 */
	public static function add_category( string $slug, array $args ) {

		if ( isset( self::$registered[ 'category:' . $slug ] ) ) {
			return;
		}

		self::$registered[ 'category:' . $slug ] = true;

		wp_register_ability_category( $slug, $args );
	}

	/**
	 * Register an ability class, for MailHawk's own and for add-ons on the mailhawk/abilities/register_abilities action
	 *
	 * @param string $class a class that extends MailHawk\Abilities\Ability
	 *
	 * @return void
	 */
	public static function add_ability( string $class ) {

		if ( isset( self::$registered[ $class ] ) || ! class_exists( $class ) ) {
			return;
		}

		self::$registered[ $class ] = true;

		new $class();
	}

	public function register_categories() {

		if ( ! $this->is_enabled() ) {
			return;
		}

		self::add_category( 'mailhawk-status', [
			'label'       => __( 'MailHawk Status', 'mailhawk' ),
			'description' => __( 'Check whether MailHawk is connected and sending well, how much of the plan is used, and how much was sent, bounced and reported as spam.', 'mailhawk' ),
		] );

		self::add_category( 'mailhawk-domains', [
			'label'       => __( 'MailHawk Domains', 'mailhawk' ),
			'description' => __( 'Register the domains email is sent from, and get the DNS records that make email from them trusted.', 'mailhawk' ),
		] );

		self::add_category( 'mailhawk-email', [
			'label'       => __( 'MailHawk Email', 'mailhawk' ),
			'description' => __( 'Look through the log of email sent by the site, retry what failed, and send a test email.', 'mailhawk' ),
		] );

		self::add_category( 'mailhawk-quarantine', [
			'label'       => __( 'MailHawk Quarantine', 'mailhawk' ),
			'description' => __( 'Review the emails MailHawk held back for sending to risky addresses, and release or reject them.', 'mailhawk' ),
		] );

		self::add_category( 'mailhawk-recipients', [
			'label'       => __( 'MailHawk Recipients', 'mailhawk' ),
			'description' => __( 'See what MailHawk has learned about the addresses it sends to, and trust or forget them.', 'mailhawk' ),
		] );

		self::add_category( 'mailhawk-settings', [
			'label'       => __( 'MailHawk Settings', 'mailhawk' ),
			'description' => __( 'Read and change the sender defaults and the email log settings. Credentials are never available.', 'mailhawk' ),
		] );

		/**
		 * Register another category for an add-on's abilities with MailHawk\Abilities\Abilities::add_category()
		 */
		do_action( 'mailhawk/abilities/register_categories' );
	}

	public function register_abilities() {

		if ( ! $this->is_enabled() ) {
			return;
		}

		$abilities = [
			Get_Status::class,
			Get_Reports::class,
			List_Domains::class,
			Get_Domain_Dns::class,
			Add_Domain::class,
			Check_Domain_Dns::class,
			Delete_Domain::class,
			List_Emails::class,
			Get_Email::class,
			Send_Test_Email::class,
			Retry_Email::class,
			Get_Quarantine_Report::class,
			Release_Email::class,
			Reject_Email::class,
			List_Recipients::class,
			Assess_Recipient_Risk::class,
			Trust_Recipient::class,
			Remove_Recipient::class,
			Get_Settings::class,
			Update_Settings::class,
		];

		foreach ( $abilities as $ability ) {
			self::add_ability( $ability );
		}

		/**
		 * Register another ability for an add-on with MailHawk\Abilities\Abilities::add_ability()
		 */
		do_action( 'mailhawk/abilities/register_abilities' );
	}
}
