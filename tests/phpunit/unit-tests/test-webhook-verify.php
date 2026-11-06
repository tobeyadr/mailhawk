<?php

use MailHawk\Utils\Signature_Verifier;

/**
 * Webhooks are trusted because of this check, so it is tested with keys made for the test: a payload signed with a
 * key is accepted for that key's DKIM record, and for nothing else.
 */
class Test_Webhook_Verify extends WP_UnitTestCase {

	/**
	 * @var string
	 */
	protected $payload = '{"event":"MessageBounced","timestamp":1584646373.302839,"payload":{"message":{"id":18,"to":"someone@example.com"},"output":"550 No such user\n"},"uuid":"d5336f05-b350-4cf9-8783-cc888d8f6069"}';

	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL is needed.' );
		}
	}

	/**
	 * @return resource|\OpenSSLAsymmetricKey
	 */
	protected function make_key() {

		$options = [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ];

		$key = openssl_pkey_new( $options );

		if ( ! $key ) {
			// PHP on Windows has no openssl.cnf to find, and can not make a key without one. An empty one is enough.
			$config = wp_tempnam( 'mailhawk-openssl.cnf' );
			file_put_contents( $config, "[req]\ndistinguished_name = dn\n[dn]\n" );

			$key = openssl_pkey_new( $options + [ 'config' => $config ] );

			unlink( $config );
		}

		$this->assertNotFalse( $key, 'OpenSSL could not make a key for the test' );

		return $key;
	}

	/**
	 * The DKIM TXT record that Postal publishes for a key
	 *
	 * @param resource|\OpenSSLAsymmetricKey $key
	 *
	 * @return string
	 */
	protected function dkim_record( $key ) {

		$public = openssl_pkey_get_details( $key )['key'];
		$public = preg_replace( '/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $public );

		return 'v=DKIM1; t=s; h=sha256; p=' . $public . ';';
	}

	/**
	 * @param resource|\OpenSSLAsymmetricKey $key
	 * @param string                         $body
	 *
	 * @return string base64, as it is in the header
	 */
	protected function sign( $key, $body ) {

		openssl_sign( $body, $signature, $key, OPENSSL_ALGO_SHA256 );

		return base64_encode( $signature );
	}

	public function test_a_payload_signed_with_the_key_is_accepted() {

		$key = $this->make_key();

		$this->assertTrue( ( new Signature_Verifier() )->verify( $this->payload, $this->sign( $key, $this->payload ), $this->dkim_record( $key ) ) );
	}

	public function test_a_payload_that_was_changed_after_signing_is_rejected() {

		$key       = $this->make_key();
		$signature = $this->sign( $key, $this->payload );
		$verifier  = new Signature_Verifier();

		$this->assertFalse( $verifier->verify( str_replace( 'someone@example.com', 'someone@example.net', $this->payload ), $signature, $this->dkim_record( $key ) ) );
		$this->assertFalse( $verifier->verify( $this->payload . ' ', $signature, $this->dkim_record( $key ) ), 'Not even whitespace' );
	}

	public function test_a_payload_signed_with_another_key_is_rejected() {

		$ours   = $this->make_key();
		$theirs = $this->make_key();

		$this->assertFalse( ( new Signature_Verifier() )->verify( $this->payload, $this->sign( $theirs, $this->payload ), $this->dkim_record( $ours ) ) );
	}

	public function test_a_signature_that_is_not_one_is_rejected() {

		$dkim     = $this->dkim_record( $this->make_key() );
		$verifier = new Signature_Verifier();

		$this->assertFalse( $verifier->verify( $this->payload, '', $dkim ) );
		$this->assertFalse( $verifier->verify( $this->payload, 'not a signature', $dkim ) );
		$this->assertFalse( $verifier->verify( $this->payload, base64_encode( str_repeat( 'a', 256 ) ), $dkim ) );
	}

	public function test_a_record_without_a_key_rejects_everything() {

		$key       = $this->make_key();
		$signature = $this->sign( $key, $this->payload );
		$verifier  = new Signature_Verifier();

		$this->assertFalse( $verifier->verify( $this->payload, $signature, 'v=DKIM1; t=s; h=sha256;' ) );
		$this->assertFalse( $verifier->verify( $this->payload, $signature, 'v=DKIM1; p=;' ) );
		$this->assertFalse( $verifier->verify( $this->payload, $signature, 'v=DKIM1; p=not-a-key;' ) );
	}
}
