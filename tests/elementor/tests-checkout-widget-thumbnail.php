<?php
/**
 * Thumbnail-width clamp coverage for the legacy monolith checkout widget.
 *
 * The legacy edd-checkout widget's render() path passes get_attributes() straight
 * to the block renderer, which does not clamp. The thumbnail_width slider setting
 * is an array, so the widget must unwrap and clamp it to a valid pixel int before
 * it reaches the cart template. These lock get_attributes()['thumbnail_width'] to
 * an int, clamped 10-100, default 25 — a value the cart template can hand to
 * get_the_post_thumbnail() without the array-to-string fallback to the full-size
 * image.
 *
 * Runs under --extra elementor: RealElementorWidgetFixture restores the real
 * \Elementor\Plugin singleton and builds the widget as a real Widget_Base instance
 * so get_settings() parses the slider value against the widget's real controls.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Widgets\Checkout;
use EDD\Tests\Elementor\Support\RealElementorWidgetFixture;

/**
 * Thumbnail-width clamp coverage for the legacy monolith checkout widget.
 *
 * @covers \EDD\Elementor\Widgets\Checkout::get_attributes
 *
 * @group elementor
 *
 * @since 3.7.1
 */
class CheckoutWidgetThumbnail extends EDD_UnitTestCase {

	use RealElementorWidgetFixture;

	/**
	 * Restore the real Elementor singleton before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->edd_boot_real_elementor();
	}

	/**
	 * Restore the real singleton after each test.
	 */
	public function tear_down() {
		if ( ! empty( $GLOBALS['edd_elementor_real_plugin'] ) ) {
			\Elementor\Plugin::$instance = $GLOBALS['edd_elementor_real_plugin'];
		}

		parent::tear_down();
	}

	/**
	 * A slider value inside the 10-100 range is unwrapped to that int.
	 *
	 * @since 3.7.1
	 */
	public function test_in_range_slider_value_is_unwrapped_to_int() {
		$width = $this->thumbnail_width( array( 'unit' => 'px', 'size' => 25, 'sizes' => array() ) );

		$this->assertIsInt( $width, 'The render path must receive an int, never the slider array.' );
		$this->assertSame( 25, $width );
	}

	/**
	 * A slider value below the minimum is clamped up to 10.
	 *
	 * @since 3.7.1
	 */
	public function test_below_range_slider_value_is_clamped_to_minimum() {
		$this->assertSame( 10, $this->thumbnail_width( array( 'unit' => 'px', 'size' => 5, 'sizes' => array() ) ) );
	}

	/**
	 * A slider value above the maximum is clamped down to 100.
	 *
	 * @since 3.7.1
	 */
	public function test_above_range_slider_value_is_clamped_to_maximum() {
		$this->assertSame( 100, $this->thumbnail_width( array( 'unit' => 'px', 'size' => 250, 'sizes' => array() ) ) );
	}

	/**
	 * A slider with an empty size falls back to the 25 default.
	 *
	 * @since 3.7.1
	 */
	public function test_empty_slider_size_falls_back_to_default() {
		$this->assertSame( 25, $this->thumbnail_width( array( 'unit' => 'px', 'size' => '', 'sizes' => array() ) ) );
	}

	/**
	 * With no thumbnail_width setting the attribute is still an int (never null/array).
	 *
	 * @since 3.7.1
	 */
	public function test_missing_setting_yields_int_default() {
		$width = $this->thumbnail_width_from_settings( array() );

		$this->assertIsInt( $width, 'A widget with no thumbnail width must still hand the template an int.' );
		$this->assertSame( 25, $width );
	}

	/**
	 * Build the widget with the given thumbnail_width slider value and return the
	 * thumbnail_width the render path would receive.
	 *
	 * @since 3.7.1
	 *
	 * @param mixed $slider_value The thumbnail_width slider setting.
	 * @return mixed The clamped thumbnail_width attribute.
	 */
	private function thumbnail_width( $slider_value ) {
		return $this->thumbnail_width_from_settings( array( 'thumbnail_width' => $slider_value ) );
	}

	/**
	 * Build the monolith widget with the given settings and return the render
	 * path's thumbnail_width attribute.
	 *
	 * get_attributes() is private and drives the live render() path, so it is
	 * invoked by reflection against a real widget instance whose settings parse
	 * against the widget's real controls stack.
	 *
	 * @since 3.7.1
	 *
	 * @param array $settings The widget settings.
	 * @return mixed The clamped thumbnail_width attribute.
	 */
	private function thumbnail_width_from_settings( array $settings ) {
		$widget = $this->edd_make_widget( Checkout::class, 'edd-checkout-node', $settings );

		$method = new \ReflectionMethod( $widget, 'get_attributes' );
		$method->setAccessible( true );

		return $method->invoke( $widget )['thumbnail_width'];
	}
}
