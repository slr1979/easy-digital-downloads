<?php
/**
 * Registration + load-order survival tests for the EDD Checkout box element.
 *
 * Runs against real Elementor (--extra elementor): the RealElementorFixture trait
 * restores the fully-initialized \Elementor\Plugin singleton in setUp so the
 * element class (which extends the native Container) instantiates and registers
 * without fatal. The registration subscriber is driven against a fake elements
 * manager, asserting:
 *
 *   1. The subscriber subscribes to `elementor/elements/elements_registered`.
 *   2. register_element() registers the element type when the native Container
 *      class is present (the element class extends it).
 *   3. Registration survives load-order: a SECOND register_element() call (the
 *      retry path the guarded plain require enables) does not fatal and still
 *      registers, where a permanently no-op'd load-once would have left the
 *      element class undefined and aborted registration silently.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2025, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Subscribers\CheckoutBox as CheckoutBoxSubscriber;
use EDD\Tests\Elementor\Support\RealElementorFixture;

/**
 * Fake controls manager that records registered control instances.
 */
class FakeControlsManager {

	/**
	 * Control instances passed to register().
	 *
	 * @var array
	 */
	public $registered = array();

	/**
	 * Record a registered control.
	 *
	 * @param object $control The control instance.
	 * @return void
	 */
	public function register( $control ) {
		$this->registered[] = $control;
	}
}

/**
 * Fake elements manager that records registered element types.
 */
class FakeElementsManager {

	/**
	 * Registered element type instances.
	 *
	 * @var array
	 */
	public $registered = array();

	/**
	 * Record a registered element type.
	 *
	 * @param object $element The element type instance.
	 * @return void
	 */
	public function register_element_type( $element ) {
		$this->registered[] = $element;
	}
}

/**
 * @covers \EDD\Elementor\Subscribers\CheckoutBox
 *
 * @group elementor
 */
class CheckoutBoxRegistration extends EDD_UnitTestCase {

	use RealElementorFixture;

	/**
	 * Restore the real, fully-initialized Elementor singleton before each test.
	 *
	 * The real native Container the element extends only survives instantiation
	 * because the singleton's kits_manager/experiments are wired; the trait
	 * restores that singleton (and forces the Container experiment active + an
	 * active kit) so register_element() can construct the real element.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->edd_boot_real_elementor();
	}

	/**
	 * The subscriber subscribes to the elements_registered hook.
	 */
	public function test_get_subscribed_events_maps_elements_registered() {
		$events = CheckoutBoxSubscriber::get_subscribed_events();

		$this->assertArrayHasKey( 'elementor/elements/elements_registered', $events );
		$this->assertSame( 'register_element', $events['elementor/elements/elements_registered'] );
	}

	/**
	 * The subscriber subscribes to the controls/register hook for the layout picker.
	 */
	public function test_get_subscribed_events_maps_controls_register() {
		$events = CheckoutBoxSubscriber::get_subscribed_events();

		$this->assertArrayHasKey( 'elementor/controls/register', $events );
		$this->assertSame( 'register_controls', $events['elementor/controls/register'] );
	}

	/**
	 * register_controls registers the LayoutPicker control type with the manager.
	 */
	public function test_register_controls_registers_the_layout_picker_type() {
		$manager    = new FakeControlsManager();
		$subscriber = new CheckoutBoxSubscriber();

		$subscriber->register_controls( $manager );

		$this->assertCount( 1, $manager->registered );
		$this->assertInstanceOf( '\EDD\Elementor\Controls\LayoutPicker', $manager->registered[0] );
		$this->assertSame( 'edd-layout-picker', $manager->registered[0]->get_type() );
	}

	/**
	 * register_controls is a no-op when the manager cannot register controls.
	 */
	public function test_register_controls_noops_without_a_valid_manager() {
		$subscriber = new CheckoutBoxSubscriber();

		// Must not fatal on a manager missing register().
		$subscriber->register_controls( new \stdClass() );

		$this->assertTrue( true, 'register_controls must degrade to a no-op without a valid manager.' );
	}

