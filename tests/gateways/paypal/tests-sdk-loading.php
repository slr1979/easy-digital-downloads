<?php
/**
 * PayPal SDK Loading Tests
 *
 * Tests the PayPal JS SDK query arguments, components, funding sources,
 * and data attributes for both V2 and V3 commerce versions.
 *
 * @package   EDD\Tests\Gateways\PayPal
 * @copyright Copyright (c) 2026, Sandhills Development, LLC
 * @license   GPL2+
 * @since     3.6.9
 */

namespace EDD\Tests\Gateways\PayPal;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Gateways\PayPal\SdkToken;

/**
 * Tests for PayPal SDK loading configuration.
 *
 * @group gateways
 * @group paypal
 * @group paypal-sdk
 */
class SdkLoadingTest extends EDD_UnitTestCase {

	/**
	 * Clean up options before and after each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->clean_up_options();
	}

	/**
	 * Clean up options after each test.
	 */
	public function tearDown(): void {
		$this->clean_up_options();
		parent::tearDown();
	}

	/**
	 * Remove PayPal-related options.
	 */
	private function clean_up_options() {
		foreach ( array( 'sandbox', 'live' ) as $mode ) {
			delete_option( "edd_paypal_{$mode}_commerce_version" );
			delete_option( "edd_paypal_{$mode}_partner_client_id" );
			delete_option( "edd_paypal_{$mode}_merchant_id" );
			delete_option( "edd_paypal_{$mode}_vaulting_available" );
			delete_option( "edd_paypal_{$mode}_store_id" );
			delete_option( "edd_paypal_{$mode}_hmac_key" );
		}
		edd_delete_option( 'paypal_fastlane' );
		edd_delete_option( 'paypal_pay_later' );
	}

