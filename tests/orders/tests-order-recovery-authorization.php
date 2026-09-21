<?php
/**
 * Order recovery authorization tests.
 *
 * Resuming an unpaid order hands the caller that order's cart, its fees and any discount
 * that was applied to it, and binds the checkout session to it. The token in the order's
 * recovery URL is what establishes that the caller is the person the order belongs to, so
 * these tests assert that the token is required, that it is bound to one order, and that
 * nothing about the order is touched before that check passes.
 *
 * Under unit tests edd_redirect() is a no-op, so each guard must return for the function
 * to end cleanly; that is what lets these tests assert which state was left alone.
 *
 */

namespace EDD\Tests\Orders;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * @group edd_orders
 */
class RecoveryAuthorization extends EDD_UnitTestCase {

	/**
	 * A recoverable (pending, no transaction id) guest order.
	 *
	 * @var int
	 */
	protected $order_id;

	/**
	 * The download the orders are for.
	 *
	 * @var int
	 */
	protected static $download_id;

	public static function wpSetUpBeforeClass() {
		self::$download_id = \EDD\Tests\Helpers\EDD_Helper_Download::create_simple_download()->ID;
	}

	public function setUp(): void {
		parent::setUp();

		wp_cache_flush();
		$this->order_id = $this->create_recoverable_order();

		EDD()->cart->empty_cart();
		EDD()->session->set( 'edd_resume_payment', null );
	}

	public function tearDown(): void {
		EDD()->cart->empty_cart();
		EDD()->session->set( 'edd_resume_payment', null );
		wp_set_current_user( 0 );
		edd_destroy_order( $this->order_id );
		wp_cache_flush();

		parent::tearDown();
	}

	/**
	 * A recovery request that carries no token must not resume the order.
	 */
	public function test_recovery_without_a_token_does_not_resume_the_order() {
		edd_recover_payment( array( 'payment_id' => $this->order_id ) );

		$this->assertOrderWasNotResumed();
	}

	/**
	 * A recovery request carrying a token that is not ours must not resume the order.
	 */
	public function test_recovery_with_an_incorrect_token_does_not_resume_the_order() {
		edd_recover_payment(
			array(
				'payment_id' => $this->order_id,
				'token'      => str_repeat( 'a', 64 ),
			)
		);

		$this->assertOrderWasNotResumed();
	}

	/**
	 * A token is bound to one order and must not resume another.
	 */
	public function test_a_token_for_another_order_does_not_resume_the_order() {
		$other_order_id = $this->create_recoverable_order( 'other-buyer@example.test' );
		$other_token    = edd_get_order( $other_order_id )->get_recovery_token();

		edd_recover_payment(
			array(
				'payment_id' => $this->order_id,
				'token'      => $other_token,
			)
		);

		$this->assertOrderWasNotResumed();

		edd_destroy_order( $other_order_id );
	}

	/**
	 * An order which belongs to a user account must not be resumed by a logged out caller.
	 */
	public function test_a_logged_out_caller_cannot_resume_a_users_order() {
		$user_id  = $this->factory->user->create();
		$order_id = $this->create_recoverable_order( 'account-holder@example.test', $user_id );
		$token    = edd_get_order( $order_id )->get_recovery_token();

		wp_set_current_user( 0 );
		edd_recover_payment(
			array(
				'payment_id' => $order_id,
				'token'      => $token,
			)
		);

		$this->assertOrderWasNotResumed();

		edd_destroy_order( $order_id );
	}

	/**
	 * An order which belongs to one user account must not be resumed by a different user.
	 *
	 * Holding the token is not sufficient on its own: a customer who forwards their recovery
	 * email, or an order whose address is guessed, must not hand the cart to another account.
	 */
	public function test_another_user_cannot_resume_a_users_order() {
		$owner_id = $this->factory->user->create();
		$other_id = $this->factory->user->create();
		$order_id = $this->create_recoverable_order( 'account-holder@example.test', $owner_id );
		$token    = edd_get_order( $order_id )->get_recovery_token();

		wp_set_current_user( $other_id );
		edd_recover_payment(
			array(
				'payment_id' => $order_id,
				'token'      => $token,
			)
		);

		$this->assertOrderWasNotResumed();

		edd_destroy_order( $order_id );
	}

	/**
	 * The user an order belongs to, holding the token, must still be able to resume it.
	 *
	 * The control for the two tests above: without it, refusing every logged in caller would
	 * satisfy them both.
	 */
	public function test_the_owning_user_can_resume_their_own_order() {
		$owner_id = $this->factory->user->create();
		$order_id = $this->create_recoverable_order( 'account-holder@example.test', $owner_id );
		$token    = edd_get_order( $order_id )->get_recovery_token();

		wp_set_current_user( $owner_id );
		edd_recover_payment(
			array(
				'payment_id' => $order_id,
				'token'      => $token,
			)
		);

		$this->assertOrderWasResumed( $order_id );

		edd_destroy_order( $order_id );
	}

