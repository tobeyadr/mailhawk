<?php
namespace MailHawk;

/**
 * Uninstall MailHawk
 *
 * @package     MailHawk
 * @subpackage  Uninstall
 * @copyright   Copyright (c) 2015, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.4.3
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Load WPGH file.
include_once __DIR__ . '/mailhawk.php';

if( get_option( 'mailhawk_delete_all_data' ) ) {

	Plugin::instance()->emails->drop();
	Plugin::instance()->log->drop();

    /** Cleanup Cron Events */
    wp_clear_scheduled_hook( 'mailhawk_trim_log_entries' );
    wp_clear_scheduled_hook( 'mailhawk_retry_failed_emails' );

    global $wpdb;

    // Remove any transients and options we've left behind
    $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name RLIKE 'mailhawk'" );
}