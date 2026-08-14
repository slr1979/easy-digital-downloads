<?php

namespace EDD\Tests\Forms;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Forms\Register\Email;
use EDD\Forms\Register\Password;
use EDD\Forms\Register\PasswordConfirm;
use EDD\Forms\Register\Username;
use EDD\Forms\Register\Weak;

class RegisterFields extends EDD_UnitTestCase {

	// Username field.

	public function test_register_username_id() {
		$field = new Username( array() );

		$this->assertEquals( 'edd_user_register', $field->get_id() );
	}

	public function test_register_username_label() {
		$field = new Username( array() );

		$this->assertEquals( 'Username or Email', $field->get_label() );
	}

	public function test_register_username_input_name() {
		$field = new Username( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_user_login"', $html );
	}

	public function test_register_username_input_id() {
		$field = new Username( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="edd_user_register"', $html );
	}

	public function test_register_username_input_is_required() {
		$field = new Username( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'required', $html );
	}

	public function test_register_username_render_includes_required_indicator() {
		$field = new Username( array() );

		ob_start();
		$field->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'edd_user_register', $html );
		$this->assertStringContainsString( 'Username or Email', $html );
	}

	// Password field.

	public function test_register_password_id() {
		$field = new Password( array() );

		$this->assertEquals( 'pass1', $field->get_id() );
	}

	public function test_register_password_label() {
		$field = new Password( array() );

		$this->assertEquals( 'Password', $field->get_label() );
	}

	public function test_register_password_input_name() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_user_pass"', $html );
	}

	public function test_register_password_input_type() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $html );
	}

	public function test_register_password_input_is_required() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'required', $html );
	}

	public function test_register_password_input_omits_wp_scripts_toggle_when_no_wp_scripts() {
		$field = new Password( array( 'no_wp_scripts' => true ) );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'wp-hide-pw', $html );
		$this->assertStringNotContainsString( 'pass-strength-result', $html );
	}

	public function test_register_password_input_includes_wp_scripts_toggle_when_allowed() {
		$field = new Password( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wp-hide-pw', $html );
		$this->assertStringContainsString( 'pass-strength-result', $html );
	}

	public function test_register_password_form_group_includes_user_pass1_wrap_class() {
		$field = new Password( array() );

		ob_start();
		$field->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'user-pass1-wrap', $html );
	}

	// PasswordConfirm field.

	public function test_register_password_confirm_id() {
		$field = new PasswordConfirm( array() );

		$this->assertEquals( 'pass2', $field->get_id() );
	}

	public function test_register_password_confirm_label() {
		$field = new PasswordConfirm( array() );

		$this->assertEquals( 'Confirm Password', $field->get_label() );
	}

	public function test_register_password_confirm_input_name() {
		$field = new PasswordConfirm( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_user_pass2"', $html );
	}

	public function test_register_password_confirm_input_id() {
		$field = new PasswordConfirm( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="pass2"', $html );
	}

	public function test_register_password_confirm_input_type() {
		$field = new PasswordConfirm( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $html );
	}

	public function test_register_password_confirm_form_group_includes_user_pass2_wrap_class() {
		$field = new PasswordConfirm( array() );

		ob_start();
		$field->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'user-pass2-wrap', $html );
	}

	// Email field.

	public function test_register_email_id() {
		$field = new Email( array() );

		$this->assertEquals( 'email', $field->get_id() );
	}

	public function test_register_email_label() {
		$field = new Email( array() );

		$this->assertEquals( 'Email', $field->get_label() );
	}

	public function test_register_email_input_name() {
		$field = new Email( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_user_email"', $html );
	}

	public function test_register_email_input_type() {
		$field = new Email( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="email"', $html );
	}

	public function test_register_email_input_is_required() {
		$field = new Email( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'required', $html );
	}

	// Weak password field.

	public function test_register_weak_id() {
		$field = new Weak( array() );

		$this->assertEquals( 'pw-weak', $field->get_id() );
	}

	public function test_register_weak_label() {
		$field = new Weak( array() );

		$this->assertEquals( 'Confirm use of weak password', $field->get_label() );
	}

	public function test_register_weak_input_is_checkbox() {
		$field = new Weak( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'name="pw_weak"', $html );
		$this->assertStringContainsString( 'id="pw-weak"', $html );
	}

	public function test_register_weak_render_includes_pw_weak_class() {
		$field = new Weak( array() );

		ob_start();
		$field->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'pw-weak', $html );
	}

	public function test_register_weak_render_includes_label_inside_input_area() {
		$field = new Weak( array() );

		ob_start();
		$field->do_input();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Confirm use of weak password', $html );
	}
}
