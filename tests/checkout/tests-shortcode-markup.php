<?php

namespace EDD\Pro\Tests\Checkout;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests that shortcode checkout form functions produce the expected HTML markup.
 *
 * These tests run against both the pre-render_fields code and the post-render_fields
 * code (with shortcode-compatible fixes) to confirm the markup contract is preserved.
 */
class ShortcodeMarkup extends EDD_UnitTestCase {

	private static string $personal_info_html = '';
	private static string $register_html      = '';
	private static string $login_html         = '';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		ob_start();
		edd_user_info_fields();
		self::$personal_info_html = ob_get_clean();

		ob_start();
		edd_get_register_fields();
		self::$register_html = ob_get_clean();

		ob_start();
		edd_get_login_fields();
		self::$login_html = ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Personal info — wrapper IDs
	// -------------------------------------------------------------------------

	public function test_email_wrapper_id() {
		$this->assertStringContainsString( 'id="edd-email-wrap"', self::$personal_info_html );
	}

	public function test_first_name_wrapper_id() {
		$this->assertStringContainsString( 'id="edd-first-name-wrap"', self::$personal_info_html );
	}

	public function test_last_name_wrapper_id() {
		$this->assertStringContainsString( 'id="edd-last-name-wrap"', self::$personal_info_html );
	}

	// -------------------------------------------------------------------------
	// Personal info — description element IDs (required for aria-describedby)
	// -------------------------------------------------------------------------

	public function test_email_description_id() {
		$this->assertStringContainsString( 'id="edd-email-description"', self::$personal_info_html );
	}

	public function test_first_name_description_id() {
		$this->assertStringContainsString( 'id="edd-first-description"', self::$personal_info_html );
	}

	public function test_last_name_description_id() {
		$this->assertStringContainsString( 'id="edd-last-description"', self::$personal_info_html );
	}

	// -------------------------------------------------------------------------
	// Personal info — aria-describedby on inputs
	// -------------------------------------------------------------------------

	public function test_email_input_aria_describedby() {
		$this->assertStringContainsString( 'aria-describedby="edd-email-description"', self::$personal_info_html );
	}

	public function test_first_name_input_aria_describedby() {
		$this->assertStringContainsString( 'aria-describedby="edd-first-description"', self::$personal_info_html );
	}

	public function test_last_name_input_aria_describedby() {
		$this->assertStringContainsString( 'aria-describedby="edd-last-description"', self::$personal_info_html );
	}

	// -------------------------------------------------------------------------
	// Personal info — description text
	// -------------------------------------------------------------------------

	public function test_email_description_text() {
		$this->assertStringContainsString(
			'We will send the purchase receipt to this address.',
			self::$personal_info_html
		);
	}

	public function test_first_name_description_text() {
		$this->assertStringContainsString(
			'We will use this to personalize your account experience.',
			self::$personal_info_html
		);
	}

	public function test_last_name_description_text() {
		$this->assertStringContainsString(
			'We will use this as well to personalize your account experience.',
			self::$personal_info_html
		);
	}

	// -------------------------------------------------------------------------
	// Personal info — input names
	// -------------------------------------------------------------------------

	public function test_email_input_name() {
		$this->assertStringContainsString( 'name="edd_email"', self::$personal_info_html );
	}

	public function test_first_name_input_name() {
		$this->assertStringContainsString( 'name="edd_first"', self::$personal_info_html );
	}

	public function test_last_name_input_name() {
		$this->assertStringContainsString( 'name="edd_last"', self::$personal_info_html );
	}

	// -------------------------------------------------------------------------
	// Register fields — wrapper IDs and classes
	// -------------------------------------------------------------------------

	public function test_register_username_wrapper_id() {
		$this->assertStringContainsString( 'id="edd-user-login-wrap"', self::$register_html );
	}

	public function test_register_password_wrapper_id() {
		$this->assertStringContainsString( 'id="edd-user-pass-wrap"', self::$register_html );
	}

	public function test_register_password_confirm_wrapper_id() {
		$this->assertStringContainsString( 'id="edd-user-pass-confirm-wrap"', self::$register_html );
	}

	public function test_register_password_confirm_wrapper_class() {
		$this->assertStringContainsString( 'class="edd_register_password"', self::$register_html );
	}

	// -------------------------------------------------------------------------
	// Register fields — input names and IDs
	// -------------------------------------------------------------------------

	public function test_register_username_input_name() {
		$this->assertStringContainsString( 'name="edd_user_login"', self::$register_html );
	}

	public function test_register_password_input_name() {
		$this->assertStringContainsString( 'name="edd_user_pass"', self::$register_html );
	}

	public function test_register_password_confirm_input_name() {
		$this->assertStringContainsString( 'name="edd_user_pass_confirm"', self::$register_html );
	}

	public function test_register_password_confirm_input_id() {
		$this->assertStringContainsString( 'id="edd_user_pass_confirm"', self::$register_html );
	}

	// -------------------------------------------------------------------------
	// Login fields — wrapper IDs and classes
	// -------------------------------------------------------------------------

	public function test_login_username_wrapper_id() {
		$this->assertStringContainsString( 'id="edd-user-login-wrap"', self::$login_html );
	}

	public function test_login_password_wrapper_id() {
		$this->assertStringContainsString( 'id="edd-user-pass-wrap"', self::$login_html );
	}

	public function test_login_password_wrapper_class() {
		$this->assertStringContainsString( 'class="edd_login_password"', self::$login_html );
	}

	// -------------------------------------------------------------------------
	// Login fields — input names
	// -------------------------------------------------------------------------

	public function test_login_username_input_name() {
		$this->assertStringContainsString( 'name="edd_user_login"', self::$login_html );
	}

	public function test_login_password_input_name() {
		$this->assertStringContainsString( 'name="edd_user_pass"', self::$login_html );
	}
}
