<?php

namespace EDD\Tests\Stripe\Admin;

use \EDD\Tests\PHPUnit\EDD_UnitTestCase;
use \EDD\Gateways\Stripe\Admin\Connect as StripeConnect;

/**
 * Tests for the Stripe Connect admin class.
 *
 * @covers \EDD\Gateways\Stripe\Admin\Connect
 */
class Connect extends EDD_UnitTestCase {

	/**
	 * Admin user fixture.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Subscriber user fixture.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Set up fixtures once.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Admin-only files are not loaded in the test context.
		require_once EDDS_PLUGIN_DIR . '/includes/admin/settings/stripe-connect.php';
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Connect::get_connect_button()
	 */
	public function test_get_connect_button_without_capability_is_empty() {
		wp_set_current_user( self::$subscriber_id );

		$this->assertSame( '', StripeConnect::get_connect_button() );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Connect::get_connect_button()
	 */
	public function test_get_connect_button_default_markup() {
		wp_set_current_user( self::$admin_id );

		$button = StripeConnect::get_connect_button();

		$this->assertStringContainsString( 'class="edd-stripe-connect"', $button );
		$this->assertStringContainsString( '<span>Connect with Stripe</span>', $button );
		$this->assertStringContainsString( 'edd_gateway_connect_init=stripe_connect', $button );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Connect::get_connect_button()
	 */
	public function test_get_connect_button_custom_text_and_classes() {
		wp_set_current_user( self::$admin_id );

		$button = StripeConnect::get_connect_button(
			array(
				'text'    => 'Custom Text',
				'classes' => array( 'my-extra-class' ),
			)
		);

		$this->assertStringContainsString( 'class="edd-stripe-connect my-extra-class"', $button );
		$this->assertStringContainsString( '<span>Custom Text</span>', $button );
	}

	/**
	 * The button markup must remain single-line: two call sites pass it through wpautop().
	 *
	 * @covers \EDD\Gateways\Stripe\Admin\Connect::get_connect_button()
	 */
	public function test_get_connect_button_markup_is_single_line() {
		wp_set_current_user( self::$admin_id );

		$this->assertStringNotContainsString( "\n", StripeConnect::get_connect_button() );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Connect::get_connect_button()
	 */
	public function test_get_connect_button_with_redirect_screen() {
		wp_set_current_user( self::$admin_id );

		$button = StripeConnect::get_connect_button(
			array(
				'redirect_screen' => 'my-screen',
			)
		);

		preg_match( '/href="([^"]+)"/', $button, $matches );
		$href = html_entity_decode( $matches[1] );

		parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query_args );

		$this->assertArrayHasKey( 'customer_site_url', $query_args );
		$this->assertStringContainsString( 'redirect_screen=my-screen', urldecode( $query_args['customer_site_url'] ) );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Connect::get_redirect_screens()
	 */
	public function test_get_redirect_screens_default_contains_onboarding_wizard() {
		$screens = StripeConnect::get_redirect_screens();

		$this->assertArrayHasKey( 'onboarding-wizard', $screens );
		$this->assertTrue( $screens['onboarding-wizard']['enable_gateway'] );
		$this->assertStringContainsString( 'page=edd-onboarding-wizard', $screens['onboarding-wizard']['url'] );
		$this->assertStringContainsString( 'current_step=payment_methods', $screens['onboarding-wizard']['url'] );
	}

	/**
	 * @covers \EDD\Gateways\Stripe\Admin\Connect::get_redirect_screens()
	 */
	public function test_get_redirect_screens_is_filterable() {
		$add_screen = static function ( $screens ) {
			$screens['my-screen'] = array(
				'url'            => 'https://example.org/wp-admin/admin.php?page=my-screen',
				'enable_gateway' => false,
			);

			return $screens;
		};

		add_filter( 'edds_stripe_connect_redirect_screens', $add_screen );

		$screens = StripeConnect::get_redirect_screens();

		remove_filter( 'edds_stripe_connect_redirect_screens', $add_screen );

		$this->assertArrayHasKey( 'my-screen', $screens );
		$this->assertFalse( $screens['my-screen']['enable_gateway'] );
	}
}
