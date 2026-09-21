<?php
/**
 * AJAX tests for the front-end cart handlers.
 *
 * Exercises the nopriv cart handlers in includes/ajax-functions.php: adding and removing
 * items, the cart subtotal, and item quantity updates. Each asserts on the behavior the
 * handler guarantees the storefront (nonce enforcement, cart state, recalculated totals),
 * not merely that a response comes back.
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
 * Cart AJAX handler tests.
 *
 * @since 3.7.1
 */
class Cart extends Ajax_UnitTestCase {

	/**
	 * Logs in an admin and enables item quantities for each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Nonce creation/verification and the discount check must see a logged-in user.
		wp_set_current_user( 1 );

		// Quantity updates only stick when item quantities are enabled.
		edd_update_option( 'item_quantities', true );
	}

	/**
	 * Restores the item quantities setting after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		edd_delete_option( 'item_quantities' );

		parent::tearDown();
	}

	/**
	 * A valid nonce adds the item and reports the new cart state.
	 *
	 * @return void
	 */
	public function test_add_to_cart_with_valid_nonce_adds_item() {
		$download_id = $this->create_ajax_download( 20.00 );

		$_POST = array(
			'download_id' => $download_id,
			// A simple download is added by passing its own ID as the price ID.
			'price_ids'   => array( $download_id ),
			'nonce'       => wp_create_nonce( 'edd-add-to-cart-' . $download_id ),
		);

		$response = json_decode( $this->_handleAjax( 'edd_add_to_cart' ), true );

		$this->assertIsArray( $response );
		$this->assertEquals( 1, edd_get_cart_quantity() );
		$this->assertStringContainsString( 'AJAX Test Download', $response['addedToCart'] );
	}

	/**
	 * A missing nonce is rejected and nothing is added to the cart.
	 *
	 * @return void
	 */
	public function test_add_to_cart_without_nonce_is_rejected() {
		$download_id = $this->create_ajax_download( 20.00 );

		$_POST = array(
			'download_id' => $download_id,
			'price_ids'   => array( $download_id ),
		);

		// A bare edd_die( '', '', 403 ) raises the "stop" exception, which _handleAjax lets through.
		try {
			$this->_handleAjax( 'edd_add_to_cart' );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );
		}

		$this->assertEquals( 0, edd_get_cart_quantity() );
	}

	/**
	 * A valid nonce removes the targeted item and reports removed => 1.
	 *
	 * @return void
	 */
	public function test_remove_from_cart_with_valid_nonce_removes_item() {
		$download_id = $this->create_ajax_download( 20.00 );
		edd_add_to_cart( $download_id );

		$_POST = array(
			'cart_item' => 0,
			'nonce'     => wp_create_nonce( 'edd-remove-cart-widget-item' ),
		);

		$response = json_decode( $this->_handleAjax( 'edd_remove_from_cart' ), true );

		$this->assertEquals( 1, $response['removed'] );
		$this->assertEquals( 0, edd_get_cart_quantity() );
	}

	/**
	 * An invalid nonce leaves the item in the cart and reports removed => 0.
	 *
	 * @return void
	 */
	public function test_remove_from_cart_with_invalid_nonce_keeps_item() {
		$download_id = $this->create_ajax_download( 20.00 );
		edd_add_to_cart( $download_id );

		$_POST = array(
			'cart_item' => 0,
			'nonce'     => 'not-a-real-nonce',
		);

		$response = json_decode( $this->_handleAjax( 'edd_remove_from_cart' ), true );

		$this->assertEquals( 0, $response['removed'] );
		$this->assertEquals( 1, edd_get_cart_quantity() );
	}

	/**
	 * The subtotal handler echoes a currency-formatted subtotal reflecting cart contents.
	 *
	 * @return void
	 */
	public function test_get_subtotal_reflects_cart_contents() {
		edd_add_to_cart( $this->create_ajax_download( 20.00 ) );
		edd_add_to_cart( $this->create_ajax_download( 20.00 ) );

		$response = $this->_handleAjax( 'edd_get_subtotal' );

		// Pin the amount rather than re-deriving it from the cart, so this can fail.
		$this->assertSame( edd_currency_filter( edd_format_amount( 40.00 ) ), $response );
	}

	/**
	 * Updating the quantity recalculates the line total when quantities are enabled.
	 *
	 * @return void
	 */
	public function test_update_cart_item_quantity_recalculates_total() {
		$download_id = $this->create_ajax_download( 20.00 );
		edd_add_to_cart( $download_id );

		$_POST = array(
			'download_id' => $download_id,
			'quantity'    => 3,
			'options'     => wp_json_encode( array() ),
		);

		$response = json_decode( $this->_handleAjax( 'edd_update_quantity' ), true );

		$this->assertEquals( 3, $response['quantity'] );
		$this->assertEquals( 60.00, $response['subtotal_raw'] );
	}

	/**
	 * The Tokenizer pair is accepted in place of a nonce, as used on cached pages.
	 *
	 * @return void
	 */
	public function test_add_to_cart_with_valid_token_adds_item() {
		$download_id = $this->create_ajax_download( 20.00 );
		$timestamp   = time();

		$_POST = array(
			'download_id' => $download_id,
			'price_ids'   => array( $download_id ),
			'timestamp'   => $timestamp,
			'token'       => \EDD\Utils\Tokenizer::tokenize( $timestamp ),
		);

		$response = json_decode( $this->_handleAjax( 'edd_add_to_cart' ), true );

		$this->assertIsArray( $response );
		$this->assertEquals( 1, edd_get_cart_quantity() );
	}

	/**
	 * A nonce issued for a different download does not authorize this one.
	 *
	 * @return void
	 */
	public function test_add_to_cart_with_mismatched_nonce_is_rejected() {
		$download_id       = $this->create_ajax_download( 20.00 );
		$other_download_id = $this->create_ajax_download( 20.00 );

		$_POST = array(
			'download_id' => $download_id,
			'price_ids'   => array( $download_id ),
			'nonce'       => wp_create_nonce( 'edd-add-to-cart-' . $other_download_id ),
		);

		try {
			$this->_handleAjax( 'edd_add_to_cart' );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );
		}

		$this->assertEquals( 0, edd_get_cart_quantity() );
	}

	/**
	 * The selected price option drives the cart, not the download's base price.
	 *
	 * @return void
	 */
	public function test_add_to_cart_with_variable_price_adds_selected_option() {
		$download_id = $this->create_ajax_variable_download( array( 20.00, 100.00 ) );

		$_POST = array(
			'download_id' => $download_id,
			'price_ids'   => array( 1 ),
			'nonce'       => wp_create_nonce( 'edd-add-to-cart-' . $download_id ),
		);

		$response = json_decode( $this->_handleAjax( 'edd_add_to_cart' ), true );

		$this->assertIsArray( $response );
		$this->assertEquals( 1, edd_get_cart_quantity() );

		// The price ID has to survive into the cart item, or the subtotal below is a coincidence.
		$cart = edd_get_cart_contents();
		$this->assertEquals( 1, $cart[0]['options']['price_id'] );
		$this->assertEquals( 100.00, edd_get_cart_subtotal() );
	}
}
