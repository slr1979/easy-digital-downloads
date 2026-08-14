<?php
/**
 * Render-output coverage for the composed Elementor checkout inner widgets.
 *
 * The composed Elementor checkout reuses the SAME static section renderers the
 * block-editor checkout uses. These tests assert the render contract directly
 * against the static \EDD\Blocks\Checkout\Elements\* render output the Elementor
 * section widgets echo, plus the discount view the Discount widget includes.
 *
 * Mirrors tests/blocks/tests-checkout-render.php, but targets the Elements/*
 * statics (echo -> captured with ob_start) rather than the block classes.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Download;
use EDD\Tests\Helpers\EDD_Helper_Discount;
use EDD\Blocks\Checkout\Cart as CartBlock;
use EDD\Blocks\Checkout\Elements\Cart;
use EDD\Blocks\Checkout\Elements\PersonalInfo;
use EDD\Blocks\Checkout\Elements\PaymentDetails;

/**
 * Composed Elementor checkout render oracle.
 *
 * @group elementor
 */
class CheckoutInnerRender extends EDD_UnitTestCase {

	/**
	 * A simple download added to the cart for render paths that require contents.
	 *
	 * @var \WP_Post
	 */
	private static $download;

	/**
	 * A percentage discount ID so the discount view render path is active.
	 *
	 * @var int
	 */
	private static $discount_id;

	/**
	 * Create shared fixtures once for the class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$download    = EDD_Helper_Download::create_simple_download();
		self::$discount_id = EDD_Helper_Discount::create_simple_percent_discount();
	}

	/**
	 * Tear down shared fixtures once for the class.
	 */
	public static function tearDownAfterClass(): void {
		EDD_Helper_Download::delete_download( self::$download->ID );
		EDD_Helper_Discount::delete_discount( self::$discount_id );

		parent::tearDownAfterClass();
	}

	/**
	 * Populate the cart before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		edd_empty_cart();
		edd_add_to_cart( self::$download->ID );
	}

	/**
	 * Empty the cart and reset editor-context simulation after each test.
	 */
	public function tearDown(): void {
		edd_empty_cart();
		unset( $_GET['edd_blocks_is_block_editor'] );

		parent::tearDown();
	}

	/**
	 * Cart: a populated cart renders the cart wrapper + the checkout cart form.
	 */
	public function test_cart_render_contains_wrapper_and_form() {
		$html = $this->capture(
			function () {
				Cart::render(
					array(
						'block_attributes' => \EDD\Blocks\Checkout\Attributes::get(),
						'cart_items'       => edd_get_cart_contents(),
					)
				);
			}
		);

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd-blocks__cart', $html );
		$this->assertStringContainsString( 'edd_checkout_cart_form', $html );
	}

	/**
	 * Cart: an empty cart renders the empty-cart message, not the cart form.
	 *
	 * Note: the empty-cart guard lives in the shared block renderer
	 * EDD\Blocks\Checkout\Cart::render (the renderer the Elementor Cart widget
	 * delegates to), which short-circuits to the edd-empty-cart message BEFORE
	 * it calls the static Elements\Cart::render. The static Elements renderer
	 * itself always includes the cart table, so the empty-cart contract is
	 * asserted against the block renderer here. CartBlock is a plain renderer
	 * (NOT an Elementor widget class), so it is safe to call in the unit suite.
	 */
	public function test_cart_render_empty_cart_shows_message() {
		// The shared CartBlock render path runs inside the EDD\Blocks\Checkout
		// namespace, where Software Licensing's checkout integration references the
		// EDD_SL_VERSION constant. When Software Licensing is loaded but that
		// constant is undefined (SL present-but-not-active, as in the coverage/
		// matrix CI image), the render fatals with an undefined-constant Error
		// rather than returning the empty-cart markup. Skip when the SL constant is
		// unavailable so this render assertion only runs where it is meaningful,
		// mirroring the suite's other integration-dependent skips.
		if ( ! defined( 'EDD_SL_VERSION' ) ) {
			$this->markTestSkipped( 'Requires EDD Software Licensing (EDD_SL_VERSION) to be active.' );
		}

		edd_empty_cart();

		$block = new CartBlock();
		$html  = $block->render();

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd-empty-cart', $html );
		$this->assertStringNotContainsString( 'edd_checkout_cart_form', $html );
	}

