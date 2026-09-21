<?php
/**
 * Tests for product notes on the order receipt.
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
 * Tests for product notes on the order receipt.
 *
 * @since 3.7.1
 * @group blocks
 */
class ReceiptNotes extends EDD_UnitTestCase {

	/**
	 * The order being receipted.
	 *
	 * @var int
	 */
	private $order_id;

	public function setUp(): void {
		parent::setUp();

		$this->order_id = EDD_Helper_Payment::create_simple_payment();
	}

	public function tearDown(): void {
		edd_destroy_order( $this->order_id );

		parent::tearDown();
	}

	/**
	 * A note cannot bring styling onto the receipt.
	 */
	public function test_a_note_cannot_carry_a_style_attribute() {
		$receipt = $this->render_with_note(
			'<p style="position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999">Pay here instead</p>'
		);

		$this->assertStringNotContainsString( 'style=', $receipt );
		$this->assertStringNotContainsString( 'position:fixed', $receipt );
	}

	/**
	 * A note cannot make the reader's browser fetch from elsewhere.
	 */
	public function test_a_note_cannot_carry_a_background_image() {
		$receipt = $this->render_with_note(
			'<p style="background-image:url(https://example.test/beacon.png)">Thanks</p>'
		);

		$this->assertStringNotContainsString( 'example.test', $receipt );
	}

	/**
	 * The text of the note is still shown.
	 *
	 * The control: without it, rendering nothing at all would satisfy the cases above.
	 */
	public function test_the_text_of_a_note_is_still_shown() {
		$receipt = $this->render_with_note( '<p style="color:red">Delivery takes two days</p>' );

		$this->assertStringContainsString( 'Delivery takes two days', $receipt );
	}

	/**
	 * The formatting a store legitimately uses survives.
	 */
	public function test_the_formatting_a_store_uses_survives() {
		$receipt = $this->render_with_note(
			'<strong>Important</strong> see the <a href="https://example.org/docs">documentation</a>'
		);

		$this->assertStringContainsString( '<strong>Important</strong>', $receipt );
		$this->assertStringContainsString( 'https://example.org/docs', $receipt );
	}

	/**
	 * The shortcode receipt holds the same line.
	 */
	public function test_the_shortcode_receipt_also_strips_styling() {
		$receipt = $this->render_shortcode_with_note(
			'<p style="position:fixed;top:0;left:0">Pay here instead</p>'
		);

		$this->assertStringNotContainsString( 'position:fixed', $receipt );
		$this->assertStringContainsString( 'Pay here instead', $receipt );
	}

	/**
	 * Renders the shortcode receipt for a product carrying the given note.
	 *
	 * @param string $note The stored note.
	 * @return string
	 */
	private function render_shortcode_with_note( $note ) {
		global $edd_receipt_args;

		$order = edd_get_order( $this->order_id );
		$items = $order->get_items();
		$item  = reset( $items );

		$this->store_note( $item->product_id, $note );

		$edd_receipt_args = array(
			'id'             => $this->order_id,
			'price'          => true,
			'discount'       => true,
			'products'       => true,
			'date'           => true,
			'notes'          => true,
			'payment_key'    => false,
			'payment_method' => true,
			'payment_id'     => true,
		);

		ob_start();
		include EDD_PLUGIN_DIR . 'templates/shortcode-receipt.php';

		return ob_get_clean();
	}

	/**
	 * Renders the receipt item for a product carrying the given note.
	 *
	 * @param string $note The stored note.
	 * @return string
	 */
	private function render_with_note( $note ) {
		$order = edd_get_order( $this->order_id );
		$items = $order->get_items();
		$item  = reset( $items );

		$this->store_note( $item->product_id, $note );

		$edd_receipt_args = array();

		ob_start();
		include EDD_BLOCKS_DIR . 'views/orders/receipt-item.php';

		return ob_get_clean();
	}

	/**
	 * Stores a note exactly as given, whatever sanitizing is registered for the key.
	 *
	 * Notes stored before a sanitizer existed are still read back and rendered, so the sink has
	 * to hold on its own.
	 *
	 * @param int    $download_id The product the note belongs to.
	 * @param string $note        The note to store.
	 */
	private function store_note( $download_id, $note ) {
		$verbatim = function () use ( $note ) {
			return $note;
		};

		add_filter( 'sanitize_post_meta_edd_product_notes_for_download', $verbatim, 99 );
		update_post_meta( $download_id, 'edd_product_notes', $note );
		remove_filter( 'sanitize_post_meta_edd_product_notes_for_download', $verbatim, 99 );

		$this->assertSame(
			$note,
			get_post_meta( $download_id, 'edd_product_notes', true ),
			'The note has to be stored as written for the case to mean anything.'
		);
	}
}
