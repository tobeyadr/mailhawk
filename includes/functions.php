<?php

namespace MailHawk;

/**
 * The URL of the MailHawk settings page
 *
 * @return string
 */
function get_admin_mailhawk_uri() {
	return add_query_arg( [ 'page' => 'mailhawk' ], admin_url( 'tools.php' ) );
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

	// If we have previously validated the email address.
	if ( Plugin::instance()->emails->exists( $email_address ) ) {
		$email_obj = Plugin::instance()->emails->get_by( 'email', $email_address );

		if ( in_array( $email_obj->status, get_valid_email_stati() ) ) {
			// Email status is valid, therefore we are OKAY
			return true;
		}

		// If not a valid status, return false;
		return false;
	}

	// If the account supports validating email addresses.
	if ( Api_Helper::instance()->is_connected_for_validation() ) {
		// Do validation

		$result = Api_Helper::instance()->validate_email_address( $email_address );

		if ( is_wp_error( $result ) ) {
			// API failed, assume email is valid.
			return true;
		}

		$status = $result['status'];

		Plugin::instance()->emails->add( [
			'email'  => sanitize_email( $email_address ),
			'status' => sanitize_key( $status )
		] );

		if ( ! in_array( $status, get_valid_email_stati() ) ) {
			return false;
		}

	}

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
	return urlencode( wp_unslash( get_array_var( $_GET, $key, $default ) ) );
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
function get_suggested_spf_record(){
	return "v=spf1 a mx include:spf.mailhawkwp.com ~all";
}

/**
 * Wrapper for API connection check.
 *
 * @return bool
 */
function mailhawk_is_connected() {

	return true; // Todo remove this
//	return Api_Helper::instance()->is_connected_for_mail();
}

/**
 * Check if the MailHawk was added to the current server SPF record.
 *
 * @return bool
 */
function mailhawk_spf_set() {

	if ( $set = get_transient( 'mailhawk_spf_set' ) ){
		return $set === 'yes';
	}

	$set = check_spf_ip( wp_parse_url( site_url(), PHP_URL_HOST ), 'spf.mailhawkwp.com' ) ? 'yes' : 'no';

	set_transient( 'mailhawk_spf_set', $set, HOUR_IN_SECONDS );

	return $set === 'yes';
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