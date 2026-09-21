<?php
/**
 * AJAX tests for the front-end discount handlers.
 *
 * Exercises the nopriv discount handlers in includes/ajax-functions.php: applying a
 * discount code to the cart and removing one. Asserts on the validity signal and the
 * resulting cart discount state the storefront relies on.
 *
 * @package   EDD\Tests\Ajax
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.1
 */

namespace EDD\Tests\Ajax;

defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use EDD\Tests\PHPUnit\Ajax_UnitTestCase;

/**
 * Discount AJAX handler tests.
 *
 * @since 3.7.1
 */
class Discounts extends Ajax_UnitTestCase {

	/**
	 * Logs in an admin, seeds a cart item, and creates an active discount for each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// The apply handler reads the current user when validating the discount.
		wp_set_current_user( 1 );

		edd_add_to_cart( $this->create_ajax_download( 20.00 ) );

		static::edd()->discount->create_and_get(
			array(
				'name'              => '20 Percent Off',
				'code'              => '20OFF',
				'status'            => 'active',
				'type'              => 'percent',
				'amount'            => '20',
				'product_condition' => 'all',
				'start_date'        => '2010-01-01 00:00:00',
				'end_date'          => '2050-12-31 23:59:59',
			)
		);
	}

	/**
	 * A valid code is accepted and echoed back to the storefront.
	 *
	 * @return void
	 */
	public function test_apply_discount_with_valid_code_is_valid() {
		$_POST = array(
			'code' => '20OFF',
		);

		$response = json_decode( $this->_handleAjax( 'edd_apply_discount' ), true );

		$this->assertEquals( 'valid', $response['msg'] );
		$this->assertEquals( '20OFF', $response['code'] );
	}

	/**
	 * A guest supplies the email through the serialized form field rather than the current user.
	 *
	 * @return void
	 */
	public function test_apply_discount_as_guest_is_valid() {
		wp_set_current_user( 0 );

		$_POST = array(
			'code' => '20OFF',
			'form' => 'edd_email=guest%40example.org',
		);

		$response = json_decode( $this->_handleAjax( 'edd_apply_discount' ), true );

		$this->assertEquals( 'valid', $response['msg'] );
	}

	/**
	 * An unknown code is reported invalid rather than applied.
	 *
	 * @return void
	 */
	public function test_apply_discount_with_unknown_code_is_invalid() {
		$_POST = array(
			'code' => 'NOTACODE',
		);

		$response = json_decode( $this->_handleAjax( 'edd_apply_discount' ), true );

		$this->assertNotEquals( 'valid', $response['msg'] );
		$this->assertStringContainsStringIgnoringCase( 'invalid', $response['msg'] );
	}

	/**
	 * Removing the applied discount clears it from the cart.
	 *
	 * @return void
	 */
	public function test_remove_discount_clears_cart_discount() {
		edd_set_cart_discount( '20OFF' );

		$_POST = array(
			'code' => '20OFF',
		);

		$response = json_decode( $this->_handleAjax( 'edd_remove_discount' ), true );

		$this->assertEquals( '20OFF', $response['code'] );
		$this->assertEmpty( $response['discounts'] );

		// EDD_Cart::has_discounts() memoizes on the singleton and is not invalidated when a
		// discount is removed; reset it so the check reflects the now-empty session, as a fresh
		// request would (production gets a new cart per request).
		EDD()->cart->has_discounts = null;
		$this->assertFalse( edd_cart_has_discounts() );
	}
}
