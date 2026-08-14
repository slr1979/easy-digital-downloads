<?php
/**
 * Tests for EDD\Utils\Browser.
 *
 * User agent retrieval, sanitization, and truncation.
 *
 * @package   EDD\Tests\Utils
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.0
 */

namespace EDD\Tests\Utils;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\Browser as Utility;

/**
 * @coversDefaultClass \EDD\Utils\Browser
 */
class Browser extends EDD_UnitTestCase {

	/**
	 * The original user agent, restored after each test.
	 *
	 * @var string|null
	 */
	private $original_user_agent;

	/**
	 * Stash the existing user agent before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : null;
	}

	/**
	 * Restore the original user agent after each test.
	 */
	public function tearDown(): void {
		if ( null === $this->original_user_agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->original_user_agent;
		}
		parent::tearDown();
	}

	/**
	 * Test a typical user agent is returned unchanged.
	 *
	 * @covers ::get_user_agent
	 */
	public function test_returns_user_agent() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';

		$this->assertEquals(
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
			Utility::get_user_agent()
		);
	}

	/**
	 * Test an empty string is returned when no user agent is set.
	 *
	 * @covers ::get_user_agent
	 */
	public function test_returns_empty_string_when_unset() {
		unset( $_SERVER['HTTP_USER_AGENT'] );

		$this->assertSame( '', Utility::get_user_agent() );
	}

	/**
	 * Test an empty user agent returns an empty string.
	 *
	 * @covers ::get_user_agent
	 */
	public function test_returns_empty_string_when_empty() {
		$_SERVER['HTTP_USER_AGENT'] = '';

		$this->assertSame( '', Utility::get_user_agent() );
	}

	/**
	 * Test the user agent is sanitized (script tags stripped).
	 *
	 * @covers ::get_user_agent
	 */
	public function test_sanitizes_user_agent() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 <script>alert(1)</script>';

		$this->assertEquals( 'Mozilla/5.0', Utility::get_user_agent() );
	}

	/**
	 * Test the user agent is unslashed.
	 *
	 * @covers ::get_user_agent
	 */
	public function test_unslashes_user_agent() {
		// wp_unslash() strips slashes magic quotes would have added.
		$_SERVER['HTTP_USER_AGENT'] = "Browser\\'s Agent";

		$this->assertEquals( "Browser's Agent", Utility::get_user_agent() );
	}

	/**
	 * Test the user agent is truncated to the requested maximum length.
	 *
	 * @covers ::get_user_agent
	 */
	public function test_truncates_to_max_length() {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 300 );

		$this->assertEquals( 200, strlen( Utility::get_user_agent( 200 ) ) );
	}

	/**
	 * Test no truncation occurs by default.
	 *
	 * @covers ::get_user_agent
	 */
	public function test_no_truncation_by_default() {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 300 );

		$this->assertEquals( 300, strlen( Utility::get_user_agent() ) );
	}
}
