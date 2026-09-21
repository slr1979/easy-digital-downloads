<?php
namespace EDD\Tests\Cart;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Download;

/**
 * Guest saved cart restoration tests.
 *
 * A guest's saved cart is held in a cookie, so these tests cover the token which authorizes
 * restoring it: that it is signed with the store's own key, that it is bound to the cart it
 * was issued for, and that a restored cart is rebuilt through EDD_Cart::add(). The logged in
 * path reads the user's own user meta and is covered by EDD\Tests\Cart\Cart::test_restore_cart().
 *
 * @group edd_cart
 */
class Restore extends EDD_UnitTestCase {

	/**
	 * A variable priced download.
	 *
	 * @var int
	 */
	protected static $download_id;

	public static function wpSetUpBeforeClass() {
		self::$download_id = EDD_Helper_Download::create_variable_download()->ID;
	}

	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 0 );
		edd_update_option( 'enable_cart_saving', '1' );

		$this->reset_request();
		edd_empty_cart();
		EDD()->session->set( 'edd_cart', array() );
		EDD()->session->set( 'edd_cart_messages', array() );
	}

	public function tearDown(): void {
		$this->reset_request();
		edd_delete_option( 'enable_cart_saving' );
		edd_empty_cart();
		EDD()->session->set( 'edd_cart_messages', array() );

		parent::tearDown();
	}

	/**
	 * A cart described entirely by cookies the requester set is not restored.
	 */
	public function test_guest_restore_rejects_a_self_issued_token() {
		$this->set_saved_cart_cookie( $this->cart_json( array() ) );
		$_COOKIE['edd_cart_token'] = 'anything';
		$_GET['edd_cart_token']    = 'anything';

		// Assert the fixture: cart saving is on and there is a cart cookie to restore.
		$this->assertNotEmpty( EDD()->cart->is_saving_enabled() );
		$this->assertNotEmpty( $_COOKIE['edd_saved_cart'] );

		$result = EDD()->cart->restore();

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_cart_token', $result->get_error_code() );
		$this->assertEmpty( EDD()->session->get( 'edd_cart' ) );
		$this->assertEmpty( edd_get_cart_contents() );
		$this->assertArrayNotHasKey( 'edd_cart_restoration_successful', (array) EDD()->session->get( 'edd_cart_messages' ) );
	}

	/**
	 * Option keys the store never authored cannot be written into the cart.
	 */
	public function test_unsigned_cart_options_do_not_reach_the_session() {
		$this->set_saved_cart_cookie(
			$this->cart_json(
				array(
					'extra_note'  => 'not-store-authored',
					'license_key' => 'not-store-issued',
				)
			)
		);
		$_COOKIE['edd_cart_token'] = 'anything';
		$_GET['edd_cart_token']    = 'anything';

		$this->assertWPError( EDD()->cart->restore() );
		$this->assertEmpty( EDD()->session->get( 'edd_cart' ) );
		$this->assertEmpty( edd_get_cart_contents() );
	}

	/**
	 * A token signed with the store's key restores the cart it was issued for.
	 */
	public function test_guest_restore_accepts_a_store_signed_token() {
		$json = $this->cart_json( array( 'price_id' => 1 ) );
		$this->set_saved_cart_cookie( $json );
		$_GET['edd_cart_token'] = $this->get_saved_cart_token( $json );

		$this->assertTrue( EDD()->cart->restore() );

		$contents = array_values( (array) EDD()->session->get( 'edd_cart' ) );
		$this->assertCount( 1, $contents );
		$this->assertEquals( self::$download_id, $contents[0]['id'] );
		$this->assertEquals( 1, $contents[0]['options']['price_id'] );
	}

	/**
	 * A token is only valid for the cart it was issued for.
	 */
	public function test_saved_cart_token_is_bound_to_its_own_cart() {
		$token_for_tier_zero = $this->get_saved_cart_token( $this->cart_json( array( 'price_id' => 0 ) ) );

		$this->set_saved_cart_cookie( $this->cart_json( array( 'price_id' => 1 ) ) );

		// Supplied as both cookie and query argument, which is what the old check compared.
		$_COOKIE['edd_cart_token'] = $token_for_tier_zero;
		$_GET['edd_cart_token']    = $token_for_tier_zero;

		$this->assertWPError( EDD()->cart->restore() );
		$this->assertEmpty( EDD()->session->get( 'edd_cart' ) );
	}

	/**
	 * Every restored item is rebuilt through EDD_Cart::add().
	 */
	public function test_restored_items_are_rebuilt_through_add() {
		$json = $this->cart_json( array() );
		$this->set_saved_cart_cookie( $json );
		$_GET['edd_cart_token'] = $this->get_saved_cart_token( $json );

		$this->assertTrue( EDD()->cart->restore() );

		$contents = array_values( (array) EDD()->session->get( 'edd_cart' ) );
		$this->assertCount( 1, $contents );

		// add() forces a variable priced product to a real price option and stamps an item hash.
		$this->assertArrayHasKey( 'price_id', $contents[0]['options'] );
		$this->assertEquals(
			edd_get_download( self::$download_id )->get_default_price_id(),
			$contents[0]['options']['price_id']
		);
		$this->assertArrayHasKey( 'hash', $contents[0] );
	}

	/**
	 * A restore with nothing saved for the requester is not reported as a success.
	 */
	public function test_restore_without_a_saved_cart_does_not_report_success() {
		$this->assertFalse( EDD()->cart->restore() );
		$this->assertEmpty( EDD()->session->get( 'edd_cart' ) );
		$this->assertArrayNotHasKey( 'edd_cart_restoration_successful', (array) EDD()->session->get( 'edd_cart_messages' ) );
	}

	/**
	 * A rebuild which kept nothing is not reported as a success.
	 */
	public function test_restore_of_a_cart_whose_products_are_gone_does_not_report_success() {
		$json = $this->cart_json( array() );
		$this->set_saved_cart_cookie( $json );
		$_GET['edd_cart_token'] = $this->get_saved_cart_token( $json );

		// The saved product is no longer purchasable, so add() refuses it during the rebuild.
		wp_update_post(
			array(
				'ID'          => self::$download_id,
				'post_status' => 'draft',
			)
		);

		// Assert the fixture: the token is good, so any refusal below is the rebuild's.
		$this->assertTrue( $this->is_saved_cart_token_valid( $_GET['edd_cart_token'], $json ) );
		$this->assertFalse( ( new \EDD_Download( self::$download_id ) )->can_purchase() );

		$restored = EDD()->cart->restore();

		wp_update_post(
			array(
				'ID'          => self::$download_id,
				'post_status' => 'publish',
			)
		);

		$this->assertFalse( $restored );
		$this->assertEmpty( EDD()->session->get( 'edd_cart' ) );
		$this->assertArrayNotHasKey( 'edd_cart_restoration_successful', (array) EDD()->session->get( 'edd_cart_messages' ) );
	}

	/**
	 * The token save() hands the customer is one restore() accepts.
	 */
	public function test_save_issues_a_token_restore_accepts() {
		edd_add_to_cart( self::$download_id, array( 'price_id' => 1 ) );

		$cart = EDD()->session->get( 'edd_cart' );

		// Assert the fixture: there is a cart to save.
		$this->assertNotEmpty( $cart );
		$this->assertTrue( EDD()->cart->save() );

		$messages = (array) EDD()->session->get( 'edd_cart_messages' );
		$this->assertArrayHasKey( 'edd_cart_save_successful', $messages );

		preg_match( '/edd_cart_token=([a-zA-Z0-9]+)/', $messages['edd_cart_save_successful'], $matches );
		$this->assertNotEmpty( $matches[1] );
		$this->assertTrue( $this->is_saved_cart_token_valid( $matches[1], wp_json_encode( $cart ) ) );
	}

	/**
	 * There is nothing to save when the cart is empty.
	 */
	public function test_save_refuses_an_empty_cart() {
		// Assert the fixture: the cart is actually empty.
		$this->assertEmpty( EDD()->session->get( 'edd_cart' ) );

		$this->assertFalse( EDD()->cart->save() );

		$messages = (array) EDD()->session->get( 'edd_cart_messages' );
		$this->assertArrayNotHasKey( 'edd_cart_save_successful', $messages );
	}

	/**
	 * Builds the JSON a saved cart cookie holds for a single item.
	 *
	 * @param array $options The item's options array.
	 * @return string
	 */
	private function cart_json( $options ) {
		return wp_json_encode(
			array(
				array(
					'id'       => self::$download_id,
					'options'  => $options,
					'quantity' => 1,
				),
			)
		);
	}

	/**
	 * Sets the saved cart cookie the way the browser would send it back.
	 *
	 * @param string $json The JSON encoded cart.
	 * @return void
	 */
	private function set_saved_cart_cookie( $json ) {
		$_COOKIE['edd_saved_cart'] = addslashes( $json );
	}

	/**
	 * Mints a token for a cart the way SavedCart::save() would, for a test to set up a
	 * cookie/token pairing directly. Private on SavedCart, since nothing else needs it.
	 *
	 * @param string $json The JSON encoded cart the token is for.
	 * @return string
	 */
	private function get_saved_cart_token( $json ) {
		$method = new \ReflectionMethod( \EDD\Cart\SavedCart::class, 'get_saved_cart_token' );
		$method->setAccessible( true );

		return $method->invoke( new \EDD\Cart\SavedCart( EDD()->cart ), $json );
	}

	/**
	 * Checks a token against a cart the way SavedCart::restore() would.
	 *
	 * @param string $token The token to check.
	 * @param string $json  The JSON encoded cart the token should be for.
	 * @return bool
	 */
	private function is_saved_cart_token_valid( $token, $json ) {
		$method = new \ReflectionMethod( \EDD\Cart\SavedCart::class, 'is_saved_cart_token_valid' );
		$method->setAccessible( true );

		return $method->invoke( new \EDD\Cart\SavedCart( EDD()->cart ), $token, $json );
	}

	/**
	 * Clears the request state each test sets up.
	 *
	 * @return void
	 */
	private function reset_request() {
		unset( $_COOKIE['edd_saved_cart'], $_COOKIE['edd_cart_token'], $_GET['edd_cart_token'] );
	}
}
