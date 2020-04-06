<?php

namespace MailHawk;

class Updater extends \MailHawk\Utils\Updater{

	protected function get_updater_name() {
		return MAILHAWK_TEXT_DOMAIN;
	}

	protected function get_available_updates() {
		return [
			'1.0.1',
			'1.0.1.1'
		];
	}

	public function version_1_0_1_1(){
		Plugin::instance()->log->create_table();
	}
}
