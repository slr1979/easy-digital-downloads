<?php
/**
 * Tests for the shape each registered setting is indexed with.
 *
 * @package     EDD\Tests\Settings\Sanitize
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Settings\Sanitize;

use EDD\Settings\Sanitize\Registry;
use EDD\Tests\Helpers\RegisteredSettings;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for the index the settings sanitizer reads.
 *
 * @since 3.7.1
 */
class RegistryIndex extends EDD_UnitTestCase {

	/**
	 * A set of registered settings covering each way a shape is decided.
	 *
	 * @var array
	 */
	private $registered;

	public function set_up(): void {
		parent::set_up();

		$this->registered = RegisteredSettings::get();
	}

	public function tear_down(): void {
		$this->registered = null;

		parent::tear_down();
	}

	/**
	 * A field the settings API renders with `multiple` posts one entry per
	 * selection, whatever its type says.
	 */
	public function test_a_setting_marked_multiple_stores_a_list() {
		$this->assertContains( 'select', Registry::SCALAR_TYPES, 'Fixture: the type must otherwise store a single value.' );

		$index = Registry::build( $this->registered );

		$this->assertSame( 'array', $index['shapes']['edd_multi_select'] );
	}

	public function test_a_single_input_setting_stores_a_single_value() {
		$index = Registry::build( $this->registered );

		$this->assertSame( 'scalar', $index['shapes']['edd_plain_select'] );
	}

	/**
	 * A type core does not render is drawn by someone else's callback, which is
	 * the only thing that knows what it posts, so both shapes are accepted.
	 */
	public function test_a_setting_core_does_not_render_accepts_either_shape() {
		$this->assertNotContains( 'level_picker', Registry::SCALAR_TYPES, 'Fixture: the type must sit outside the single-input types.' );
		$this->assertNotContains( 'level_picker', Registry::ARRAY_TYPES, 'Fixture: the type must sit outside the list types.' );
		$this->assertNotContains( 'level_picker', Registry::EITHER_SHAPE_TYPES, 'Fixture: the type must sit outside the either-shape types.' );

		$index = Registry::build( $this->registered );

		$this->assertSame( 'either', $index['shapes']['edd_own_field'] );
	}

	public function test_a_list_type_stores_a_list() {
		$this->assertContains( 'multicheck', Registry::ARRAY_TYPES, 'Fixture: the type must be listed as a list type.' );

		$index = Registry::build( $this->registered );

		$this->assertSame( 'array', $index['shapes']['edd_multicheck'] );
	}

	public function test_a_textarea_accepts_either_shape() {
		$this->assertContains( 'textarea', Registry::EITHER_SHAPE_TYPES, 'Fixture: the type must be listed as either-shape.' );

		$index = Registry::build( $this->registered );

		$this->assertSame( 'either', $index['shapes']['edd_textarea'] );
	}

	/**
	 * A sortable setting posts its order alongside itself as one comma separated
	 * string, both for the two keys the index seeds and for the key it adds.
	 */
	public function test_a_sort_order_stores_a_single_value() {
		$index = Registry::build( $this->registered );

		$this->assertContains( 'edd_multicheck_order', $index['sort_orders'] );
		$this->assertSame( 'scalar', $index['shapes']['edd_multicheck_order'] );
		$this->assertSame( 'scalar', $index['shapes']['gateways_order'] );
		$this->assertSame( 'scalar', $index['shapes']['payment_icons_order'] );
	}

	/**
	 * The label is what a refusal notice names, so the index carries it alongside
	 * the type, and a setting registered without one is left out.
	 */
	public function test_a_setting_is_indexed_by_the_label_it_renders_with() {
		$index = Registry::build( $this->registered );

		$this->assertSame( 'Multi Select', $index['names']['edd_multi_select'] );
		$this->assertArrayNotHasKey( 'edd_plain_select', $index['names'] );
	}

	/**
	 * A setting registered against the tab rather than a section is indexed the
	 * same way as one inside a section.
	 */
	public function test_a_tab_level_setting_is_indexed() {
		$index = Registry::build( $this->registered );

		$this->assertSame( 'text', $index['types']['edd_tab_level'] );
		$this->assertSame( 'scalar', $index['shapes']['edd_tab_level'] );
	}

	/**
	 * A save posts under the key the field is registered with, so a rule that names a
	 * key is reachable under that key as well as under the setting id.
	 */
	public function test_a_setting_registered_under_another_key_is_named_under_both() {
		$index = Registry::build( $this->registered );

		$this->assertSame( 'Renamed Field', $index['names']['edd_renamed_field'] );
		$this->assertSame( 'Renamed Field', $index['names']['renamed_key'] );
	}

	/**
	 * The type decides which branch of the pass a value takes, and a type read under the
	 * registration key would decide it before the section sanitizer refines the value.
	 */
	public function test_a_setting_registered_under_another_key_is_not_typed_under_it() {
		$index = Registry::build( $this->registered );

		$this->assertSame( 'text', $index['types']['edd_renamed_field'] );
		$this->assertArrayNotHasKey( 'renamed_key', $index['types'] );
		$this->assertArrayNotHasKey( 'renamed_key', $index['shapes'] );
	}

	/**
	 * The setting says whether it carries markup, so a textarea registered without the
	 * argument is plain text.
	 */
	public function test_a_setting_is_indexed_by_whether_it_carries_markup() {
		$index = Registry::build( $this->registered );

		$this->assertArrayHasKey( 'edd_html_textarea', $index['allow_html'] );
		$this->assertArrayNotHasKey( 'edd_textarea', $index['allow_html'] );
	}
}
