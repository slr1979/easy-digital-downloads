<?php
/**
 * Coverage tests for the CheckoutEditorPreview Elementor subscriber.
 *
 * The subscriber makes the checkout form's controls non-interactive inside the
 * Elementor editor preview. Its event map and its inline-style enqueue have no
 * Elementor-element dependency, so they run without a real Elementor node.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Subscribers\CheckoutEditorPreview;

/**
 * Coverage tests for the CheckoutEditorPreview subscriber.
 *
 * @covers \EDD\Elementor\Subscribers\CheckoutEditorPreview
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class CheckoutEditorPreviewSubscriberCoverage extends EDD_UnitTestCase {

	/**
	 * The inline-only style handle the subscriber registers.
	 *
	 * @var string
	 */
	private const HANDLE = 'edd-elementor-checkout-editor-preview';

	/**
	 * Deregister the handle between tests so each enqueue starts clean.
	 */
	public function tear_down() {
		wp_deregister_style( self::HANDLE );
		wp_dequeue_style( self::HANDLE );

		parent::tear_down();
	}

	/**
	 * The subscriber maps the preview-styles event, which fires only in the editor iframe.
	 *
	 * @since 3.7.0
	 */
	public function test_get_subscribed_events() {
		$events = CheckoutEditorPreview::get_subscribed_events();

		$this->assertSame( 'enqueue_inert_styles', $events['elementor/preview/enqueue_styles'] );
	}

	/**
	 * enqueue_inert_styles enqueues an inline rule that disables pointer events on the form controls.
	 *
	 * @since 3.7.0
	 */
	public function test_enqueue_inert_styles_disables_form_controls() {
		$subscriber = new CheckoutEditorPreview();
		$subscriber->enqueue_inert_styles();

		$this->assertTrue(
			wp_style_is( self::HANDLE, 'enqueued' ),
			'The inert-controls style must be enqueued in the preview.'
		);

		$inline = wp_styles()->get_data( self::HANDLE, 'after' );
		$css    = is_array( $inline ) ? implode( '', $inline ) : (string) $inline;

		$this->assertStringContainsString(
			'pointer-events:none',
			$css,
			'The inert rule must disable pointer events.'
		);
		$this->assertStringContainsString(
			'#edd_purchase_form',
			$css,
			'The inert rule must be scoped to the purchase form so it cannot reach unrelated widgets.'
		);
	}
}