	/**
	 * register_element registers the element type when Container is present.
	 */
	public function test_register_element_registers_the_element_type() {
		$manager    = new FakeElementsManager();
		$subscriber = new CheckoutBoxSubscriber();

		$subscriber->register_element( $manager );

		$this->assertCount( 1, $manager->registered );
		$this->assertInstanceOf( '\EDD\Elementor\Elements\CheckoutBox', $manager->registered[0] );
	}

	/**
	 * register_element skips registration when the Container experiment is off.
	 *
	 * The composable box requires Elementor containers, so with the experiment off
	 * the element must not be registered (leaving the legacy checkout as the only
	 * option in the panel).
	 */
	public function test_register_element_skips_when_container_experiment_off() {
		$this->set_container_experiment_active( false );

		$manager    = new FakeElementsManager();
		$subscriber = new CheckoutBoxSubscriber();

		$subscriber->register_element( $manager );

		$this->assertCount( 0, $manager->registered, 'The element must not register when the Container experiment is off.' );
	}

	/**
	 * register_element registers the element when the Container experiment is on.
	 */
	public function test_register_element_registers_when_container_experiment_on() {
		$this->set_container_experiment_active( true );

		$manager    = new FakeElementsManager();
		$subscriber = new CheckoutBoxSubscriber();

		$subscriber->register_element( $manager );

		$this->assertCount( 1, $manager->registered );
		$this->assertInstanceOf( '\EDD\Elementor\Elements\CheckoutBox', $manager->registered[0] );
	}

	/**
	 * The element reports the FINAL type/name on both accessors.
	 */
	public function test_element_type_and_name_match_final_string() {
		$manager    = new FakeElementsManager();
		$subscriber = new CheckoutBoxSubscriber();
		$subscriber->register_element( $manager );

		$element = $manager->registered[0];

		$this->assertSame( 'edd-checkout-box', $element::get_type() );
		$this->assertSame( 'edd-checkout-box', $element->get_name() );
	}

	/**
	 * Registration survives a repeated call (the guarded-require retry path).
	 *
	 * A second register_element() must not fatal and must still register. With a
	 * permanently no-op'd load-once the element class could be left undefined and
	 * registration would silently abort; the guarded plain require allows the
	 * retry, and once the class is defined class_exists short-circuits the require.
	 */
	public function test_registration_survives_repeated_calls() {
		$subscriber = new CheckoutBoxSubscriber();

		$first = new FakeElementsManager();
		$subscriber->register_element( $first );

		$second = new FakeElementsManager();
		$subscriber->register_element( $second );

		$this->assertCount( 1, $first->registered );
		$this->assertCount( 1, $second->registered );
		$this->assertInstanceOf( '\EDD\Elementor\Elements\CheckoutBox', $second->registered[0] );
	}

	/**
	 * The subscriber's guard defines the element class and registers it.
	 *
	 * This exercises the guard path in register_element(): after the call,
	 * class_exists( CheckoutBox, false ) is true AND register_element_type was
	 * called. This is the scenario that fails under require_once — a second
	 * require_once is a no-op so the class stays undefined and registration
	 * silently aborts. The guarded plain require (class_exists check + plain
	 * require) allows a retry to define the class.
	 *
	 * Because we are in a single-process suite the class is already defined by
	 * prior tests, so we assert the post-call invariants: the class is defined
	 * (the guard did not prevent it) and registration_element_type was called.
	 */
	public function test_guard_defines_class_and_registers() {
		// Pre-condition: the real native Container must be loaded (via --extra
		// elementor) so the element class file can be parsed.
		$this->assertTrue(
			class_exists( '\Elementor\Includes\Elements\Container', false ),
			'The native Container must be loaded before the guard test runs.'
		);

		$manager    = new FakeElementsManager();
		$subscriber = new CheckoutBoxSubscriber();

		// class_exists with false (no autoload) mirrors the subscriber guard.
		// In this process the class is already defined from prior tests; the
		// guard short-circuits the require and falls through to registration.
		$subscriber->register_element( $manager );

		// Post-condition 1: the class is defined (guard did not block it).
		$this->assertTrue(
			class_exists( '\EDD\Elementor\Elements\CheckoutBox', false ),
			'CheckoutBox must be defined after register_element() runs.'
		);

		// Post-condition 2: register_element_type was called.
		$this->assertCount(
			1,
			$manager->registered,
			'register_element_type must be called exactly once.'
		);

		$this->assertInstanceOf(
			'\EDD\Elementor\Elements\CheckoutBox',
			$manager->registered[0]
		);
	}

