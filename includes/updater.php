<?php

namespace MailHawk;

class Updater extends \MailHawk\Utils\Updater{

	protected function get_updater_name() {
		return MAILHAWK_TEXT_DOMAIN;
	}

	protected function get_available_updates() {
		return [];
	}
}
