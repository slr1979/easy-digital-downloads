<?php
/**
 * Tests for Stripe payment method availability and billing address requirements.
 *
 * Covers the eligibility gates introduced alongside the UPI payment method:
 * the shared currency check in Method::is_available(), the Affirm-specific
 * business rules, and PaymentMethods::requires_billing_address().
 *
 * @package     EDD\Tests\Stripe
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Stripe;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Download as Download;
use EDD\Gateways\Stripe\PaymentMethods;
use EDD\Gateways\Stripe\PaymentMethods\Affirm;
use EDD\Gateways\Stripe\PaymentMethods\Upi;

/**
 * Tests for payment method availability gates.
 *
 * @group edd_stripe
 * @group edd_stripe_payment_methods
 */
class PaymentMethodAvailability_Tests extends EDD_UnitTestCase {

	/**
	 * Establishes a known baseline before each test: empty cart, a supported
	 * currency, and Payment Elements mode.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		EDD()->cart->empty_cart();
		edd_update_option( 'currency', 'USD' );
		edd_update_option( 'stripe_elements_mode', 'payment-elements' );
	}

	/**
	 * Cleans up cart contents, downloads, and any seeded Stripe configuration.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		EDD()->cart->empty_cart();
		Download::delete_all_downloads();

		( new \EDD\Utils\Transient( 'edd_stripe_pmc_test' ) )->delete();
		( new \EDD\Utils\Transient( 'edd_stripe_pmc_live' ) )->delete();
		( new \EDD\Utils\Transient( 'edd_test_pmc' ) )->delete();
		edd_stripe()->connect()->is_connected = false;

		edd_delete_option( 'enable_taxes' );

		// edds_require_address() registers this conditionally; clear it so it does
		// not leak into other tests.
		remove_filter( 'edd_purchase_form_required_fields', 'edd_stripe_require_card_address' );

		parent::tearDown();
	}

	/**
	 * Seeds the Stripe payment method configuration so the given methods report
	 * themselves as available, and forces the connection as established.
	 *
	 * @param array $methods Map of payment method ID to its configuration array,
	 *                       e.g. array( 'affirm' => array( 'available' => true ) ).
	 * @return void
	 */
	private function seed_stripe_configuration( array $methods ) {
		$mode          = edd_is_test_mode() ? 'test' : 'live';
		$configuration = 'edd_test_pmc';

		( new \EDD\Utils\Transient( "edd_stripe_pmc_{$mode}" ) )->set( array( 'edd20241002' => $configuration ) );
		( new \EDD\Utils\Transient( $configuration ) )->set( $methods );

		edd_stripe()->connect()->is_connected = true;
	}

	/**
	 * The shared currency check short-circuits before any configuration lookup.
	 *
	 * UPI only supports INR, so a USD store makes it unavailable without ever
	 * reaching get_base_configuration().
	 *
	 * @covers \EDD\Gateways\Stripe\PaymentMethods\Method::is_available
	 */
	public function test_is_available_returns_false_for_unsupported_currency() {
		edd_update_option( 'currency', 'USD' );

		$this->assertFalse( Upi::is_available() );
	}

	/**
	 * Billing address is never forced outside of Payment Elements mode.
	 *
	 * @covers \EDD\Gateways\Stripe\PaymentMethods::requires_billing_address
	 */
	public function test_requires_billing_address_false_outside_payment_elements() {
		edd_update_option( 'stripe_elements_mode', 'card-elements' );

		$this->assertFalse( PaymentMethods::requires_billing_address() );
	}

	/**
	 * A billing address is not forced simply because Affirm/UPI are registered.
	 *
	 * In Payment Elements mode with a USD store and an empty cart, Affirm fails
	 * its cart-total gate and UPI fails its currency gate, so no opted-in method
	 * reports itself as available.
	 *
	 * @covers \EDD\Gateways\Stripe\PaymentMethods::requires_billing_address
	 */
	public function test_requires_billing_address_false_when_no_method_available() {
		$this->assertFalse( PaymentMethods::requires_billing_address() );
	}

	/**
	 * Affirm is unavailable below its $50 minimum cart total.
	 *
	 * The cart-total gate runs before the parent configuration lookup, so this
	 * holds without seeding any Stripe configuration.
	 *
	 * @covers \EDD\Gateways\Stripe\PaymentMethods\Affirm::is_available
	 */
	public function test_affirm_not_available_below_minimum_cart_total() {
		$download = Download::create_simple_download();
		edd_add_to_cart( $download->ID );

		$this->assertTrue( edd_get_cart_total() < 50 );
		$this->assertFalse( Affirm::is_available() );
	}