	/**
	 * The box replaces the inherited native "Grid" preset with none of its own.
	 *
	 * Regression guard for the recurring stray "Grid" tile. The native Container's
	 * get_panel_presets() returns a `container_grid` ("Grid") preset; because this
	 * element extends the native Container and is forced into the EDD category, an
	 * un-replaced method lets that Grid preset surface under Easy Digital Downloads.
	 * The override REPLACES the inherited list with the base-class empty list, so no
	 * scoped preset (and therefore no "Grid" tile) is contributed — the box shows
	 * only its own single base "EDD Checkout" tile. Asserts the method exists (so it
	 * overrides the inherited one) and returns an empty array containing no
	 * `container_grid`/grid preset.
	 */
	public function test_get_panel_presets_returns_no_grid_preset() {
		$manager    = new FakeElementsManager();
		$subscriber = new CheckoutBoxSubscriber();
		$subscriber->register_element( $manager );

		$element = $manager->registered[0];

		$this->assertTrue(
			method_exists( $element, 'get_panel_presets' ),
			'get_panel_presets() must be present so it replaces the inherited native "Grid" preset.'
		);

		$presets = $element->get_panel_presets();

		$this->assertIsArray( $presets );
		$this->assertSame( array(), $presets, 'The box must contribute no scoped preset, so the inherited "Grid" preset is replaced.' );
		$this->assertArrayNotHasKey( 'container_grid', $presets, 'The native "Grid" preset must never surface.' );
	}

