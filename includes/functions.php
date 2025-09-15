<?php

namespace MailHawk;

use MailHawk\Api\Postal\Reporting;
use WP_Error;

/**
 * The URL of the MailHawk settings page
 *
 * @param array $params
 *
 * @return string
 */
function mailhawk_admin_page( $params = [] ) {
	$params = array_merge( [ 'page' => 'mailhawk' ], $params );

	return add_query_arg( $params, admin_url( 'tools.php' ) );
}

/**
 * Get the API webhook listener URL
 *
 * @return string
 */
function get_rest_api_webhook_listener_uri() {

	$token = get_option( 'mailhawk_api_webhook_token' );
	if ( ! $token ) {
		$token        = wp_generate_password( 8, false );
		$hashed_token = wp_hash_password( $token );
		update_option( 'mailhawk_api_webhook_token', $hashed_token );
	}

	return add_query_arg( 'mhtoken', $token, rest_url( 'mailhawk/listen' ) );
}

/**
 * Get the hostname of a URI or the current site
 *
 * @param $url
 *
 * @return array|false|mixed|null
 */
function get_hostname( $url = '' ) {

	if ( empty( $url ) ) {
		$url = home_url();
	}

	return wp_parse_url( $url, PHP_URL_HOST );
}

/**
 * Get the email address status
 *
 * @param $email_address
 *
 * @return bool
 */
function get_email_status( $email_address ) {

	$email_obj = Plugin::instance()->emails->get_by( 'email', $email_address );

	// If not a valid status, return false;
	if ( ! $email_obj ) {
		return false;
	}

	return $email_obj->status;
}

function get_email_hostname( $email_address ) {
	return explode( '@', $email_address )[1];
}

/**
 * Test whether an email address is valid.
 *
 * @param $email_address string an email address
 *
 * @return bool
 */
function is_valid_email( $email_address ) {

	if ( ! is_email( $email_address ) ) {
		return false;
	}


//	if ( get_email_hostname( $email_address ) ) {
//
//	}

	$global_email = sprintf( '*@%s', substr( $email_address, strpos( $email_address, '@' ) + 1 ) );

	// If we have previously validated the email address...
	if ( Plugin::instance()->emails->exists( $email_address ) ) {

		$status = get_email_status( $email_address );

		if ( in_array( $status, get_valid_email_stati() ) ) {
			// Email status is valid, therefore we are OKAY
			return true;
		}

		// If not a valid status, return false;
		return false;

		// Check global email is available...
	} else if ( Plugin::instance()->emails->exists( $global_email ) ) {

		$status = get_email_status( $global_email );

		if ( in_array( $status, get_valid_email_stati() ) ) {
			// Email status is valid, therefore we are OKAY
			return true;
		}

		// If not a valid status, return false;
		return false;

	}

	// Todo: enable this when we have email validation in place.
//	// If the account supports validating email addresses.
//	if ( Api_Helper::instance()->is_connected_for_validation() ) {
//		// Do validation
//
//		$result = Api_Helper::instance()->validate_email_address( $email_address );
//
//		if ( is_wp_error( $result ) ) {
//			// API failed, assume email is valid.
//			return true;
//		}
//
//		$status = $result['status'];
//
//		Plugin::instance()->emails->add( [
//			'email'  => sanitize_email( $email_address ),
//			'status' => sanitize_key( $status )
//		] );
//
//		if ( ! in_array( $status, get_valid_email_stati() ) ) {
//			return false;
//		}
//
//	}

	return true;
}

/**
 * Get any valid email stati
 *
 * @return array
 */
function get_valid_email_stati() {
	return [
		'valid',
		'whitelist'
	];
}

/**
 * Get a variable from an array or default if it doesn't exist.
 *
 * @param        $array
 * @param string $key
 * @param bool   $default
 *
 * @return mixed
 */
function get_array_var( $array, $key = '', $default = false ) {
	if ( isset_not_empty( $array, $key ) ) {
		if ( is_object( $array ) ) {
			return $array->$key;
		} else if ( is_array( $array ) ) {
			return $array[ $key ];
		}
	}

	return $default;
}

/**
 * Return if a value in an array isset and is not empty
 *
 * @param $array
 * @param $key
 *
 * @return bool
 */
