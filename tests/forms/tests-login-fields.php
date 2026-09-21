<?php

namespace EDD\Tests\Forms;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Forms\Login\Password;
use EDD\Forms\Login\Remember;
use EDD\Forms\Login\Username;

class LoginFields extends EDD_UnitTestCase {

	public function tearDown(): void {
		edd_delete_option( 'logged_in_only' );
		edd_delete_option( 'show_register_form' );
		parent::tearDown();
	}

	// Username field.

	public function test_login_username_id() {
		$field = new Username( array() );

		$this->assertEquals( 'edd_user_login', $field->get_id() );
	}

	public function test_login_username_label() {
		$field = new Username( array() );

		$this->assertEquals( 'Username or Email', $field->get_label() );
	}

	public function test_login_username_input_name() {
		$field = new Username( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_user_login"', $html );
	}

	public function test_login_username_input_id() {
		$field = new Username( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="edd_user_login"', $html );
	}

	public function test_login_username_input_type_is_text() {
		$field = new Username( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="text"', $html );
	}

	public function test_login_username_input_is_required() {
		$field = new Username( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'required', $html );
	}

	public function test_login_username_render_includes_label_and_input() {
		$field = new Username( array() );

		ob_start();
		$field->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Username or Email', $html );
		$this->assertStringContainsString( 'edd_user_login', $html );
	}

	// Password field.

	public function test_login_password_id() {
		$field = new Password( array() );

		$this->assertEquals( 'edd_user_pass', $field->get_id() );
	}

	public function test_login_password_label() {
		$field = new Password( array() );

		$this->assertEquals( 'Password', $field->get_label() );
	}

	public function test_login_password_input_name() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_user_pass"', $html );
	}

	public function test_login_password_input_id() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="edd_user_pass"', $html );
	}

	public function test_login_password_input_type_is_password() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $html );
	}

	public function test_login_password_input_is_required() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'required', $html );
	}

	public function test_login_password_field_class_includes_edd_password() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'edd-password', $html );
	}

	// Remember field.

	public function test_login_remember_id() {
		$field = new Remember( array() );

		$this->assertEquals( 'rememberme', $field->get_id() );
	}

	public function test_login_remember_label() {
		$field = new Remember( array() );

		$this->assertEquals( 'Remember Me', $field->get_label() );
	}

	public function test_login_remember_input_is_checkbox() {
		$field = new Remember( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'name="rememberme"', $html );
		$this->assertStringContainsString( 'id="rememberme"', $html );
	}

	public function test_login_remember_input_value_is_forever() {
		$field = new Remember( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="forever"', $html );
	}

	public function test_login_remember_input_is_not_required() {
		$field = new Remember( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'required', $html );
	}

	public function test_login_remember_render_includes_label_inside_input_area() {
		$field = new Remember( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Remember Me', $html );
	}

	// Optional login fields.

	public function test_login_username_not_required_is_optional() {
		$field = new Username( array( 'not_required' => true ) );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assert_input_is_optional( $html, 'edd_user_login' );
	}

	public function test_login_password_not_required_is_optional() {
		$field = new Password( array( 'not_required' => true ) );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assert_input_is_optional( $html, 'edd_user_pass' );
	}

	public function test_login_username_not_required_is_optional_on_shortcode() {
		$field = new Username(
			array(
				'is_block'     => false,
				'not_required' => true,
			)
		);

		ob_start();
		$field->render();
		$html = ob_get_clean();

		$this->assert_input_is_optional( $html, 'edd_user_login' );
	}

	public function test_login_password_not_required_is_optional_on_shortcode() {
		$field = new Password(
			array(
				'is_block'     => false,
				'not_required' => true,
			)
		);

		ob_start();
		$field->render();
		$html = ob_get_clean();

		$this->assert_input_is_optional( $html, 'edd_user_pass' );
	}

	public function test_login_username_not_required_omits_label_required_indicator() {
		$field = new Username(
			array(
				'is_block'     => false,
				'not_required' => true,
			)
		);

		ob_start();
		$field->render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'edd-required-indicator', $html );
	}

	/*
	 * Guest checkout leaves the shortcode login form collapsed behind a reveal toggle, and a
	 * hidden field carrying the required attribute blocks reportValidity() for the whole
	 * purchase form, so the gateway button never opens.
	 */

	public function test_checkout_login_fields_are_optional_when_guest_checkout_is_allowed() {
		edd_update_option( 'logged_in_only', '' );

		$html = $this->get_checkout_login_fields();

		$this->assert_input_is_optional( $html, 'edd_user_login' );
		$this->assert_input_is_optional( $html, 'edd_user_pass' );
	}

	public function test_checkout_login_fields_are_optional_when_accounts_are_auto_registered() {
		edd_update_option( 'logged_in_only', 'auto' );

		$html = $this->get_checkout_login_fields();

		$this->assert_input_is_optional( $html, 'edd_user_login' );
		$this->assert_input_is_optional( $html, 'edd_user_pass' );
	}

	public function test_checkout_login_fields_are_required_when_login_is_required() {
		edd_update_option( 'logged_in_only', 'required' );

		$html = $this->get_checkout_login_fields();

		$this->assert_input_is_required( $html, 'edd_user_login' );
		$this->assert_input_is_required( $html, 'edd_user_pass' );
	}

	/**
	 * Renders the shortcode checkout login form.
	 *
	 * @return string
	 */
	private function get_checkout_login_fields(): string {
		ob_start();
		edd_get_login_fields();

		return ob_get_clean();
	}

	/**
	 * Asserts that an input neither carries the required attribute nor advertises itself as required.
	 *
	 * @param string $html The rendered markup.
	 * @param string $id   The input ID.
	 */
	private function assert_input_is_optional( string $html, string $id ): void {
		$input = $this->get_input( $html, $id );

		$this->assertFalse( $input->hasAttribute( 'required' ), "{$id} should not carry the required attribute" );
		$this->assertNotContains( 'required', $this->get_classes( $input ), "{$id} should not carry the required class" );
	}

	/**
	 * Asserts that an input carries the required attribute and the matching class.
	 *
	 * @param string $html The rendered markup.
	 * @param string $id   The input ID.
	 */
	private function assert_input_is_required( string $html, string $id ): void {
		$input = $this->get_input( $html, $id );

		$this->assertTrue( $input->hasAttribute( 'required' ), "{$id} should carry the required attribute" );
		$this->assertContains( 'required', $this->get_classes( $input ), "{$id} should carry the required class" );
	}

	/**
	 * Gets an input element by ID from rendered markup.
	 *
	 * The word "required" is both an attribute and a class on these inputs, so string matching
	 * cannot tell the two apart.
	 *
	 * @param string $html The rendered markup.
	 * @param string $id   The input ID.
	 * @return \DOMElement
	 */
	private function get_input( string $html, string $id ): \DOMElement {
		$dom = new \DOMDocument();
		$dom->loadHTML( '<div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		$input = $dom->getElementById( $id );

		$this->assertInstanceOf( \DOMElement::class, $input, "Expected an input with the ID {$id}" );

		return $input;
	}

	/**
	 * Gets the classes on an element.
	 *
	 * @param \DOMElement $element The element.
	 * @return array
	 */
	private function get_classes( \DOMElement $element ): array {
		return preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
	}
}
