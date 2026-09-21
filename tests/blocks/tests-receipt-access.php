<?php
/**
 * Tests for guest access to an order receipt.
 *
 * @package     EDD\Tests\Blocks
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Blocks;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Payment;

/**
 * ReceiptAccess tests.
 *
 * @since 3.7.1
 * @group edd_blocks
 */
class ReceiptAccess extends EDD_UnitTestCase {

	/**
	 * A guest order.
	 *
	 * @var \EDD\Orders\Order
	 */
	protected $order;

	public function setUp(): void {
		parent::setUp();

		$order_id = EDD_Helper_Payment::create_simple_payment();

		edd_update_order( $order_id, array( 'user_id' => 0 ) );

		$this->order = edd_get_order( $order_id );

		EDD()->session->set( 'edd_purchase', null );
	}

	public function tearDown(): void {
		EDD()->session->set( 'edd_purchase', null );
		$_GET  = array();
		$_POST = array();

		parent::tearDown();
	}

	/**
	 * Confirming an email without the receipt hash must not grant access.
	 */
	public function test_confirmation_without_the_hash_is_refused() {
		\EDD\Blocks\Orders\verify_guest_email( $this->confirm_args( array( 'order' => '' ) ) );

		$this->assertFalse( $this->has_session(), 'No access may be granted.' );
	}

	/**
	 * Confirming with the wrong hash must not grant access.
	 */
	public function test_confirmation_with_an_incorrect_hash_is_refused() {
		\EDD\Blocks\Orders\verify_guest_email( $this->confirm_args( array( 'order' => str_repeat( 'a', 32 ) ) ) );

		$this->assertFalse( $this->has_session(), 'No access may be granted.' );
	}

	/**
	 * An array-valued hash (e.g. order[]=x) must be refused rather than fatal.
	 *
	 * urldecode() throws a TypeError on a non-string argument, so the array has to be caught
	 * before it reaches that call rather than after.
	 */
	public function test_confirmation_with_an_array_valued_hash_is_refused() {
		\EDD\Blocks\Orders\verify_guest_email( $this->confirm_args( array( 'order' => array( 'x' ) ) ) );

		$this->assertFalse( $this->has_session(), 'No access may be granted.' );
	}

	/**
	 * A hash for one order must not confirm another.
	 */
	public function test_a_hash_for_another_order_is_refused() {
		$other_id = EDD_Helper_Payment::create_simple_payment();
		$other    = edd_get_order( $other_id );

		\EDD\Blocks\Orders\verify_guest_email(
			$this->confirm_args( array( 'order' => $other->get_receipt_hash() ) )
		);

		$this->assertFalse( $this->has_session(), 'No access may be granted.' );

		edd_destroy_order( $other_id );
	}

	/**
	 * The wrong email must not confirm, even with the correct hash.
	 */
	public function test_the_wrong_email_is_refused() {
		\EDD\Blocks\Orders\verify_guest_email(
			$this->confirm_args( array( 'edd_guest_email' => 'somebody-else@example.test' ) )
		);

		$this->assertFalse( $this->has_session(), 'No access may be granted.' );
	}

	/**
	 * The customer, holding the link and their address, is granted access.
	 *
	 * The control: without it, refusing everything would satisfy the tests above.
	 */
	public function test_the_customer_with_the_hash_and_their_email_is_granted_access() {
		\EDD\Blocks\Orders\verify_guest_email( $this->confirm_args() );

		$this->assertTrue( $this->has_session(), 'The customer must be able to confirm.' );
	}

	/**
	 * The confirmation form carries the hash it requires.
	 */
	public function test_the_confirmation_form_carries_the_hash() {
		$_GET['order'] = $this->order->get_receipt_hash();
		$_GET['id']    = $this->order->id;
		$order         = $this->order;

		ob_start();
		include EDD_BLOCKS_DIR . 'views/orders/guest.php';
		$form = ob_get_clean();

		$this->assertStringContainsString( 'name="order"', $form, 'The form must carry the hash.' );
		$this->assertStringContainsString( $this->order->get_receipt_hash(), $form, 'The hash must be the one from the request.' );
	}

	/**
	 * The PayPal success page must not establish access for a request-supplied order.
	 */
	public function test_the_paypal_success_page_does_not_establish_access() {
		require_once EDD_PLUGIN_DIR . 'includes/gateways/paypal-standard.php';

		$_GET['payment-id']           = $this->order->id;
		$_GET['payment-confirmation'] = 'paypal';

		edd_paypal_success_page_content( 'content' );

		$this->assertFalse( $this->has_session(), 'No access may be granted from a request parameter.' );
	}

	/**
	 * The buyer returning from PayPal keeps their receipt.
	 *
	 * The control for the change above: their session already identifies the order.
	 */
	public function test_the_paypal_success_page_leaves_the_buyers_session_alone() {
		require_once EDD_PLUGIN_DIR . 'includes/gateways/paypal-standard.php';

		edd_set_purchase_session( array( 'purchase_key' => $this->order->payment_key ) );

		$_GET['payment-id']           = $this->order->id;
		$_GET['payment-confirmation'] = 'paypal';

		edd_paypal_success_page_content( 'content' );

		$this->assertTrue( $this->has_session(), 'The buyer must keep their access.' );
	}

	/**
	 * The receipt page URI must carry the order's own receipt hash.
	 *
	 * `edd_get_receipt_page_uri()` is what builds the emailed receipt link — the value every
	 * other reader of the hash validates against, so it has to be the one thing that stays in
	 * sync if the hash's own source ever changes.
	 */
	public function test_the_receipt_page_uri_carries_the_orders_receipt_hash() {
		$uri   = edd_get_receipt_page_uri( $this->order->id );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );

		$this->assertSame( $this->order->get_receipt_hash(), $query['order'] ?? null, 'The URI must carry the order\'s own hash.' );
	}

	/**
	 * A request with no receipt hash must not be offered a confirmation form it cannot pass.
	 *
	 * Nothing EDD sends reaches this page without the hash, so an absent one only ever means an
	 * expired or missing session, and the guest form's own guard refuses every submission from
	 * this state anyway.
	 */
	public function test_no_access_message_does_not_offer_the_form_without_a_hash() {
		ob_start();
		\EDD\Blocks\Orders\show_no_access_message( $this->order );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'name="order"', $output, 'The unusable form must not render.' );
		$this->assertStringContainsString( 'purchase session has expired', $output, 'The expired-session message must render instead.' );
	}

	/**
	 * A request carrying the hash must still be offered the confirmation form.
	 *
	 * The control for the test above: without it, refusing every request would satisfy it too.
	 */
	public function test_no_access_message_offers_the_form_with_a_hash() {
		$_GET['order'] = $this->order->get_receipt_hash();

		ob_start();
		\EDD\Blocks\Orders\show_no_access_message( $this->order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="order"', $output, 'The form must render.' );
	}

	/**
	 * The request the confirmation form submits.
	 *
	 * @param array $args Overrides.
	 * @return array
	 */
	private function confirm_args( $args = array() ) {
		return wp_parse_args(
			$args,
			array(
				'order_id'        => $this->order->id,
				'edd_guest_email' => $this->order->email,
				'edd_guest_nonce' => wp_create_nonce( 'edd-guest-nonce' ),
				'order'           => $this->order->get_receipt_hash(),
			)
		);
	}

	/**
	 * Whether a purchase session was established for the order.
	 *
	 * @return bool
	 */
	private function has_session() {
		$session = edd_get_purchase_session();

		return ! empty( $session['purchase_key'] ) && $session['purchase_key'] === $this->order->payment_key;
	}
}
