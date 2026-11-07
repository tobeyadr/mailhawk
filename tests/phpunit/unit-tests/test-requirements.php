<?php

class Test_Requirements extends WP_UnitTestCase {

	protected function notice( $callback ) {
		ob_start();
		$callback();

		return ob_get_clean();
	}

	public function test_the_php_notice_says_what_is_needed_and_what_the_site_has() {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->notice( 'mailhawk_fail_php_version' );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'PHP version 7.4 or newer', $html );
		$this->assertStringContainsString( PHP_VERSION, $html );
		$this->assertStringContainsString( 'NOT RUNNING', $html );
	}

	public function test_the_wordpress_notice_says_what_is_needed() {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->notice( 'mailhawk_fail_wp_version' );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'WordPress version 6.9 or newer', $html );
	}

	public function test_the_notices_are_for_people_who_can_do_something_about_it() {

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( '', $this->notice( 'mailhawk_fail_php_version' ) );
		$this->assertSame( '', $this->notice( 'mailhawk_fail_wp_version' ) );
	}

	public function test_the_minimums_are_what_the_plugin_header_says() {

		$header = get_file_data( MAILHAWK__FILE__, [ 'php' => 'Requires PHP', 'wp' => 'Requires at least' ] );

		$this->assertSame( MAILHAWK_MINIMUM_PHP_VERSION, $header['php'] );
		$this->assertSame( MAILHAWK_MINIMUM_WP_VERSION, $header['wp'] );

		$readme = file_get_contents( MAILHAWK_PATH . 'readme.txt' );

		$this->assertStringContainsString( 'Requires PHP: ' . MAILHAWK_MINIMUM_PHP_VERSION, $readme );
		$this->assertStringContainsString( 'Requires at least: ' . MAILHAWK_MINIMUM_WP_VERSION, $readme );
	}
}