function isset_not_empty( $array, $key = '' ) {
	if ( is_object( $array ) ) {
		return isset( $array->$key ) && ! empty( $array->$key );
	} else if ( is_array( $array ) ) {
		return isset( $array[ $key ] ) && ! empty( $array[ $key ] );
	}

	return false;
}

/**
 * Get a variable from the $_POST global
 *
 * @param string $key
 * @param bool   $default
 *
 * @return mixed
 */
function get_post_var( $key = '', $default = false ) {
	return wp_unslash( get_array_var( $_POST, $key, $default ) );
}

/**
 * Get a variable from the $_REQUEST global
 *
 * @param string $key
 * @param bool   $default
 *
 * @return mixed
 */
function get_request_var( $key = '', $default = false ) {
	return wp_unslash( get_array_var( $_REQUEST, $key, $default ) );
}

/**
 * Get a variable from the $_GET global
 *
 * @param string $key
 * @param bool   $default
 *
 * @return mixed
 */
function get_url_var( $key = '', $default = false ) {
	return urldecode( wp_unslash( get_array_var( $_GET, $key, $default ) ) );
}

/**
 * Convert array to HTML tag attributes
 *
 * @param $atts
 *
 * @return string
 */
function array_to_atts( $atts ) {
	$tag = '';
	foreach ( $atts as $key => $value ) {

		if ( empty( $value ) ) {
			continue;
		}

		if ( $key === 'style' && is_array( $value ) ) {
			$value = array_to_css( $value );
		}

		if ( is_array( $value ) ) {
			$value = implode( ' ', $value );
		}

		$tag .= sanitize_key( $key ) . '="' . esc_attr( $value ) . '" ';
	}

	return $tag;
}

/**
 * Convert array to CSS style attributes
 *
 * @param $atts
 *
 * @return string
 */
function array_to_css( $atts ) {
	$css = '';

	foreach ( $atts as $key => $value ) {

		if ( empty( $value ) || is_numeric( $key ) ) {
			continue;
		}

		$css .= sanitize_key( $key ) . ':' . esc_attr( $value ) . ';';
	}

	return $css;
}

/**
 * Set a cookie the WP way
 *
 * @param string $name
 * @param string $val
 * @param bool   $expiry
 *
 * @return bool
 */
function set_cookie( $name = '', $val = '', $expiry = false ) {
	return setcookie( $name, $val, time() + $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl() );
}

/**
 * Retrieve a cookie
 *
 * @param string $cookie
 * @param bool   $default
 *
 * @return mixed
 */
function get_cookie( $cookie = '', $default = false ) {
	return get_array_var( $_COOKIE, $cookie, $default );
}

/**
 * Delete a cookie
 *
 * @param string $cookie
 *
 * @return bool
 */
function delete_cookie( $cookie = '' ) {
	unset( $_COOKIE[ $cookie ] );

	// empty value and expiration one hour before
	return setcookie( $cookie, '', time() - 3600 );
}

/**
 * Ensures an array
 *
 * @param $array
 *
 * @return array
 */
function ensure_array( $array ) {
	if ( is_array( $array ) ) {
		return $array;
	}

	return [ $array ];
}

/**
 * Get the suggest SPF record
 *
 * Todo Make this a real suggested record based on the existing one.
 *
 * @return string
 */
function get_suggested_spf_record() {
	return "v=spf1 a mx include:spf.mailhawk.io ~all";
}

/**
 * Wrapper for API connection check.
 *
 * @return bool
 */
function mailhawk_is_connected() {
//	return true; // Todo remove this
	return get_option( 'mailhawk_is_connected' ) === 'yes';
}

/**
 * Make mailhawk connected or not
 *
 * @param bool $connected
 *
 * @return bool
 */
function set_mailhawk_is_connected( $connected = true ) {
	return update_option( 'mailhawk_is_connected', $connected ? 'yes' : 'no' );
}

/**
 * Wrapper for API connection check.
 *
 * @return bool
 */
function mailhawk_is_suspended() {
	return get_transient( 'mailhawk_is_suspended' ) === 'yes';
}

/**
 * Make mailhawk connected or not
 *
 * @param bool $suspened
 *
 * @return bool
 */
function set_mailhawk_is_suspended( $suspened = true ) {
	return set_transient( 'mailhawk_is_suspended', $suspened ? 'yes' : 'no', DAY_IN_SECONDS );
}

/**
 * Get the date/time format
 *
 * @return string
 */
