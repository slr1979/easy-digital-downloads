<?php

namespace EDD\Tests\Settings;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

class Setting extends EDD_UnitTestCase {
	public function test_get_settings() {
		$this->assertIsArray( edd_get_settings() );
	}

	public function test_get_setting_return_default() {
		$this->assertSame( 'default', edd_get_option( 'non_existent_setting', 'default' ) );
	}

	public function test_get_setting_existing_numeric_non_zero() {
		edd_update_option( 'existing_setting', 1 );
		$this->assertSame( 1, edd_get_option( 'existing_setting', 0 ) );
	}

	public function test_get_setting_existing_numeric_zero() {
		edd_update_option( 'existing_setting', 0 );
		$this->assertSame( 0, edd_get_option( 'existing_setting', 1 ) );
	}

	public function test_get_setting_existing_string() {
		edd_update_option( 'existing_setting', 'string' );
		$this->assertSame( 'string', edd_get_option( 'existing_setting', 'default' ) );
	}

	public function test_update_option_no_setting() {
		$this->assertFalse( edd_update_option( '', 'value' ) );
	}

	public function test_update_option_empty_after_sanitization() {
		add_filter( 'edd_update_option_fake_option', function( $value ) { return ''; } );
		$this->assertFalse( edd_update_option( 'fake_option', 'value' ) );
		remove_filter( 'edd_update_option_fake_option', function( $value ) { return ''; } );
	}

	public function test_delete_option_no_setting() {
		$this->assertFalse( edd_delete_option( '' ) );
	}

	/**
	 * Setting::update() dispatches to the type class itself, so a type that stores
	 * a single value sanitizes a list entry by entry.
	 */
	public function test_a_text_setting_written_a_list_is_sanitized_entry_by_entry() {
		$this->assertSame( 'text', edd_get_registered_settings_types()['thousands_separator'], 'Fixture: the setting must be declared as a single-value type.' );

		edd_update_option( 'thousands_separator', array( '<h2>kept</h2>' ) );

		$this->assertSame( array( 'kept' ), edd_get_option( 'thousands_separator' ) );

		edd_delete_option( 'thousands_separator' );
	}

	/**
	 * Setting::update() names the setting it is writing, so a type whose rule is per
	 * setting reads the same rule here as on a settings save.
	 */
	public function test_a_textarea_setting_written_directly_keeps_the_markup_it_is_registered_for() {
		$this->assertSame( 'textarea', edd_get_registered_settings_types()['empty_cart_preview'], 'Fixture: the setting must be declared as a textarea.' );
		$this->assertTrue( \EDD\Settings\Sanitize\Registry::allows_html( 'empty_cart_preview' ), 'Fixture: the setting must be registered as carrying markup, or the markup is stripped.' );

		edd_update_option( 'empty_cart_preview', '<strong>Nothing</strong> here' );

		$this->assertSame( '<strong>Nothing</strong> here', edd_get_option( 'empty_cart_preview' ) );

		edd_delete_option( 'empty_cart_preview' );
	}
}
