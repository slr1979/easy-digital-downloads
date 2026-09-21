<?php

namespace EDD\Tests\Settings\Sanitize\Tabs\Gateways;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Admin\Settings\Sanitize\Tabs\Gateways\Checkout;

class CheckoutSection extends EDD_UnitTestCase {

	public function tearDown(): void {
		// Clear any of the settings errors.
		global $wp_settings_errors;
		$wp_settings_errors = array();

		parent::tearDown();
	}

	public function test_banned_emails_empty_input() {
		$this->assertSame(
			array(
				'banned_emails' => ''
			),
			Checkout::sanitize(
				array(
					'banned_emails' => ''
				)
			)
		);
	}

	public function test_banned_emails_single_email() {
		$this->assertSame(
			array(
				'banned_emails' => array( 'user1@example.local' ),
			),
			Checkout::sanitize(
				array(
					'banned_emails' => 'user1@example.local'
				)
			)
		);
	}

	public function test_banned_emails_multiple_emails() {
		$this->assertSame(
			array(
				'banned_emails' => array( 'user1@example.local', 'user2@example.local' ),
			),
			Checkout::sanitize(
				array(
					'banned_emails' => 'user1@example.local' . "\n" . 'user2@example.local',
				)
			)
		);
	}

	public function test_banned_emails_array_input() {
		$this->assertSame(
			array(
				'banned_emails' => array( 'user1@example.local', 'user2@example.local' ),
			),
			Checkout::sanitize(
				array(
					'banned_emails' => array( 'user1@example.local', 'user2@example.local' ),
				)
			)
		);
	}

	public function test_banned_emails_array_input_with_empty_and_whitespace_entries() {
		$this->assertSame(
			array(
				'banned_emails' => array( 'user1@example.local' ),
			),
			Checkout::sanitize(
				array(
					'banned_emails' => array( 'user1@example.local', '', '   ', 'user1@example.local' ),
				)
			)
		);
	}

	public function test_banned_emails_with_invalid_emails() {
		$this->assertSame(
			array(
				'banned_emails' => array(),
			),
			Checkout::sanitize(
				array(
					'banned_emails' => 'not-an-email',
				)
			)
		);
	}

	public function test_banned_emails_with_valid_and_invalid_emails() {
		$this->assertSame(
			array(
				'banned_emails' => array( 'user1@example.local' ),
			),
			Checkout::sanitize(
				array(
					'banned_emails' => 'not-an-email' . "\n" . 'user1@example.local',
				)
			)
		);
	}

	public function test_banned_emails_with_domain() {
		$this->assertSame(
			array(
				'banned_emails' => array( '@example.local' ),
			),
			Checkout::sanitize(
				array(
					'banned_emails' => '@example.local',
				)
			)
		);
	}

	/**
	 * The address fields post a marker to say nothing is checked, which the section
	 * reads as the empty list rather than as a value it could not use.
	 */
	public function test_address_fields_posted_with_nothing_checked_are_emptied_without_a_notice() {
		$this->assertSame( 'array', \EDD\Settings\Sanitize\Registry::get_shape( 'checkout_address_fields' ), 'Fixture: the setting must store a list.' );
		$this->assertTrue( method_exists( Checkout::class, 'sanitize_checkout_address_fields' ), 'Fixture: the section must name the key.' );

		if ( ! function_exists( 'add_settings_error' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$this->assertTrue( function_exists( 'add_settings_error' ), 'Fixture: the admin include must be loaded, or an empty notice list proves nothing.' );

		$this->assertSame( array(), Checkout::sanitize_field( 'checkout_address_fields', '-1' ) );
		$this->assertSame( array(), get_settings_errors( 'edd-notices' ) );
	}

	public function test_checkout_address_fields_empty() {
		$this->assertSame(
			array(
				'checkout_address_fields' => array()
			),
			Checkout::sanitize(
				array(
					'checkout_address_fields' => array()
				)
			)
		);
	}

	public function test_checkout_address_fields_country() {
		$this->assertSame(
			array(
				'checkout_address_fields' => array(
					'country' => 1
				)
			),
			Checkout::sanitize(
				array(
					'checkout_address_fields' => array(
						'country' => 1
					)
				)
			)
		);
	}

	public function test_taxes_enabled_checkout_address_fields_country() {
		edd_update_option( 'enable_taxes', true );
		$this->assertSame(
			array(
				'checkout_address_fields' => array(
					'country' => 1
				)
			),
			Checkout::sanitize(
				array(
					'checkout_address_fields' => array(
						'country' => 1
					)
				)
			)
		);
		edd_delete_option( 'enable_taxes' );
	}

	public function test_taxes_enabled_regional_rate_checkout_address_fields_country_is_full() {
		edd_update_option( 'enable_taxes', true );
		$tax_rate = edd_add_tax_rate(
			array(
				'scope'       => 'region',
				'name'        => 'US',
				'description' => 'TN',
				'amount'      => 9.25,
				'status'      => 'active',
			)
		);
		$this->assertSame(
			array(
				'checkout_address_fields' => array(
					'country' => 1,
					'address' => 1,
					'city'    => 1,
					'state'   => 1,
					'zip'     => 1,
				)
			),
			Checkout::sanitize(
				array(
					'checkout_address_fields' => array(
						'country' => 1,
						'address' => 1,
						'city'    => 1,
						'state'   => 1,
						'zip'     => 1,
					)
				)
			)
		);
		edd_delete_option( 'enable_taxes' );
		edd_delete_adjustment( $tax_rate );
	}
}
