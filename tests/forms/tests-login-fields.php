<?php

namespace EDD\Tests\Forms;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Forms\Login\Password;
use EDD\Forms\Login\Remember;
use EDD\Forms\Login\Username;

class LoginFields extends EDD_UnitTestCase {

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
}
