<?php
/**
 * Coverage for the composable box's control changes not disturbing the shipped monolithic widget.
 *
 * The personal-info section styling, the cart item border and the title legends all changed for the
 * composable checkout box. The monolithic `edd-checkout` widget shipped those controls in 3.6.0, so
 * these assert the shipped selectors/ids stay put while the composable gets the new behavior.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Widgets\Config\Checkout\Styles\Sections;
use EDD\Elementor\Widgets\Config\Checkout\Styles\Cart as StylesCart;
use EDD\Elementor\Widgets\Config\CheckoutInner\Controls;

/**
 * Monolith backward-compat coverage.
 *
 * @covers \EDD\Elementor\Widgets\Config\Checkout\Styles\Sections::get_controls
 *
 * @group elementor
 */
class MonolithCompat extends EDD_UnitTestCase {

	/**
	 * The selector the personal-info background control targets in a given context.
	 *
	 * @param string $context 'monolith' or 'composable'.
	 * @return string
	 */
	private function personal_info_selector( string $context ): string {
		$controls = Sections::get_controls( $context )['personal_information_section_style']['controls'];
		return (string) array_key_first( $controls['personal_information_section_background_color']['selectors'] );
	}

	/**
	 * The monolith keeps the 3.6.0 guest-fieldset selector, so existing saved values still render.
	 */
	public function test_monolith_personal_info_keeps_the_shipped_fieldset_selector() {
		$this->assertSame( 'form#edd_purchase_form #edd_checkout_user_info', $this->personal_info_selector( 'monolith' ) );
	}

	/**
	 * The composable box targets the wrapper that spans the guest, log-in and register forms.
	 */
	public function test_composable_personal_info_targets_the_wrapper() {
		$this->assertSame( 'form#edd_purchase_form .edd-checkout-block__personal-info', $this->personal_info_selector( 'composable' ) );
	}

	/**
	 * The composable cart uses the row-divider variant, not the shipped full-item border.
	 */
	public function test_composable_cart_uses_a_row_divider() {
		$controls = Controls::get_cart_controls()['cart_section_style']['controls'];

		$this->assertArrayHasKey( 'cart_items_row_divider', $controls );
		$this->assertArrayNotHasKey( 'cart_items_border', $controls );
		$this->assertStringContainsString( ':not(:last-child)', $controls['cart_items_row_divider']['selector'] );
	}

	/**
	 * The monolith keeps its shipped full-item "Border" control untouched.
	 */
	public function test_monolith_cart_keeps_its_border_control() {
		$controls = StylesCart::get_controls()['cart_section_style']['controls'];

		$this->assertArrayHasKey( 'cart_items_border', $controls );
		$this->assertSame( __( 'Border', 'easy-digital-downloads' ), $controls['cart_items_border']['label'] );
		$this->assertStringNotContainsString( ':not(:last-child)', $controls['cart_items_border']['selector'] );
	}
}
