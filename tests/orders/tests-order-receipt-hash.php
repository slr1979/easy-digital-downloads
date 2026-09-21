<?php
/**
 * Order receipt hash tests.
 *
 * The receipt hash is what a guest or PayPal return presents to establish that it may see an
 * order's receipt, so it is signed rather than derived from the order's own values.
 *
 * @package     EDD\Tests\Orders
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Orders;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Payment;

/**
 * ReceiptHash tests.
 *
 * @since 3.7.1
 * @group edd_orders
 */
class ReceiptHash extends EDD_UnitTestCase {

	/**
	 * A guest order.
	 *
	 * @var \EDD\Orders\Order
	 */
	protected $order;

	public function setUp(): void {
		parent::setUp();

		$this->order = edd_get_order( EDD_Helper_Payment::create_simple_payment() );
	}

	public function tearDown(): void {
		edd_destroy_order( $this->order->id );

		parent::tearDown();
	}

	/**
	 * The hash must not be a plain hash of the order's own data.
	 *
	 * A plain hash is only as unguessable as the payment key it is built from. Signing it binds
	 * the hash to the store's own key as well, so this asserts the value actually is signed.
	 */
	public function test_the_receipt_hash_is_not_a_plain_hash_of_its_data() {
		$plain_hash = md5( $this->order->id . $this->order->payment_key . $this->order->email );

		$this->assertNotSame( $plain_hash, $this->order->get_receipt_hash() );
	}

	/**
	 * Rotating the store's signing key must invalidate a hash issued under the old one.
	 *
	 * The control for the test above: without signing, nothing about the store's own key could
	 * affect the hash's validity at all.
	 */
	public function test_rotating_the_signing_key_invalidates_an_issued_hash() {
		$hash = $this->order->get_receipt_hash();

		update_option( 'edd_tokenizer_signing_key', 'a-different-signing-key' );

		$this->assertFalse( $this->order->is_receipt_hash_valid( $hash ) );
	}
}
