<?php
/**
 * Tests for the pass over every setting a save posted.
 *
 * @package     EDD\Tests\Settings\Sanitize
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Settings\Sanitize;

use EDD\Admin\Settings\Sanitize\Tabs\Misc\ButtonText;
use EDD\Settings\Sanitize\Posted;
use EDD\Settings\Sanitize\Registry;
use EDD\Tests\Helpers\RegisteredSettings;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\Colors;

/**
 * Tests for the pass over every setting a save posted.
 *
 * @since 3.7.1
 */
class PostedSettings extends EDD_UnitTestCase {

	/**
	 * The section class that owns the empty cart preview message.
	 *
	 * @var string
	 */
	const CART_SECTION = 'EDD\\Admin\\Settings\\Sanitize\\Tabs\\Gateways\\Cart';

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

		if ( ! function_exists( 'add_settings_error' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
	}

	public function tear_down(): void {
		global $edd_options, $wp_settings_errors;

		$wp_settings_errors = array();
		$edd_options        = $this->original_options;

		remove_filter( 'edd_non_setting_types', array( $this, 'add_own_field_type' ) );

		RegisteredSettings::reset_index();

		parent::tear_down();
	}

	/**
	 * A section sanitizer is the authority for the key it names, so its result is
	 * what the pass stores.
	 */
	public function test_a_section_sanitizer_that_names_the_key_refines_the_value() {
		$index = Registry::get_index();

		$this->assertSame( self::CART_SECTION, $index['sanitizers']['empty_cart_preview'], 'Fixture: the section must name the key, or nothing refines the value.' );
		$this->assertTrue( shortcode_exists( 'download_cart' ), 'Fixture: the shortcode must be registered, or strip_shortcodes() has nothing to do.' );

		$value = '<strong>Nothing here</strong> [download_cart]';

		$output = Posted::sanitize( array( 'empty_cart_preview' => $value ) );

		$this->assertSame( wp_kses_post( strip_shortcodes( $value ) ), $output['empty_cart_preview'] );
	}

	/**
	 * The section the save came from has already sanitized its own keys, so the
	 * pass must not run it a second time.
	 */
	public function test_the_section_the_save_came_from_is_not_run_again() {
		$this->assertTrue( shortcode_exists( 'download_cart' ), 'Fixture: the shortcode must be registered, or strip_shortcodes() has nothing to do.' );

		$value = '<strong>Nothing here</strong> [download_cart]';

		$output = Posted::sanitize( array( 'empty_cart_preview' => $value ), self::CART_SECTION );

		$this->assertStringContainsString( '[download_cart]', $output['empty_cart_preview'] );
	}

	/**
	 * The button colors field posts under the key it is registered with rather than
	 * under its id, and an import is a save that no section has already been through.
	 */
	public function test_a_section_sanitizer_is_reached_under_the_key_a_field_is_registered_with() {
		global $edd_options;

		RegisteredSettings::use_as_index();

		$index = Registry::get_index();

		$this->assertSame( ButtonText::class, $index['sanitizers']['button_colors'], 'Fixture: the section must be indexed under the key the field posts under, or nothing refines the value.' );
		$this->assertSame( '', Registry::get_type( 'button_colors' ), 'Fixture: the key must carry no type of its own, or the value is stored before the section sees it.' );
		$this->assertSame( 'Default Button Colors', Registry::get_name( 'button_colors' ), 'Fixture: the label must be reachable under the key, or the notice names the key instead.' );

		$saved = array(
			'background' => '#1e73be',
			'text'       => '#ffffff',
		);

		$edd_options['button_colors'] = $saved;

		$this->assertSame( $saved, Colors::get_stored_button_colors(), 'Fixture: both colors must be saved, or there is nothing to keep.' );

		$output = Posted::sanitize(
			array(
				'button_colors' => array(
					'background' => 'red;}body{display:none}',
					'text'       => '#ffffff',
				),
			)
		);

		$this->assertSame( $saved, $output['button_colors'] );
		$this->assertSame( array( 'edd-setting-kept-button_colors' ), wp_list_pluck( get_settings_errors( 'edd-notices' ), 'code' ) );
	}

	/**
	 * A value identical to what is stored was sanitized when it was stored, so the
	 * pass leaves it alone.
	 */
	public function test_a_value_identical_to_what_is_stored_is_left_alone() {
		global $edd_options;

		$stored = '<strong>Nothing here</strong> [download_cart]';

		$edd_options['empty_cart_preview'] = $stored;

		$output = Posted::sanitize( array( 'empty_cart_preview' => $stored ) );

		$this->assertSame( $stored, $output['empty_cart_preview'] );
	}

	/**
	 * A value the setting cannot store leaves the stored one in place and says so
	 * on the screen.
	 */
	public function test_a_value_of_another_shape_keeps_what_is_stored_and_says_so() {
		global $edd_options;

		$this->assertSame( 'scalar', Registry::get_shape( 'currency' ), 'Fixture: the setting must store a single value, or the list is accepted.' );

		$edd_options['currency'] = 'GBP';

		$output = Posted::sanitize( array( 'currency' => array( 'x' ) ) );

		$this->assertSame( 'GBP', $output['currency'] );
		$this->assertSame( array( 'edd-setting-kept-currency' ), wp_list_pluck( get_settings_errors( 'edd-notices' ), 'code' ) );
	}

	/**
	 * A stored value of the wrong shape is no more usable than the submitted one,
	 * so the refusal falls back to the empty value of the shape.
	 */
	public function test_a_stored_value_of_the_wrong_shape_is_not_what_the_setting_keeps() {
		global $edd_options;

		$this->assertSame( 'scalar', Registry::get_shape( 'currency' ), 'Fixture: the setting must store a single value.' );

		$edd_options['currency'] = array( 'GBP' );

		$output = Posted::sanitize( array( 'currency' => array( 'x' ) ) );

		$this->assertSame( '', $output['currency'] );
		$this->assertSame( array( 'edd-setting-kept-currency' ), wp_list_pluck( get_settings_errors( 'edd-notices' ), 'code' ) );
	}

	/**
	 * A list posted with nothing checked is what the form sends to say so, not a
	 * value the store owner needs telling about.
	 */
	public function test_a_list_posted_with_nothing_checked_is_emptied_without_a_notice() {
		global $edd_options;

		$this->assertSame( 'array', Registry::get_shape( 'gateways' ), 'Fixture: the setting must store a list, or the marker is not read as one.' );
		$this->assertTrue( function_exists( 'add_settings_error' ), 'Fixture: the admin include must be loaded, or an empty notice list proves nothing.' );

		$edd_options['gateways'] = array( 'manual' => 1 );

		$output = Posted::sanitize( array( 'gateways' => '-1' ) );

		$this->assertSame( array(), $output['gateways'] );
		$this->assertSame( array(), get_settings_errors( 'edd-notices' ) );
	}

	/**
	 * Altering a credential silently breaks the integration it belongs to.
	 */
	public function test_a_credential_is_stored_character_for_character() {
		$this->assertSame( 'password', Registry::get_type( 'recaptcha_secret_key' ), 'Fixture: the setting must be declared as a credential.' );

		$secret = 'sk_live_<>&"abc123';

		$output = Posted::sanitize( array( 'recaptcha_secret_key' => $secret ) );

		$this->assertSame( $secret, $output['recaptcha_secret_key'] );
	}

	/**
	 * Nothing registers the order a sortable setting posts, so the index is what
	 * names its type and gets it reduced to keys.
	 */
	public function test_the_order_a_sortable_setting_posts_is_reduced_to_keys() {
		$this->assertSame( 'sort_order', Registry::get_type( 'gateways_order' ), 'Fixture: the index must name the type, or the value reaches the fallback.' );

		$posted   = 'manual, "x" ,paypal_commerce';
		$expected = implode( ',', array_filter( array_map( 'edd_sanitize_key', explode( ',', $posted ) ) ) );

		$output = Posted::sanitize( array( 'gateways_order' => $posted ) );

		$this->assertSame( $expected, $output['gateways_order'] );
	}

	/**
	 * A declared type that names the abstract base class has no sanitizer of its
	 * own to reach, so the value goes through the fallback instead.
	 */
	public function test_a_declared_type_that_names_the_base_class_reaches_the_fallback() {
		RegisteredSettings::use_as_index();

		$this->assertSame( 'type', Registry::get_type( 'edd_base_type' ), 'Fixture: the declared type must name the base class.' );
		$this->assertTrue( class_exists( 'EDD\\Settings\\Sanitize\\Types\\Type' ), 'Fixture: the base class must exist, or the dispatch never reaches it.' );

		$output = Posted::sanitize( array( 'edd_base_type' => 'Store <b>name</b>' ) );

		$this->assertStringContainsString( 'name', $output['edd_base_type'] );
		$this->assertStringNotContainsString( '<', $output['edd_base_type'] );
	}

	/**
	 * A header renders no input, so a value posted under one is not the store
	 * owner's and is not stored.
	 */
	public function test_a_value_posted_under_a_header_is_not_stored() {
		global $edd_options;

		$this->assertSame( 'header', Registry::get_type( 'business_settings' ), 'Fixture: the setting must be declared as a header.' );
		$this->assertContains( 'header', edd_get_non_setting_types(), 'Fixture: the type must be one the settings API renders no input for.' );
		$this->assertArrayNotHasKey( 'business_settings', $edd_options, 'Fixture: nothing may be stored under the key, or the value is dropped for being identical.' );

		$output = Posted::sanitize( array( 'business_settings' => '<script>alert(1)</script>' ) );

		$this->assertArrayNotHasKey( 'business_settings', $output );
	}

	/**
	 * A descriptive text renders no input either, so the store keeps what it holds
	 * rather than what was posted.
	 */
	public function test_a_value_posted_under_a_descriptive_text_leaves_the_stored_value_in_place() {
		global $edd_options;

		$this->assertSame( 'descriptive_text', Registry::get_type( 'bounce_webhook_url' ), 'Fixture: the setting must be declared as a descriptive text.' );

		$edd_options['bounce_webhook_url'] = 'https://example.org/edd-bounce';

		$output = Posted::sanitize( array( 'bounce_webhook_url' => 'https://example.com/taken-over' ) );

		$this->assertSame( 'https://example.org/edd-bounce', $output['bounce_webhook_url'] );
	}

	/**
	 * A callback-rendered field owns its own value, so it is the one non-setting
	 * type a save still stores.
	 */
	public function test_a_value_posted_under_a_hook_is_stored_as_posted() {
		global $edd_options;

		$index = Registry::get_index();

		$this->assertSame( 'hook', Registry::get_type( 'email_settings' ), 'Fixture: the setting must be declared as a hook.' );
		$this->assertArrayNotHasKey( 'email_settings', $index['sanitizers'], 'Fixture: no section may name the key, or the section is what decides the value.' );
		$this->assertArrayNotHasKey( 'email_settings', $edd_options, 'Fixture: nothing may be stored under the key, or the value is kept for being identical.' );

		$value = array( 'from_name' => 'Store <b>name</b>' );

		$output = Posted::sanitize( array( 'email_settings' => $value ) );

		$this->assertSame( $value, $output['email_settings'] );
	}

	/**
	 * The list of types the settings API renders no input for is filterable, so a
	 * type filtered into it may well render one and own what it posts.
	 */
	public function test_a_type_filtered_into_the_non_setting_types_keeps_its_posted_value() {
		global $edd_options;

		RegisteredSettings::use_as_index();
		add_filter( 'edd_non_setting_types', array( $this, 'add_own_field_type' ) );

		$this->assertSame( 'level_picker', Registry::get_type( 'edd_own_field' ), 'Fixture: the setting must be declared with the type the filter adds.' );
		$this->assertContains( 'level_picker', edd_get_non_setting_types(), 'Fixture: the filter must have added the type, or the branch is never reached.' );
		$this->assertArrayNotHasKey( 'edd_own_field', $edd_options, 'Fixture: nothing may be stored under the key, or the value is kept for being identical.' );

		$value = 'Store <b>name</b>';

		$output = Posted::sanitize( array( 'edd_own_field' => $value ) );

		$this->assertSame( $value, $output['edd_own_field'] );
	}

	/**
	 * The button color class is printed as a CSS class, so only a key the dropdown offers is stored.
	 */
	public function test_a_color_select_setting_is_held_to_the_colors_the_dropdown_offers() {
		$this->assertSame( 'color_select', Registry::get_type( 'checkout_color' ), 'Fixture: the setting must be declared as a color select.' );

		$output = Posted::sanitize( array( 'checkout_color' => 'blue" onmouseover="alert(1)' ) );

		$this->assertSame( '', $output['checkout_color'] );
		$this->assertSame( 'green', Posted::sanitize( array( 'checkout_color' => 'green' ) )['checkout_color'] );
	}

	/**
	 * A color setting is printed into a stylesheet, so only a hex color is stored.
	 */
	public function test_a_color_setting_is_held_to_a_hex_color() {
		RegisteredSettings::use_as_index();

		$this->assertSame( 'color', Registry::get_type( 'edd_color' ), 'Fixture: the setting must be declared as a color.' );

		$output = Posted::sanitize( array( 'edd_color' => 'red;}body{display:none}' ) );

		$this->assertSame( '', $output['edd_color'] );
		$this->assertSame( '#1e73be', Posted::sanitize( array( 'edd_color' => '1e73be' ) )['edd_color'] );
	}

	/**
	 * A key that is an integer keeps the key it was posted with, and the value is
	 * not left behind unsanitized under a second one.
	 */
	public function test_an_integer_key_keeps_its_key_and_is_not_duplicated() {
		global $edd_options;

		$this->assertArrayNotHasKey( 0, $edd_options, 'Fixture: nothing may be stored under the key a renumbered post would land on.' );

		$output = Posted::sanitize( array( 5 => 'Store <b>name</b>' ) );

		$this->assertArrayHasKey( 5, $output );
		$this->assertStringContainsString( 'name', $output[5], 'The posted value must reach the key it was posted with.' );
		$this->assertStringNotContainsString( '<', $output[5] );
		$this->assertArrayNotHasKey( 0, $output, 'The value must not be duplicated under a renumbered key.' );
	}

	/**
	 * Adds the fixture's own-field type to the types the settings API renders no input for.
	 *
	 * @param string[] $types The registered non-setting types.
	 * @return string[]
	 */
	public function add_own_field_type( $types ) {
		$types[] = 'level_picker';

		return $types;
	}
}
