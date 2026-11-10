<?php

use function MailHawk\get_dns_record_names;

/**
 * DNS record names as a Host relative to the sending domain and a full hostname
 */
class Test_Dns_Names extends WP_UnitTestCase {

	public function test_a_name_relative_to_the_domain_gets_the_domain_added_for_the_full_hostname() {
		$this->assertSame( [ 'host' => 'postal-abc._domainkey', 'hostname' => 'postal-abc._domainkey.example.com' ], get_dns_record_names( 'postal-ABC._domainkey', 'example.com' ) );
	}

	public function test_a_full_hostname_is_cut_down_to_the_host() {
		$this->assertSame( [ 'host' => 'psrp', 'hostname' => 'psrp.example.com' ], get_dns_record_names( 'psrp.example.com', 'example.com' ) );
	}

	public function test_a_subdomain_keeps_the_subdomain_in_the_full_hostname_only() {
		$this->assertSame( [ 'host' => 'postal-abc._domainkey', 'hostname' => 'postal-abc._domainkey.academy.example.com' ], get_dns_record_names( 'postal-abc._domainkey', 'academy.example.com' ) );
		$this->assertSame( [ 'host' => 'psrp', 'hostname' => 'psrp.academy.example.com' ], get_dns_record_names( 'psrp.academy.example.com', 'academy.example.com' ) );
	}

	public function test_the_domain_itself_is_at() {
		$this->assertSame( [ 'host' => '@', 'hostname' => 'example.com' ], get_dns_record_names( '@', 'example.com' ) );
		$this->assertSame( [ 'host' => '@', 'hostname' => 'example.com' ], get_dns_record_names( '', 'Example.com' ) );
		$this->assertSame( [ 'host' => '@', 'hostname' => 'example.com' ], get_dns_record_names( 'example.com.', 'example.com' ) );
	}

	/**
	 * A domain as the MTA gives it: the DKIM name relative, the return path in full
	 */
	protected function domain( $name, $status ) {
		return (object) [
			'name'        => $name,
			'spf'         => (object) [ 'spf_status' => $status, 'spf_record' => 'v=spf1 a mx include:spf.mta01.mailhawk.io ~all' ],
			'dkim'        => (object) [ 'dkim_status' => $status, 'dkim_record_name' => 'postal-AbC123._domainkey', 'dkim_record' => 'v=DKIM1; t=s; p=abc' ],
			'return_path' => (object) [ 'return_path_status' => $status, 'return_path_domain' => 'psrp.' . $name ],
		];
	}

	public function test_the_instructions_have_every_record_with_its_host_full_hostname_and_value() {

		$text = \MailHawk\get_dns_instructions( $this->domain( 'example.com', 'OK' ) );

		$this->assertStringContainsString( 'DNS records for example.com', $text );
		$this->assertStringContainsString( 'nothing needs to change', $text );

		foreach ( [
			'Type: TXT', 'Host: postal-abc123._domainkey', 'Full hostname: postal-abc123._domainkey.example.com', 'Value: v=DKIM1; t=s; p=abc',
			'Type: CNAME', 'Host: psrp', 'Full hostname: psrp.example.com', 'Value: rp.mta01.mailhawk.io',
			'Host: @', 'Full hostname: example.com', 'Value: v=spf1 a mx include:spf.mta01.mailhawk.io ~all',
			'TTL: 3600',
		] as $line ) {
			$this->assertStringContainsString( $line, $text, $line );
		}

		$this->assertStringContainsString( 'page=mailhawk&view=domains&domain=example.com', $text, 'Where to verify' );
		$this->assertStringNotContainsString( 'is a subdomain', $text, 'example.com can not be a subdomain' );
	}

	public function test_only_a_domain_that_may_be_a_subdomain_is_taken_for_one() {

		// Never a subdomain
		$this->assertFalse( \MailHawk\is_maybe_subdomain( 'example.com' ) );
		$this->assertFalse( \MailHawk\is_maybe_subdomain( 'MailHawk.io.' ) );
		$this->assertFalse( \MailHawk\is_maybe_subdomain( 'example.co.uk' ), 'Registered under co.uk' );
		$this->assertFalse( \MailHawk\is_maybe_subdomain( 'example.com.au' ) );

		// May be one
		$this->assertTrue( \MailHawk\is_maybe_subdomain( 'academy.example.com' ) );
		$this->assertTrue( \MailHawk\is_maybe_subdomain( 'mail.example.co.uk' ) );
		$this->assertTrue( \MailHawk\is_maybe_subdomain( 'example.something.xyz' ), 'Unsure is a maybe' );
	}

	public function test_the_instructions_for_a_subdomain_that_has_an_spf_record_say_to_change_it() {

		add_filter( 'mailhawk/abilities/existing_spf_record', function () {
			return 'v=spf1 include:_spf.google.com ~all';
		} );

		$text = \MailHawk\get_dns_instructions( $this->domain( 'academy.example.com', 'Missing' ) );

		remove_all_filters( 'mailhawk/abilities/existing_spf_record' );

		$this->assertStringNotContainsString( 'already verified', $text );
		$this->assertStringContainsString( 'Full hostname: postal-abc123._domainkey.academy.example.com', $text );
		$this->assertStringContainsString( 'If academy.example.com is a subdomain', $text );

		// The one SPF record the domain has, with MailHawk added, and not a second one
		$this->assertStringContainsString( 'Value: v=spf1 include:_spf.google.com include:spf.mta01.mailhawk.io ~all', $text );
		$this->assertStringContainsString( 'already has an SPF record: v=spf1 include:_spf.google.com ~all', $text );
	}

	public function test_a_name_that_only_ends_like_the_domain_is_not_cut() {
		// notexample.com is not under example.com
		$this->assertSame( [ 'host' => 'psrp.notexample.com', 'hostname' => 'psrp.notexample.com.example.com' ], get_dns_record_names( 'psrp.notexample.com', 'example.com' ) );
	}
}