	/**
	 * register_controls registers the panel-hosted layout picker.
	 *
	 * The retired `edd_layout` Select is gone: the box's layout is now chosen by a
	 * five-pattern picker rendered IN the box's options panel as the custom
	 * `edd-layout-picker` control, hosted in its OWN dedicated "Checkout Layout"
	 * section (a start_controls_section/end_controls_section), separate from the
	 * native Container "Layout" section whose controls all stay visible at their
	 * defaults. So register_controls must register the `edd_layout_picker` control
	 * (type `edd-layout-picker`) inside the `edd_checkout_layout` section, but NOT the
	 * retired `edd_layout`, and must NOT re-type/hide the native `flex_direction`
	 * control (it stays visible and its CSS still drives the row patterns). It still
	 * re-defaults `css_classes` to `edd-checkout`.
	 *
	 * @since 3.7.0
	 */
	public function test_register_controls_registers_panel_layout_picker() {
		$manager    = new FakeElementsManager();
		$subscriber = new CheckoutBoxSubscriber();
		$subscriber->register_element( $manager );

		$element = $manager->registered[0];

		// Initialize the real controls stack — this runs the element's
		// register_controls() through the native Container's control pipeline —
		// then read the registered controls straight off the stack.
		$element->get_controls();

		$this->assertNull(
			$element->get_controls( 'edd_layout' ),
			'The retired edd_layout control must not be registered.'
		);

		$picker = $element->get_controls( 'edd_layout_picker' );
		$this->assertIsArray(
			$picker,
			'The panel-hosted layout picker control must be registered.'
		);
		$this->assertSame(
			'edd-layout-picker',
			$picker['type'],
			'The picker control must use the edd-layout-picker type.'
		);
		$this->assertSame(
			'edd_checkout_layout',
			$picker['section'],
			'The picker must live inside the dedicated "Checkout Layout" section, not injected into a native control.'
		);

		// The picker lives in its own dedicated section (a real SECTION control),
		// separate from the native Container "Layout" section. Elementor strips the
		// editor-presentation args (label, tab) from the persisted control the stack
		// returns here, so the retrievable real contract is the section's existence
		// and type plus the controls' `section` membership asserted above/below.
		$section = $element->get_controls( 'edd_checkout_layout' );
		$this->assertIsArray(
			$section,
			'The picker must live in its own dedicated "Checkout Layout" controls section.'
		);
		$this->assertSame(
			'section',
			$section['type'],
			'edd_checkout_layout must be registered as its own controls section.'
		);

		$this->assertNotNull(
			$element->get_controls( 'flex_direction' ),
			'The native flex_direction control must stay registered (it stays visible and its CSS drives the row patterns).'
		);

		$css_classes = $element->get_controls( 'css_classes' );
		$this->assertIsArray(
			$css_classes,
			'The box must still re-default css_classes to edd-checkout.'
		);
		$this->assertSame(
			'edd-checkout',
			$css_classes['default'],
			'css_classes must default to edd-checkout.'
		);

		// The two non-required sections are toggled by NATIVE Elementor `switcher`
		// controls (the slider), copying the monolith checkout widget's show/hide shape.
		// (Elementor strips the label_on/label_off editor-presentation args from the
		// persisted control here, so the retrievable real contract is the control
		// type, its dedicated-section membership, and its seed default.)
		$cart     = $element->get_controls( 'edd_section_cart' );
		$discount = $element->get_controls( 'edd_section_discount_form' );

		$this->assertIsArray(
			$cart,
			'The Cart section must be toggled by a native switcher control.'
		);
		$this->assertIsArray(
			$discount,
			'The Discount Form section must be toggled by a native switcher control.'
		);
		$this->assertSame(
			'switcher',
			$cart['type'],
			'The Cart toggle must be a native switcher, not a custom control.'
		);
		$this->assertSame(
			'switcher',
			$discount['type'],
			'The Discount Form toggle must be a native switcher, not a custom control.'
		);
		$this->assertSame(
			'edd_checkout_layout',
			$cart['section'],
			'The Cart switcher must live in the dedicated Checkout Layout section.'
		);
		$this->assertSame(
			'yes',
			$cart['default'],
			'Cart is seeded into every fresh box, so its switcher defaults ON.'
		);
		$this->assertSame(
			'',
			$discount['default'],
			"The discount form's switcher keeps its '' default — presence-driven; the editor JS syncs it from the box's contents on panel open."
		);
	}

