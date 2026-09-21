<?php
/**
 * Tests for the block checkout register form markup.
 *
 * @package EDD\Tests\Forms
 * @since 3.7.1
 */

namespace EDD\Pro\Tests\Checkout;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests that the block checkout register form submits the keys checkout processing reads.
 *
 * The register view renders shared fields which are also used by the standalone registration
 * form, and the two forms are validated by different functions which read different keys.
 */
class BlockMarkup extends EDD_UnitTestCase {

	private static string $register_html = '';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$customer               = array(
			'email'      => '',
			'first_name' => '',
			'last_name'  => '',
		);
		$customer_info_complete = false;

		ob_start();
		include EDD_BLOCKS_DIR . 'views/checkout/purchase-form/register.php';
		self::$register_html = ob_get_clean();
	}

	public function test_username_input_name() {
		$this->assertStringContainsString( 'name="edd_user_login"', self::$register_html );
	}

	public function test_password_input_name() {
		$this->assertStringContainsString( 'name="edd_user_pass"', self::$register_html );
	}

	/**
	 * edd_purchase_form_validate_new_user() reads edd_user_pass_confirm.
	 */
	public function test_password_confirm_input_name() {
		$this->assertStringContainsString( 'name="edd_user_pass_confirm"', self::$register_html );
	}

	/**
	 * edd_user_pass2 belongs to the standalone registration form; submitting it here fails validation.
	 */
	public function test_password_confirm_does_not_use_registration_key() {
		$this->assertStringNotContainsString( 'name="edd_user_pass2"', self::$register_html );
	}

	public function test_purchase_var_marks_the_form_as_registration() {
		$this->assertStringContainsString( 'name="edd-purchase-var" value="needs-to-register"', self::$register_html );
	}
}
