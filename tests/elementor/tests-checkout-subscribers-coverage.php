<?php
/**
 * Coverage tests for the Checkout and EditorAssets Elementor subscribers.
 *
 * These assert the subscribers' event maps and their asset enqueues, which have no
 * Elementor-element dependency, so they run without a real Elementor node.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Subscribers\Checkout;
use EDD\Elementor\Subscribers\EditorAssets;
use EDD\Tests\Elementor\Support\RealElementorFixture;

/**
 * Coverage tests for the Checkout and EditorAssets subscribers.
 *
 * @covers \EDD\Elementor\Subscribers\Checkout
 * @covers \EDD\Elementor\Subscribers\EditorAssets
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class CheckoutAndEditorAssetsSubscriberCoverage extends EDD_UnitTestCase {

	use RealElementorFixture;

	/**
	 * The Checkout subscriber maps its page/checkout events.
	 *
	 * @since 3.7.0
	 */
	public function test_checkout_get_subscribed_events() {
		$events = Checkout::get_subscribed_events();

		$this->assertArrayHasKey( 'elementor/preview/enqueue_scripts', $events );
		$this->assertArrayHasKey( 'pre_render_block', $events );

		// The bespoke edd_is_checkout filter and the edd_purchase_form_top user-info
		// suppressor were removed: the plain-content block markers make has_block()
		// true, so core supplies both behaviors on the Elementor path.
		$this->assertArrayNotHasKey( 'edd_is_checkout', $events );
		$this->assertArrayNotHasKey( 'edd_purchase_form_top', $events );
	}

	/**
	 * enqueue_preview_assets enqueues the checkout editor style.
	 *
	 * @since 3.7.0
	 */
	public function test_enqueue_preview_assets() {
		// enqueue_preview_assets() statically loads the Checkout widget, which
		// extends the native \Elementor\Widget_Base; skip unless real Elementor is
		// present (run with --extra elementor).
		$this->edd_require_real_elementor();

		$subscriber = new Checkout();
		$subscriber->enqueue_preview_assets();

		$this->assertTrue( wp_style_is( 'edd-checkout-style', 'enqueued' ) || wp_style_is( 'edd-checkout-style', 'registered' ) );
	}

	/**
	 * The EditorAssets subscriber maps the editor-enqueue event.
	 *
	 * @since 3.7.0
	 */
	public function test_editor_assets_get_subscribed_events() {
		$events = EditorAssets::get_subscribed_events();

		$this->assertSame( 'enqueue_editor_scripts', $events['elementor/editor/before_enqueue_scripts'] );
	}

	/**
	 * enqueue_editor_scripts registers the checkout-box editor bundle.
	 *
	 * @since 3.7.0
	 */
	public function test_enqueue_editor_scripts() {
		$subscriber = new EditorAssets();
		$subscriber->enqueue_editor_scripts();

		$this->assertTrue( wp_script_is( 'edd-elementor-checkout-box', 'enqueued' ) );
	}
}
