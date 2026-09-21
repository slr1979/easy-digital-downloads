<?php
/**
 * AJAX tests for the checkout address/tax handlers.
 *
 * Exercises the nopriv handlers in includes/ajax-functions.php that back the checkout
 * address fields: the states drop-down and the tax recalculation. Both enforce a nonce,
 * so each handler is tested for the accepted and rejected paths.
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
 * Checkout address/tax AJAX handler tests.
 *
 * @since 3.7.1
 */
class Checkout extends Ajax_UnitTestCase {

	/**
	 * Logs in an admin for consistent nonce handling.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
	}

	/**
	 * Disables taxes after each test, since the recalculation test turns them on.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		edd_update_option( 'enable_taxes', false );

		parent::tearDown();
	}

	/**
	 * A valid nonce returns the states drop-down for the requested country.
	 *
	 * @return void
	 */
	public function test_get_states_field_with_valid_nonce_returns_states() {
		$_POST = array(
			'nonce'   => wp_create_nonce( 'edd-country-field-nonce' ),
			'country' => 'US',
		);

		$response = $this->_handleAjax( 'edd_get_shop_states' );

		$this->assertStringContainsString( '<select', $response );
		$this->assertStringContainsString( 'California', $response );
	}

	/**
	 * An invalid nonce is rejected with no states output.
	 *
	 * @return void
	 */
	public function test_get_states_field_with_invalid_nonce_is_rejected() {
		$_POST = array(
			'nonce'   => 'not-a-real-nonce',
			'country' => 'US',
		);

		// A rejected nonce triggers a bare edd_die(), i.e. the propagating "stop" exception.
		try {
			$this->_handleAjax( 'edd_get_shop_states' );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );
		}

		$this->assertEmpty( $this->_last_response );
	}

	/**
	 * A valid nonce with cart contents applies the rate for the submitted address.
	 *
	 * @return void
	 */
	public function test_recalculate_taxes_with_valid_nonce_returns_total() {
		edd_update_option( 'enable_taxes', true );
		edd_add_tax_rate(
			array(
				'scope'       => 'region',
				'name'        => 'US',
				'description' => 'TN',
				'amount'      => 10,
			)
		);

		edd_add_to_cart( $this->create_ajax_download( 20.00 ) );

		$_POST = array(
			'nonce'           => wp_create_nonce( 'edd-checkout-address-fields' ),
			'billing_country' => 'US',
			// edd_get_tax_rate() reads the region from `state`, which is what checkout.js sends.
			'state'           => 'TN',
		);

		// EDD_Cart memoizes the tax rate the first time it prices an item, which here was before the
		// address was submitted. Production gets a new cart per request; reset it so this one does too.
		EDD()->cart->set_tax_rate( null );

		$response = json_decode( $this->_handleAjax( 'edd_recalculate_taxes' ), true );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'html', $response );
		$this->assertEquals( 2.00, $response['tax_raw'] );
		$this->assertEquals( 22.00, $response['total_raw'] );
	}

	/**
	 * An invalid nonce short-circuits before any recalculation output.
	 *
	 * @return void
	 */
	public function test_recalculate_taxes_with_invalid_nonce_is_rejected() {
		edd_add_to_cart( $this->create_ajax_download( 20.00 ) );

		$_POST = array(
			'nonce' => 'not-a-real-nonce',
		);

		$this->assertEmpty( $this->_handleAjax( 'edd_recalculate_taxes' ) );
	}
}