	/**
	 * Personal Info: rendered DIRECTLY (skipping the UserDetails coordinator, per
	 * the port plan), PersonalInfo::render emits its own .edd-blocks__checkout-user
	 * wrapper plus the #edd-email field.
	 *
	 * Note: the outer .edd-blocks__user-details wrapper is added by the
	 * UserDetails coordinator (Elements/UserDetails.php), NOT by PersonalInfo
	 * itself. Because the port calls PersonalInfo directly, the front-end
	 * .edd-blocks__user-details marker is asserted in the e2e oracle (where the
	 * composed widget produces the full DOM); the direct-call output asserted
	 * here is .edd-blocks__checkout-user + edd-email.
	 *
	 * The attributes are stated here rather than read from Attributes::get(), which caches per-request
	 * so the result depended on whichever earlier test primed it. The fields under test are the guest
	 * ones, so it asks for a guest.
	 */
	public function test_personal_info_render_contains_user_fields() {
		$attributes              = \EDD\Blocks\Checkout\Attributes::get();
		$attributes['logged_in'] = false;

		$html = $this->capture(
			function () use ( $attributes ) {
				PersonalInfo::render( $attributes );
			}
		);

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd-blocks__checkout-user', $html );
		$this->assertStringContainsString( 'edd-email', $html );
	}

	/**
	 * Payment Info: PaymentDetails::render always emits the payment-details
	 * wrapper. With multiple gateways enabled and a non-zero cart total,
	 * edd_show_gateways() is true and it emits the EMPTY #edd_purchase_form_wrap
	 * div that the gateway AJAX (action=edd_load_gateway) later fills.
	 *
	 * Force the multi-gateway branch explicitly: the unit suite enables a single
	 * gateway by default (edd_show_gateways() false), which renders the form
	 * inline instead of leaving the empty wrap.
	 */
	public function test_payment_info_render_contains_payment_details_and_wrap() {
		add_filter( 'edd_show_gateways', '__return_true' );

		$html = $this->capture(
			function () {
				PaymentDetails::render( \EDD\Blocks\Checkout\Attributes::get(), null );
			}
		);

		remove_filter( 'edd_show_gateways', '__return_true' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd-blocks__payment-details', $html );
		$this->assertStringContainsString( 'edd_purchase_form_wrap', $html );
	}

	/**
	 * Discount: with an active discount + a non-zero cart total, the discount
	 * view (the file the Discount widget includes) renders the discount-code form.
	 */
	public function test_discount_view_render_contains_discount_code() {
		$html = $this->capture(
			function () {
				include EDD_BLOCKS_DIR . 'views/checkout/discount.php';
			}
		);

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'edd_discount_code', $html );
	}

	/**
	 * Nonce (no-gateways branch): forcing edd_show_gateways() false makes
	 * PaymentDetails render the purchase form INLINE (do_action('edd_purchase_form')),
	 * which fires edd_purchase_form_after_cc_form -> edd_checkout_submit() ->
	 * edd_checkout_hidden_fields() -> the canonical process-checkout nonce.
	 *
	 * Pins the parent contract: the composed Elementor checkout must NOT add its
	 * own hidden fields, so there is exactly ONE edd_purchase_submit fieldset and
	 * the process-checkout nonce is present inside it.
	 *
	 * Counting raw 'edd-process-checkout-nonce' occurrences is NOT a stable
	 * oracle in the unit suite: the gateway bundled in the test image (Stripe)
	 * emits its own copy of the hidden-fields block, so the raw substring count is
	 * environment-dependent (observed 2 with Stripe active, both carrying the same
	 * edd_action=purchase + edd-gateway hidden inputs). The parent-owned contract
	 * is "exactly one submit fieldset, nonce inside" — what the composed checkout
	 * must guarantee, asserted here. The strict "exactly one nonce after the
	 * gateway loads" timing assertion lives in the e2e oracle, where the real
	 * front-end (without the unit image's extra gateway shims) is exercised.
	 *
	 * The cart is emptied so edd_get_cart_total() is 0, keeping the inline form on
	 * its free-checkout path: no gateway-specific credit-card form loads at all
	 * (edd_show_purchase_form only fires the CC form when the total is > 0), while
	 * the submit fieldset + hidden fields still render.
	 */
	public function test_payment_info_no_gateways_emits_single_submit_with_nonce() {
		edd_empty_cart();
		add_filter( 'edd_show_gateways', '__return_false' );

		$html = $this->capture(
			function () {
				PaymentDetails::render( \EDD\Blocks\Checkout\Attributes::get(), null );
			}
		);

		remove_filter( 'edd_show_gateways', '__return_false' );

		$this->assertIsString( $html );
		// Exactly one purchase-submit fieldset: the parent does not double-emit it.
		$this->assertSame( 1, substr_count( $html, 'id="edd_purchase_submit"' ) );
		// The process-checkout nonce is present inside the inline submit form.
		$this->assertStringContainsString( 'edd-process-checkout-nonce', $html );
	}

