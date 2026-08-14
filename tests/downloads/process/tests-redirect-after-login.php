<?php
namespace EDD\Tests\Downloads\Process;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\Tokenizer;

/**
 * Tests for edd_redirect_file_download_after_login() guard behavior.
 *
 * Under unit tests edd_redirect() is a no-op, so the `return;` after each guard
 * lets the function end cleanly and we can assert what state it touched. This
 * is what Robin's edd_redirect()/return change unlocks (issue #2578).
 *
 * @group edd_downloads
 */
class RedirectAfterLogin extends EDD_UnitTestCase {

	/**
	 * The session key the function reads and clears.
	 *
	 * @var string
	 */
	private $session_key = 'edd_require_login_to_download_redirect';

	public function tearDown(): void {
		unset( $_GET['_token'] );
		EDD()->session->set( $this->session_key, '' );

		parent::tearDown();
	}

	/**
	 * Counts the callbacks registered on wp_footer at the default priority.
	 *
	 * The function registers an anonymous closure, so has_action() cannot target
	 * it by reference; we assert on the callback count delta instead.
	 *
	 * @return int
	 */
	private function count_footer_callbacks() {
		global $wp_filter;

		if ( empty( $wp_filter['wp_footer'] ) || empty( $wp_filter['wp_footer']->callbacks[10] ) ) {
			return 0;
		}

		return count( $wp_filter['wp_footer']->callbacks[10] );
	}

	/**
	 * An empty token returns before reading the session, so the session is left
	 * untouched and no download footer script is registered.
	 */
	public function test_empty_token_does_not_register_footer_or_touch_session() {
		unset( $_GET['_token'] );
		EDD()->session->set( $this->session_key, array( 'download' => '1', 'order' => '99' ) );

		$before = $this->count_footer_callbacks();
		edd_redirect_file_download_after_login();
		$after = $this->count_footer_callbacks();

		$this->assertEquals(
			array( 'download' => '1', 'order' => '99' ),
			EDD()->session->get( $this->session_key ),
			'Session data should be untouched when no token is provided.'
		);
		$this->assertSame( $before, $after, 'No wp_footer callback should be registered when no token is provided.' );
	}

	/**
	 * An invalid token returns before the session is cleared, so the stored data
	 * survives and no download footer script is registered.
	 */
	public function test_invalid_token_does_not_clear_session_or_register_footer() {
		$data = array( 'download' => '1', 'order' => '99' );
		EDD()->session->set( $this->session_key, $data );
		$_GET['_token'] = 'this-is-not-a-valid-token';

		$before = $this->count_footer_callbacks();
		edd_redirect_file_download_after_login();
		$after = $this->count_footer_callbacks();

		$this->assertEquals( $data, EDD()->session->get( $this->session_key ), 'Session data should not be cleared when the token is invalid.' );
		$this->assertSame( $before, $after, 'No wp_footer callback should be registered when the token is invalid.' );
	}

	/**
	 * A valid token clears the session key and registers the download footer
	 * script that performs the deferred download.
	 */
	public function test_valid_token_clears_session_and_registers_footer() {
		$data = array( 'download' => '1', 'order' => '99' );
		EDD()->session->set( $this->session_key, $data );
		$stored         = EDD()->session->get( $this->session_key );
		$_GET['_token'] = Tokenizer::tokenize( $stored );

		$before = $this->count_footer_callbacks();
		edd_redirect_file_download_after_login();
		$after = $this->count_footer_callbacks();

		$this->assertNull( EDD()->session->get( $this->session_key ), 'Session key should be cleared after a valid token.' );
		$this->assertSame( $before + 1, $after, 'A wp_footer callback should be registered after a valid token.' );
	}
}
