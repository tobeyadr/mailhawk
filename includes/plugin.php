<?php

namespace MailHawk;

use MailHawk\Admin\Admin;
use MailHawk\Api\Webhook_Listener;
use MailHawk\DB\Email_Log;
use MailHawk\DB\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MailHawk plugin.
 *
 * The main plugin handler class is responsible for initializing mailhawk. The
 * class registers and all the components required to run the plugin.
 *
 * @since 2.0
 */
class Plugin {

	/**
	 * @var Emails
	 */
	public $emails;

	/**
	 * @var Installer
	 */
	public $installer;

	/**
	 * @var Updater
	 */
	public $updater;

	/**
	 * @var Email_Log
	 */
	public $log;

	/**
	 * Instance.
	 *
	 * Holds the plugin instance.
	 *
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 * @var Plugin
	 */
	public static $instance = null;

	/**
	 * Clone.
	 *
	 * Disable class cloning and throw an error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object. Therefore, we don't want the object to be cloned.
	 *
	 * @access public
	 * @since 1.0.0
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'mailhawk' ), '2.0.0' );
	}

	/**
	 * Wakeup.
	 *
	 * Disable unserializing of the class.
	 *
	 * @access public
	 * @since 1.0.0
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'mailhawk' ), '2.0.0' );
	}

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @return Plugin An instance of the class.
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Init.
	 *
	 * Initialize mailhawk Plugin. Register mailhawk support for all the
	 * supported post types and initialize mailhawk components.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function init() {

		$this->includes();
		$this->init_components();

		/**
		 * mailhawk init.
		 *
		 * Fires on mailhawk init, after mailhawk has finished loading but
		 * before any headers are sent.
		 *
		 * @since 1.0.0
		 */
		do_action( 'mailhawk/init' );
	}

	/**
	 * Init components.
	 *
	 * Initialize mailhawk components. Register actions, run setting manager,
	 * initialize all the components that run mailhawk, and if in admin page
	 * initialize admin components.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function init_components() {
		$this->emails = new Emails();
		$this->log    = new Email_Log();
		new Cron_Events();

		if ( is_admin() ) {
			new Admin();
		}

		Groundhogg::instance();

		$this->installer = new Installer();
		$this->updater   = new Updater();

		// Init Rest API
		add_action( 'rest_api_init', function (){
			new Webhook_Listener();
		} );

		// Compat for Follow Up Emails Plugin
		add_filter( 'fue_mail_method', function (){
			return 'wp_mail';
		}, 99 );

		new Telemetry();
		new Quarantine();
	}

	/**
	 * Register autoloader.
	 *
	 * mailhawk autoloader loads all the classes needed to run the plugin.
	 *
	 * @since 1.6.0
	 * @access private
	 */
	private function register_autoloader() {
		require __DIR__ . '/autoloader.php';

		Autoloader::run();
	}

	/**
	 * Plugin constructor.
	 *
	 * Initializing mailhawk plugin.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function __construct() {

		$this->register_autoloader();
		$this->load_immediate();

		if ( did_action( 'plugins_loaded' ) ) {
			$this->init();
		} else {
			add_action( 'plugins_loaded', [ $this, 'init' ], 0 );
		}

	}

	/**
	 * Include any files that must be loaded right away
	 */
	protected function load_immediate() {
		require __DIR__ . '/multisite.php';
		require __DIR__ . '/pluggable.php';
	}

	/**
	 * Include other files
	 */
	private function includes() {
		require __DIR__ . '/functions.php';
	}
}

Plugin::instance();
