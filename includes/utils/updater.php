<?php

namespace MailHawk\Utils;

use function MailHawk\action_url;
use function MailHawk\get_array_var;
use function MailHawk\get_url_var;

/**
 * Updater
 *
 * @since             File available since Release 1.0.16
 * @author            Adrian Tobey <info@mailhawk.io>
 * @copyrimailhawkt   Copyrimailhawkt (c) 2018, Groundhogg Inc.
 * @license           https://opensource.org/licenses/GPL-3.0 GNU Public License v3
 * @package           Includes
 */
abstract class Updater {

	/**
	 * @var bool if updates were done during the request
	 */
	protected $did_updates = false;

	/**
	 * WPGH_Upgrade constructor.
	 */
	public function __construct() {

		// Show updates are required
		add_action( 'admin_init', [ $this, 'listen_for_updates' ], 9 );
		add_action( 'admin_notices', [ $this, 'updates_notice' ] );

		// Save previous updates when plugin installed.
		add_action( 'mailhawk/activated', [ $this, 'save_previous_updates_when_installed' ], 99 );
	}

	/**
	 * Get the previous version which the plugin was updated to.
	 *
	 * @return string[]
	 */
	public function get_previous_versions() {
		return get_option( $this->get_version_option_name(), [] );
	}

	/**
	 * Gets the DB option name to retrieve the previous version.
	 *
	 * @return string
	 */
	protected function get_version_option_name() {
		return sanitize_key( sprintf( 'mailhawk_%s_version_updates', $this->get_updater_name() ) );
	}

	/**
	 * A unique name for the updater to avoid conflicts
	 *
	 * @return string
	 */
	abstract protected function get_updater_name();

	/**
	 * Get a list of updates which are available.
	 *
	 * @return string[]
	 */
	abstract protected function get_available_updates();

	/**
	 * Get a list of updates that do not update automatically, but will show on the updates page
	 *
	 * @return string[]
	 */
	protected function get_optional_updates() {
		return [];
	}

	/**
	 * Get a description of a certain update.
	 *
	 * @param $update
	 *
	 * @return string
	 */
	private function get_update_description( $update ) {
		return get_array_var( $this->get_update_descriptions(), $update );
	}

	/**
	 * Associative array of versions to descriptions
	 *
	 * @return string[]
	 */
	protected function get_update_descriptions() {
		return [];
	}

	/**
	 * Given a version number call the related function
	 *
	 * @param $version
	 *
	 * @return bool
	 */
	private function update_to_version( $version ) {

		// Check if the version we want to update to is greater than that of the db_version
		$func = $this->convert_version_to_function( $version );

		if ( $func && method_exists( $this, $func ) ) {

			call_user_func( array( $this, $func ) );

			$this->remember_version_update( $version );

			do_action( "mailhawk/updater/{$this->get_updater_name()}/{$func}" );

			return true;
		}

		return false;
	}

	/**
	 * Takes the current version number and converts it to a function which can be clled to perform the upgrade requirements.
	 *
	 * @param $version string
	 *
	 * @return bool|string
	 */
	private function convert_version_to_function( $version ) {

		$nums = explode( '.', $version );
		$func = sprintf( 'version_%s', implode( '_', $nums ) );

		if ( method_exists( $this, $func ) ) {
			return $func;
		}

		return false;
	}

	/**
	 * Set the last updated to version in the DB
	 *
	 * @param $version
	 *
	 * @return bool
	 */
	protected function remember_version_update( $version ) {
		$versions = $this->get_previous_versions();

		$date_of_updates = get_option( $this->get_version_option_name() . '_dates', [] );

		if ( ! in_array( $version, $versions ) ) {
			$versions[] = $version;
		}

		$date_of_updates[ $version ] = time();

		// Save the date updated for this version
		update_option( $this->get_version_option_name() . '_dates', $date_of_updates );

		return update_option( $this->get_version_option_name(), $versions );
	}

