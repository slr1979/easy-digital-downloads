<?php
/**
 * Checkout-marker regression tests for the Elementor template importer.
 *
 * The composable checkout refactor made a single bare wp:edd/checkout marker
 * insufficient: an imported page must carry the FULL marker set (the outer
 * wp:edd/checkout marker plus the matching inner section markers) or it renders
 * duplicate personal-info fields and a default 25px cart thumbnail. These tests
 * lock the importer to emitting that full set, byte-identical to the live-widget
 * save path (both route through MarkerBuilder so they cannot drift), and prove
 * the attribute reconstruction cannot be broken by a hostile downloaded template.
 *
 * Runs under --extra elementor: the import() assertions drive Elementor's real
 * Document::save() path via RealElementorWidgetFixture. The builder assertions
 * are pure data-to-string and gate on the same fixture only so the whole class
 * shares one setUp.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\MarkerBuilder;
use EDD\Elementor\Widgets\CheckoutInner\Cart;
use EDD\Elementor\Widgets\CheckoutInner\PersonalInfo;
use EDD\Elementor\Widgets\CheckoutInner\PaymentInfo;
use EDD\Elementor\Widgets\CheckoutInner\DiscountForm;
use EDD\Pro\Checkout\Templates\Importer\ElementorImporter;
use EDD\Tests\Elementor\Support\RealElementorWidgetFixture;

/**
 * Marker-set coverage for the Elementor importer and the shared MarkerBuilder.
 *
 * @covers \EDD\Elementor\MarkerBuilder
 * @covers \EDD\Pro\Checkout\Templates\Importer\ElementorImporter::write_checkout_markers
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class ElementorImporterMarkers extends EDD_UnitTestCase {

	use RealElementorWidgetFixture;

	/**
	 * The cart thumbnail width the composable fixture carries (not the 25 default).
	 *
	 * @var int
	 */
	private const CART_THUMBNAIL_WIDTH = 60;

	/**
	 * The page the importer saves into.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * Restore the real Elementor singleton and act as an administrator.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->edd_boot_real_elementor();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Restore the real singleton after each test (the byte-identity test swaps in a
	 * throwaway documents plugin for the marker-write gate).
	 */
	public function tear_down() {
		if ( ! empty( $GLOBALS['edd_elementor_real_plugin'] ) ) {
			\Elementor\Plugin::$instance = $GLOBALS['edd_elementor_real_plugin'];
		}

		parent::tear_down();
	}

	/**
	 * A composable import writes the full marker set: has_block() true, every inner
	 * section marker present, and the cart's thumbnail width on the outer attributes.
	 *
	 * @since 3.7.0
	 */
	public function test_import_writes_full_composable_marker_set() {
		$result = ( new ElementorImporter() )->import( $this->composable_template(), $this->page_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$post = get_post( $this->page_id );

		// The outer block is discoverable, so the block-gated checkout behavior engages.
		$this->assertTrue( has_block( 'edd/checkout', $post ) );

		// Every inner section marker is present (the personal-info / payment-info
		// ownership signals that stop the duplicate-personal-info symptom).
		$this->assertStringContainsString( '<!-- wp:edd/checkout-cart /-->', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:edd/checkout-personal-info /-->', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:edd/checkout-payment-info /-->', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:edd/checkout-discount-form /-->', $post->post_content );

		// The thumbnail width comes from the cart widget, not the 25 default.
		$this->assertStringContainsString( '"thumbnail_width":' . self::CART_THUMBNAIL_WIDTH, $post->post_content );
		$this->assertStringNotContainsString( '"thumbnail_width":25', $post->post_content );
	}

	/**
	 * The importer's reconstructed marker set is byte-identical to the live-widget
	 * save path — both route through MarkerBuilder, so import and editor output
	 * cannot drift. Compared against REAL section widgets rendering their own
	 * plain content against the same document tree.
	 *
	 * @since 3.7.0
	 */
	public function test_import_output_is_byte_identical_to_live_widget_save() {
		$content = $this->composable_elements();

		// Live editor-save path: each real section widget, saved inside the box,
		// emits its own plain-content markers. Concatenated with no separator, this
		// is exactly what Elementor's save_plain_text() writes to post_content.
		$this->edd_install_current_document( $content );

		$widgets = array(
			Cart::class         => 'cart-node',
			PersonalInfo::class => 'personal-node',
			PaymentInfo::class  => 'payment-node',
			DiscountForm::class => 'discount-node',
		);

		$live_output = '';
		foreach ( $widgets as $class => $node_id ) {
			ob_start();
			$this->edd_make_widget( $class, $node_id )->render_plain_content();
			$live_output .= (string) ob_get_clean();
		}

		// Importer path: reconstruct the marker set from the same element tree.
		$import_output = MarkerBuilder::build( MarkerBuilder::from_elements( $content ) );

		$this->assertSame( $live_output, $import_output );

		// And the live output really is the full composable set with the cart width.
		$this->assertSame( $this->expected_composable_markers(), $live_output );
	}

	/**
	 * Re-importing onto a page that already carries the OLD bare marker leaves no
	 * stale or duplicate marker: post_content becomes exactly the fresh full set.
	 *
	 * @since 3.7.0
	 */
	public function test_reimport_over_bare_marker_leaves_no_stale_marker() {
		// Simulate a page imported before the fix: a single bare marker.
		wp_update_post(
			array(
				'ID'           => $this->page_id,
				'post_content' => '<!-- wp:edd/checkout /-->',
			)
		);

		$result = ( new ElementorImporter() )->import( $this->composable_template(), $this->page_id );
		$this->assertTrue( $result['success'] );

		$post = get_post( $this->page_id );

		// The pre-fix bare marker is gone (it is not a substring of any full marker).
		$this->assertStringNotContainsString( '<!-- wp:edd/checkout /-->', $post->post_content );

		// Exactly four outer markers, one per section — no duplicates left behind.
		$this->assertSame( 4, substr_count( $post->post_content, '<!-- wp:edd/checkout {' ) );
	}

	/**
	 * The builder emits the full composable set (outer + inner per section) in order.
	 *
	 * @since 3.7.0
	 */
	public function test_builder_emits_full_composable_set_from_tree() {
		$this->assertSame(
			$this->expected_composable_markers(),
			MarkerBuilder::build( MarkerBuilder::from_elements( $this->composable_elements() ) )
		);
	}

	/**
	 * The builder emits a single outer marker (no inner block) for the legacy monolith.
	 *
	 * @since 3.7.0
	 */
	public function test_builder_emits_single_marker_for_legacy_monolith() {
		$elements = array(
			array(
				'id'       => 'wrap',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'legacy',
						'elType'     => 'widget',
						'widgetType' => 'edd-checkout',
						'settings'   => array( 'thumbnail_width' => array( 'size' => 40 ) ),
						'elements'   => array(),
					),
				),
			),
		);

		$this->assertSame(
			'<!-- wp:edd/checkout {"layout":"","show_discount_form":true,"thumbnail_width":40} /-->',
			MarkerBuilder::build( MarkerBuilder::from_elements( $elements ) )
		);
	}

	/**
	 * The builder emits nothing when the tree carries no checkout widget.
	 *
	 * @since 3.7.0
	 */
	public function test_builder_emits_nothing_without_checkout_widget() {
		$elements = array(
			array(
				'id'         => 'heading',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array(),
				'elements'   => array(),
			),
		);

		$this->assertSame( array(), MarkerBuilder::from_elements( $elements ) );
		$this->assertSame( '', MarkerBuilder::build( MarkerBuilder::from_elements( $elements ) ) );
	}

	/**
	 * Hostile characters in the source attributes cannot break the marker delimiter.
	 *
	 * The outer marker is wrapped in an HTML comment (<!-- ... -->). A free-form
	 * string containing -->, >, or -- would break that delimiter and allow markup
	 * injection. The builder constrains every attribute to a numeric/enum/const
	 * value, so the hostile input is fully neutralized.
	 *
	 * @since 3.7.0
	 */
	public function test_builder_neutralizes_hostile_attribute_characters() {
		$output = MarkerBuilder::build(
			array(
				array(
					'block' => 'edd/checkout-cart',
					'attrs' => array(
						'layout'             => '--><script>alert(1)</script><!--',
						'show_discount_form' => '"--><!--',
						'thumbnail_width'    => array( 'size' => '60"/--><script>' ),
					),
				),
			)
		);

		// The hostile layout collapses to the empty enum default; the hostile
		// discount value casts to a boolean; the thumbnail casts to a clamped int.
		$this->assertSame(
			'<!-- wp:edd/checkout {"layout":"","show_discount_form":true,"thumbnail_width":60} /--><!-- wp:edd/checkout-cart /-->',
			$output
		);

		// No injected markup survived into the marker.
		$this->assertStringNotContainsString( 'script', $output );
		$this->assertStringNotContainsString( 'alert', $output );
	}

	/**
	 * A composable template payload for import().
	 *
	 * @since 3.7.0
	 *
	 * @return array The template array.
	 */
	private function composable_template(): array {
		return array(
			'template_id' => 'test-composable-markers',
			'name'        => 'Composable Marker Template',
			'version'     => '1.0.0',
			'content'     => $this->composable_elements(),
			'settings'    => array(),
		);
	}

	/**
	 * A composable element tree: an edd-checkout-box with the four section widgets,
	 * the cart carrying a non-default thumbnail width.
	 *
	 * @since 3.7.0
	 *
	 * @return array The Elementor element tree.
	 */
	private function composable_elements(): array {
		return array(
			array(
				'id'       => 'box-node',
				'elType'   => 'edd-checkout-box',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'cart-node',
						'elType'     => 'widget',
						'widgetType' => 'edd-checkout-cart',
						'settings'   => array( 'thumbnail_width' => array( 'size' => self::CART_THUMBNAIL_WIDTH, 'unit' => 'px' ) ),
						'elements'   => array(),
					),
					array(
						'id'         => 'personal-node',
						'elType'     => 'widget',
						'widgetType' => 'edd-checkout-personal-info',
						'settings'   => array(),
						'elements'   => array(),
					),
					array(
						'id'         => 'payment-node',
						'elType'     => 'widget',
						'widgetType' => 'edd-checkout-payment-info',
						'settings'   => array(),
						'elements'   => array(),
					),
					array(
						'id'         => 'discount-node',
						'elType'     => 'widget',
						'widgetType' => 'edd-checkout-discount-form',
						'settings'   => array(),
						'elements'   => array(),
					),
				),
			),
		);
	}

	/**
	 * The exact marker set the composable fixture must produce.
	 *
	 * @since 3.7.0
	 *
	 * @return string The expected marker string.
	 */
	private function expected_composable_markers(): string {
		$outer = '<!-- wp:edd/checkout {"layout":"","show_discount_form":true,"thumbnail_width":' . self::CART_THUMBNAIL_WIDTH . '} /-->';

		return $outer . '<!-- wp:edd/checkout-cart /-->'
			. $outer . '<!-- wp:edd/checkout-personal-info /-->'
			. $outer . '<!-- wp:edd/checkout-payment-info /-->'
			. $outer . '<!-- wp:edd/checkout-discount-form /-->';
	}
}
