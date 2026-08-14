<?php
/**
 * Tests for the per-render-pass section-dedupe shared by the checkout section
 * widgets: the FormLayer rendered-section registry and the SectionGuard trait.
 *
 * The first instance of a section type in a render pass renders; any later
 * same-type instance renders nothing, so the page never emits a duplicate
 * gateway selector, form wrap, or field id. Editor and preview are exempt.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2025, Sandhills Development, LLC
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Checkout\FormLayer;
use EDD\Elementor\Subscribers\CheckoutFormLayer;
use EDD\Tests\Elementor\Support\RealElementorFixture;

/**
 * A minimal fake checkout section widget that uses the SectionGuard trait.
 *
 * The four real section widgets (Cart/PersonalInfo/PaymentInfo/DiscountForm) each
 * `use SectionGuard` and call is_duplicate_section() at the top of render(),
 * returning early when it reports a duplicate. This fake supplies just the two
 * members the trait touches — get_name() (the widget type) and a public accessor
 * for the protected is_duplicate_section() — so the per-render-pass dedupe can be
 * exercised without loading the real Elementor Widget_Base.
 */
class FakeSectionWidget {

	use \EDD\Elementor\Widgets\CheckoutInner\Concerns\SectionGuard;

	/**
	 * The widget type this fake reports as its name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Build the fake around a widget type.
	 *
	 * @param string $name The widget type (e.g. edd-checkout-payment-info).
	 */
	public function __construct( string $name ) {
		$this->name = $name;
	}

	/**
	 * Report the widget type the trait keys its claim on.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Public accessor for the protected trait method under test.
	 *
	 * @return bool True when this render is a duplicate to be skipped.
	 */
	public function is_duplicate(): bool {
		return $this->is_duplicate_section();
	}
}

