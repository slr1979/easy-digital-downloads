<?php
/**
 * PayPal Connect Button Tests
 *
 * Tests the ConnectButton renderer: capability guard, default markup,
 * custom args, and single-line output.
 *
 * @package   EDD\Tests\Gateways\PayPal
 * @copyright Copyright (c) 2026, Sandhills Development, LLC
 * @license   GPL2+
 * @since     3.7.0
 */

namespace EDD\Tests\Gateways\PayPal;

use EDD\Gateways\PayPal\Admin\ConnectButton;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for the ConnectButton class.
 *
 * @group gateways
 * @group paypal
 * @group paypal-connect-button
 *
 * @covers \EDD\Gateways\PayPal\Admin\ConnectButton
 */
class ConnectButtonTest extends EDD_UnitTestCase {

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
	 *
	 * @param \WP_UnitTest_Factory $factory Fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * @covers \EDD\Gateways\PayPal\Admin\ConnectButton::get()
	 */
	public function test_get_without_capability_is_empty() {
		wp_set_current_user( self::$subscriber_id );

		$this->assertSame( '', ConnectButton::get() );
	}

	/**
	 * @covers \EDD\Gateways\PayPal\Admin\ConnectButton::get()
	 */
	public function test_get_default_markup() {
		wp_set_current_user( self::$admin_id );

		$button = ConnectButton::get();

		$this->assertStringContainsString( 'id="edd-paypal-commerce-v3-connect"', $button );
		$this->assertStringContainsString( 'class="edd-paypal-connect"', $button );
		$this->assertStringContainsString( 'data-nonce="', $button );
		$this->assertStringContainsString( 'assets/images/paypal-logo.svg', $button );
		$this->assertStringContainsString( '<span>Connect with PayPal in', $button );
	}

	/**
	 * The mode label reflects the store mode.
	 *
	 * @covers \EDD\Gateways\PayPal\Admin\ConnectButton::get()
	 */
	public function test_get_mode_label_matches_store_mode() {
		wp_set_current_user( self::$admin_id );

		$expected = edd_is_test_mode() ? 'sandbox' : 'live';

		$this->assertStringContainsString( "Connect with PayPal in {$expected} mode", ConnectButton::get() );
	}

	/**
	 * @covers \EDD\Gateways\PayPal\Admin\ConnectButton::get()
	 */
	public function test_get_with_custom_args() {
		wp_set_current_user( self::$admin_id );

		$button = ConnectButton::get(
			array(
				'text'    => 'Custom Label',
				'classes' => array( 'my-extra-class' ),
				'data'    => array( 'origin' => 'setup-checklist' ),
			)
		);

		$this->assertStringContainsString( '<span>Custom Label</span>', $button );
		$this->assertStringContainsString( 'class="edd-paypal-connect my-extra-class"', $button );
		$this->assertStringContainsString( 'data-origin="setup-checklist"', $button );
		$this->assertStringContainsString( 'data-nonce="', $button );
	}

	/**
	 * The markup must be a single line so it survives wpautop().
	 *
	 * @covers \EDD\Gateways\PayPal\Admin\ConnectButton::get()
	 */
	public function test_get_is_single_line() {
		wp_set_current_user( self::$admin_id );

		$this->assertStringNotContainsString( "\n", ConnectButton::get() );
	}
}
