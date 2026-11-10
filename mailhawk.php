<?php

/**
 * Plugin Name: MailHawk
 * Plugin URI: https://mailhawk.io
 * Description: Send better email that will reach the inbox with MailHawk.
 * Version: 1.5.1
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: MailHawk Inc.
 * Author URI: http://mailhawk.io
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * MailHawk is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * MailHawk is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MAILHAWK_VERSION', '1.5.1' );
define( 'MAILHAWK_PREVIOUS_STABLE_VERSION', '1.5.0' );
define( 'MAILHAWK_LICENSE_SERVER_URL', 'https://mailhawk.io' );

define( 'MAILHAWK__FILE__', __FILE__ );
define( 'MAILHAWK_PLUGIN_BASE', plugin_basename( MAILHAWK__FILE__ ) );
define( 'MAILHAWK_PATH', plugin_dir_path( MAILHAWK__FILE__ ) );

define( 'MAILHAWK_URL', plugins_url( '/', MAILHAWK__FILE__ ) );

define( 'MAILHAWK_ASSETS_PATH', MAILHAWK_PATH . 'assets/' );
define( 'MAILHAWK_ASSETS_URL', MAILHAWK_URL . 'assets/' );

add_action( 'plugins_loaded', 'mailhawk_load_plugin_textdomain' );

define( 'MAILHAWK_TEXT_DOMAIN', 'mailhawk' );

define( 'MAILHAWK_MINIMUM_PHP_VERSION', '7.4' );
define( 'MAILHAWK_MINIMUM_WP_VERSION', '6.9' );

// Nothing else is loaded when PHP is too old, the rest of the plugin uses syntax that would be a fatal error there.
// This file has to stay readable by old versions of PHP for that reason.
if ( ! version_compare( PHP_VERSION, MAILHAWK_MINIMUM_PHP_VERSION, '>=' ) ) {
    add_action( 'admin_notices', 'mailhawk_fail_php_version' );
    add_action( 'network_admin_notices', 'mailhawk_fail_php_version' );
} elseif ( ! version_compare( get_bloginfo( 'version' ), MAILHAWK_MINIMUM_WP_VERSION, '>=' ) ) {
    add_action( 'admin_notices', 'mailhawk_fail_wp_version' );
    add_action( 'network_admin_notices', 'mailhawk_fail_wp_version' );
} else {
    require __DIR__ . '/includes/plugin.php';

    /**
     * MailHawk loaded.
     *
     * Fires when MailHawk was fully loaded and instantiated.
     *
     * @since 1.0.0
     */
    do_action( 'mailhawk/loaded' );
}

/**
 * Load Groundhogg textdomain.
 *
 * Load gettext translate for Groundhogg text domain.
 *
 * @since 1.0.0
 *
 * @return void
 */
function mailhawk_load_plugin_textdomain() {
    load_plugin_textdomain( 'mailhawk', false, basename( __DIR__ ) . '/languages' );
}

/**
 * Groundhogg admin notice for minimum PHP version.
 *
 * Warning when the site doesn't have the minimum required PHP version.
 *
 * @since 2.0
 *
 * @return void
 */
function mailhawk_fail_php_version() {

    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    /* translators: 1: required PHP version, 2: the PHP version of the site */
    $message = sprintf( esc_html__( 'MailHawk requires PHP version %1$s or newer, and this site is on %2$s. MailHawk is currently NOT RUNNING and your emails are not being sent through it. Ask your host to update PHP.', 'mailhawk' ), esc_html( MAILHAWK_MINIMUM_PHP_VERSION ), esc_html( PHP_VERSION ) );
    $html_message = sprintf( '<div class="notice notice-error">%s</div>', wpautop( $message ) );
    echo wp_kses_post( $html_message );
}

/**
 * Groundhogg admin notice for minimum WordPress version.
 *
 * Warning when the site doesn't have the minimum required WordPress version.
 *
 * @since 2.0
 *
 * @return void
 */
function mailhawk_fail_wp_version(){

    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    /* translators: %s: WordPress version */
    $message = sprintf( esc_html__( 'MailHawk requires WordPress version %s or newer. Because you are using an earlier version, the plugin is currently NOT RUNNING.', 'mailhawk' ), esc_html( MAILHAWK_MINIMUM_WP_VERSION ) );
    $html_message = sprintf( '<div class="notice notice-error">%s</div>', wpautop( $message ) );
    echo wp_kses_post( $html_message );
}

