<?php
/**
 * Tests for checkout block patterns.
 *
 * @package EDD\Tests\Blocks\Checkout
 */

namespace EDD\Tests\Blocks\Checkout;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Blocks\Checkout\Patterns as CheckoutPatterns;

/**
 * @group blocks
 */
class Patterns extends EDD_UnitTestCase {

	private static $registry;

	private static $slugs = array(
		'edd-checkout/single-column',
		'edd-checkout/two-column-50-50',
		'edd-checkout/two-column-70-30',
		'edd-checkout/two-column-80-20',
		'edd-checkout/cart-top-two-column',
	);

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$registry = \WP_Block_Patterns_Registry::get_instance();

		$patterns = new CheckoutPatterns();
		$patterns->register();
	}

	public static function tearDownAfterClass(): void {
		foreach ( self::$slugs as $slug ) {
			if ( self::$registry->is_registered( $slug ) ) {
				self::$registry->unregister( $slug );
			}
		}

		parent::tearDownAfterClass();
	}

	public function test_all_five_patterns_are_registered() {
		foreach ( self::$slugs as $slug ) {
			$this->assertTrue( self::$registry->is_registered( $slug ), "Pattern '{$slug}' should be registered." );
		}
	}

	public function test_all_patterns_target_checkout_block() {
		foreach ( self::$slugs as $slug ) {
			$pattern = self::$registry->get_registered( $slug );
			$this->assertContains(
				'edd/checkout',
				$pattern['blockTypes'],
				"Pattern '{$slug}' should target the edd/checkout block."
			);
		}
	}

	public function test_50_50_pattern_has_no_explicit_column_widths() {
		$pattern = self::$registry->get_registered( 'edd-checkout/two-column-50-50' );
		$this->assertStringNotContainsString( 'flex-basis', $pattern['content'] );
		$this->assertStringNotContainsString( '"width"', $pattern['content'] );
	}

	public function test_70_30_pattern_has_correct_column_widths() {
		$pattern = self::$registry->get_registered( 'edd-checkout/two-column-70-30' );
		$this->assertStringContainsString( 'flex-basis:70%', $pattern['content'] );
		$this->assertStringContainsString( 'flex-basis:30%', $pattern['content'] );
	}

	public function test_80_20_pattern_has_correct_column_widths() {
		$pattern = self::$registry->get_registered( 'edd-checkout/two-column-80-20' );
		$this->assertStringContainsString( 'flex-basis:80%', $pattern['content'] );
		$this->assertStringContainsString( 'flex-basis:20%', $pattern['content'] );
	}

	public function test_single_column_contains_all_checkout_inner_blocks() {
		$pattern = self::$registry->get_registered( 'edd-checkout/single-column' );
		$this->assertStringContainsString( 'wp:edd/checkout-cart', $pattern['content'] );
		$this->assertStringContainsString( 'wp:edd/checkout-personal-info', $pattern['content'] );
		$this->assertStringContainsString( 'wp:edd/checkout-payment-info', $pattern['content'] );
		$this->assertStringNotContainsString( 'wp:edd/checkout-purchase-form', $pattern['content'] );
	}

	public function test_cart_top_two_column_contains_all_checkout_inner_blocks() {
		$pattern = self::$registry->get_registered( 'edd-checkout/cart-top-two-column' );
		$this->assertStringContainsString( 'wp:edd/checkout-cart', $pattern['content'] );
		$this->assertStringContainsString( 'wp:edd/checkout-personal-info', $pattern['content'] );
		$this->assertStringContainsString( 'wp:edd/checkout-payment-info', $pattern['content'] );
		$this->assertStringNotContainsString( 'wp:edd/checkout-purchase-form', $pattern['content'] );
	}
}
