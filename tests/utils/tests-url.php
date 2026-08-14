<?php
/**
 * Tests for EDD\Utils\URL.
 *
 * Host extraction (lowercase, IDN, www-stripping) and URL normalization.
 *
 * @package   EDD\Tests\Utils
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.0
 */

namespace EDD\Tests\Utils;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\URL;

/**
 * @coversDefaultClass \EDD\Utils\URL
 */
class URLTest extends EDD_UnitTestCase {

	/**
	 * host() strips a leading www. from the host.
	 *
	 * @covers ::host
	 */
	public function test_host_strips_www() {
		$this->assertSame( 'example.com', URL::host( 'https://www.example.com' ) );
	}

	/**
	 * host() returns a non-www subdomain unchanged.
	 *
	 * @covers ::host
	 */
	public function test_host_keeps_non_www_subdomain() {
		$this->assertSame( 'shop.example.com', URL::host( 'https://shop.example.com' ) );
	}

	/**
	 * host() lowercases the host and ignores any path.
	 *
	 * @covers ::host
	 */
	public function test_host_lowercases_and_ignores_path() {
		$this->assertSame( 'example.com', URL::host( 'https://WWW.Example.COM/some/path?x=1' ) );
	}

	/**
	 * host() returns an empty string when the URL has no host.
	 *
	 * @covers ::host
	 */
	public function test_host_returns_empty_string_when_hostless() {
		$this->assertSame( '', URL::host( 'not-a-url' ) );
		$this->assertSame( '', URL::host( '' ) );
	}

	/**
	 * host() converts an IDN host to its ASCII/Punycode form.
	 *
	 * @covers ::host
	 */
	public function test_host_converts_idn_to_punycode() {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'The intl extension is required for IDN conversion.' );
		}

		$this->assertSame( 'xn--mnchen-3ya.de', URL::host( 'https://www.münchen.de' ) );
	}

	/**
	 * normalize() keeps the scheme and host but drops a trailing slash.
	 *
	 * @covers ::normalize
	 */
	public function test_normalize_keeps_scheme_and_drops_trailing_slash() {
		$this->assertSame( 'https://www.example.com', URL::normalize( 'https://www.example.com/' ) );
	}

	/**
	 * normalize() lowercases the scheme and host.
	 *
	 * @covers ::normalize
	 */
	public function test_normalize_lowercases_scheme_and_host() {
		$this->assertSame( 'https://example.com', URL::normalize( 'HTTPS://Example.com' ) );
	}

	/**
	 * normalize() reduces an IDN host to the same canonical form as its Punycode.
	 *
	 * @covers ::normalize
	 */
	public function test_normalize_idn_matches_punycode() {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'The intl extension is required for IDN conversion.' );
		}

		$this->assertSame(
			URL::normalize( 'https://xn--mnchen-3ya.de' ),
			URL::normalize( 'https://münchen.de' )
		);
	}
}