/**
 * Tests for the per-render-pass section-dedupe: the FormLayer registry and the
 * SectionGuard trait the four checkout section widgets share.
 *
 * The FIRST instance of a section type in a render pass renders; any later
 * same-type instance renders nothing, so the page never emits a duplicate
 * payment-info gateway selector / #edd_purchase_form_wrap / field id. The editor
 * and preview are exempt so authors always see (and can delete) a duplicate.
 *
 * @covers \EDD\Elementor\Checkout\FormLayer::claim_section_render
 * @covers \EDD\Elementor\Checkout\FormLayer::has_section_rendered
 * @covers \EDD\Elementor\Checkout\FormLayer::clear_rendered_sections
 * @covers \EDD\Elementor\Widgets\CheckoutInner\Concerns\SectionGuard::is_duplicate_section
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class Tests_Checkout_Section_Dedup extends EDD_UnitTestCase {

	use RealElementorFixture;

	/**
	 * The REQUEST_URI saved before an Elementor stub, restored after.
	 *
	 * @var string|null
	 */
	private $prev_request_uri = null;

	/**
	 * Start each test with a clean rendered-section registry.
	 *
	 * @since 3.7.0
	 */
	public function setUp(): void {
		parent::setUp();

		FormLayer::clear_rendered_sections();
		FormLayer::reset_engaged();
		FormLayer::reset_page_form_open();
	}

	/**
	 * Clear the registry, engagement, and the Elementor stub between tests.
	 *
	 * @since 3.7.0
	 */
	public function tear_down() {
		FormLayer::clear_rendered_sections();
		FormLayer::reset_engaged();
		FormLayer::reset_page_form_open();
		$this->reset_elementor();

		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// FormLayer rendered-section registry: first-instance-wins, clear resets.
	// -----------------------------------------------------------------------

	/**
	 * claim_section_render records the first instance and refuses the second,
	 * while a distinct type still claims its own slot.
	 *
	 * @since 3.7.0
	 */
	public function test_claim_section_render_first_wins_duplicate_refused() {
		$this->assertTrue(
			FormLayer::claim_section_render( 'edd-checkout-payment-info' ),
			'The first payment-info render claims its slot.'
		);
		$this->assertFalse(
			FormLayer::claim_section_render( 'edd-checkout-payment-info' ),
			'A second payment-info render is refused (duplicate).'
		);
		$this->assertTrue(
			FormLayer::has_section_rendered( 'edd-checkout-payment-info' ),
			'The type is recorded as rendered after the first claim.'
		);
		$this->assertTrue(
			FormLayer::claim_section_render( 'edd-checkout-personal-info' ),
			'A distinct type still claims its own slot.'
		);
	}

	/**
	 * An empty type is always allowed and never tracked (it cannot be a
	 * tracked duplicate).
	 *
	 * @since 3.7.0
	 */
	public function test_empty_type_always_allowed_and_untracked() {
		$this->assertTrue( FormLayer::claim_section_render( '' ) );
		$this->assertTrue( FormLayer::claim_section_render( '' ) );
		$this->assertFalse( FormLayer::has_section_rendered( '' ) );
	}

	/**
	 * clear_rendered_sections resets the registry so the next pass's first
	 * instance of a type claims cleanly again.
	 *
	 * @since 3.7.0
	 */
	public function test_clear_rendered_sections_resets_the_registry() {
		FormLayer::claim_section_render( 'edd-checkout-cart' );
		$this->assertTrue( FormLayer::has_section_rendered( 'edd-checkout-cart' ) );

		FormLayer::clear_rendered_sections();

		$this->assertFalse( FormLayer::has_section_rendered( 'edd-checkout-cart' ) );
		$this->assertTrue(
			FormLayer::claim_section_render( 'edd-checkout-cart' ),
			'After a clear the type claims its slot as a first instance again.'
		);
	}

	/**
	 * A new document render pass (reset_for_new_render_pass) clears the
	 * rendered-section registry.
	 *
	 * @since 3.7.0
	 */
	public function test_reset_for_new_render_pass_clears_rendered_sections() {
		FormLayer::claim_section_render( 'edd-checkout-payment-info' );
		$this->assertTrue( FormLayer::has_section_rendered( 'edd-checkout-payment-info' ) );

		$subscriber = new CheckoutFormLayer();
		$subscriber->reset_for_new_render_pass();

		$this->assertFalse(
			FormLayer::has_section_rendered( 'edd-checkout-payment-info' ),
			'A new render pass must clear the rendered-section registry.'
		);
	}

	// -----------------------------------------------------------------------
	// SectionGuard trait: duplicate suppressed on the front end, both allowed
	// in the editor.
	// -----------------------------------------------------------------------

	/**
	 * On the front end a duplicate payment-info render is skipped: the first
	 * instance renders, the second reports a duplicate (renders nothing).
	 *
	 * @since 3.7.0
	 */
	public function test_duplicate_payment_info_renders_once_on_frontend() {
		$this->stub_elementor( false );

		// The dedupe only applies inside an actively-engaged checkout box.
		FormLayer::mark_engaged( 'dedupBox' );

		$first  = new FakeSectionWidget( 'edd-checkout-payment-info' );
		$second = new FakeSectionWidget( 'edd-checkout-payment-info' );

		$this->assertFalse( $first->is_duplicate(), 'The first payment-info renders on the front end.' );
		$this->assertTrue( $second->is_duplicate(), 'The second payment-info is a duplicate and renders nothing.' );
	}

	/**
	 * On the front end a duplicate personal-info render is likewise skipped.
	 *
	 * @since 3.7.0
	 */
	public function test_duplicate_personal_info_renders_once_on_frontend() {
		$this->stub_elementor( false );

		// The dedupe only applies inside an actively-engaged checkout box.
		FormLayer::mark_engaged( 'dedupBox' );

		$first  = new FakeSectionWidget( 'edd-checkout-personal-info' );
		$second = new FakeSectionWidget( 'edd-checkout-personal-info' );

		$this->assertFalse( $first->is_duplicate(), 'The first personal-info renders on the front end.' );
		$this->assertTrue( $second->is_duplicate(), 'The second personal-info is a duplicate and renders nothing.' );
	}

	/**
	 * Distinct section types all render on the front end — dedupe is per type,
	 * never across types.
	 *
	 * @since 3.7.0
	 */
	public function test_distinct_section_types_all_render_on_frontend() {
		$this->stub_elementor( false );

		// The dedupe only applies inside an actively-engaged checkout box.
		FormLayer::mark_engaged( 'dedupBox' );

		foreach ( array( 'edd-checkout-cart', 'edd-checkout-personal-info', 'edd-checkout-payment-info', 'edd-checkout-discount-form' ) as $type ) {
			$widget = new FakeSectionWidget( $type );
			$this->assertFalse( $widget->is_duplicate(), "The first {$type} must render on the front end." );
		}
	}

	/**
	 * In the Elementor editor BOTH duplicate instances render: the guard is
	 * edit-mode exempt so authors see (and can delete) a duplicate they placed.
	 *
	 * @since 3.7.0
	 */
	public function test_edit_mode_renders_both_duplicate_instances() {
		$this->stub_elementor( true );

		$first  = new FakeSectionWidget( 'edd-checkout-payment-info' );
		$second = new FakeSectionWidget( 'edd-checkout-payment-info' );

		$this->assertFalse( $first->is_duplicate(), 'Editor: the first instance renders.' );
		$this->assertFalse(
			$second->is_duplicate(),
			'Editor: the duplicate also renders (edit-mode exempt), and the registry is never touched.'
		);
		$this->assertFalse(
			FormLayer::has_section_rendered( 'edd-checkout-payment-info' ),
			'Editor renders must not populate the front-end dedupe registry.'
		);
	}

	// -----------------------------------------------------------------------
	// The dedupe is scoped to an engaged box: a standalone section widget
	// rendered when NO box is engaged renders normally and never consumes a
	// slot, so it cannot silently blank the box's own same-type section.
	// -----------------------------------------------------------------------

	/**
	 * A section widget rendered while NO checkout box is engaged renders normally
	 * and does NOT claim a slot. The box's own same-type section then still renders
	 * when the box later engages; a genuine second same-type section inside the
	 * engaged box is still suppressed.
	 *
	 * Regression guard: a generic section widget placed standalone above the box
	 * must not claim the type first and blank the box's real section (uncompletable
	 * checkout).
	 *
	 * @since 3.7.0
	 */
	public function test_standalone_section_outside_engaged_box_does_not_consume_slot() {
		$this->stub_elementor( false );

		// No box engaged: a standalone/above-the-box section widget.
		FormLayer::reset_engaged();

		$standalone = new FakeSectionWidget( 'edd-checkout-payment-info' );
		$this->assertFalse(
			$standalone->is_duplicate(),
			'A section widget rendered while no box is engaged must render normally.'
		);
		$this->assertFalse(
			FormLayer::has_section_rendered( 'edd-checkout-payment-info' ),
			'A section rendered outside an engaged box must NOT claim/consume a slot.'
		);

		// The box now engages and renders its OWN same-type section: it must render
		// (the standalone widget did not consume the slot).
		FormLayer::mark_engaged( 'engagedBox' );

		$box_section = new FakeSectionWidget( 'edd-checkout-payment-info' );
		$this->assertFalse(
			$box_section->is_duplicate(),
			'The box\'s own payment-info must render — a standalone widget outside the box must not have consumed its slot.'
		);

		// A genuine second same-type section INSIDE the engaged box is still suppressed.
		$second_in_box = new FakeSectionWidget( 'edd-checkout-payment-info' );
		$this->assertTrue(
			$second_in_box->is_duplicate(),
			'A second same-type section inside the engaged box must still be suppressed.'
		);
	}

	// -----------------------------------------------------------------------
	// Discount-form dedupe: the discount form carries no visibility toggle (its
	// presence in the box is the gate), so the first instance claims the slot and
	// renders while a genuine second same-type instance inside the engaged box is
	// suppressed — the same first-instance-wins contract as the other sections.
	// -----------------------------------------------------------------------

	/**
	 * The first discount-form inside the engaged box claims the dedupe slot and
	 * renders; a genuine second same-type instance is suppressed.
	 *
	 * @since 3.7.0
	 *
	 * @covers \EDD\Elementor\Checkout\FormLayer::claim_section_render
	 * @covers \EDD\Elementor\Widgets\CheckoutInner\Concerns\SectionGuard::is_duplicate_section
	 */
	public function test_first_discount_form_claims_slot_second_suppressed() {
		$this->stub_elementor( false );
		FormLayer::mark_engaged( 'discountBox' );

		$this->assertFalse(
			FormLayer::has_section_rendered( 'edd-checkout-discount-form' ),
			'No discount-form has claimed the dedupe slot yet.'
		);

		// The first discount-form reaches the guard, claims the slot, and renders.
		$first = new FakeSectionWidget( 'edd-checkout-discount-form' );
		$this->assertFalse(
			$first->is_duplicate(),
			'The first discount-form inside the engaged box must render.'
		);

		// A genuine second discount-form inside the engaged box is suppressed.
		$second = new FakeSectionWidget( 'edd-checkout-discount-form' );
		$this->assertTrue(
			$second->is_duplicate(),
			'A second discount-form inside the engaged box must be suppressed.'
		);
	}

	/**
	 * Point Page::is_edit_mode() at a stubbed Elementor instance.
	 *
	 * Installs an \Elementor\Plugin shim whose editor reports the requested
	 * edit-mode state and normalizes the request URI to a plain front-end path.
	 *
	 * @since 3.7.0
	 *
	 * @param bool $edit_mode Whether the stubbed editor reports edit mode.
	 * @return void
	 */
	private function stub_elementor( bool $edit_mode ): void {
		$this->edd_require_real_elementor();

		$this->prev_request_uri = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/checkout/';

		\Elementor\Plugin::$instance         = new class() {
			public $editor;
			public $documents;
		};
		\Elementor\Plugin::$instance->editor = new class( $edit_mode ) {
			private $edit;
			public function __construct( $edit ) {
				$this->edit = $edit;
			}
			public function is_edit_mode() {
				return $this->edit;
			}
		};
	}

	/**
	 * Restore request state and clear the Elementor stub.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	private function reset_elementor(): void {
		if ( null === $this->prev_request_uri ) {
			$_SERVER['REQUEST_URI'] = '';
		} else {
			$_SERVER['REQUEST_URI'] = $this->prev_request_uri;
		}

		if ( class_exists( '\Elementor\Plugin', false ) ) {
			\Elementor\Plugin::$instance = null;
		}
	}
}
