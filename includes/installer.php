<?php

namespace MailHawk;

class Installer extends \MailHawk\Utils\Installer{

	/**
	 * Install the emails tabe
	 */
	protected function activate() {
		Plugin::instance()->emails->create_table();
	}

	/**
	 * Tables to uninstall
	 */
	protected function get_table_names() {
		return [
			Plugin::instance()->emails->get_table_name()
		];
	}

	protected function deactivate() {
		// TODO: Implement deactivate() method.
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
