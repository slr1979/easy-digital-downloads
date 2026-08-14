<?php
/**
 * Coverage for the gateway selector style controls.
 *
 * EDD's Stripe integration samples a gateway tile's background, border and radius and
 * applies them to the Payment Element's own payment-method tabs. That predates these
 * controls, but the controls are what make it reachable without hand-written CSS, so the
 * panel carries an opt-out. These assert the static config, so nothing depends on
 * Elementor being active.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Widgets\Config\Checkout\Styles\GatewayTiles;

/**
 * Gateway tile config coverage.
 *
 * @covers \EDD\Elementor\Widgets\Config\Checkout\Styles\GatewayTiles::get_controls
 *
 * @group elementor
 */
class GatewayTilesConfig extends EDD_UnitTestCase {

	/**
	 * The tile selector the Stripe integration samples.
	 *
	 * @var string
	 */
	private $tile = '#edd-payment-mode-wrap .edd-gateway-option';

	/**
	 * Returns the flattened control config for the gateway selector panel.
	 *
	 * @return array
	 */
	private function get_controls(): array {
		$sections = GatewayTiles::get_controls();

		return $sections['gateway_tiles_style']['controls'] ?? array();
	}

	/**
	 * The opt-out exists on the panel.
	 */
	public function test_the_opt_out_control_exists() {
		$this->assertArrayHasKey( 'gateway_tile_skip_stripe', $this->get_controls() );
	}

	/**
	 * It is off by default, so no existing checkout changes appearance.
	 */
	public function test_the_opt_out_defaults_to_off() {
		$control = $this->get_controls()['gateway_tile_skip_stripe'];

		$this->assertSame( '', $control['default'], 'Matching Stripe stays the default behavior.' );
		$this->assertSame( 'switcher', $control['type'] );
	}

	/**
	 * Switching it on declares the property on the tile the integration samples, which is
	 * where the runtime reads it from.
	 */
	public function test_the_opt_out_declares_the_property_on_the_sampled_tile() {
		$control = $this->get_controls()['gateway_tile_skip_stripe'];

		$this->assertArrayHasKey( $this->tile, $control['selectors'] );
		$this->assertStringContainsString( '--edd-stripe-match-tiles', $control['selectors'][ $this->tile ] );
		$this->assertSame( 'off', $control['return_value'], 'The runtime compares the property against "off".' );
	}

	/**
	 * The three properties Stripe samples all have controls, which is why the opt-out is needed.
	 */
	public function test_the_sampled_properties_are_all_controllable() {
		$controls = $this->get_controls();

		foreach ( array( 'gateway_tile_background', 'gateway_tile_border', 'gateway_tile_radius' ) as $control ) {
			$this->assertArrayHasKey( $control, $controls, "{$control} is one of the properties Stripe reads." );
		}
	}
}
