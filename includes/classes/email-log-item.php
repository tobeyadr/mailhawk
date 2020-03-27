<?php

namespace MailHawk\Classes;

use MailHawk\DB\DB;
use MailHawk\Plugin;

class Email_Log_Item extends Base_Object {

	/**
	 * Handle post setup actions...
	 */
	protected function post_setup() {

	}

	/**
	 * Get the log DB
	 *
	 * @return DB|\MailHawk\DB\Email_Log
	 */
	protected function get_db() {
		return Plugin::instance()->log;
	}
}