	/**
	 * The elType/widgetType names baked into the e2e fixtures must not drift from
	 * the get_name() the registered element/widget classes actually report.
	 *
	 * Reads every JSON fixture in e2e/fixtures/elementor, recursively collects each
	 * element's elType and every widget's widgetType, and asserts each collected
	 * name matches the class it identifies. The checkout box's get_name() is read
	 * live off the registered element (against the real native Container). The
	 * child section widgets extend the real Elementor Widget_Base, but constructing
	 * them needs the full widget/controls fixture (out of scope here), so their
	 * get_name() literal is instead read straight out of the widget's own source file.
	 */
	public function test_fixture_widget_names_match_get_name() {
		$manager    = new FakeElementsManager();
		$subscriber = new CheckoutBoxSubscriber();
		$subscriber->register_element( $manager );

		$widget_source_files = array(
			'edd-checkout-cart'          => dirname( __DIR__, 2 ) . '/src/Elementor/Widgets/CheckoutInner/Cart.php',
			'edd-checkout-personal-info' => dirname( __DIR__, 2 ) . '/src/Elementor/Widgets/CheckoutInner/PersonalInfo.php',
			'edd-checkout-payment-info'  => dirname( __DIR__, 2 ) . '/src/Elementor/Widgets/CheckoutInner/PaymentInfo.php',
			'edd-checkout-discount-form' => dirname( __DIR__, 2 ) . '/src/Elementor/Widgets/CheckoutInner/DiscountForm.php',
		);

		$expected = array( 'edd-checkout-box' => $manager->registered[0]->get_name() );

		foreach ( $widget_source_files as $name => $file ) {
			$expected[ $name ] = $this->read_get_name_literal( $file );
		}

		$fixtures_dir = dirname( __DIR__, 2 ) . '/e2e/fixtures/elementor';
		$fixtures     = glob( $fixtures_dir . '/*.json' );

		$this->assertNotEmpty( $fixtures, 'No fixture files found in ' . $fixtures_dir );

		$names = array();

		foreach ( $fixtures as $fixture ) {
			$data = json_decode( file_get_contents( $fixture ), true );

			$this->assertIsArray( $data, 'Fixture did not decode to an array: ' . $fixture );

			$this->collect_fixture_names( $data, $names );
		}

		foreach ( $names as $name ) {
			$this->assertArrayHasKey( $name, $expected, 'Fixture name has no known class mapping: ' . $name );
			$this->assertSame(
				$expected[ $name ],
				$name,
				sprintf( 'Fixture name "%s" does not match get_name() for the class it identifies.', $name )
			);
		}
	}

	/**
	 * Report the Container experiment active or inactive to Page::is_container_active().
	 *
	 * Active: the real singleton booted in setUp already forces the Container
	 * experiment on. Inactive: install a throwaway, uninitialized real Plugin
	 * whose only wired member is an experiments manager returning false;
	 * register_element() short-circuits before it constructs the element, so the
	 * uninitialized instance is sufficient (it never reaches kits_manager).
	 *
	 * @param bool $active Whether the Container experiment reports active.
	 * @return void
	 */
	private function set_container_experiment_active( bool $active ): void {
		if ( $active ) {
			$this->edd_boot_real_elementor();

			return;
		}

		$plugin              = ( new \ReflectionClass( \Elementor\Plugin::class ) )->newInstanceWithoutConstructor();
		$plugin->experiments = new class() {
			/**
			 * Report the Container experiment inactive.
			 *
			 * @param string $feature The feature slug.
			 * @return bool
			 */
			public function is_feature_active( $feature ) {
				return false;
			}
		};

		\Elementor\Plugin::$instance = $plugin;
	}

	/**
	 * Read the string literal returned by a widget's get_name() straight out of
	 * its source file.
	 *
	 * The widget extends Elementor's Widget_Base; constructing it needs the full
	 * widget/controls fixture (out of scope for this suite). Parsing the literal
	 * off the source is the drift check available without instantiating the widget.
	 *
	 * @param string $file Absolute path to the widget's source file.
	 * @return string The widget name literal.
	 */
	private function read_get_name_literal( string $file ): string {
		$source = file_get_contents( $file );

		$this->assertNotFalse( $source, 'Could not read widget source file: ' . $file );

		$found = preg_match(
			'/function\s+get_name\s*\([^)]*\)[^{]*\{\s*return\s+([\'"])([^\'"]+)\1\s*;/',
			$source,
			$matches
		);

		$this->assertSame( 1, $found, 'Could not locate a literal get_name() return in: ' . $file );

		return $matches[2];
	}

	/**
	 * Recursively collect every element's elType and widgetType from fixture data.
	 *
	 * @param array $elements Elementor element tree (or a subset of it).
	 * @param array $names    Collected names, appended to by reference.
	 * @return void
	 */
	private function collect_fixture_names( array $elements, array &$names ) {
		foreach ( $elements as $element ) {
			if ( isset( $element['elType'] ) && 'edd-checkout-box' === $element['elType'] ) {
				$names[] = $element['elType'];
			}

			if ( isset( $element['widgetType'] ) ) {
				$names[] = $element['widgetType'];
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect_fixture_names( $element['elements'], $names );
			}
		}
	}
}
