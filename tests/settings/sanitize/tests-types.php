<?php
/**
 * Tests for the entry point every setting type sanitizer shares.
 *
 * @package     EDD\Tests\Settings\Sanitize
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Settings\Sanitize;

use EDD\Settings\Sanitize\Registry;
use EDD\Settings\Sanitize\Types\Color;
use EDD\Settings\Sanitize\Types\ColorSelect;
use EDD\Settings\Sanitize\Types\SortOrder;
use EDD\Settings\Sanitize\Types\Textarea;
use EDD\Settings\Sanitize\Types\Type;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for the entry point every setting type sanitizer shares.
 *
 * @since 3.7.1
 */
class Types extends EDD_UnitTestCase {

	/**
	 * A type class only has to say what it does with one value: the shared entry
	 * point is what reaches every entry of a list, leaving the array keys as they are.
	 */
	public function test_a_type_that_sanitizes_one_value_sanitizes_each_entry_of_a_list() {
		$type = new class() extends Type {
			/**
			 * Sanitize one value.
			 *
			 * @param mixed $value The value to sanitize.
			 * @return string
			 */
			protected static function _sanitize( $value ) {
				return strtoupper( (string) $value );
			}
		};

		$expected = array(
			'x' => 'A',
			7   => 'B',
			'y' => array( 3 => 'C' ),
		);
		$posted   = array(
			'x' => 'a',
			7   => 'b',
			'y' => array( 3 => 'c' ),
		);

		$this->assertSame( $expected, $type::sanitize( $posted ) );
	}

	/**
	 * A store owner types an address or a list of emails into a plain text setting, and
	 * wp_kses_post() would rewrite the ampersand in it.
	 */
	public function test_a_plain_text_textarea_keeps_an_ampersand() {
		$this->assertFalse( Registry::allows_html( 'banned_emails' ), 'Fixture: the setting must not be registered as carrying markup, or the value reaches wp_kses_post().' );

		$value = "Smith & Sons\na < b";

		$this->assertSame( "Smith & Sons\na &lt; b", Textarea::sanitize( $value, 'banned_emails' ) );
	}

	public function test_a_plain_text_textarea_is_stripped_of_markup() {
		$this->assertFalse( Registry::allows_html( 'banned_emails' ), 'Fixture: the setting must not be registered as carrying markup, or the markup is kept.' );

		$this->assertSame( 'Open shut', Textarea::sanitize( '<strong>Open</strong> shut', 'banned_emails' ) );
	}

	public function test_a_textarea_registered_as_carrying_markup_keeps_it() {
		$this->assertTrue( Registry::allows_html( 'empty_cart_preview' ), 'Fixture: the setting must be registered as carrying markup, or the markup is stripped.' );

		$value = '<strong>Open</strong> & <script>alert(1)</script> shut';

		$this->assertSame( '<strong>Open</strong> &amp; alert(1) shut', Textarea::sanitize( $value, 'empty_cart_preview' ) );
	}

	public function test_a_color_that_is_not_a_hex_color_is_stored_as_unset() {
		$this->assertSame( '', Color::sanitize( 'red;}body{display:none}' ) );
	}

	public function test_a_hex_color_is_stored_hashed() {
		$this->assertSame( '#1e73be', Color::sanitize( '1e73be' ) );
		$this->assertSame( '#1e73be', Color::sanitize( '#1e73be' ) );
	}

	public function test_a_button_color_that_the_dropdown_does_not_offer_is_stored_as_unset() {
		$this->assertArrayHasKey( 'blue', edd_get_button_colors(), 'Fixture: the dropdown must offer blue.' );

		$this->assertSame( '', ColorSelect::sanitize( 'blue" onmouseover="alert(1)' ) );
		$this->assertSame( 'blue', ColorSelect::sanitize( 'blue' ) );
		$this->assertSame( 'inherit', ColorSelect::sanitize( 'inherit' ) );
	}

	/**
	 * The order a sortable setting posts is one comma separated list, so the whole
	 * value reaches the sanitizer rather than each entry of it.
	 */
	public function test_a_sort_order_is_sanitized_as_one_value() {
		$this->assertSame( '', SortOrder::sanitize( array( 'billing_country', 'address' ) ) );
	}
}