function get_date_time_format() {
	return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
}

/**
 * Get core option based on mailhawk configuration
 *
 * @param $option
 *
 * @return false|mixed|null
 */
function get_core_option( $option = '' ) {

	if ( is_mailhawk_network_active() ) {
		return get_blog_option( get_main_site_id(), $option );
	}

	return get_option( $option );
}

/**
 * Update core option based on mailhawk configuration
 *
 * @param string $option
 * @param mixed  $value
 *
 * @return mixed
 */
function update_core_option( $option = '', $value = '' ) {

	if ( is_mailhawk_network_active() ) {
		return update_blog_option( get_main_site_id(), $option, $value );
	}

	return update_option( $option, $value );
}

/**
 * Get the API key for the network...
 *
 * @return mixed|void
 */
function get_mailhawk_api_key() {
	return get_core_option( 'mailhawk_mta_credential_key' );
}

/**
 * Set the mailhawk API key
 *
 * @param string $key
 *
 * @return bool
 */
function set_mailhawk_api_credentials( $key = '' ) {
	return update_option( 'mailhawk_mta_credential_key', $key );
}

/**
 * Return an actionable url
 *
 * @param       $action
 * @param array $args
 *
 * @return string
 */
function action_url( $action, $args = [] ) {
	$url_args = [
		'page'     => get_request_var( 'page' ),
		'tab'      => get_request_var( 'tab' ),
		'action'   => $action,
		'_wpnonce' => wp_create_nonce( $action )
	];

	$url_args = array_filter( array_merge( $url_args, $args ) );

	return add_query_arg( urlencode_deep( $url_args ), admin_url( 'admin.php' ) );
}

/**
 * Check if the MailHawk was added to the current server SPF record.
 *
 * Check is the SPF is set...
 *
 * @param $domain string the domain in question...
 *
 * @return bool
 */
function mailhawk_spf_is_set( $domain = '' ) {

	if ( ! $domain ) {
		$domain = home_url();
	}

	$set = check_spf_ip( wp_parse_url( $domain, PHP_URL_HOST ), 'spf.mta01.mailhawk.io' ) ? 'yes' : 'no';

	return $set === 'yes';
}

/**
 * Get the value of the SPF record or false if it doesn't exist.
 *
 * @param $hostname
 *
 * @return bool|string
 */
function get_spf_record( $hostname ) {
	$txt_records = @dns_get_record( $hostname, DNS_TXT );

	if ( empty( $txt_records ) ) {
		return false;
	}

	foreach ( $txt_records as $record ) {
		if ( array_key_exists( 'txt', $record ) ) {
			if ( strpos( $record['txt'], 'v=spf1' ) !== false ) {
				return $record['txt'];
			}
		}
	}

	return false;
}

/**
 * Check if IP address is allowed to send emails on behalf of the hostname by SPF record.
 *
 * @author Samui Banti - https://samiwell.eu
 *
 * @param string $hostname - The host name of the email address in format suitable for dns_get_record() function.
 * @param string $ip       - The IP address of the server that sends the email.
 *
 * @return bool if the server is allowed to send on the behalf of the hostname
 */
