<?php
/**
 * Tests for the Purchase Buttons section sanitizer.
 *
 * @package     EDD\Tests\Settings\Sanitize\Tabs\Misc
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Settings\Sanitize\Tabs\Misc;

use EDD\Admin\Settings\Sanitize\Tabs\Misc\ButtonText;
use EDD\Settings\Sanitize\Registry;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\Colors;

/**
 * Tests for the Purchase Buttons section sanitizer.
 *
 * @since 3.7.1
 */
class ButtonTextSection extends EDD_UnitTestCase {

	/**
	 * The colors seeded before each test.
	 *
	 * @var array
	 */
	const SAVED = array(
		'background' => '#1e73be',
		'text'       => '#ffffff',
	);

	/**
	 * Settings global as it stood before the test ran.
	 *
	 * @var array
	 */
	private $original_options;

	public function set_up(): void {
		parent::set_up();

		global $edd_options, $wp_settings_errors;

		// Settings errors accumulate for the whole request, so each test starts with none.
		$wp_settings_errors = array();

		$this->original_options = $edd_options;

		$edd_options['button_colors'] = self::SAVED;

		if ( ! function_exists( 'add_settings_error' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
	}

	public function tear_down(): void {
		global $edd_options, $wp_settings_errors;

		$wp_settings_errors = array();
		$edd_options        = $this->original_options;

		parent::tear_down();
	}

	/**
	 * A save resolves its section class from the tab and section it was posted from,
	 * so this class is only reached while those two names hold.
	 */
	public function test_the_section_a_purchase_buttons_save_posts_from_names_this_class() {
		$this->assertArrayHasKey( 'button_text', edd_get_settings_tab_sections( 'misc' ), 'Fixture: the section must be registered in the tab, or no save posts from it.' );

		$this->assertSame( ButtonText::class, Registry::get_section_class( 'misc', 'button_text' ) );
	}

	/**
	 * A color that is not a hex color is no more usable than a value of the wrong
	 * shape, so the saved one is kept and the owner is told.
	 */
	public function test_a_background_that_is_not_a_hex_color_keeps_the_saved_color_and_says_so() {
		$this->assertSame( self::SAVED, Colors::get_stored_button_colors(), 'Fixture: both colors must be saved, or there is nothing to keep.' );

		$output = ButtonText::sanitize(
			array(
				'button_colors' => array(
					'background' => 'red;}body{display:none}',
					'text'       => '#ffffff',
				),
			)
		);

		$this->assertSame( self::SAVED, $output['button_colors'] );
		$this->assertSame( array( 'edd-setting-kept-button_colors' ), wp_list_pluck( get_settings_errors( 'edd-notices' ), 'code' ) );
	}

	/**
	 * A value the store cannot read back as a pair of colors leaves both saved
	 * colors in place.
	 */
	public function test_a_value_that_is_not_a_pair_of_colors_keeps_both_saved_colors_and_says_so() {
		$this->assertSame( self::SAVED, Colors::get_stored_button_colors(), 'Fixture: both colors must be saved, or there is nothing to keep.' );

		$output = ButtonText::sanitize( array( 'button_colors' => '#1e73be' ) );

		$this->assertSame( self::SAVED, $output['button_colors'] );
		$this->assertSame( array( 'edd-setting-kept-button_colors' ), wp_list_pluck( get_settings_errors( 'edd-notices' ), 'code' ) );
	}

	/**
	 * The color picker posts a hex color without the hash, which is the same color.
	 */
	public function test_a_hex_color_posted_without_the_hash_is_stored_hashed() {
		// Neither color may match the seeded one, or keeping the saved color reads as a pass.
		$this->assertSame( self::SAVED, Colors::get_stored_button_colors(), 'Fixture: both colors must be saved, and neither may be what this save posts.' );
		$this->assertTrue( function_exists( 'add_settings_error' ), 'Fixture: the admin include must be loaded, or an empty notice list proves nothing.' );

		$output = ButtonText::sanitize(
			array(
				'button_colors' => array(
					'background' => '8899aa',
					'text'       => 'abc',
				),
			)
		);

		$this->assertSame(
			array(
				'background' => '#8899aa',
				'text'       => '#abc',
			),
			$output['button_colors']
		);
		$this->assertSame( array(), get_settings_errors( 'edd-notices' ) );
	}

	public function test_a_hex_color_is_stored_as_posted() {
		$this->assertTrue( function_exists( 'add_settings_error' ), 'Fixture: the admin include must be loaded, or an empty notice list proves nothing.' );

		$output = ButtonText::sanitize(
			array(
				'button_colors' => array(
					'background' => '#8899aa',
					'text'       => '#000000',
				),
			)
		);

		$this->assertSame(
			array(
				'background' => '#8899aa',
				'text'       => '#000000',
			),
			$output['button_colors']
		);
		$this->assertSame( array(), get_settings_errors( 'edd-notices' ) );
	}

	/**
	 * An empty field is the owner clearing the color, not a value to refuse.
	 */
	public function test_a_color_the_owner_cleared_is_stored_as_unset_without_a_notice() {
		$this->assertSame( self::SAVED, Colors::get_stored_button_colors(), 'Fixture: both colors must be saved, or an empty result proves nothing.' );
		$this->assertTrue( function_exists( 'add_settings_error' ), 'Fixture: the admin include must be loaded, or an empty notice list proves nothing.' );

		$output = ButtonText::sanitize(
			array(
				'button_colors' => array(
					'background' => '',
					'text'       => '#ffffff',
				),
			)
		);

		$this->assertSame(
			array(
				'background' => '',
				'text'       => '#ffffff',
			),
			$output['button_colors']
		);
		$this->assertSame( array(), get_settings_errors( 'edd-notices' ) );
	}

	/**
	 * A color the save did not post is a field the form did not carry, which the
	 * store reads the same way as a cleared one.
	 */
	public function test_a_color_the_save_did_not_post_is_stored_as_unset() {
		$output = ButtonText::sanitize(
			array(
				'button_colors' => array(
					'background' => '#1e73be',
				),
			)
		);

		$this->assertSame(
			array(
				'background' => '#1e73be',
				'text'       => '',
			),
			$output['button_colors']
		);
	}
}
