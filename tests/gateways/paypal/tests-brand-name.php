<?php
/**
 * PayPal Brand Name Helper Tests
 *
 * Tests BrandName::get(): Entity Name preference, Site Title fallback, blank-title
 * domain fallback, blank host fallback to home_url(), and mb-safe 127-character
 * truncation. Also covers the helper's call sites in the vault setup token and the
 * buttons script localization, so they always send a non-empty brand name.
 *
 * @package   EDD\Tests\Gateways\PayPal
 * @copyright Copyright (c) 2026, Sandhills Development, LLC
 * @license   GPL2+
 * @since     3.7.0
 */

namespace EDD\Tests\Gateways\PayPal;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Gateways\PayPal\BrandName;
use EDD\Gateways\PayPal\V3\Vault;

/**
 * Tests for BrandName::get().
 *
 * @group gateways
 * @group paypal
 * @group paypal-brand-name
 */
class BrandNameTest extends EDD_UnitTestCase {

	/**
	 * Original blogname option, restored after each test.
	 *
	 * @var string
	 */
	private $original_blogname;

	/**
	 * Original home option, restored after each test.
	 *
	 * @var string
	 */
	private $original_home;

	/**
	 * Capture the original site options before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_blogname = get_option( 'blogname' );
		$this->original_home     = get_option( 'home' );

		// Ensure no business name is set so the Site Title/host fallbacks are exercised.
		edd_delete_option( 'entity_name' );
	}

	/**
	 * Restore the original site options after each test.
	 */
	public function tearDown(): void {
		update_option( 'blogname', $this->original_blogname );
		update_option( 'home', $this->original_home );
		edd_delete_option( 'entity_name' );

		parent::tearDown();
	}

	/**
	 * A non-blank Site Title is returned as the brand name.
	 */
	public function test_non_blank_site_title_is_returned() {
		update_option( 'blogname', 'My Store' );

		$this->assertSame( 'My Store', BrandName::get() );
	}

	/**
	 * The EDD business name (Entity Name) is preferred over the Site Title.
	 */
	public function test_entity_name_is_preferred_over_site_title() {
		update_option( 'blogname', 'My Store' );
		edd_update_option( 'entity_name', 'My Store LLC' );

		$this->assertSame( 'My Store LLC', BrandName::get() );
	}

	/**
	 * A blank Entity Name falls back to the Site Title.
	 */
	public function test_blank_entity_name_falls_back_to_site_title() {
		update_option( 'blogname', 'My Store' );
		edd_update_option( 'entity_name', '' );

		$this->assertSame( 'My Store', BrandName::get() );
	}

	/**
	 * HTML entities in the Site Title are decoded before being returned.
	 */
	public function test_site_title_entities_are_decoded() {
		update_option( 'blogname', 'Tom &amp; Jerry&#039;s' );

		$this->assertSame( "Tom & Jerry's", BrandName::get() );
	}

	/**
	 * A Site Title longer than 127 characters is truncated mb-safely.
	 */
	public function test_long_site_title_is_truncated_to_127() {
		$long_title = str_repeat( 'a', 200 );
		update_option( 'blogname', $long_title );

		$brand_name = BrandName::get();

		$this->assertSame( 127, strlen( $brand_name ) );
		$this->assertSame( str_repeat( 'a', 127 ), $brand_name );
	}

	/**
	 * A multibyte Site Title over 127 characters is truncated without splitting characters.
	 */
	public function test_long_multibyte_site_title_is_truncated_mb_safely() {
		// Each "é" is two bytes; 200 of them is 400 bytes but 200 characters.
		$long_title = str_repeat( 'é', 200 );
		update_option( 'blogname', $long_title );

		$brand_name = BrandName::get();

		// mb_substr keeps 127 whole characters (254 bytes), never a split byte.
		$this->assertSame( 127, mb_strlen( $brand_name ) );
		$this->assertSame( str_repeat( 'é', 127 ), $brand_name );
	}

	/**
	 * A blank Site Title falls back to the home_url() host with www. stripped.
	 */
	public function test_blank_site_title_falls_back_to_host_without_www() {
		update_option( 'blogname', '' );
		update_option( 'home', 'https://www.example.com' );

		$this->assertSame( 'example.com', BrandName::get() );
	}

	/**
	 * A blank Site Title with a host that has no www. returns the bare host.
	 */
	public function test_blank_site_title_returns_bare_host() {
		update_option( 'blogname', '' );
		update_option( 'home', 'https://shop.example.com' );

		$this->assertSame( 'shop.example.com', BrandName::get() );
	}

