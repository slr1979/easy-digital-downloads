<?php
/**
 * Tests for edd_settings_sanitize().
 *
 * @package EDD\Tests\Settings
 */

namespace EDD\Tests\Settings;

use EDD\Tests\Helpers\RegisteredSettings;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for the settings save path.
 */
class Sanitize extends EDD_UnitTestCase {

	/**
	 * Settings global as it stood before the test ran.
	 *
	 * @var array
	 */
	private $original_options;

	/**
	 * Referrer as it stood before the test ran.
	 *
	 * @var mixed
	 */
	private $original_referrer;

	/**
	 * Option page as it stood before the test ran.
	 *
	 * @var mixed
	 */
	private $original_option_page;

	/**
	 * Value the generic sanitize filter was handed for the key under test.
	 *
	 * @var mixed
	 */
	private $filtered_value;

	public function set_up(): void {
		parent::set_up();

		global $edd_options, $wp_settings_errors;

		// Settings errors accumulate for the whole request, so each test starts with none.
		$wp_settings_errors = array();

		$this->original_options     = $edd_options;
		$this->original_referrer    = isset( $_POST['_wp_http_referer'] ) ? $_POST['_wp_http_referer'] : null;
		$this->original_option_page = isset( $_POST['option_page'] ) ? $_POST['option_page'] : null;

		if ( ! function_exists( 'add_settings_error' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
	}

	public function tear_down(): void {
		global $edd_options, $wp_settings_errors;

		$wp_settings_errors = array();

		remove_filter( 'edd_settings_sanitize', array( $this, 'record_filtered_value' ), 10 );
		remove_filter( 'edd_use_taxes', '__return_true' );

		RegisteredSettings::reset_index();

		$this->filtered_value = null;
		$edd_options          = $this->original_options;

		if ( is_null( $this->original_referrer ) ) {
			unset( $_POST['_wp_http_referer'] );
		} else {
			$_POST['_wp_http_referer'] = $this->original_referrer;
		}

		if ( is_null( $this->original_option_page ) ) {
			unset( $_POST['option_page'] );
		} else {
			$_POST['option_page'] = $this->original_option_page;
		}

		parent::tear_down();
	}

	public function test_key_that_is_neither_registered_nor_stored_is_stored_sanitized() {
		$_POST['option_page'] = 'edd_settings';

		$this->assertArrayNotHasKey( 'edd_undeclared_key', edd_get_registered_settings_types() );

		$unsanitized_html = '<img src=x onerror=alert(1)>';

		$output = edd_settings_sanitize( array( 'edd_undeclared_key' => $unsanitized_html ) );

		$this->assertArrayHasKey( 'edd_undeclared_key', $output );
		$this->assertStringNotContainsString( '<', $output['edd_undeclared_key'] );
	}

	/**
	 * The settings form can post a key that is an integer, and the value stored
	 * under it must be the sanitized one.
	 */
	public function test_an_integer_key_is_stored_sanitized_under_the_key_it_was_posted_with() {
		global $edd_options;

		$this->assertArrayNotHasKey( 0, $edd_options, 'Fixture: nothing may be stored under the key a renumbered post would land on.' );

		$unsanitized_html = 'Store <b>name</b>';

		$output = edd_settings_sanitize( array( 5 => $unsanitized_html ) );

		$this->assertArrayHasKey( 5, $output );
		$this->assertStringContainsString( 'name', $output[5], 'The posted value must reach the key it was posted with.' );
		$this->assertStringNotContainsString( '<', $output[5] );
		$this->assertArrayNotHasKey( 0, $output, 'The value must not be duplicated under a renumbered key.' );
	}

	/**
	 * A key an add-on stored without registering it must survive, or an unrelated
	 * save would silently discard it.
	 */
	public function test_stored_key_that_is_not_registered_is_preserved() {
		global $edd_options;

		$_POST['option_page'] = 'edd_settings';

		$edd_options['edd_stored_key'] = 'keep me';

		$this->assertArrayNotHasKey( 'edd_stored_key', edd_get_registered_settings_types() );

		$output = edd_settings_sanitize( array( 'gateways_order' => 'manual' ) );

		$this->assertSame( 'keep me', $output['edd_stored_key'] );
	}

	/**
	 * The settings import and programmatic writes reach this function without an
	 * option_page, and must keep storing keys the registry does not declare.
	 */
	public function test_undeclared_key_from_an_import_is_stored() {
		$output = edd_settings_sanitize( array( 'edd_imported_key' => 'from an import' ) );

		$this->assertSame( 'from an import', $output['edd_imported_key'] );
	}

	/**
	 * A setting already stored under a different section is still reachable by a
	 * save made from another section's form, and must be sanitized there too.
	 */
	public function test_stored_key_posted_from_another_section_is_sanitized() {
		global $edd_options;

		$this->assertArrayNotHasKey( 'button_colors', edd_get_registered_settings_types() );

		$edd_options['button_colors'] = array( 'background' => '#ffffff' );
		$_POST['_wp_http_referer']    = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=main';

		$unsanitized_html = '<img src=x onerror=alert(1)>';

		$output = edd_settings_sanitize( array( 'button_colors' => array( 'background' => $unsanitized_html ) ) );

		$this->assertStringNotContainsString( '<', $output['button_colors']['background'] );
	}

	/**
	 * A setting registered under a different section from the one being saved is
	 * still sanitized by its declared type.
	 */
	public function test_key_declared_in_another_section_is_sanitized_by_its_type() {
		$this->assertArrayNotHasKey( 'base_state', edd_get_registered_settings_types( 'gateways', 'main' ) );
		$this->assertArrayHasKey( 'base_state', edd_get_registered_settings_types() );

		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=main';

		$unsanitized_html = '<img src=x onerror=alert(1)>';

		$output = edd_settings_sanitize( array( 'base_state' => $unsanitized_html ) );

		$this->assertStringNotContainsString( '<', $output['base_state'] );
	}

	/**
	 * The sortable address field order is posted under its own name rather than
	 * the multicheck setting's id, and a store that has never saved it must still
	 * store it as the comma separated string both consumers explode().
	 */
	public function test_a_setting_the_ui_posts_under_its_own_name_is_stored_on_a_fresh_store() {
		global $edd_options;

		$this->assertArrayNotHasKey( 'checkout_address_fields_order', $edd_options );

		$_POST['option_page']      = 'edd_settings';
		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=checkout';

		$output = edd_settings_sanitize( array( 'checkout_address_fields_order' => 'billing_country,address' ) );

		$this->assertSame( 'billing_country,address', $output['checkout_address_fields_order'] );
	}

	/**
	 * A sort order key stores a comma separated string; posting an array shape for
	 * it must not be stored as an array.
	 */
	public function test_a_sort_order_posted_as_an_array_is_stored_empty() {
		$_POST['option_page']      = 'edd_settings';
		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=checkout';

		$output = edd_settings_sanitize( array( 'checkout_address_fields_order' => array( 'address' ) ) );

		$this->assertSame( '', $output['checkout_address_fields_order'] );
	}

	/**
	 * A sort order value is reduced to the same keys edd_sanitize_key() would
	 * produce for each comma separated entry.
	 */
	public function test_a_sort_order_is_reduced_to_keys() {
		$_POST['option_page']      = 'edd_settings';
		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=checkout';

		$posted   = 'billing_country, "x" ,address';
		$expected = implode( ',', array_filter( array_map( 'edd_sanitize_key', explode( ',', $posted ) ) ) );

		$output = edd_settings_sanitize( array( 'checkout_address_fields_order' => $posted ) );

		$this->assertSame( $expected, $output['checkout_address_fields_order'] );
	}

	/**
	 * A section-specific sanitizer's result is what gets stored; the pass over
	 * posted keys must not sanitize a key that sanitizer already named.
	 */
	public function test_a_section_sanitizer_result_is_kept() {
		$this->assertTrue( shortcode_exists( 'download_cart' ), 'Fixture: the shortcode must be registered, or strip_shortcodes() has nothing to do.' );

		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=cart';

		$value    = '<strong>Nothing here</strong> [download_cart]';
		$expected = wp_kses_post( strip_shortcodes( $value ) );

		$output = edd_settings_sanitize( array( 'empty_cart_preview' => $value ) );

		$this->assertSame( $expected, $output['empty_cart_preview'] );
	}

	/**
	 * A section sanitizer is the authority for its key even when the save that
	 * reaches this key comes from a different section's form.
	 */
	public function test_a_section_sanitizer_runs_for_its_key_from_another_form() {
		$this->assertTrue( shortcode_exists( 'download_cart' ), 'Fixture: the shortcode must be registered, or strip_shortcodes() has nothing to do.' );

		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=general&section=main';

		$value    = '<strong>Nothing here</strong> [download_cart]';
		$expected = wp_kses_post( strip_shortcodes( $value ) );

		$output = edd_settings_sanitize( array( 'empty_cart_preview' => $value ) );

		$this->assertSame( $expected, $output['empty_cart_preview'] );
	}

	/**
	 * A write of the entire options array, as happens with no referer, must not
	 * re-sanitize a value that is already stored and unchanged.
	 */
	public function test_a_stored_value_survives_a_write_of_the_whole_options_array() {
		$this->assertTrue( shortcode_exists( 'download_cart' ), 'Fixture: the shortcode must be registered, or strip_shortcodes() has nothing to do.' );

		global $edd_options;

		$edd_options['empty_cart_preview'] = '<strong>Nothing here</strong> [download_cart]';

		unset( $_POST['_wp_http_referer'], $_POST['option_page'] );

		$output = edd_settings_sanitize( $edd_options );

		$this->assertSame( '<strong>Nothing here</strong> [download_cart]', $output['empty_cart_preview'] );
	}

	/**
	 * An upload setting stores a URL, so a save must keep the percent-encoded
	 * characters a URL can carry.
	 */
	public function test_an_upload_setting_keeps_its_encoded_characters() {
		$this->assertSame( 'upload', edd_get_registered_settings_types()['email_logo'] );

		$url = 'https://example.com/a%20file.png';

		$output = edd_settings_sanitize( array( 'email_logo' => $url ) );

		$this->assertSame( $url, $output['email_logo'] );
	}

	public function test_gateways_order_is_reduced_to_a_slug_list() {
		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=main';

		$unsanitized_html = 'manual" /><img src=x onerror=alert(1)><input x="';

		$output = edd_settings_sanitize( array( 'gateways_order' => $unsanitized_html ) );

		$this->assertStringNotContainsString( '<', $output['gateways_order'] );
		$this->assertStringStartsWith( 'manual', $output['gateways_order'] );
	}

	public function test_payment_icons_order_is_reduced_to_a_slug_list() {
		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=main';

		$unsanitized_html = 'visa" /><svg onload=alert(1)><input y="';

		$output = edd_settings_sanitize( array( 'payment_icons_order' => $unsanitized_html ) );

		$this->assertStringNotContainsString( '<', $output['payment_icons_order'] );
		$this->assertStringStartsWith( 'visa', $output['payment_icons_order'] );
	}

	/**
	 * Every declared type must resolve to a sanitizer, whether or not it has its
	 * own Sanitize\Types class. Exemptions are listed explicitly so a new type
	 * that carries markup or a credential has to be added here deliberately.
	 */
	public function test_declared_type_without_its_own_class_is_still_sanitized() {
		$exempt = array( 'password' );
		$types  = array_diff( edd_get_registered_settings_types(), edd_get_non_setting_types() );

		$unsanitized_html = '<img src=x onerror=alert(1)>';
		$asserted         = 0;

		foreach ( $types as $key => $type ) {
			if ( empty( $type ) || in_array( $type, $exempt, true ) ) {
				continue;
			}

			$type_class = 'EDD\\Settings\\Sanitize\\Types\\' . \EDD\Utils\Convert::snake_to_camel( $type );
			if ( class_exists( $type_class ) ) {
				continue;
			}

			// A list type is posted a list, since a single value for it is refused unsanitized.
			$posted = in_array( $type, \EDD\Settings\Sanitize\Registry::ARRAY_TYPES, true )
				? array( $unsanitized_html )
				: $unsanitized_html;

			$output = edd_settings_sanitize( array( $key => $posted ) );
			$stored = is_array( $output[ $key ] ) ? implode( '', $output[ $key ] ) : $output[ $key ];

			$this->assertStringNotContainsString(
				'<',
				$stored,
				"Setting '{$key}' of type '{$type}' was stored without sanitization."
			);

			++$asserted;
		}

		$this->assertGreaterThan( 0, $asserted, 'No unmapped setting types were found to assert against.' );
	}

	/**
	 * Several settings are documented as one value per line, so the fallback
	 * sanitizer must not collapse newlines.
	 */
	public function test_multiline_setting_keeps_its_newlines() {
		$recipients = "first@example.com\nsecond@example.com";

		$output = edd_settings_sanitize( array( 'admin_notice_emails' => $recipients ) );

		$this->assertSame( $recipients, $output['admin_notice_emails'] );
	}

	/**
	 * Credential values are returned byte for byte: altering one silently breaks
	 * the integration it belongs to.
	 */
	public function test_credential_setting_is_returned_unchanged() {
		$this->assertSame( 'password', edd_get_registered_settings_types()['recaptcha_secret_key'] );

		$secret = 'sk_live_<>&"abc123';

		$output = edd_settings_sanitize( array( 'recaptcha_secret_key' => $secret ) );

		$this->assertSame( $secret, $output['recaptcha_secret_key'] );
	}

	/**
	 * A single-value setting reached from a form that never rendered it must be
	 * refused when the posted value is a list, since the sanitizer that owns the
	 * key reads it as a single value.
	 */
	public function test_a_value_whose_shape_does_not_match_its_type_is_refused() {
		$this->assertSame( 'select', edd_get_registered_settings_types()['currency'], 'Fixture: currency must be declared as a single-value type.' );
		$this->assertTrue(
			method_exists( 'EDD\Admin\Settings\Sanitize\Tabs\General\Currency', 'sanitize_currency' ),
			'Fixture: the Currency section must own the currency key, or nothing reads the posted value.'
		);

		edd_update_option( 'currency', 'GBP' );
		$this->assertSame( 'GBP', edd_get_option( 'currency' ), 'Fixture: a currency must be stored, or there is nothing to keep.' );

		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=general&section=main';

		$output = edd_settings_sanitize( array( 'currency' => array( 'x' ) ) );

		$this->assertSame( 'GBP', $output['currency'] );
	}

	/**
	 * A credential is one value, so a list posted for it keeps what is stored
	 * rather than reaching the field that renders it.
	 */
	public function test_a_credential_posted_as_a_list_keeps_what_is_stored() {
		global $edd_options;

		$this->assertSame( 'password', edd_get_registered_settings_types()['recaptcha_secret_key'], 'Fixture: the setting must be declared as a credential.' );

		$edd_options['recaptcha_secret_key'] = 'sk_live_abc123';

		$output = edd_settings_sanitize( array( 'recaptcha_secret_key' => array( 'x' ) ) );

		$this->assertSame( 'sk_live_abc123', $output['recaptcha_secret_key'] );
	}

	/**
	 * A save that keeps a value says so on the screen, since the field goes on
	 * showing what is stored.
	 */
	public function test_a_kept_value_adds_a_settings_error() {
		$this->assertTrue( function_exists( 'add_settings_error' ), 'Fixture: the admin include must be loaded.' );

		edd_update_option( 'currency', 'GBP' );
		$this->assertSame( 'GBP', edd_get_option( 'currency' ), 'Fixture: a currency must be stored.' );

		edd_settings_sanitize( array( 'currency' => array( 'x' ) ) );

		$this->assertSame( array( 'edd-setting-kept-currency' ), wp_list_pluck( get_settings_errors( 'edd-notices' ), 'code' ) );
	}

	/**
	 * Two settings refused in one save each get their own notice, since a notice is
	 * keyed by its code and a shared code would drop all but the first.
	 */
	public function test_two_refused_settings_each_add_their_own_settings_error() {
		$this->assertSame( 'scalar', \EDD\Settings\Sanitize\Registry::get_shape( 'currency' ), 'Fixture: the setting must store a single value.' );
		$this->assertSame( 'scalar', \EDD\Settings\Sanitize\Registry::get_shape( 'base_state' ), 'Fixture: the setting must store a single value.' );

		edd_settings_sanitize(
			array(
				'currency'   => array( 'x' ),
				'base_state' => array( 'y' ),
			)
		);

		$this->assertSame(
			array( 'edd-setting-kept-currency', 'edd-setting-kept-base_state' ),
			wp_list_pluck( get_settings_errors( 'edd-notices' ), 'code' )
		);
	}

	/**
	 * The notice is read by whoever filled the field in, so it names the field
	 * rather than the id the value is stored under.
	 */
	public function test_a_refused_setting_is_named_on_screen_by_its_label() {
		$this->assertSame( 'Business Region', \EDD\Settings\Sanitize\Registry::get_name( 'base_state' ), 'Fixture: the registry must carry the label.' );

		edd_settings_sanitize( array( 'base_state' => array( 'x' ) ) );

		$errors = get_settings_errors( 'edd-notices' );

		$this->assertStringContainsString( 'Business Region', $errors[0]['message'] );
		$this->assertStringNotContainsString( 'base_state', $errors[0]['message'] );
	}

	/**
	 * A list posted with nothing checked is what the form sends to say so, not a
	 * value the store owner needs telling about.
	 */
	public function test_a_list_posted_with_nothing_checked_adds_no_settings_error() {
		global $edd_options;

		$this->assertTrue( function_exists( 'add_settings_error' ), 'Fixture: the admin include must be loaded, or an empty notice list proves nothing.' );

		$edd_options['gateways'] = array( 'manual' => 1 );

		edd_settings_sanitize( array( 'gateways' => '-1' ) );

		$this->assertSame( array(), get_settings_errors( 'edd-notices' ) );
	}

	/**
	 * A key nothing is stored under has nothing to keep, so the refusal yields the
	 * empty value of the shape it stores.
	 */
	public function test_a_refused_value_is_empty_when_nothing_is_stored() {
		global $edd_options;

		$this->assertSame( 'shop_states', edd_get_registered_settings_types()['base_state'], 'Fixture: base_state must be declared as a single-value type.' );

		unset( $edd_options['base_state'] );
		$this->assertArrayNotHasKey( 'base_state', $edd_options, 'Fixture: nothing may be stored under the key.' );

		$output = edd_settings_sanitize( array( 'base_state' => array( 'x' ) ) );

		$this->assertSame( '', $output['base_state'] );
	}

	/**
	 * A field the settings API renders with `multiple` posts one entry per
	 * selection, so the list is kept and each entry sanitized.
	 */
	public function test_a_multi_select_setting_keeps_the_list_it_posts() {
		$this->assertArrayNotHasKey( 'edd_multi_select', edd_get_registered_settings_types(), 'Fixture: the registry must not carry the key, so the index under test decides.' );

		RegisteredSettings::use_as_index();

		$output = edd_settings_sanitize( array( 'edd_multi_select' => array( 'a', 'b<b>' ) ) );

		$this->assertSame( array( 'a', 'b' ), $output['edd_multi_select'] );
	}

	/**
	 * A setting core does not render is drawn by someone else's callback, so a
	 * list it posts is kept and sanitized.
	 */
	public function test_a_setting_core_does_not_render_keeps_the_list_it_posts() {
		$this->assertArrayNotHasKey( 'edd_own_field', edd_get_registered_settings_types(), 'Fixture: the registry must not carry the key, so the index under test decides.' );

		RegisteredSettings::use_as_index();

		$output = edd_settings_sanitize( array( 'edd_own_field' => array( 'a', 'b<b>' ) ) );

		$this->assertSame( array( 'a', 'b' ), $output['edd_own_field'] );
	}

	/**
	 * A setting the settings API renders with one input stores one value, so a
	 * list posted for it is refused.
	 */
	public function test_a_single_input_setting_is_refused_a_list() {
		$this->assertArrayNotHasKey( 'edd_plain_select', edd_get_registered_settings_types(), 'Fixture: the registry must not carry the key, so the index under test decides.' );

		RegisteredSettings::use_as_index();

		$output = edd_settings_sanitize( array( 'edd_plain_select' => array( 'a' ) ) );

		$this->assertSame( '', $output['edd_plain_select'] );
	}

	/**
	 * A setting stored as a list of keys must be refused when a single value is
	 * posted for it, including from the form that renders it.
	 */
	public function test_a_multicheck_posted_as_a_single_value_is_refused() {
		$this->assertSame( 'multicheck', edd_get_registered_settings_types()['checkout_address_fields'], 'Fixture: the address fields must be declared as a list type.' );

		global $edd_options;

		$this->assertArrayNotHasKey( 'checkout_address_fields', $edd_options, 'Fixture: nothing may be stored under the key, or the stored list is kept instead.' );

		add_filter( 'edd_use_taxes', '__return_true' );
		$this->assertTrue( edd_use_taxes(), 'Fixture: taxes must be on, or the section sanitizer returns the value untouched.' );

		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=checkout';

		$output = edd_settings_sanitize( array( 'checkout_address_fields' => 'address' ) );

		$this->assertSame( array(), $output['checkout_address_fields'] );
	}

	/**
	 * An import and a programmatic write reach this function with no form behind
	 * them, so the shape a type stores is enforced there too.
	 */
	public function test_a_list_type_is_refused_a_single_value_with_no_form_behind_it() {
		$index = \EDD\Settings\Sanitize\Registry::get_index();

		$this->assertSame( 'gateways', $index['types']['gateways'], 'Fixture: the gateways setting must be declared as a list type.' );
		$this->assertArrayNotHasKey( 'gateways', $index['sanitizers'], 'Fixture: no section may name the key, or a section method is what refuses the value.' );
		$this->assertFalse( class_exists( 'EDD\Settings\Sanitize\Types\Gateways' ), 'Fixture: no type class may claim the type, or its own guard is what refuses the value.' );

		unset( $_POST['_wp_http_referer'], $_POST['option_page'] );

		$output = edd_settings_sanitize( array( 'gateways' => 'manual' ) );

		$this->assertSame( array(), $output['gateways'] );
	}

	/**
	 * What a filter returns is what gets stored, so a filter must be handed the
	 * sanitized value rather than the posted one.
	 */
	public function test_a_filter_receives_the_sanitized_value() {
		$this->assertSame( 'shop_states', edd_get_registered_settings_types()['base_state'], 'Fixture: base_state must be declared, or the type filter never fires.' );
		$this->assertArrayHasKey( 'base_state', edd_get_registered_settings_types( 'general', 'main' ), 'Fixture: the key must belong to the section being saved, or the filters do not reach it.' );

		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=general&section=main';

		add_filter( 'edd_settings_sanitize', array( $this, 'record_filtered_value' ), 10, 2 );

		edd_settings_sanitize( array( 'base_state' => 'Arizona <b>x</b>' ) );

		$this->assertSame( 'Arizona x', $this->filtered_value );
	}

	/**
	 * A list posted with nothing checked carries the marker the form sends to say
	 * so, and the stored list gives way to it.
	 */
	public function test_a_list_posted_with_nothing_checked_is_emptied() {
		global $edd_options;

		$this->assertSame( 'gateways', edd_get_registered_settings_types()['gateways'], 'Fixture: the setting must be declared as a list type.' );

		$edd_options['gateways'] = array( 'manual' => 1 );
		$this->assertSame( array( 'manual' => 1 ), edd_get_option( 'gateways' ), 'Fixture: a list must be stored, or there is nothing to give way.' );

		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=main';

		$output = edd_settings_sanitize( array( 'gateways' => '-1' ) );

		$this->assertSame( array(), $output['gateways'] );
	}

	/**
	 * The payment icons send an empty value to say nothing is checked.
	 */
	public function test_payment_icons_posted_with_nothing_checked_are_emptied() {
		global $edd_options;

		$this->assertSame( 'payment_icons', edd_get_registered_settings_types()['accepted_cards'], 'Fixture: the setting must be declared as a list type.' );

		$edd_options['accepted_cards'] = array( 'visa' => 'visa' );
		$this->assertSame( array( 'visa' => 'visa' ), edd_get_option( 'accepted_cards' ), 'Fixture: a list must be stored, or there is nothing to give way.' );

		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=main';

		$output = edd_settings_sanitize( array( 'accepted_cards' => '' ) );

		$this->assertSame( array(), $output['accepted_cards'] );
	}

	/**
	 * A screen that submits a setting with the value it already holds still runs
	 * the filters for it, which is how a field acts on its own submit button.
	 */
	public function test_a_filter_runs_for_a_setting_submitted_unchanged() {
		global $edd_options;

		$this->assertSame( 'text', edd_get_registered_settings_types( 'general', 'main' )['entity_name'], 'Fixture: the key must belong to the section being saved and be declared.' );

		$edd_options['entity_name'] = 'Acme';
		$_POST['_wp_http_referer'] = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=general&section=main';

		add_filter( 'edd_settings_sanitize', array( $this, 'record_filtered_value' ), 10, 2 );

		edd_settings_sanitize( array( 'entity_name' => 'Acme' ) );

		$this->assertSame( 'Acme', $this->filtered_value );
	}

	/**
	 * A setting rendered by a callback carries whatever shape that callback reads,
	 * so its value is stored as posted.
	 */
	public function test_a_callback_rendered_setting_is_stored_as_posted() {
		$this->assertSame( 'hook', edd_get_registered_settings_types()['email_settings'], 'Fixture: email_settings must be declared as a callback-rendered type.' );
		$this->assertContains( 'hook', edd_get_non_setting_types(), 'Fixture: the callback-rendered type must be listed as a non-setting type.' );

		$value = 'x%20y';

		$output = edd_settings_sanitize( array( 'email_settings' => $value ) );

		$this->assertSame( $value, $output['email_settings'] );
	}

	/**
	 * The admin recipients are read back one address per line, so the setting carries no
	 * markup and the line breaks it is split on are kept.
	 */
	public function test_a_textarea_setting_that_carries_no_markup_is_stripped_of_it() {
		$this->assertSame( 'textarea', edd_get_registered_settings_types()['admin_notice_emails'], 'Fixture: the setting must be declared as a textarea.' );
		$this->assertFalse( \EDD\Settings\Sanitize\Registry::allows_html( 'admin_notice_emails' ), 'Fixture: the setting must not be registered as carrying markup, or the markup is kept.' );

		$output = edd_settings_sanitize( array( 'admin_notice_emails' => "<h2>Heading</h2>\nsales@example.com" ) );

		$this->assertSame( "Heading\nsales@example.com", $output['admin_notice_emails'] );
	}

	/**
	 * A textarea registered as carrying markup is printed as markup, so it keeps what a
	 * post may carry and drops what a post may not.
	 */
	public function test_a_textarea_setting_registered_as_carrying_markup_keeps_it() {
		$this->assertSame( 'textarea', edd_get_registered_settings_types()['empty_cart_preview'], 'Fixture: the setting must be declared as a textarea.' );
		$this->assertTrue( \EDD\Settings\Sanitize\Registry::allows_html( 'empty_cart_preview' ), 'Fixture: the setting must be registered as carrying markup, or the markup is stripped.' );

		$output = edd_settings_sanitize( array( 'empty_cart_preview' => '<h2>Heading</h2><script>window.x</script>' ) );

		$this->assertStringContainsString( '<h2>Heading</h2>', $output['empty_cart_preview'] );
		$this->assertStringNotContainsString( '<script', $output['empty_cart_preview'] );
	}

	/**
	 * Records the value the generic sanitize filter is handed for the key under test.
	 *
	 * @param mixed  $value The value being filtered.
	 * @param string $key   The setting id.
	 * @return mixed
	 */
	public function record_filtered_value( $value, $key ) {
		if ( in_array( $key, array( 'base_state', 'entity_name' ), true ) ) {
			$this->filtered_value = $value;
		}

		return $value;
	}
}
