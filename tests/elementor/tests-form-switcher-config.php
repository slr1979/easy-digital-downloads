<?php
/**
 * Coverage for the account form-switch button style controls.
 *
 * The "Log In" / "Register" buttons default to an absolute top-corner position that assumes a
 * section title beside them, so a template that hides the title gets the button on the first field.
 * The layout control is the fix: its "stacked" value drops the switcher into normal flow. These
 * assert the static config, so nothing depends on Elementor being active.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Widgets\Config\Checkout\Styles\FormSwitcher;

/**
 * Account form-switcher config coverage.
 *
 * @covers \EDD\Elementor\Widgets\Config\Checkout\Styles\FormSwitcher::get_controls
 *
 * @group elementor
 */
class FormSwitcherConfig extends EDD_UnitTestCase {

	/**
	 * The switch-button row the layout control repositions.
	 *
	 * @var string
	 */
	private $wrap = '#edd-account-forms';

	/**
	 * Returns the flattened control config for the switcher panel.
	 *
	 * @return array
	 */
	private function get_controls(): array {
		$sections = FormSwitcher::get_controls();

		return $sections['form_switcher_style']['controls'] ?? array();
	}

	/**
	 * The panel exists.
	 */
	public function test_the_panel_exists() {
		$sections = FormSwitcher::get_controls();

		$this->assertArrayHasKey( 'form_switcher_style', $sections );
		$this->assertSame( 'style', $sections['form_switcher_style']['tab'] );
	}

	/**
	 * The placement control leaves the stylesheet alone by default, so an existing template that
	 * relies on the top-corner position is unchanged.
	 */
	public function test_placement_defaults_to_the_stylesheet_behavior() {
		$control = $this->get_controls()['form_switcher_layout'];

		$this->assertSame( '', $control['default'] );
		$this->assertSame( '', $control['selectors_dictionary'][''], 'The default value emits nothing.' );
	}

	/**
	 * The "stacked" value drops the switcher out of absolute positioning, which is what stops it
	 * overlapping the first field when the section title is hidden.
	 */
	public function test_stacked_placement_takes_the_switcher_out_of_absolute_flow() {
		$control = $this->get_controls()['form_switcher_layout'];

		$this->assertArrayHasKey( $this->wrap, $control['selectors'] );
		$this->assertStringContainsString( 'position: static', $control['selectors_dictionary']['stacked'] );
	}

	/**
	 * The button box model is controllable, so a template can style the buttons without CSS.
	 */
	public function test_the_button_box_model_is_controllable() {
		$controls = $this->get_controls();

		foreach ( array( 'form_switcher_typography', 'form_switcher_color', 'form_switcher_background', 'form_switcher_border', 'form_switcher_radius', 'form_switcher_padding' ) as $control ) {
			$this->assertArrayHasKey( $control, $controls, "{$control} keeps the buttons off custom CSS." );
		}
	}
}