	/**
	 * Affirm is unavailable when the cart contains a recurring item.
	 *
	 * The cart total clears the $50 minimum, so availability hinges on the
	 * recurring gate, which runs before the parent configuration lookup.
	 *
	 * @covers \EDD\Gateways\Stripe\PaymentMethods\Affirm::is_available
	 */
	public function test_affirm_not_available_for_recurring_cart() {
		if ( ! function_exists( 'edd_recurring' ) ) {
			$this->markTestSkipped( 'EDD Recurring is not active.' );
		}

		$download = Download::create_simple_download();
		update_post_meta( $download->ID, 'edd_price', '100.00' );
		update_post_meta( $download->ID, 'edd_recurring', 'yes' );
		update_post_meta( $download->ID, 'edd_period', 'month' );

		edd_add_to_cart( $download->ID );

		$this->assertTrue( edd_get_cart_total() >= 50 );
		$this->assertTrue( edd_recurring()->cart_contains_recurring() );
		$this->assertFalse( Affirm::is_available() );
	}

	/**
	 * Affirm is available when currency, configuration, cart total, and the
	 * non-recurring rule all pass — the happy path the other gates bracket.
	 *
	 * @covers \EDD\Gateways\Stripe\PaymentMethods\Affirm::is_available
	 */
	public function test_affirm_available_when_all_conditions_met() {
		$download = Download::create_simple_download();
		update_post_meta( $download->ID, 'edd_price', '100.00' );
		edd_add_to_cart( $download->ID );

		$this->seed_stripe_configuration( array( 'affirm' => array( 'available' => true ) ) );

		$this->assertTrue( edd_get_cart_total() >= 50 );
		$this->assertTrue( Affirm::is_available() );
	}

	/**
	 * A billing address is required when an opted-in method (UPI) is available.
	 *
	 * This is the new behavior the PR ships: with an INR store and UPI present
	 * in the Stripe configuration, requires_billing_address() reports true.
	 *
	 * @covers \EDD\Gateways\Stripe\PaymentMethods::requires_billing_address
	 */
	public function test_requires_billing_address_true_when_upi_available() {
		edd_update_option( 'currency', 'INR' );

		$this->seed_stripe_configuration( array( 'upi' => array( 'available' => true ) ) );

		$this->assertTrue( Upi::is_available() );
		$this->assertTrue( PaymentMethods::requires_billing_address() );
	}

	/**
	 * Address line 1 (card_address) is added to the required fields when UPI is
	 * available, even when taxes already force the billing address.
	 *
	 * Regression test: card_address is not part of the default required fields,
	 * so it is only required when edds_require_address() registers its filter.
	 * That registration must happen regardless of whether the billing address is
	 * already required for another reason (such as enabled taxes), because Stripe
	 * rejects a UPI transaction without address line 1.
	 *
	 * @covers ::edds_require_address
	 * @covers ::edd_stripe_require_card_address
	 */
	public function test_card_address_required_when_upi_available_and_taxes_enabled() {
		edd_update_option( 'currency', 'INR' );
		edd_update_option( 'enable_taxes', true );

		$download = Download::create_simple_download();
		edd_add_to_cart( $download->ID );

		$this->seed_stripe_configuration( array( 'upi' => array( 'available' => true ) ) );

		// Taxes already force the billing address; this is the case the fix targets.
		$this->assertTrue( edd_use_taxes() && (bool) edd_get_cart_total() );

		$required_fields = edd_purchase_form_required_fields();

		$this->assertArrayHasKey( 'card_address', $required_fields );
	}

	/**
	 * Address line 1 (card_address) is still added when UPI is available and taxes
	 * are disabled — the original path that promoted the billing address from not
	 * required to required.
	 *
	 * @covers ::edds_require_address
	 * @covers ::edd_stripe_require_card_address
	 */
	public function test_card_address_required_when_upi_available_and_taxes_disabled() {
		edd_update_option( 'currency', 'INR' );

		$download = Download::create_simple_download();
		edd_add_to_cart( $download->ID );

		$this->seed_stripe_configuration( array( 'upi' => array( 'available' => true ) ) );

		$this->assertFalse( edd_use_taxes() );

		$required_fields = edd_purchase_form_required_fields();

		$this->assertArrayHasKey( 'card_address', $required_fields );
	}

	/**
	 * Address line 1 (card_address) is not forced when no Stripe method requires
	 * the billing address, even when taxes require the rest of the address.
	 *
	 * Guards against the fix over-reaching: a plain tax-driven billing address
	 * should require country/state/city/zip but not address line 1.
	 *
	 * @covers ::edds_require_address
	 */
	public function test_card_address_not_required_for_tax_address_without_stripe_method() {
		edd_update_option( 'enable_taxes', true );

		$download = Download::create_simple_download();
		edd_add_to_cart( $download->ID );

		// USD + empty Stripe configuration: no opted-in method is available.
		$this->assertTrue( edd_use_taxes() && (bool) edd_get_cart_total() );
		$this->assertFalse( PaymentMethods::requires_billing_address() );

		$required_fields = edd_purchase_form_required_fields();

		$this->assertArrayNotHasKey( 'card_address', $required_fields );
	}
}