	/**
	 * Remove a version from the previous versions so that the updater will perform that version update
	 *
	 * @param $version
	 *
	 * @return bool
	 */
	public function forget_version_update( $version ) {
		$versions = $this->get_previous_versions();

		if ( ! in_array( $version, $versions ) ) {
			return false;
		}

		unset( $versions[ array_search( $version, $versions ) ] );

		return update_option( $this->get_version_option_name(), $versions );
	}

	/**
	 * When the plugin is installed save the initial versions.
	 * Do not overwrite older versions.
	 *
	 * @return bool
	 */
	public function save_previous_updates_when_installed() {

		$updates = $this->get_previous_versions();

		if ( ! empty( $updates ) ) {
			return false;
		}

		return update_option( $this->get_version_option_name(), $this->get_available_updates() );
	}

	/**
	 * If there are missing updates, show a notice to run the upgrade path.
	 *
	 * @return void
	 */
	public function updates_notice() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$previous_updates = $this->get_previous_versions();

		// No previous updates, if this is the case something has gone wrong...
		if ( empty( $previous_updates ) || $this->did_updates ) {
			return;
		}

		$available_updates = $this->get_available_updates();
		$missing_updates   = array_diff( $available_updates, $previous_updates );

		if ( empty( $missing_updates ) ) {
			return;
		}

		add_action( 'admin_notices', [ $this, 'updates_required_notice' ] );
	}

	/**
	 * Listen for the updates url param to tell us the updates button has been clicked
	 */
	public function listen_for_updates() {

		$action = 'mailhawk_' . $this->get_updater_name() . '_do_updates';

		if ( ! current_user_can( 'manage_options' ) || ! get_url_var( 'action' ) === $action || ! wp_verify_nonce( get_url_var( '_wpnonce' ), $action ) ) {
			return;
		}

		if ( $this->do_updates() ) {
		    add_action( 'admin_notices', [ $this,  'updates_successful_notice' ] );
		}
	}

	/**
	 * Show updates require notice.
	 */
	public function updates_required_notice() {

		$action = 'mailhawk_' . $this->get_updater_name() . '_do_updates';

		$action_url = action_url( $action, [ 'page' => 'mailhawk' ] );

	    ?>
        <div class="notice notice-warning">
            <p><?php _e( 'MailHawk database upgrade is required! Please consider backing up your site before upgrading.', 'mailhawk' ); ?></p>
            <p><a href="<?php echo esc_url( $action_url ); ?>"><?php _e( 'Upgrade database now!', 'mailhawk' ); ?></a></p>
        </div>
        <?php
    }

	/**
	 * Show successful updates notice.
	 */
	public function updates_successful_notice() {
		?>
        <div class="notice notice-success">
            <p><?php _e( 'Database upgrade was successful.', 'mailhawk' ); ?></p>
        </div>
		<?php
	}

	/**
	 * Check whether upgrades should happen or not.
	 */
	public function do_updates() {

		$update_lock = 'mailhawk_' . $this->get_updater_name() . '_doing_updates';

		// Check if an update lock is present.
		if ( get_transient( $update_lock ) ) {
			return false;
		}

		// Set lock so second update process cannot be run before this one is complete.
		set_transient( $update_lock, time(), MINUTE_IN_SECONDS );

		$previous_updates = $this->get_previous_versions();

		// No previous updates, if this is the case something has gone wrong...
		if ( empty( $previous_updates ) ) {
			return false;
		}

		$available_updates = $this->get_available_updates();
		$missing_updates   = array_diff( $available_updates, $previous_updates );

		if ( empty( $missing_updates ) ) {
			return false;
		}

		foreach ( $missing_updates as $update ) {
			$this->update_to_version( $update );
		}

		$this->did_updates = true;

		do_action( "mailhawk/updater/{$this->get_updater_name()}/finished" );

		return true;
	}

	/**
	 * Whether a certain update was performed or not.
	 *
	 * @param $version
	 *
	 * @return bool
	 */
	public function did_update( $version ) {
		return in_array( $version, $this->get_previous_versions() );
	}

	/**
	 * Get the plugin file for this extension
	 *
	 * @return string
	 */
	protected function get_plugin_file() {
		return MAILHAWK__FILE__;
	}
}