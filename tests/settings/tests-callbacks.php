<?php

namespace EDD\Tests\Settings;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

class Callbacks extends EDD_UnitTestCase {

	public function test_select() {
		$args = array(
			'id'      => 'email_template',
			'name'    => __( 'Template', 'easy-digital-downloads' ),
			'desc'    => __( 'Choose a template. Click "Save Changes" then "Preview Purchase Receipt" to see the new template.', 'easy-digital-downloads' ),
			'options' => edd_get_email_templates(),
		);

		ob_start();
		edd_select_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'multiple', $output );
		$this->assertStringContainsString( 'name="edd_settings[email_template]"', $output );
	}

	public function test_multiple_select() {
		$args = array(
			'id'          => 'edd_das_service_categories',
			'name'        => __( 'Downloads as Services', 'easy-digital-downloads' ),
			'desc'        => __( 'Select the categories that contain services, or products with no downloadable files.', 'easy-digital-downloads' ),
			'options'     => array(
				'1' => 'Category 1',
				'2' => 'Category 2',
				'3' => 'Category 3',
			),
			'multiple'    => true,
			'chosen'      => true,
			'placeholder' => __( 'Select categories', 'easy-digital-downloads' ),
			'std'         => array(),
		);

		ob_start();
		edd_select_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'multiple', $output );
		$this->assertStringContainsString( 'name="edd_settings[edd_das_service_categories][]"', $output );
	}

	public function test_checkbox_toggle() {
		$args = array(
			'id'    => 'enable_public_request_logs',
			'name'  => __( 'Request Logs', 'easy-digital-downloads' ),
			'check' => __( 'Log public API requests.', 'easy-digital-downloads' ),
			'desc'  => __( 'Authenticated requests to the EDD API are always logged.', 'easy-digital-downloads' ),
			'type'  => 'checkbox_toggle',
		);

		ob_start();
		edd_checkbox_toggle_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_settings[enable_public_request_logs]"', $output );
		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'class="edd-toggle', $output );
	}

	public function test_upload() {
		$args = array(
			'id'   => 'email_logo',
			'name' => __( 'Logo', 'easy-digital-downloads' ),
			'desc' => __( 'Upload or choose a logo to be displayed at the top of sales receipt emails. Displayed on HTML emails only.', 'easy-digital-downloads' ),
			'type' => 'upload',
		);

		ob_start();
		edd_upload_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_settings[email_logo]"', $output );
		$this->assertStringContainsString( 'type="text"', $output );
		$this->assertStringContainsString( 'class="regular-text"', $output );

	}

	public function test_textarea() {
		$args = array(
			'id'   => 'empty_cart_preview',
			'name' => __( 'Empty Cart Preview', 'easy-digital-downloads' ),
			'desc' => __( 'Preview the empty cart behavior.', 'easy-digital-downloads' ),
			'type' => 'textarea',
			'std'  => 'Default text here',
		);

		ob_start();
		edd_textarea_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_settings[empty_cart_preview]"', $output );
		$this->assertStringContainsString( '<textarea', $output );
		$this->assertStringContainsString( '</textarea>', $output );
		$this->assertStringContainsString( 'class="regular-text"', $output );
	}

	public function test_textarea_with_value() {
		$args = array(
			'id'   => 'admin_notice_emails',
			'name' => __( 'Admin Notice Emails', 'easy-digital-downloads' ),
			'desc' => __( 'Enter the email address(es) that may receive admin notices.', 'easy-digital-downloads' ),
			'type' => 'textarea',
		);

		// Set the option value.
		edd_update_option( 'admin_notice_emails', 'admin@example.com' );

		ob_start();
		edd_textarea_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_settings[admin_notice_emails]"', $output );
		$this->assertStringContainsString( 'admin@example.com', $output );

		// Clean up.
		edd_delete_option( 'admin_notice_emails' );
	}

	public function test_textarea_with_array_value() {
		$args = array(
			'id'   => 'admin_notice_emails',
			'name' => __( 'Admin Notice Emails', 'easy-digital-downloads' ),
			'desc' => __( 'Enter the email address(es) that may receive admin notices. One per line. Leave blank to use %s.', 'easy-digital-downloads' ),
			'type' => 'textarea',
		);

		// Set the option value as an array.
		edd_update_option( 'admin_notice_emails', array( 'test1@example.com', 'test2@example.com', 'test3@example.com' ) );

		ob_start();
		edd_textarea_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_settings[admin_notice_emails]"', $output );
		$this->assertStringContainsString( "test1@example.com\ntest2@example.com\ntest3@example.com", $output );

		// Clean up.
		edd_delete_option( 'admin_notice_emails' );
	}

	public function test_textarea_with_custom_rows() {
		$args = array(
			'id'   => 'empty_cart_preview',
			'name' => __( 'Empty Cart Preview', 'easy-digital-downloads' ),
			'desc' => __( 'Preview the empty cart behavior.', 'easy-digital-downloads' ),
			'type' => 'textarea',
			'rows' => 10,
		);

		ob_start();
		edd_textarea_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'rows="10"', $output );
	}

	public function test_textarea_readonly() {
		$args = array(
			'id'       => 'empty_cart_preview',
			'name'     => __( 'Empty Cart Preview', 'easy-digital-downloads' ),
			'desc'     => __( 'This field is readonly.', 'easy-digital-downloads' ),
			'type'     => 'textarea',
			'readonly' => true,
		);

		ob_start();
		edd_textarea_callback( $this->parse_args( $args ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'readonly', $output );
	}

	/**
	 * The three ordering and region callbacks build an attribute by concatenation,
	 * so each option value has to be escaped for that context.
	 *
	 */
	public function test_gateways_callback_escapes_the_order_value() {
		$output = $this->render_with_option(
			'gateways_order',
			'manual" /><img src=x onerror=alert(1)><input x="',
			function () {
				return edd_gateways_callback(
					$this->parse_args(
						array(
							// Empty so the assertion rests on the hidden ordering input,
							// which renders ahead of the gateway list.
							'id'      => 'gateways',
							'options' => array(),
						)
					)
				);
			}
		);

		$this->assertStringNotContainsString( '<img src=x', $output );
		$this->assertStringContainsString( '&lt;img', $output );
	}

	public function test_payment_icons_callback_escapes_the_order_value() {
		$output = $this->render_with_option(
			'payment_icons_order',
			'visa" /><img src=x onerror=alert(1)><input y="',
			function () {
				return edd_payment_icons_callback(
					$this->parse_args(
						array(
							'id'      => 'accepted_cards',
							'options' => array(),
						)
					)
				);
			}
		);

		$this->assertStringNotContainsString( '<img src=x', $output );
		$this->assertStringContainsString( '&lt;img', $output );
	}

	/**
	 * The region field only prints a stored value when the configured country has
	 * no predefined regions; with a country that has them it renders a select
	 * instead, which is asserted here as a control.
	 *
	 */
	public function test_shop_states_callback_escapes_the_region_value() {
		global $edd_options;

		$original_country = isset( $edd_options['base_country'] ) ? $edd_options['base_country'] : null;

		$unsanitized_html = 'Kabul" /><img src=x onerror=alert(1)><input z="';

		$edd_options['base_country'] = 'SG';
		$this->assertEmpty( edd_get_shop_states( 'SG' ), 'SG must have no predefined regions for this test to reach the text branch.' );

		$output = $this->render_with_option(
			'base_state',
			$unsanitized_html,
			function () {
				return edd_shop_states_callback(
					$this->parse_args(
						array(
							'id'          => 'base_state',
							'field_class' => 'edd_regions_filter',
						)
					)
				);
			}
		);

		$this->assertStringNotContainsString( '<img src=x', $output );
		$this->assertStringContainsString( '&lt;img', $output );

		// Control: a country with regions renders a select and never prints the stored value.
		$edd_options['base_country'] = 'US';
		$this->assertNotEmpty( edd_get_shop_states( 'US' ) );

		$control = $this->render_with_option(
			'base_state',
			$unsanitized_html,
			function () {
				return edd_shop_states_callback(
					$this->parse_args(
						array(
							'id'          => 'base_state',
							'field_class' => 'edd_regions_filter',
						)
					)
				);
			}
		);

		$this->assertStringNotContainsString( 'onerror', $control );

		if ( is_null( $original_country ) ) {
			unset( $edd_options['base_country'] );
		} else {
			$edd_options['base_country'] = $original_country;
		}
	}

	/**
	 * Renders a callback with one option seeded directly on the settings global.
	 *
	 * The value is set on the global rather than through edd_update_option() so the
	 * renderer is measured on its own: a sanitizer on the save path would otherwise
	 * satisfy the assertion without the escaping being present.
	 *
	 * @param string   $setting  Setting key to seed.
	 * @param string   $value    Value to seed it with.
	 * @param callable $callback Renders the field, echoing or returning its markup.
	 * @return string The rendered markup.
	 */
	private function render_with_option( $setting, $value, $callback ) {
		global $edd_options;

		$original = isset( $edd_options[ $setting ] ) ? $edd_options[ $setting ] : null;

		$edd_options[ $setting ] = $value;
		$this->assertSame( $value, edd_get_option( $setting ), 'The value under test was not readable before rendering.' );

		ob_start();
		$returned = $callback();
		$echoed   = ob_get_clean();

		if ( is_null( $original ) ) {
			unset( $edd_options[ $setting ] );
		} else {
			$edd_options[ $setting ] = $original;
		}

		return is_string( $returned ) && '' !== $returned ? $returned : $echoed;
	}

	private function parse_args( $args ) {
		return wp_parse_args(
			$args,
			array(
				'id'            => null,
				'desc'          => '',
				'name'          => '',
				'size'          => null,
				'options'       => '',
				'std'           => '',
				'min'           => null,
				'max'           => null,
				'step'          => null,
				'chosen'        => null,
				'multiple'      => null,
				'placeholder'   => null,
				'allow_blank'   => true,
				'readonly'      => false,
				'faux'          => false,
				'tooltip_title' => false,
				'tooltip_desc'  => false,
				'field_class'   => '',
				'label_for'     => false
			)
		);
	}
}