function check_spf_ip( $hostname, $ip ) {

	$txt_records = @dns_get_record( $hostname, DNS_TXT );

	if ( empty( $txt_records ) ) {
		return false;
	}

	foreach ( $txt_records as $record ) {
		if ( array_key_exists( 'txt', $record ) ) {
			if ( strpos( $record['txt'], 'v=spf1' ) !== false ) {
				if ( strpos( $record['txt'], $ip ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * If the JSON is your typical error response
 *
 * @param $json
 *
 * @return bool
 */
function is_json_error( $json ) {
	return isset_not_empty( $json, 'code' ) && isset_not_empty( $json, 'message' ) && get_array_var( $json, 'code' ) !== 'success';
}

/**
 * Convert JSON to a WP_Error
 *
 * @param $json
 *
 * @return bool|WP_Error
 */
function get_json_error( $json ) {
	if ( is_json_error( $json ) ) {
		return new WP_Error( get_array_var( $json, 'code' ), get_array_var( $json, 'message' ), get_array_var( $json, 'data' ) );
	}

	return false;
}

/**
 * Extract the host name from an email address
 *
 * @param $address
 *
 * @return bool|string
 */
function get_address_email_hostname( $address ) {

	if ( strpos( $address, '@' ) === false ) {
		return false;
	}

	return substr( $address, strpos( $address, '@' ) + 1 );
}

/**
 * Build a default site email address.
 *
 * @param        $url
 * @param string $prefix
 *
 * @return bool|string
 */
function build_site_email( $url, $prefix = 'wp' ) {

	$hostname = wp_parse_url( $url, PHP_URL_HOST );

	if ( ! $hostname ) {
		return false;
	}

	$domain = str_replace( 'www.', '', $hostname );

	// Invalid domain
	if ( ! $domain ) {
		return false;
	}

	$potential_email = $prefix . '@' . $domain;

	// Can't build an email address form this domain
	if ( ! is_email( $potential_email ) ) {
		return false;
	}

	return $potential_email;
}

/**
 * The number of days to retain log entries
 *
 * @return int
 */
function get_log_retention_days() {
	return absint( get_option( 'mailhawk_log_retention_in_days', 14 ) );
}

/**
 * The number of days to retain log entries
 *
 * @return int
 */
function get_email_retry_attempts() {
	return absint( get_option( 'mailhawk_failed_email_retries', 3 ) );
}

/**
 * Get the default from email address
 *
 * @return mixed|void
 */
function get_default_from_email_address() {
	return get_option( 'mailhawk_default_from_email_address' );
}

function home_url_no_scheme() {
	return preg_replace( '#^https?://#i', '', home_url() );
}

/**
 * Override the default from email
 *
 * @param $original_email_address
 *
 * @return mixed
 */
function sender_email( $original_email_address ) {

	// Might not be set.
	if ( ! isset_not_empty( $_SERVER, 'SERVER_NAME' ) ) {
		return $original_email_address;
	}

	// Get the site domain and get rid of www.
	$sitename = strtolower( $_SERVER['SERVER_NAME'] );

	if ( substr( $sitename, 0, 4 ) == 'www.' ) {
		$sitename = substr( $sitename, 4 );
	}

	$from_email = 'wordpress@' . $sitename;

	if ( $original_email_address === $from_email ) {
		$new_email_address = get_option( 'mailhawk_default_from_email_address' );

		if ( ! empty( $new_email_address ) ) {
			$original_email_address = $new_email_address;
		}
	}

	return $original_email_address;
}

/**
 * Override the default from name
 *
 * @param $original_email_from
 *
 * @return mixed
 */
function sender_name( $original_email_from ) {

	if ( $original_email_from === 'WordPress' ) {
		$new_email_from = get_option( 'mailhawk_default_from_name' );

		if ( ! empty( $new_email_from ) ) {
			$original_email_from = $new_email_from;
		}
	}

	return $original_email_from;
}

// Hooking up our functions to WordPress filters, these go first to override other plugins doing stuff.
add_filter( 'wp_mail_from', __NAMESPACE__ . '\sender_email', 9 );
add_filter( 'wp_mail_from_name', __NAMESPACE__ . '\sender_name', 9 );

/**
 * Get's the display name of a status
 *
 * @param $status string
 *
 * @return mixed
 */
function get_email_status_pretty_name( $status ) {

	$status = strtolower( $status );

	$stati = [
		'sent'       => __( 'Sent', 'mailhawk' ),
		'failed'     => __( 'Failed', 'mailhawk' ),
		'delivered'  => __( 'Delivered', 'mailhawk' ),
		'bounced'    => __( 'Bounced', 'mailhawk' ),
		'softfail'   => __( 'Soft Fail', 'mailhawk' ),
		'quarantine' => __( 'Quarantined', 'mailhawk' ),
	];

	return get_array_var( $stati, $status );
}

/**
 * Output an wp_json error if the test email failed to send for whatever reason.
 *
 * @param $wp_error
 */
function fue_test_email_output_error_msg() {
	add_action( 'wp_mail_failed', function ( $error ) {
		wp_send_json_error( $error );
	} );
}

add_action( 'fue_before_test_email_send', __NAMESPACE__ . '\fue_test_email_output_error_msg' );

/**
 * Get the current email usage
 *
 * @return array|bool
 */
function get_email_usage() {

	$usage = get_transient( 'mailhawk_email_usage' );

	if ( $usage ) {
		return $usage;
	}

	$limits = Reporting::limits();

	if ( is_wp_error( $limits ) ) {
		return false;
	}

	$monthly_send_limit = $limits[ array_search( 'monthly_send_limit', wp_list_pluck( $limits, 'type' ) ) ];

	$usage = $monthly_send_limit->usage;
	$limit = $monthly_send_limit->limit;

	$percent_usage = floor( ( $usage / $limit ?: 1 ) * 100 );

	$result = [
		'percent'   => $percent_usage,
		'usage'     => $usage,
		'limit'     => $limit,
		'remaining' => $limit - $usage
	];

	update_core_option( 'mailhawk_monthly_email_limit', $limit );
	set_transient( 'mailhawk_email_usage', $result, MINUTE_IN_SECONDS );

	return $result;
}

/**
 * Gets data from a transient, if not set use the callback function and update the transient
 *
 * @param $transient string
 * @param $callback  callable
 *
 * @return mixed
 */
function maybe_get_from_transient( $transient, $callback, $ttl = DAY_IN_SECONDS ) {

	$data = get_transient( $transient );

	if ( empty( $data ) ) {
		$data = call_user_func( $callback );
		set_transient( $transient, $data, $ttl );
	}

	return $data;
}


/**
 * List of free inbox providers
 *
 * @return array
 */
function get_free_inbox_providers(): array {
	static $providers = [];

	// initialize providers
	if ( empty( $providers ) ) {
		$providers = json_decode( file_get_contents( MAILHAWK_ASSETS_PATH . 'lib/free-email-providers.json' ), true );
		$providers = apply_filters( 'mailhawk/get_free_inbox_providers', $providers );
	}

	return $providers;
}


/**
 * Get the hostname of an email address
 *
 * @param $email string
 *
 * @return string
 */
function get_email_address_hostname( string $email ) {

	if ( ! is_email( $email ) ) {
		return false;
	}

	$parts = explode( '@', $email );

	return $parts[1];
}

/**
 * If the given email is from a free inbox provider
 *
 * @param $email string
 *
 * @return bool
 */
function is_free_email_provider( string $email ): bool {

	if ( ! is_email( $email ) ) {
		return false;
	}

	return apply_filters( 'mailhawk/is_free_email_provider', key_exists( get_email_address_hostname( $email ), get_free_inbox_providers() ), $email );
}

/**
 * If the given email is from a free inbox provider
 *
 * @param $email string
 *
 * @return int
 */
function get_inbox_risk( string $email ): int {

	if ( ! is_email( $email ) || ! is_free_email_provider( $email ) ) {
		return 0;
	}

	$hostname = get_email_address_hostname( $email );
	$inboxes  = get_free_inbox_providers();

	return apply_filters( 'mailhawk/get_inbox_risk', $inboxes[ $hostname ], $email );
}

/**
 * Assess the risk of sending an email to a particular user
 *
 * @param string $email_address
 *
 * @return int
 */
function assess_risk( string $email_address ): int {

	// The baseline risk is 0;
	$risk = 0;

	// Test quarantine system using + synatx
	if ( str_contains( $email_address, '+test-quarantine@' ) ) {
		return 999;
	}

	// It's the admin email, dw about it
	if ( $email_address === get_option( 'admin_email' ) ) {
		return 0;
	}

	// Same hostname as the site
	if ( get_email_address_hostname( $email_address ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		return 0;
	}

	// Free email providers get risk increased
	if ( is_free_email_provider( $email_address ) ) {
		$risk += get_inbox_risk( $email_address );
	}

	// If they are a user, reduce their score
	if ( email_exists( $email_address ) ) {
		$risk -= 1;
	}

	return apply_filters( 'mailhawk/assess_risk', $risk, $email_address );
}

/**
 * Is groundhogg installed maybe?
 *
 * @return bool
 */
function is_groundhogg_active() {
	return defined( 'GROUNDHOGG_VERSION' );
}

/**
 * Create a string list that ends witgh and
 *
 * @param $array
 *
 * @return mixed|string
 */
function andList( $array ) {
	if ( empty( $array ) ) {
		return '';
	}
	if ( count( $array ) === 1 ) {
		return $array[0];
	}

	return sprintf( _x( '%s and %s', 'and preceding the last item in a list', 'mailhawk' ),
		implode( ', ', array_slice( $array, 0, - 1 ) ), $array[ count( $array ) - 1 ] );
}
