<?php

namespace MailHawk;

class Installer extends Utils\Installer {

	/**
	 * Install the tables
	 */
	protected function activate() {
		Plugin::instance()->log->create_table();
		Plugin::instance()->recipients->create_table();
	}

	/**
	 * Tables to uninstall
	 */
	protected function get_table_names() {
		return [
			Plugin::instance()->log->get_table_name(),
			Plugin::instance()->recipients->get_table_name(),
		];
	}

	protected function deactivate() {
		// TODO: Implement deactivate() method.
	}

	/**
	 * Take the customer to connecting right after they activate the plugin
	 *
	 * @param string $plugin
	 *
	 * @return void
	 */
	public function plugin_activated( $plugin ) {

		$request = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( ! should_redirect_to_connect_after_activation( $plugin, $request ) ) {
			return;
		}

		wp_safe_redirect( mailhawk_admin_page() );
		exit;
	}

	function get_plugin_file() {
		return MAILHAWK__FILE__;
	}

	function get_plugin_version() {
		return MAILHAWK_VERSION;
	}

	protected function get_installer_name() {
		return MAILHAWK_TEXT_DOMAIN;
	}
}