	/**
	 * An unauthorized recovery request must not annotate the order.
	 *
	 * The note is written for the store owner's benefit, so writing it before the request is
	 * authorized both records something that did not happen and lets any caller append to an
	 * order they have no relationship with.
	 */
	public function test_unauthorized_recovery_does_not_add_an_order_note() {
		$before = $this->count_order_notes();

		edd_recover_payment( array( 'payment_id' => $this->order_id ) );

		$this->assertSame( $before, $this->count_order_notes(), 'No note may be added by an unauthorized request.' );
	}

	/**
	 * The order owner, holding the token, must still be able to resume.
	 *
	 * The control: without it, refusing every request would satisfy the tests above.
	 */
	public function test_recovery_with_a_valid_token_resumes_the_order() {
		$token = edd_get_order( $this->order_id )->get_recovery_token();

		edd_recover_payment(
			array(
				'payment_id' => $this->order_id,
				'token'      => $token,
			)
		);

		$this->assertOrderWasResumed( $this->order_id );
	}

	/**
	 * The recovery link EDD generates must carry the token the handler requires.
	 *
	 * Without this the guard above would make the feature unusable.
	 */
	public function test_recovery_url_carries_a_token() {
		$order = edd_get_order( $this->order_id );
		$query = $this->parse_recovery_url( $order );

		$this->assertArrayHasKey( 'token', $query, 'The recovery URL must carry a token.' );
		$this->assertTrue( $order->is_recovery_token_valid( $query['token'] ), 'The token in the URL must validate.' );
	}

	/**
	 * The recovery link must not expose the order's payment key.
	 *
	 * The payment key also grants access to the receipt and its download links, so a URL
	 * which is emailed should not carry it.
	 */
	public function test_recovery_url_does_not_expose_the_payment_key() {
		$order = edd_get_order( $this->order_id );

		$this->assertStringNotContainsString(
			$order->payment_key,
			$order->get_recovery_url(),
			'The recovery URL must not contain the payment key.'
		);
	}

	/**
	 * Asserts that nothing about the order was loaded into the caller's session.
	 */
	private function assertOrderWasNotResumed() {
		$this->assertEmpty( EDD()->cart->get_contents(), 'The order cart must not be loaded.' );
		$this->assertEmpty( EDD()->session->get( 'edd_resume_payment' ), 'The session must not be bound to the order.' );
	}

	/**
	 * Asserts that the order was loaded into the caller's session.
	 *
	 * @param int $order_id The order which should have been resumed.
	 */
	private function assertOrderWasResumed( $order_id ) {
		$this->assertNotEmpty( EDD()->cart->get_contents(), 'The order cart must be loaded for the owner.' );
		$this->assertEquals( $order_id, EDD()->session->get( 'edd_resume_payment' ), 'The session must be bound to the order.' );
	}

	/**
	 * The number of notes currently attached to the order under test.
	 *
	 * @return int
	 */
	private function count_order_notes() {
		return count(
			edd_get_notes(
				array(
					'object_id'   => $this->order_id,
					'object_type' => 'order',
					'number'      => 50,
				)
			)
		);
	}

	/**
	 * Parses the query arguments out of an order's recovery URL.
	 *
	 * @param \EDD\Orders\Order $order The order.
	 * @return array
	 */
	private function parse_recovery_url( $order ) {
		$query = array();
		wp_parse_str( (string) wp_parse_url( $order->get_recovery_url(), PHP_URL_QUERY ), $query );

		return $query;
	}

	/**
	 * Creates a pending order with no transaction id, which is what makes it recoverable.
	 *
	 * @param string $email   The order email.
	 * @param int    $user_id The user the order belongs to, if any.
	 * @return int
	 */
	private function create_recoverable_order( $email = 'recovery-owner@example.test', $user_id = 0 ) {
		$order_id = edd_add_order(
			array(
				'status'      => 'pending',
				'user_id'     => $user_id,
				'customer_id' => 0,
				'email'       => $email,
				'currency'    => 'USD',
				'gateway'     => 'manual',
				'payment_key' => strtolower( md5( uniqid( 'recovery', true ) ) ),
				'subtotal'    => 20.00,
				'total'       => 20.00,
			)
		);

		edd_add_order_item(
			array(
				'order_id'   => $order_id,
				'product_id' => self::$download_id,
				'status'     => 'pending',
				'quantity'   => 1,
				'amount'     => 20.00,
				'subtotal'   => 20.00,
				'total'      => 20.00,
			)
		);

		return $order_id;
	}
}