	/**
	 * Helper to set up V3 connection options.
	 */
	private function setup_v3_options() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		update_option( 'edd_paypal_sandbox_commerce_version', 'v3' );
		update_option( 'edd_paypal_sandbox_partner_client_id', 'PARTNER_CLIENT_ID' );
		update_option( 'edd_paypal_sandbox_merchant_id', 'MERCHANT_ID' );
		update_option( 'edd_paypal_sandbox_store_id', 'test-store-id' );
		update_option( 'edd_paypal_sandbox_hmac_key', str_repeat( 'a', 64 ) );
	}

	/**
	 * V3 SDK query args include the commit parameter.
	 */
	public function test_v3_sdk_includes_commit_param() {
		$this->setup_v3_options();

		$args = apply_filters( 'edd_paypal_js_sdk_query_args', array(
			'client-id'   => 'PARTNER_CLIENT_ID',
			'merchant-id' => 'MERCHANT_ID',
			'currency'    => 'USD',
			'intent'      => 'capture',
			'commit'      => 'true',
			'components'  => 'buttons,messages',
		) );

		$this->assertArrayHasKey( 'commit', $args );
		$this->assertSame( 'true', $args['commit'] );

		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * V3 SDK components include applepay and googlepay.
	 */
	public function test_v3_sdk_includes_applepay_googlepay_components() {
		$this->setup_v3_options();

		// Simulate the SDK args that scripts.php would build for V3 without Fastlane.
		$components = 'buttons,applepay,googlepay,messages';

		$this->assertStringContainsString( 'applepay', $components );
		$this->assertStringContainsString( 'googlepay', $components );
		$this->assertStringContainsString( 'buttons', $components );

		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * V3 SDK enables Venmo funding.
	 */
	public function test_v3_sdk_enables_venmo() {
		$this->setup_v3_options();

		$enable_funding = array( 'venmo' );

		$this->assertContains( 'venmo', $enable_funding );

		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * V3 SDK includes messages component and paylater funding when Pay Later is enabled.
	 */
	public function test_v3_sdk_pay_later_enabled() {
		$this->setup_v3_options();
		edd_update_option( 'paypal_pay_later', true );

		$pay_later_enabled = (bool) edd_get_option( 'paypal_pay_later', true );

		$this->assertTrue( $pay_later_enabled );

		$enable_funding = array( 'venmo' );
		if ( $pay_later_enabled ) {
			$enable_funding[] = 'paylater';
		}
		$components = $pay_later_enabled ? 'buttons,applepay,googlepay,messages' : 'buttons,applepay,googlepay';

		$this->assertContains( 'paylater', $enable_funding );
		$this->assertStringContainsString( 'messages', $components );

		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * V3 SDK disables credit and paylater when Pay Later is off.
	 */
	public function test_v3_sdk_pay_later_disabled() {
		$this->setup_v3_options();
		edd_update_option( 'paypal_pay_later', false );

		$pay_later_enabled = (bool) edd_get_option( 'paypal_pay_later', false );

		$this->assertFalse( $pay_later_enabled );

		$disable_funding = array();
		if ( ! $pay_later_enabled ) {
			$disable_funding[] = 'credit';
			$disable_funding[] = 'paylater';
		}
		$components = $pay_later_enabled ? 'buttons,applepay,googlepay,messages' : 'buttons,applepay,googlepay';

		$this->assertContains( 'credit', $disable_funding );
		$this->assertContains( 'paylater', $disable_funding );
		$this->assertStringNotContainsString( 'messages', $components );

		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * A successful Connect API response returns the client token.
	 */
	public function test_sdk_token_fetch_fresh_fetch_returns_token() {
		$this->setup_v3_options();

		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'client_token' => 'TOKEN_FRESH' ) ),
			);
		} );

		$token = SdkToken::fetch( 'sandbox', '', 'fresh.example.com' );

		$this->assertSame( 'TOKEN_FRESH', $token );

		remove_all_filters( 'pre_http_request' );
		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * A second call for the same tuple is served from the static cache and
	 * never re-fetches, even if the HTTP layer would now return a different token.
	 */
	public function test_sdk_token_fetch_static_cache_hit() {
		$this->setup_v3_options();

		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'client_token' => 'TOKEN_STATIC' ) ),
			);
		} );

		$first = SdkToken::fetch( 'sandbox', '', 'static.example.com' );
		$this->assertSame( 'TOKEN_STATIC', $first );

		// Swap the HTTP response; the static cache should shadow it.
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'client_token' => 'TOKEN_CHANGED' ) ),
			);
		} );

		$second = SdkToken::fetch( 'sandbox', '', 'static.example.com' );
		$this->assertSame( 'TOKEN_STATIC', $second );

		remove_all_filters( 'pre_http_request' );
		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * A value primed in the object cache is returned without an HTTP request.
	 */
	public function test_sdk_token_fetch_object_cache_hit() {
		$this->setup_v3_options();

		$mode        = 'sandbox';
		$customer_id = '';
		$domain      = 'objectcache.example.com';
		$cache_key   = 'edd_paypal_sdk_token_' . md5( $mode . '_' . $customer_id . '_' . $domain );
		wp_cache_set( $cache_key, 'TOKEN_CACHED', 'edd_paypal', 5 * MINUTE_IN_SECONDS );

		// Any HTTP request would fail the test by returning a different token.
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'client_token' => 'TOKEN_HTTP' ) ),
			);
		} );

		$token = SdkToken::fetch( $mode, $customer_id, $domain );

		$this->assertSame( 'TOKEN_CACHED', $token );

		remove_all_filters( 'pre_http_request' );
		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * A transport error (WP_Error) yields an empty token and caches nothing.
	 */
	public function test_sdk_token_fetch_error_returns_empty() {
		$this->setup_v3_options();

		add_filter( 'pre_http_request', function () {
			return new \WP_Error( 'http_request_failed', 'Connection refused' );
		} );

		$token = SdkToken::fetch( 'sandbox', '', 'error.example.com' );

		$this->assertSame( '', $token );

		$cache_key = 'edd_paypal_sdk_token_' . md5( 'sandbox' . '_' . '' . '_' . 'error.example.com' );
		$this->assertFalse( wp_cache_get( $cache_key, 'edd_paypal' ) );

		remove_all_filters( 'pre_http_request' );
		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * A non-string client_token in the response is rejected and not cached.
	 */
	public function test_sdk_token_fetch_non_string_token_rejected() {
		$this->setup_v3_options();

		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'client_token' => array( 'unexpected' => 'shape' ) ) ),
			);
		} );

		$token = SdkToken::fetch( 'sandbox', '', 'nonstring.example.com' );

		$this->assertSame( '', $token );

		remove_all_filters( 'pre_http_request' );
		remove_filter( 'edd_is_test_mode', '__return_true' );
	}

	/**
	 * register_js on a non-checkout page does not fetch a client token, even
	 * when forced to load for a Buy Now button.
	 */
	public function test_register_js_force_load_does_not_fetch_client_token() {
		$this->setup_v3_options();
		$this->mark_paypal_ready();

		// Enable Fastlane so methods_requiring_client_token() would be non-empty
		// at checkout; the gate must still skip the fetch off-checkout.
		update_option( 'edd_paypal_sandbox_vaulting_available', true );
		edd_update_option( 'paypal_fastlane', true );

		$requested = false;
		add_filter( 'pre_http_request', function () use ( &$requested ) {
			$requested = true;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'client_token' => 'SHOULD_NOT_FETCH' ) ),
			);
		} );

		// Not on checkout. register_js( true ) mimics a Buy Now button.
		\EDD\Gateways\PayPal\register_js( true );

		$this->assertFalse( $requested, 'Buy Now buttons must not trigger the SDK-token Connect API call.' );

		remove_all_filters( 'pre_http_request' );
		remove_filter( 'edd_is_test_mode', '__return_true' );
		edd_delete_option( 'gateways' );
		edd_delete_option( 'paypal_fastlane' );
	}

	/**
	 * Enables the PayPal gateway so register_js() runs past its early guards.
	 *
	 * The v3 store options set in setup_v3_options() satisfy
	 * ready_to_accept_payments(); this only needs to mark the gateway active.
	 */
	private function mark_paypal_ready() {
		edd_update_option( 'gateways', array( 'paypal_commerce' => 1 ) );
	}

	/**
	 * Payment source label filter returns correct labels.
	 */
	public function test_payment_source_label_returns_paypal() {
		$payments = new \EDD\Gateways\PayPal\Payments();

		// Create a mock order.
		$order_id = edd_add_order( array(
			'status'   => 'complete',
			'gateway'  => 'paypal_commerce',
			'total'    => 10.00,
			'currency' => 'USD',
		) );

		edd_update_order_meta( $order_id, '_edd_paypal_payment_source', 'paypal' );
		$order = edd_get_order( $order_id );

		$label = $payments->payment_source_checkout_label( 'PayPal Commerce', 'paypal_commerce', $order );
		$this->assertSame( 'PayPal', $label );

		edd_delete_order( $order_id );
	}

	/**
	 * Payment source label filter returns Venmo.
	 */
	public function test_payment_source_label_returns_venmo() {
		$payments = new \EDD\Gateways\PayPal\Payments();

		$order_id = edd_add_order( array(
			'status'   => 'complete',
			'gateway'  => 'paypal_commerce',
			'total'    => 10.00,
			'currency' => 'USD',
		) );

		edd_update_order_meta( $order_id, '_edd_paypal_payment_source', 'venmo' );
		$order = edd_get_order( $order_id );

		$label = $payments->payment_source_checkout_label( 'PayPal Commerce', 'paypal_commerce', $order );
		$this->assertSame( 'Venmo', $label );

		edd_delete_order( $order_id );
	}

	/**
	 * Payment source label filter returns Credit Card (PayPal) for card.
	 */
	public function test_payment_source_label_returns_card() {
		$payments = new \EDD\Gateways\PayPal\Payments();

		$order_id = edd_add_order( array(
			'status'   => 'complete',
			'gateway'  => 'paypal_commerce',
			'total'    => 10.00,
			'currency' => 'USD',
		) );

		edd_update_order_meta( $order_id, '_edd_paypal_payment_source', 'card' );
		$order = edd_get_order( $order_id );

		$label = $payments->payment_source_checkout_label( 'PayPal Commerce', 'paypal_commerce', $order );
		$this->assertSame( 'Credit Card (PayPal)', $label );

		edd_delete_order( $order_id );
	}

	/**
	 * Payment source label filter returns Apple Pay.
	 */
	public function test_payment_source_label_returns_apple_pay() {
		$payments = new \EDD\Gateways\PayPal\Payments();

		$order_id = edd_add_order( array(
			'status'   => 'complete',
			'gateway'  => 'paypal_commerce',
			'total'    => 10.00,
			'currency' => 'USD',
		) );

		edd_update_order_meta( $order_id, '_edd_paypal_payment_source', 'apple_pay' );
		$order = edd_get_order( $order_id );

		$label = $payments->payment_source_checkout_label( 'PayPal Commerce', 'paypal_commerce', $order );
		$this->assertSame( 'Apple Pay', $label );

		edd_delete_order( $order_id );
	}

	/**
	 * Payment source label filter returns Google Pay.
	 */
	public function test_payment_source_label_returns_google_pay() {
		$payments = new \EDD\Gateways\PayPal\Payments();

		$order_id = edd_add_order( array(
			'status'   => 'complete',
			'gateway'  => 'paypal_commerce',
			'total'    => 10.00,
			'currency' => 'USD',
		) );

		edd_update_order_meta( $order_id, '_edd_paypal_payment_source', 'google_pay' );
		$order = edd_get_order( $order_id );

		$label = $payments->payment_source_checkout_label( 'PayPal Commerce', 'paypal_commerce', $order );
		$this->assertSame( 'Google Pay', $label );

		edd_delete_order( $order_id );
	}

	/**
	 * Payment source label is unchanged for non-PayPal gateways.
	 */
	public function test_payment_source_label_unchanged_for_other_gateways() {
		$payments = new \EDD\Gateways\PayPal\Payments();

		$label = $payments->payment_source_checkout_label( 'Stripe', 'stripe', null );
		$this->assertSame( 'Stripe', $label );
	}

	/**
	 * Payment source label uses default when no meta is set.
	 */
	public function test_payment_source_label_default_when_no_meta() {
		$payments = new \EDD\Gateways\PayPal\Payments();

		$order_id = edd_add_order( array(
			'status'   => 'complete',
			'gateway'  => 'paypal_commerce',
			'total'    => 10.00,
			'currency' => 'USD',
		) );

		$order = edd_get_order( $order_id );

		$label = $payments->payment_source_checkout_label( 'PayPal Commerce', 'paypal_commerce', $order );
		$this->assertSame( 'PayPal Commerce', $label );

		edd_delete_order( $order_id );
	}
}
