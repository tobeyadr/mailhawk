<?php

namespace MailHawk\Utils;

/**
 * Service for verifying Postal signatures.
 */
class Signature_Verifier {

	/**
	 * Parses a DKIM record for the purpose of extracting the key.
	 *
	 * @param string $string
	 *   TXT record for DKIM.
	 *
	 * @return array|null
	 *   Returns array if valid; otherwise NULL.
	 */
	public function parseDKIM( $string ) {
		if ( preg_match_all( '/([a-z])=([^;]+);/', $string, $matches, PREG_SET_ORDER ) ) {
			$dkim = array();
			foreach ( $matches as $match ) {
				$dkim[ $match[1] ] = $match[2];
			}

			// We require 'p' to consider it a valid DKIM record.
			if ( ! empty( $dkim['p'] ) ) {
				return $dkim;
			}
		}

		return null;
	}

	/**
	 * Gets the RSA key based on the default DKIM record from Postal.
	 *
	 * @return resource|NULL
	 */
	protected function getRSAKey( $dkim_string ) {
		if ( $dkim = $this->parseDKIM( $dkim_string ) ) {
			$rsa_key_pem = "-----BEGIN PUBLIC KEY-----\r\n" .
			               chunk_split( $dkim['p'], 64 ) .
			               "-----END PUBLIC KEY-----\r\n";

			return openssl_pkey_get_public( $rsa_key_pem );
		}

		return null;
	}

	/**
	 * Verify a signature.
	 *
	 * @param string $body
	 *   The body of the request that was signed.
	 * @param string $signature
	 *   Base64 encoded signature string from 'X-Postal-Signature'.
	 * @param string $dkim
	 *   The default DKIM record from Postal.
	 *
	 * @return bool
	 */
	public function verify( $body, $signature, $dkim = "" ) {

		if ( ! function_exists( 'openssl_verify' ) ) {
			return false;
		}

		if ( ! $dkim ) {
			$dkim = file_get_contents( MAILHAWK_PATH . '.dkim' );
		}

		$rsa_key = $this->getRSAKey( $dkim );

		if ( ! $rsa_key ) {
			return false;
		}

		$result = openssl_verify( $body, base64_decode( $signature ), $rsa_key, OPENSSL_ALGO_SHA256 );

		// Result can be 1, 0, -1 or FALSE. Only 1 is success, consider everything
		// else a failure.
		return $result === 1;
	}

}
