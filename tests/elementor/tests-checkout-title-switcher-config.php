<?php
/**
 * Coverage for the section-title switcher's emitted selectors.
 *
 * The switcher used to force its fieldset to `display: flex; flex-direction: column`, which overrode
 * the block stylesheet's grid and stacked the paired single-line-name and city/state/ZIP rows. These
 * assert the static config, so nothing here depends on Elementor being active.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Widgets\Config\Checkout\Titles;

/**
 * Section-title switcher config coverage.
 *
 * @covers \EDD\Elementor\Widgets\Config\Checkout\Titles::get_controls
 *
 * @group elementor
 */
class CheckoutTitleSwitcherConfig extends EDD_UnitTestCase {

	/**
	 * The title switchers and the fieldset each one targets.
	 *
	 * Log In and Register render into the personal-info slot, one at a time per the store's
	 * Customer Registration setting, and each has its own toggle so hiding one legend never
	 * hides another.
	 *
	 * @return array
	 */
	public function data_title_switchers(): array {
		return array(
			'personal info'   => array( 'show_personal_info_title', '#edd_checkout_user_info' ),
			'log in'          => array( 'show_login_title', '#edd_login_fields' ),
			'register'        => array( 'show_register_title', '#edd_register_fields' ),
			'billing details' => array( 'show_billing_details_title', '#edd_cc_address' ),
			'payment method'  => array( 'show_payment_method_title', '#edd_payment_mode_select' ),
			'card info'       => array( 'show_cc_details_title', '#edd_cc_fields' ),
		);
	}

	/**
	 * Returns the selectors a given title switcher emits.
	 *
	 * @param string $control The control id.
	 * @return array
	 */
	private function get_selectors( string $control ): array {
		foreach ( Titles::get_controls() as $section ) {
			if ( isset( $section['controls'][ $control ]['selectors'] ) ) {
				return $section['controls'][ $control ]['selectors'];
			}
		}

		return array();
	}

	/**
	 * Every switcher styles its legend, which is the whole job.
	 *
	 * @dataProvider data_title_switchers
	 *
	 * @param string $control  The control id.
	 * @param string $fieldset The fieldset selector it targets.
	 */
	public function test_switcher_styles_its_legend( string $control, string $fieldset ) {
		$selectors = $this->get_selectors( $control );

		$this->assertArrayHasKey( $fieldset . ' legend', $selectors, "{$control} must style its legend." );
		$this->assertStringContainsString( 'display: {{VALUE}}', $selectors[ $fieldset . ' legend' ] );
	}

	/**
	 * The switcher toggles display only. It never floats or sizes the legend: templates that need the
	 * legend lifted out of the fieldset border slot apply that float themselves as custom CSS, so the
	 * shared control never carries it onto the monolithic widget.
	 *
	 * @dataProvider data_title_switchers
	 *
	 * @param string $control  The control id.
	 * @param string $fieldset The fieldset selector it targets.
	 */
	public function test_switcher_toggles_display_only( string $control, string $fieldset ) {
		$css = $this->get_selectors( $control )[ $fieldset . ' legend' ] ?? '';

		$this->assertStringNotContainsString( 'float', $css, "{$control} must not bake the float into the shared switcher." );
		$this->assertStringNotContainsString( 'width:', $css, "{$control} should not size its legend." );
	}

	/**
	 * No switcher may set the fieldset's own display, which would discard the block
	 * stylesheet's grid and stack any paired row inside it.
	 *
	 * @dataProvider data_title_switchers
	 *
	 * @param string $control  The control id.
	 * @param string $fieldset The fieldset selector it targets.
	 */
	public function test_switcher_leaves_the_fieldset_display_alone( string $control, string $fieldset ) {
		$selectors = $this->get_selectors( $control );

		$this->assertNotEmpty( $selectors, "{$control} should emit selectors." );

		foreach ( $selectors as $selector => $css ) {
			// The legend rule is allowed to set display; the fieldset rule is not.
			if ( $selector === $fieldset . ' legend' ) {
				continue;
			}

			$this->assertStringNotContainsString( 'display:', $css, "{$control} must not set display on {$selector}." );
			$this->assertStringNotContainsString( 'flex-direction', $css, "{$control} must not set flex-direction on {$selector}." );
			$this->assertStringNotContainsString( 'grid-template', $css, "{$control} must not set a grid template on {$selector}." );
		}
	}
}
