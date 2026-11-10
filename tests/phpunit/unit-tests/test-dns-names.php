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

	public function test_a_name_that_only_ends_like_the_domain_is_not_cut() {
		// notexample.com is not under example.com
		$this->assertSame( [ 'host' => 'psrp.notexample.com', 'hostname' => 'psrp.notexample.com.example.com' ], get_dns_record_names( 'psrp.notexample.com', 'example.com' ) );
	}
}
