<?php
/**
 * Render-callback HTML-assertion tests for the checkout inner blocks.
 *
 * Render-callback coverage for the checkout inner blocks (cart, personal-info,
 * payment-info, discount-form). Each test invokes
 * the block's render callback with a representative cart/state and asserts the
 * output contains the block's stable wrapper/markers and does not fatal.
 *
 * @package EDD\Tests\Blocks\Checkout
 */

namespace EDD\Tests\Blocks\Checkout;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Download;
use EDD\Tests\Helpers\EDD_Helper_Discount;
use EDD\Blocks\Checkout\Cart as CartBlock;
use EDD\Blocks\Checkout\PersonalInfo as PersonalInfoBlock;
use EDD\Blocks\Checkout\PaymentInfo as PaymentInfoBlock;
use EDD\Blocks\Checkout\DiscountForm as DiscountFormBlock;

/**
 * @group blocks
 */
class CheckoutRender extends EDD_UnitTestCase {

	/**
	 * A simple download added to the cart for render paths that require contents.
	 *
	 * @var \WP_Post
	 */
	private static $download;

	/**
	 * A percentage discount ID so the discount-form render path is active.
	 *
	 * @var int
	 */
	private static $discount_id;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$download    = EDD_Helper_Download::create_simple_download();
		self::$discount_id = EDD_Helper_Discount::create_simple_percent_discount();
	}

	public static function tearDownAfterClass(): void {
		EDD_Helper_Download::delete_download( self::$download->ID );
		EDD_Helper_Discount::delete_discount( self::$discount_id );

		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();

		edd_empty_cart();
		edd_add_to_cart( self::$download->ID );
	}

	public function tearDown(): void {
		edd_empty_cart();

		// Reset the editor-context simulation.
		unset( $_GET['edd_blocks_is_block_editor'] );

		parent::tearDown();
	}

	/**
	 * Cart block: populated cart renders the cart wrapper + form.
	 */
	public function test_cart_block_render_contains_wrapper_and_form() {
		$block = new CartBlock();
		$html  = $block->render();

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd-blocks__cart', $html );
		$this->assertStringContainsString( 'edd_checkout_cart_form', $html );
	}

	/**
	 * Cart block: empty cart with no fees renders the empty-cart message, not the form.
	 */
	public function test_cart_block_render_empty_cart_shows_message() {
		edd_empty_cart();

		$block = new CartBlock();
		$html  = $block->render();

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd-empty-cart', $html );
		$this->assertStringNotContainsString( 'edd_checkout_cart_form', $html );
	}

	/**
	 * Personal-info block: renders the user-details wrapper with the email field.
	 */
	public function test_personal_info_block_render_contains_user_details() {
		$block = new PersonalInfoBlock();
		$html  = $block->render();

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd-blocks__user-details', $html );
		$this->assertStringContainsString( 'edd-email', $html );
	}

	/**
	 * Payment-info block: renders the payment-details wrapper.
	 */
	public function test_payment_info_block_render_contains_payment_details() {
		$block = new PaymentInfoBlock();
		$html  = $block->render();

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd-blocks__payment-details', $html );
	}

	/**
	 * Discount-form block: with an active discount + cart total the form renders.
	 */
	public function test_discount_form_block_render_contains_discount_code() {
		$block = new DiscountFormBlock();
		$html  = $block->render();

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd_discount_code', $html );
	}
}