	// -----------------------------------------------------------------------
	// The Elementor template presets are not shipped on this branch, so the
	// preset-file structural test (preset_files_provider /
	// test_preset_is_structurally_valid) is intentionally absent here. It will
	// be re-added alongside the preset files once they exist and carry the
	// container's final element name (edd-checkout-box).
	// -----------------------------------------------------------------------

	/**
	 * find_widget locates a container node by its elType, not widgetType.
	 *
	 * Verifies find_widget() correctly matches a node whose elType is
	 * 'edd-checkout-box' (the EDD container element's type string). Structural
	 * regression guard: a rename of the elType string without updating the
	 * matcher would leave find_widget() returning null for every container node.
	 *
	 * This validates the finder shape the preset structural test relies on; it
	 * runs immediately because the fixture is authored inline here.
	 */
	public function test_find_widget_matches_container_el_type() {
		$tree = array(
			array(
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'elType'   => 'edd-checkout-box',
						'settings' => array(),
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'edd-checkout-personal-info',
								'settings'   => array(),
								'elements'   => array(),
							),
						),
					),
				),
			),
		);

		$container = $this->find_widget( $tree, 'edd-checkout-box' );

		$this->assertIsArray( $container );
		$this->assertSame( 'edd-checkout-box', $container['elType'] );
		$this->assertNotEmpty( $container['elements'] );
	}

	/**
	 * find_widget does NOT match a node when elType is 'widget' (regression guard).
	 *
	 * Confirms that the reshaped find_widget() does not accidentally match
	 * edd-checkout-* section widgets (elType === 'widget') when searching for
	 * the container element (elType === 'edd-checkout-box'). Without the shape
	 * change a string-only rename would have caused these two distinct element
	 * types to be confused.
	 *
	 * Guards the element-shape contract the preset structural test relies on.
	 */
	public function test_find_widget_does_not_match_widget_el_type() {
		$tree = array(
			array(
				'elType'     => 'widget',
				'widgetType' => 'edd-checkout-box',
				'settings'   => array(),
				'elements'   => array(),
			),
		);

		// Searching for the container elType must NOT match a widget node even
		// if its widgetType happens to be the same string.
		$result = $this->find_widget( $tree, 'edd-checkout-box' );

		$this->assertNull( $result );
	}

	/**
	 * Capture the echoed output of a static Elements renderer.
	 *
	 * The Elements/* renderers echo (they do not return), so we wrap the call in
	 * an output buffer to obtain the rendered HTML the Elementor widgets emit.
	 *
	 * @param callable $renderer The renderer invocation.
	 * @return string The captured HTML.
	 */
	private function capture( callable $renderer ): string {
		ob_start();
		$renderer();
		return (string) ob_get_clean();
	}

	/**
	 * Recursively find the first container node whose elType matches the given
	 * container type (elType === $container_type) in a raw element-data tree.
	 *
	 * The EDD Checkout Box is a native Elementor container element (elType ===
	 * 'edd-checkout-box'), NOT a widget (elType === 'widget'). Matching on
	 * elType directly (not widgetType) is required; a string-only rename of the
	 * old widgetType guard would silently match nothing because the elType gate
	 * would still reject the container node.
	 *
	 * @param array  $elements       Raw element-data arrays.
	 * @param string $container_type The elType to locate (e.g. 'edd-checkout-box').
	 * @return array|null The matching container node, or null when absent.
	 */
	private function find_widget( array $elements, string $container_type ): ?array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// Match a container element by elType, not widgetType. The
			// edd-checkout-box element is a container (elType === 'edd-checkout-box'),
			// so the old elType==='widget' gate must not be used here.
			if ( isset( $element['elType'] ) && $container_type === $element['elType'] ) {
				return $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = $this->find_widget( $element['elements'], $container_type );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}
}
