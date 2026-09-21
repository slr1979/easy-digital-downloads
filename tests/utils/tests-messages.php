<?php
/**
 * Tests for EDD\Utils\Messages.
 *
 * @package   EDD\Tests\Utils
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.6.5
 */

namespace EDD\Tests\Utils;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\Messages as Utility;

/**
 * @coversDefaultClass \EDD\Utils\Messages
 */
class Messages extends EDD_UnitTestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		Utility::clear();
	}

	public function tearDown(): void {
		Utility::clear();
		parent::tearDown();
	}

	/**
	 * @covers \EDD\Utils\Messages::add
	 * @covers \EDD\Utils\Messages::get_by_type
	 */
	public function test_add_and_get_by_type() {
		Utility::add( 'error', 'code1', 'Error one' );
		Utility::add( 'error', 'code2', 'Error two' );
		Utility::add( 'success', 'code3', 'Success one' );

		$errors = Utility::get_by_type( 'error' );
		$this->assertIsArray( $errors );
		$this->assertCount( 2, $errors );
		$this->assertSame( 'Error one', $errors['code1'] );
		$this->assertSame( 'Error two', $errors['code2'] );

		$successes = Utility::get_by_type( 'success' );
		$this->assertIsArray( $successes );
		$this->assertCount( 1, $successes );
		$this->assertSame( 'Success one', $successes['code3'] );
	}

	/**
	 * @covers \EDD\Utils\Messages::get_by_code
	 */
	public function test_get_by_code() {
		Utility::add( 'info', 'my_code', 'Info message' );

		$found = Utility::get_by_code( 'my_code' );
		$this->assertIsArray( $found );
		$this->assertSame( 'info', $found['type'] );
		$this->assertSame( 'Info message', $found['message'] );

		$this->assertNull( Utility::get_by_code( 'nonexistent' ) );
	}

	/**
	 * @covers \EDD\Utils\Messages::get_all
	 */
	public function test_get_all() {
		Utility::add( 'error', 'e1', 'Err' );
		Utility::add( 'success', 's1', 'Ok' );
		Utility::add( 'info', 'i1', 'Info' );
		Utility::add( 'warn', 'w1', 'Warn' );

		$all = Utility::get_all();
		$this->assertIsArray( $all );
		$this->assertArrayHasKey( 'error', $all );
		$this->assertArrayHasKey( 'success', $all );
		$this->assertArrayHasKey( 'info', $all );
		$this->assertArrayHasKey( 'warn', $all );
		$this->assertSame( array( 'e1' => 'Err' ), $all['error'] );
		$this->assertSame( array( 's1' => 'Ok' ), $all['success'] );
		$this->assertSame( array( 'i1' => 'Info' ), $all['info'] );
		$this->assertSame( array( 'w1' => 'Warn' ), $all['warn'] );
	}

	/**
	 * @covers \EDD\Utils\Messages::remove
	 */
	public function test_remove() {
		Utility::add( 'error', 'to_remove', 'Message' );
		Utility::remove( 'to_remove', 'error' );

		$errors = Utility::get_by_type( 'error' );
		$this->assertArrayNotHasKey( 'to_remove', $errors );
	}

	/**
	 * @covers \EDD\Utils\Messages::clear
	 * @covers \EDD\Utils\Messages::has_any
	 */
	public function test_clear_and_has_any() {
		Utility::add( 'error', 'e1', 'Err' );
		$this->assertTrue( Utility::has_any() );

		Utility::clear();
		$this->assertFalse( Utility::has_any() );
		$this->assertEmpty( Utility::get_by_type( 'error' ) );
	}

	/**
	 * @covers \EDD\Utils\Messages::to_html
	 */
	public function test_to_html_contains_expected_classes_and_escaped_content() {
		Utility::add( 'error', 'err_1', 'Test error <script>' );
		Utility::add( 'success', 'suc_1', 'Test success' );
		Utility::add( 'info', 'inf_1', 'Test info' );
		Utility::add( 'warn', 'war_1', 'Test warn' );

		$html = Utility::to_html();
		$this->assertStringContainsString( 'edd-errors', $html );
		$this->assertStringContainsString( 'edd-alert-error', $html );
		$this->assertStringContainsString( 'edd-success', $html );
		$this->assertStringContainsString( 'edd-alert-success', $html );
		$this->assertStringContainsString( 'edd_error_err_1', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Test error', $html );
		$this->assertStringContainsString( 'Test success', $html );
		$this->assertStringContainsString( 'Test info', $html );
		$this->assertStringContainsString( 'Test warn', $html );
	}

	/**
	 * @covers \EDD\Utils\Messages::build_html_for_messages
	 */
	public function test_build_html_for_messages() {
		$errors = array( 'id1' => 'Error text' );
		$html   = Utility::build_html_for_messages( $errors, 'error' );

		$this->assertStringContainsString( 'edd-errors', $html );
		$this->assertStringContainsString( 'edd-alert-error', $html );
		$this->assertStringContainsString( 'Error text', $html );
		$this->assertStringContainsString( 'edd_error_id1', $html );
	}

	/**
	 * @covers \EDD\Utils\Messages::get_by_type
	 */
	public function test_migration_from_legacy_keys() {
		EDD()->session->set( 'edd_errors', array( 'legacy_e' => 'Legacy error' ) );
		EDD()->session->set( 'edd_success_errors', array( 'legacy_s' => 'Legacy success' ) );
		EDD()->session->set( Utility::SESSION_KEY, null );

		$errors = Utility::get_by_type( 'error' );
		$this->assertArrayHasKey( 'legacy_e', $errors );
		$this->assertSame( 'Legacy error', $errors['legacy_e'] );

		$successes = Utility::get_by_type( 'success' );
		$this->assertArrayHasKey( 'legacy_s', $successes );
		$this->assertSame( 'Legacy success', $successes['legacy_s'] );

		$this->assertNull( EDD()->session->get( 'edd_errors' ) );
		$this->assertNull( EDD()->session->get( 'edd_success_errors' ) );
	}

	/**
	 * Regression test for GitHub issue #2681: the checkout login form's
	 * "Reset Password" link must survive Messages::add() (storage) and
	 * Messages::to_html() (render) intact, including its href.
	 *
	 * @covers \EDD\Utils\Messages::add
	 * @covers \EDD\Utils\Messages::to_html
	 */
	public function test_add_and_to_html_preserves_anchor_link() {
		$message = 'Invalid username or incorrect password. <a href="https://example.com/wp-login.php?action=lostpassword">Reset Password</a>';
		Utility::add( 'error', 'login_reset_password', $message );

		$html = Utility::to_html();

		$this->assertStringContainsString( '<a href="https://example.com/wp-login.php?action=lostpassword">', $html );
		$this->assertStringContainsString( 'Reset Password</a>', $html );
	}

	/**
	 * Regression test for GitHub issue #2681, exercised through the legacy
	 * edd_set_error() / edd_build_errors_html( edd_get_errors() ) wrapper
	 * path used at checkout.
	 *
	 * @covers \EDD\Utils\Messages::add
	 * @covers \EDD\Utils\Messages::build_html_for_messages
	 */
	public function test_legacy_error_wrappers_preserve_anchor_link() {
		$message = 'Invalid username or incorrect password. <a href="https://example.com/wp-login.php?action=lostpassword">Reset Password</a>';
		edd_set_error( 'login_reset_password', $message );

		$html = edd_build_errors_html( edd_get_errors() );

		$this->assertStringContainsString( '<a href="https://example.com/wp-login.php?action=lostpassword">', $html );
		$this->assertStringContainsString( 'Reset Password</a>', $html );
	}

	/**
	 * Disallowed tags/attributes must never survive storage, and a disallowed
	 * attribute on an otherwise allowed tag (e.g. onclick on <a>) must be
	 * stripped while the tag itself and its allowed attributes remain.
	 *
	 * @covers \EDD\Utils\Messages::add
	 * @covers \EDD\Utils\Messages::get_by_type
	 */
	public function test_add_strips_disallowed_tags_and_attributes_in_storage() {
		$message = 'Click <a href="#" onclick="alert(1)">here</a> <img src="x.jpg" alt="x"> and <script>alert(2);</script> done.';
		Utility::add( 'error', 'disallowed_markup', $message );

		$stored = Utility::get_by_type( 'error' );
		$this->assertArrayHasKey( 'disallowed_markup', $stored );

		$stored_message = $stored['disallowed_markup'];
		$this->assertStringContainsString( '<a href="#">here</a>', $stored_message );
		$this->assertStringNotContainsString( '<img', $stored_message );
		$this->assertStringNotContainsString( '<script', $stored_message );
		$this->assertStringNotContainsString( 'onclick', $stored_message );
	}

	/**
	 * Same disallowed tags/attributes as above, but asserted against the
	 * rendered output of to_html() rather than storage.
	 *
	 * @covers \EDD\Utils\Messages::add
	 * @covers \EDD\Utils\Messages::to_html
	 */
	public function test_to_html_strips_disallowed_tags_and_attributes() {
		$message = 'Click <a href="#" onclick="alert(1)">here</a> <img src="x.jpg" alt="x"> and <script>alert(2);</script> done.';
		Utility::add( 'error', 'disallowed_markup', $message );

		$html = Utility::to_html();

		$this->assertStringContainsString( '<a href="#">here</a>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	/**
	 * A javascript: URL in an otherwise allowed <a href> must not survive
	 * storage or output, so the allowlist cannot carry a script payload.
	 *
	 * @covers \EDD\Utils\Messages::add
	 * @covers \EDD\Utils\Messages::build_html_for_messages
	 */
	public function test_javascript_protocol_href_is_stripped() {
		$message = 'Click <a href="javascript:alert(1)">here</a> now.';

		Utility::add( 'error', 'bad_protocol', $message );
		$stored = Utility::get_by_type( 'error' );
		$this->assertArrayHasKey( 'bad_protocol', $stored );
		$this->assertStringNotContainsString( 'javascript:', $stored['bad_protocol'] );

		// Rendering is guarded separately: build_html_for_messages() also takes
		// messages that never passed through add(), such as legacy session data.
		$html = Utility::build_html_for_messages( array( 'bad_protocol' => $message ), 'error' );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( 'here</a>', $html );
	}

	/**
	 * API-contract test: ALLOWED_TAGS must permit the 'a' tag and its href so
	 * links (e.g. the checkout "Reset Password" link) survive the pipeline.
	 */
	public function test_allowed_tags_contains_anchor() {
		$this->assertArrayHasKey( 'a', Utility::ALLOWED_TAGS );
		$this->assertArrayHasKey( 'href', Utility::ALLOWED_TAGS['a'] );
	}
}