	/**
	 * A whitespace-only Site Title is treated as blank and falls back to the host.
	 */
	public function test_whitespace_site_title_falls_back_to_host() {
		update_option( 'blogname', '   ' );
		update_option( 'home', 'https://www.example.com' );

		$this->assertSame( 'example.com', BrandName::get() );
	}

	/**
	 * A blank Site Title and a hostless home_url() fall back to the home_url() value.
	 */
	public function test_blank_title_and_blank_host_falls_back_to_home_url() {
		update_option( 'blogname', '' );

		// Force a hostless home_url() so wp_parse_url() returns an empty host.
		$hostless = static function () {
			return 'not-a-url';
		};
		add_filter( 'home_url', $hostless );

		$this->assertSame( 'not-a-url', BrandName::get() );

		remove_filter( 'home_url', $hostless );
	}

	/**
	 * The vault setup token sends the brand name fallback when no caller value is given.
	 */
	public function test_vault_setup_token_sends_brand_name_fallback() {
		update_option( 'blogname', 'Vault Store' );

		$this->setup_v3_options();

		$captured_body = '';
		add_filter(
			'pre_http_request',
			function ( $status, $args ) use ( &$captured_body ) {
				$captured_body = $args['body'];

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'SETUP_TOKEN_BRAND' ) ),
				);
			},
			10,
			2
		);

		Vault::create_setup_token( 1, 'https://example.com/return', 'https://example.com/cancel' );

		$decoded = json_decode( $captured_body, true );

		$this->assertSame(
			'Vault Store',
			$decoded['payment_source']['paypal']['experience_context']['brand_name']
		);

		$this->tear_down_v3_options();
	}

	/**
	 * The vault setup token prefers an explicit caller brand name over the fallback.
	 */
	public function test_vault_setup_token_prefers_caller_brand_name() {
		update_option( 'blogname', 'Vault Store' );

		$this->setup_v3_options();

		$captured_body = '';
		add_filter(
			'pre_http_request',
			function ( $status, $args ) use ( &$captured_body ) {
				$captured_body = $args['body'];

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'SETUP_TOKEN_BRAND' ) ),
				);
			},
			10,
			2
		);

		Vault::create_setup_token(
			1,
			'https://example.com/return',
			'https://example.com/cancel',
			array( 'brand_name' => 'Caller Brand' )
		);

		$decoded = json_decode( $captured_body, true );

		$this->assertSame(
			'Caller Brand',
			$decoded['payment_source']['paypal']['experience_context']['brand_name']
		);

		$this->tear_down_v3_options();
	}

	/**
	 * The PayPal buttons script localizes the brand name as the store name.
	 */
	public function test_register_js_localizes_brand_name_as_store_name() {
		update_option( 'blogname', 'Buttons Store' );

		$this->setup_v3_options();

		// Keep only the PayPal button active so register_js() never requests an
		// SDK client token (Fastlane and the card fields are the only methods
		// that do, and both stay off here).
		update_option( 'paypal_payment_methods', array( 'paypal' => '1' ) );
		add_filter( 'edd_is_gateway_active', '__return_true' );

		\EDD\Gateways\PayPal\register_js( true );

		$data = wp_scripts()->get_data( 'edd-paypal', 'data' );

		$this->assertIsString( $data );
		$this->assertStringContainsString( '"storeName":"Buttons Store"', $data );

		remove_filter( 'edd_is_gateway_active', '__return_true' );
		delete_option( 'paypal_payment_methods' );
		wp_dequeue_script( 'edd-paypal' );
		$this->tear_down_v3_options();
	}

	/**
	 * Set up the V3 (Connect) onboarding options needed to exercise call sites.
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
	 * Remove the V3 onboarding options and any stubbed HTTP responses.
	 */
	private function tear_down_v3_options() {
		remove_all_filters( 'pre_http_request' );
		remove_filter( 'edd_is_test_mode', '__return_true' );
		delete_option( 'edd_paypal_sandbox_commerce_version' );
		delete_option( 'edd_paypal_sandbox_partner_client_id' );
		delete_option( 'edd_paypal_sandbox_merchant_id' );
		delete_option( 'edd_paypal_sandbox_store_id' );
		delete_option( 'edd_paypal_sandbox_hmac_key' );
	}
}
