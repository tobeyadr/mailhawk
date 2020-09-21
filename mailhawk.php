<?php

/**
 * Plugin Name: MailHawk
 * Plugin URI: https://mailhawk.io
 * Description: Send better email that will reach the inbox with MailHawk.
 * Version: 1.0.9
 * Author: MailHawk Inc.
 * Author URI: http://mailhawk.io
 * License: GPLv3
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

define( 'MAILHAWK_VERSION', '1.0.9' );
define( 'MAILHAWK_PREVIOUS_STABLE_VERSION', '1.0.8' );
define( 'MAILHAWK_LICENSE_SERVER_URL', 'https://mailhawk.io' );

define( 'MAILHAWK__FILE__', __FILE__ );
define( 'MAILHAWK_PLUGIN_BASE', plugin_basename( MAILHAWK__FILE__ ) );
define( 'MAILHAWK_PATH', plugin_dir_path( MAILHAWK__FILE__ ) );

define( 'MAILHAWK_URL', plugins_url( '/', MAILHAWK__FILE__ ) );

define( 'MAILHAWK_ASSETS_PATH', MAILHAWK_PATH . 'assets/' );
define( 'MAILHAWK_ASSETS_URL', MAILHAWK_URL . 'assets/' );

add_action( 'plugins_loaded', 'mailhawk_load_plugin_textdomain' );

define( 'MAILHAWK_TEXT_DOMAIN', 'mailhawk' );

if ( ! version_compare( PHP_VERSION, '5.6', '>=' ) ) {
    add_action( 'admin_notices', 'mailhawk_fail_php_version' );
} elseif ( ! version_compare( get_bloginfo( 'version' ), '4.9', '>=' ) ) {
    add_action( 'admin_notices', 'mailhawk_fail_wp_version' );
} else {
    require __DIR__ . 'includes/plugin.php';
}

/**
 * MailHawk loaded.
 *
 * Fires when MailHawk was fully loaded and instantiated.
 *
 * @since 1.0.0
 */
do_action( 'mailhawk/loaded' );

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
    load_plugin_textdomain( 'mailhawk', false, basename( dirname( __FILE__ ) ) . '/languages' );
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
    /* translators: %s: PHP version */
    $message = sprintf( esc_html__( 'MailHawk requires PHP version %s+, plugin is currently NOT RUNNING.', 'groundhogg' ), '5.6' );
    $html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
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
    /* translators: %s: WordPress version */
    $message = sprintf(esc_html__('MailHawk requires WordPress version %s+. Because you are using an earlier version, the plugin is currently NOT RUNNING.', 'groundhogg'), '4.9');
    $html_message = sprintf('<div class="error">%s</div>', wpautop($message));
    echo wp_kses_post($html_message);
}

