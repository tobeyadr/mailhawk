<?php

namespace MailHawk;

/**
 * Get's the sender domain to authenticate all subsites
 *
 * @param string $prefix
 *
 * @return mixed|void
 */
function get_authenticated_sender_inbox( $prefix = 'sites' ) {
	$domain = get_authenticated_sender_domain();

	if ( ! $domain ) {
		return false;
	}

	return sprintf( '%s@%s', $prefix, $domain );
}

/**
 * Get's the sender domain to authenticate all subsites
 *
 * @return mixed|void
 */
function get_authenticated_sender_domain() {
	$domain = get_blog_option( get_main_site_id(), 'mailhawk_ms_sender_domain' );

	return apply_filters( 'mailhawk/ms/authenticated_sender_domain', $domain );
}

/**
 * Get the email limit subsites are allowed to send before the email is rejected...
 *
 * @return mixed|void
 */
function get_sub_site_email_limit() {
	$limit = absint( get_blog_option( get_main_site_id(), 'mailhawk_ms_email_limit' ) );

	return apply_filters( 'mailhawk/ms/authenticated_sender_domain', $limit );
}

/**
 * Whether MailHawk is network active or not.
 *
 * @return bool
 */
function is_mailhawk_network_active() {
	if ( ! is_multisite() ) {
		return false;
	}

	$plugins = get_site_option( 'active_sitewide_plugins' );

	if ( isset( $plugins['mailhawk/mailhawk.php'] ) ) {
		return true;
	}

	return false;
}