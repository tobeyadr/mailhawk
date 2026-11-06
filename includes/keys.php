<?php

namespace MailHawk;

class Keys {

	/**
	 * @var Keys
	 */
	public static $instance;

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @since  1.0.0
	 * @access public
	 * @static
	 *
	 * @return Keys An instance of the class.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	final public function __clone() {
		trigger_error( "Singleton. No cloning allowed!", E_USER_ERROR );
	}

	final public function __wakeup() {
		trigger_error( "Singleton. No serialization allowed!", E_USER_ERROR );
	}

	/**
	 * A random position, unpredictable because these keys protect the connection to MailHawk
	 *
	 * @param int $count how many positions there are
	 *
	 * @return int
	 */
	protected function random_index( $count ) {

		try {
			return random_int( 0, $count - 1 );
		} catch ( \Exception $e ) {
			// No secure source of randomness, WordPress knows the next best thing
			return wp_rand( 0, $count - 1 );
		}
	}

	/**
	 * Generate a public key
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	public function generate_random_key( $length = 40 ) {

		// Min key length.
		if ( 0 >= $length ) {
			$length = 40;
		}

		// Max key length.
		if ( 255 <= $length ) {
			$length = 255;
		}

		$characters    = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$random_string = '';
		for ( $i = 0; $i < $length; $i ++ ) {
			$random_string .= $characters[ $this->random_index( strlen( $characters ) ) ];
		}

		return $random_string;
	}

	/**
	 * Generate a key and store it in an option
	 *
	 * @param $key
	 * @param $length
	 *
	 * @return string
	 */
	public function get_persistent_key( $key = '', $length = 20 ) {

		$stored = get_option( $key );

		if ( $stored ) {
			return $stored;
		}

		$generated = $this->generate_random_key( $length );
		update_option( $key, $generated );

		return $generated;

	}

	/**
	 * Generate a key and store it in a transient
	 *
	 * @param $key      string
	 * @param $lifetime int
	 * @param $length   int
	 *
	 * @return string
	 */
	public function get_temp_key( $key = '', $lifetime = 3600, $length = 20 ) {

		$stored = get_user_meta( get_current_user_id(), $key, true );

		if ( ! empty( $stored ) && is_array( $stored ) ) {

			$value      = $stored['value'] ?? null;
			$expiration = $stored['expiration'] ?? null;

			if ( $value && $expiration > time() ) {
				return $value;
			}
		}

		$generated = $this->generate_random_key( $length );

		update_user_meta( get_current_user_id(), $key, [
			'value'      => $generated,
			'expiration' => time() + $lifetime,
		] );

		return $generated;
	}

	/**
	 * Get the public_key
	 *
	 * @return string
	 */
	public function public_key() {
		return $this->get_persistent_key( 'mailhawk_public_key', 40 );
	}

	/**
	 * Get the Access Token
	 *
	 * @return mixed|void
	 */
	public function access_token() {
		return get_option( 'mailhawk_access_token' );
	}

	/**
	 * Get the state to use
	 *
	 * @return string
	 */
	public function state() {
		return $this->get_temp_key( 'mailhawk_state', HOUR_IN_SECONDS, 32 );
	}

	/**
	 * Check the state MailHawk sent back, and use it up so the same return can't be replayed
	 *
	 * @param string $state
	 *
	 * @return bool
	 */
	public function consume_state( $state ) {

		$stored = get_user_meta( get_current_user_id(), 'mailhawk_state', true );

		if ( ! is_string( $state ) || ! is_array( $stored ) ) {
			return false;
		}

		$value      = (string) ( $stored['value'] ?? '' );
		$expiration = (int) ( $stored['expiration'] ?? 0 );

		if ( $value === '' || $expiration <= time() || ! hash_equals( $value, $state ) ) {
			return false;
		}

		delete_user_meta( get_current_user_id(), 'mailhawk_state' );

		return true;
	}

	/**
	 * The client ID
	 *
	 * @return string
	 */
	public function client_id() {
		return hash( 'sha256', wp_parse_url( site_url(), PHP_URL_HOST ) );
	}

	/**
	 * Get the client secret
	 *
	 * @return string
	 */
	public function client_secret() {
		return $this->get_persistent_key( 'mailhawk_client_secret' );
	}
}
