<?php
namespace EDD\Tests;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\URL;

/**
 * @group edd_url
 */
class Tests_URL extends EDD_UnitTestCase {
	public function test_ajax_url() {
		$_SERVER['SERVER_PORT'] = 80;
		$_SERVER['HTTPS'] = 'off';

		$this->assertEquals( edd_get_ajax_url(), get_site_url( null, '/wp-admin/admin-ajax.php', 'http' ) );
	}

	public function test_current_page_url() {
		$_SERVER['SERVER_PORT'] = 80;
		$_SERVER["SERVER_NAME"] = 'example.org';
		$this->assertEquals( 'http://example.org/', edd_get_current_page_url() );
	}

	/**
	 * URL::is_production_url() returns false for common staging/local hosts via
	 * the static fallback list (Software Licensing is not loaded in this test suite).
	 *
	 * @dataProvider staging_url_provider
	 */
	public function test_is_production_url_flags_common_staging_hosts_as_non_production( $url ) {
		$this->assertFalse( URL::is_production_url( $url ), "Expected {$url} to be detected as non-production." );
	}

	public function staging_url_provider() {
		return array(
			array( 'http://localhost' ),
			array( 'https://mysite.local' ),
			array( 'https://mysite.test' ),
			array( 'https://mysite.localhost' ),
			array( 'https://dev.example.com' ),
			array( 'https://staging.example.com' ),
			array( 'https://sub.staging.example.com' ),
			array( 'https://staging-42.example.com' ),
			array( 'https://test.example.com' ),
			array( 'https://mysite.wpengine.com' ),
			array( 'https://mysite.kinsta.cloud' ),
			array( 'https://mysite.instawp.xyz' ),
			array( 'https://mysite.dreamhosters.com' ),
			array( 'https://staging1.example.com' ),
			array( 'https://example.com/staging/12345/' ),
			array( 'http://127.0.0.1' ),
			array( 'http://[::1]' ),
			array( 'http://[fe80::1]' ),
		);
	}

	/**
	 * URL::is_production_url() returns true for ordinary production hosts.
	 *
	 * @dataProvider production_url_provider
	 */
	public function test_is_production_url_accepts_ordinary_production_hosts( $url ) {
		$this->assertTrue( URL::is_production_url( $url ), "Expected {$url} to be detected as production." );
	}

	public function production_url_provider() {
		return array(
			array( 'https://easydigitaldownloads.com' ),
			array( 'https://example.com' ),
			array( 'https://shop.example.com' ),
			// Contains ".test" as a substring but is not the .test TLD or a "test." label.
			array( 'https://mystore.testing.com' ),
			// A public IPv6 literal is not a loopback/link-local address.
			array( 'http://[2001:4860:4860::8888]' ),
		);
	}
}
