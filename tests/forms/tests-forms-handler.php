<?php

namespace EDD\Tests\Forms;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Forms\Handler;

class FormsHandler extends EDD_UnitTestCase {

	public function test_handler_render_field_outputs_html_for_valid_field() {
		ob_start();
		Handler::render_field( '\\EDD\\Forms\\Login\\Username', array() );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'edd_user_login', $html );
	}

	public function test_handler_render_field_skips_nonexistent_class() {
		ob_start();
		Handler::render_field( '\\EDD\\Forms\\Nonexistent\\Field', array() );
		$html = ob_get_clean();

		$this->assertEmpty( $html );
	}

	public function test_handler_render_field_skips_class_not_extending_field() {
		ob_start();
		// stdClass exists but does not extend EDD\Forms\Fields\Field.
		Handler::render_field( 'stdClass', array() );
		$html = ob_get_clean();

		$this->assertEmpty( $html );
	}

	public function test_handler_render_fields_outputs_all_provided_fields() {
		ob_start();
		Handler::render_fields(
			array(
				'\\EDD\\Forms\\Register\\Username',
				'\\EDD\\Forms\\Register\\PasswordConfirm',
			),
			array( 'no_wp_scripts' => true )
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'edd_user_register', $html );
		$this->assertStringContainsString( 'pass2', $html );
	}

	public function test_handler_render_fields_with_empty_array_produces_no_output() {
		ob_start();
		Handler::render_fields( array() );
		$html = ob_get_clean();

		$this->assertEmpty( $html );
	}

	public function test_handler_render_fields_skips_invalid_entries_and_renders_valid_ones() {
		ob_start();
		Handler::render_fields(
			array(
				'\\EDD\\Forms\\Nonexistent\\Field',
				'\\EDD\\Forms\\Login\\Password',
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'edd_user_pass', $html );
	}

	public function test_handler_passes_data_to_rendered_field() {
		ob_start();
		// Password field respects the no_wp_scripts flag passed via $data.
		Handler::render_field( '\\EDD\\Forms\\Register\\Password', array( 'no_wp_scripts' => true ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'wp-hide-pw', $html );
	}
}
