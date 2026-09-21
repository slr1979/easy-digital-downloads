<?php

namespace EDD\Tests\Stripe\Admin;

use \EDD\Tests\PHPUnit\EDD_UnitTestCase;
use \EDD\Gateways\Stripe\Admin\Settings as StripeSettings;

/**
 * Tests for the Stripe settings registration.
 *
 * @covers \EDD\Gateways\Stripe\Admin\Settings
 */
class Settings extends EDD_UnitTestCase {

	/**
	 * Loads the Stripe Connect admin file, which is not loaded in the test context.
	 */
	public static function wpSetUpBeforeClass() {
		require_once EDDS_PLUGIN_DIR . '/includes/admin/settings/stripe-connect.php';
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		delete_option( '_edds_legacy_elements_enabled' );
		edd_delete_option( 'stripe_elements_mode' );
		edd_delete_option( 'stripe_connect_account_id' );
		edd_delete_option( 'test_secret_key' );
		edd_delete_option( 'test_mode' );

		parent::tearDown();
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Settings::get()
	 */
	public function test_card_elements_store_without_legacy_flag_sees_elements_mode_setting() {
		$this->connect_store();
		edd_update_option( 'stripe_elements_mode', 'card-elements' );

		$this->assertArrayHasKey( 'stripe_elements_mode', ( new StripeSettings() )->get() );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Settings::get()
	 */
	public function test_legacy_flag_store_sees_elements_mode_setting() {
		$this->connect_store();
		add_option( '_edds_legacy_elements_enabled', 1, false );
		edd_update_option( 'stripe_elements_mode', 'payment-elements' );

		$this->assertArrayHasKey( 'stripe_elements_mode', ( new StripeSettings() )->get() );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Settings::get()
	 */
	public function test_payment_elements_store_without_legacy_flag_hides_elements_mode_setting() {
		$this->connect_store();
		edd_update_option( 'stripe_elements_mode', 'payment-elements' );

		$this->assertArrayNotHasKey( 'stripe_elements_mode', ( new StripeSettings() )->get() );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Settings::get()
	 */
	public function test_manual_api_key_store_hides_elements_mode_setting() {
		$this->use_manual_api_keys();
		add_option( '_edds_legacy_elements_enabled', 1, false );

		$this->assertArrayNotHasKey( 'stripe_elements_mode', ( new StripeSettings() )->get() );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Settings::get()
	 */
	public function test_card_elements_store_without_legacy_flag_hides_card_elements_only_settings() {
		$this->connect_store();
		edd_update_option( 'stripe_elements_mode', 'card-elements' );

		$settings = ( new StripeSettings() )->get();

		$this->assertArrayNotHasKey( 'stripe_allow_prepaid', $settings );
		$this->assertArrayNotHasKey( 'stripe_split_payment_fields', $settings );
		$this->assertArrayNotHasKey( 'stripe_use_existing_cards', $settings );
	}

	/**
	 * Stores a Stripe Connect account ID so the store counts as connected.
	 */
	private function connect_store(): void {
		edd_update_option( 'stripe_connect_account_id', 'acct_test' );
	}

	/**
	 * Stores a manual test secret key with no Connect account, so keys are managed manually.
	 */
	private function use_manual_api_keys(): void {
		edd_update_option( 'test_mode', true );
		edd_update_option( 'test_secret_key', 'sk_test_manual' );
	}
}
