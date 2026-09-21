<?php
/**
 * Order email dispatch tests.
 *
 * @package     EDD\Tests\Emails
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Emails;

use EDD\Tests\Helpers;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Order email dispatch tests.
 *
 * The receipt, the admin notice and the Stripe early fraud warning are sent by listeners on
 * order hooks, and EDD's action router turns any request value into a hook name. These assert
 * that those listeners only act for a caller supplying a real order, and that the platform's
 * own callers still do.
 *
 * @group edd_emails
 */
class OrderEmailsDispatch extends EDD_UnitTestCase {

	/**
	 * A completed order awaiting its emails.
	 *
	 * @var int
	 */
	protected $order_id;

	/**
	 * The email address the order's customer receives the receipt at.
	 *
	 * @var string
	 */
	protected $customer_email;

	public function setUp(): void {
		parent::setUp();

		// A fresh test database has no email rows, so both emails have to be installed and
		// enabled before anything will send.
		Helpers\EDD_Helper_Email::enable( 'order_receipt' );
		Helpers\EDD_Helper_Email::enable( 'admin_order_notice' );
		Helpers\EDD_Helper_Email::start_capturing_mail();

		$this->order_id = $this->create_order_awaiting_its_emails();

		$order                = edd_get_order( $this->order_id );
		$customer             = edd_get_customer( $order->customer_id );
		$this->customer_email = $customer ? $customer->email : $order->email;
	}

	public function tearDown(): void {
		Helpers\EDD_Helper_Email::stop_capturing_mail();
		remove_filter( 'edd_use_after_payment_actions', '__return_false' );
		remove_filter( 'edd_should_send_email_stripe_early_fraud_warning', '__return_true' );

		$_GET = array();

		parent::tearDown();
	}

	/**
	 * A request that names the hook must not send the order's emails.
	 *
	 * edd_get_actions() passes $_GET as the first argument, which is where the order ID the
	 * listener resolves comes from.
	 */
	public function test_router_request_does_not_send_the_order_emails() {
		$this->assert_order_is_ready_to_send();

		$_GET = array(
			'edd_action' => 'after_order_actions',
			'id'         => (string) $this->order_id,
		);

		edd_get_actions();

		$this->assertEmpty(
			Helpers\EDD_Helper_Email::captured_mail(),
			'A request naming the hook must not send the order emails.'
		);
		$this->assert_order_still_awaits_its_emails();
	}

	/**
	 * The same refusal when the listener is called directly with a request array.
	 *
	 * The two init routers and the admin_init router all hand their superglobal to the
	 * listener in this shape, so the refusal has to be in the listener rather than in one
	 * router's wiring.
	 */
	public function test_listener_called_with_a_request_array_does_not_send_the_order_emails() {
		$this->assert_order_is_ready_to_send();

		( new \EDD\Emails\Triggers() )->send_order_emails(
			array(
				'edd_action' => 'after_order_actions',
				'id'         => (string) $this->order_id,
			)
		);

		$this->assertEmpty(
			Helpers\EDD_Helper_Email::captured_mail(),
			'A request array must not resolve into an order.'
		);
		$this->assert_order_still_awaits_its_emails();
	}

	/**
	 * The deferred-actions flow still sends both emails and consumes both flags.
	 *
	 * This is the scheduled path every store uses: DeferredActions::run_deferred_actions()
	 * fires edd_after_order_actions with the real order.
	 */
	public function test_deferred_actions_sends_the_order_emails() {
		$this->assert_order_is_ready_to_send();

		( new \EDD\Orders\DeferredActions() )->run_deferred_actions( $this->order_id );

		$this->assert_both_emails_were_sent();
		$this->assert_order_emails_were_consumed();
	}

	/**
	 * The inline completion flow still sends both emails.
	 *
	 * With edd_use_after_payment_actions filtered false, edd_complete_purchase() fires
	 * edd_after_order_actions itself, at request time, rather than scheduling it.
	 */
	public function test_inline_order_completion_sends_the_order_emails() {
		// Asserted for the emails being enabled: the order completed below consumes its own
		// flags during the call, so they cannot be read back afterwards.
		$this->assert_order_is_ready_to_send();

		add_filter( 'edd_use_after_payment_actions', '__return_false' );

		$before   = count( Helpers\EDD_Helper_Email::captured_mail() );
		$order_id = Helpers\EDD_Helper_Payment::create_simple_payment();

		edd_update_order_status( $order_id, 'complete' );

		remove_filter( 'edd_use_after_payment_actions', '__return_false' );

		$sent = array_slice( Helpers\EDD_Helper_Email::captured_mail(), $before );

		$this->assertNotEmpty( $sent, 'Completing an order inline must still send its emails.' );
		$this->assertContains(
			$this->customer_email,
			$this->recipients_of( $sent ),
			'The customer must still receive the receipt.'
		);
		$this->assertNotEmpty(
			edd_get_order( $order_id )->date_actions_run,
			'The inline flow must still stamp date_actions_run.'
		);
	}

	/**
	 * A numeric string order ID is still an order ID.
	 *
	 * Callers which hand the ID over as a string are not the case being refused, so the
	 * refusal cannot be written as a strict integer test.
	 */
	public function test_numeric_string_order_id_still_sends_the_order_emails() {
		$this->assert_order_is_ready_to_send();

		( new \EDD\Emails\Triggers() )->send_order_emails( (string) $this->order_id );

		$this->assert_both_emails_were_sent();
		$this->assert_order_emails_were_consumed();
	}

	/**
	 * An Order object with no ID argument is still enough to send.
	 *
	 * This is the signature's other supported shape, so the refusal cannot be written as an
	 * unconditional requirement on the first argument.
	 */
	public function test_order_object_alone_still_sends_the_order_emails() {
		$this->assert_order_is_ready_to_send();

		( new \EDD\Emails\Triggers() )->send_order_emails( 0, edd_get_order( $this->order_id ) );

		$this->assert_both_emails_were_sent();
		$this->assert_order_emails_were_consumed();
	}

	/**
	 * A request that names the early fraud warning hook must not reach the email.
	 *
	 * Unguarded, the email is built around the request array and an email tag raises an
	 * uncaught Error on it, so this asserts the absence of a 500 rather than of a send. The
	 * array can never resolve to an order here, unlike send_order_emails().
	 */
	public function test_router_request_does_not_send_the_early_fraud_warning() {
		$this->make_early_fraud_warning_sendable();

		$_GET = array( 'edd_action' => 'stripe_early_fraud_warning' );

		// Reading `->id` off the request array is only a warning, which PHPUnit converts into
		// an exception, ending the request before the Error that a real one would hit.
		$warnings = array();
		set_error_handler(
			function ( $errno, $errstr ) use ( &$warnings ) {
				$warnings[] = $errstr;

				return true;
			},
			E_WARNING
		);

		$error = null;
		try {
			edd_get_actions();
		} catch ( \Throwable $e ) {
			$error = $e;
		} finally {
			restore_error_handler();
		}

		$this->assertNull(
			$error,
			'A request naming the hook must not reach the email: ' . ( $error ? $error->getMessage() : '' )
		);
		$this->assertSame(
			array(),
			$warnings,
			'A refused request should raise no warning at all.'
		);
		$this->assertEmpty(
			Helpers\EDD_Helper_Email::captured_mail(),
			'A request naming the hook must not send the early fraud warning.'
		);
	}

	/**
	 * The early fraud warning still sends for a real order.
	 */
	public function test_early_fraud_warning_still_sends_for_an_order() {
		$this->make_early_fraud_warning_sendable();

		( new \EDD\Emails\Triggers() )->send_stripe_early_fraud_warning( edd_get_order( $this->order_id ) );

		$recipients = $this->recipients_of( Helpers\EDD_Helper_Email::captured_mail() );

		$this->assertNotEmpty( $recipients, 'The early fraud warning must still be sent.' );

		foreach ( edd_get_admin_notice_emails() as $admin_email ) {
			$this->assertContains( $admin_email, $recipients, 'The store must receive the early fraud warning.' );
		}
	}

	/**
	 * Makes the early fraud warning email sendable.
	 *
	 * It ships disabled and can only be enabled on a store running Stripe in payment-elements
	 * mode, so its own filter stands in for that configuration.
	 */
	private function make_early_fraud_warning_sendable() {
		Helpers\EDD_Helper_Email::install();

		add_filter( 'edd_should_send_email_stripe_early_fraud_warning', '__return_true' );
	}

	/**
	 * Creates a completed order whose receipt and admin notice have not been sent yet.
	 *
	 * Completing the order is what writes the two flags the sender reads, by way of the
	 * deprecated edd_trigger_purchase_receipt()/edd_admin_email_notice() listeners.
	 *
	 * @return int The order ID.
	 */
	private function create_order_awaiting_its_emails() {
		$order_id = Helpers\EDD_Helper_Payment::create_simple_payment();

		edd_update_order_status( $order_id, 'complete' );

		return $order_id;
	}

	/**
	 * Asserts the fixture is one the sender would act on.
	 *
	 * Without this a negative case passes on an order that could never have sent anything,
	 * which looks identical to the guard working.
	 */
	private function assert_order_is_ready_to_send() {
		$order = edd_get_order( $this->order_id );

		$this->assertSame( 'complete', $order->status, 'The fixture order must be complete.' );
		$this->assertSame( 'sale', $order->type, 'The fixture order must be a sale.' );
		$this->assertEmpty( $order->date_actions_run, 'The fixture order must not have run its actions yet.' );

		$receipt = edd_get_email_by( 'email_id', 'order_receipt' );
		$notice  = edd_get_email_by( 'email_id', 'admin_order_notice' );

		$this->assertTrue( (bool) $receipt && $receipt->is_enabled(), 'The order receipt must be enabled.' );
		$this->assertTrue( (bool) $notice && $notice->is_enabled(), 'The admin order notice must be enabled.' );

		$this->assert_order_still_awaits_its_emails();
	}

	/**
	 * Asserts both send flags are still on the order.
	 */
	private function assert_order_still_awaits_its_emails() {
		$this->assertTrue(
			metadata_exists( 'edd_order', $this->order_id, '_edd_should_send_order_receipt' ),
			'The receipt flag must still be on the order.'
		);
		$this->assertTrue(
			metadata_exists( 'edd_order', $this->order_id, '_edd_should_send_admin_order_notice' ),
			'The admin notice flag must still be on the order.'
		);
	}

	/**
	 * Asserts both send flags were consumed.
	 */
	private function assert_order_emails_were_consumed() {
		$this->assertFalse(
			metadata_exists( 'edd_order', $this->order_id, '_edd_should_send_order_receipt' ),
			'The receipt flag must be consumed once the receipt is sent.'
		);
		$this->assertFalse(
			metadata_exists( 'edd_order', $this->order_id, '_edd_should_send_admin_order_notice' ),
			'The admin notice flag must be consumed once the notice is sent.'
		);
	}

	/**
	 * Asserts the customer's receipt and the store's admin notice were both sent.
	 *
	 * The store's address and the test customer's are the same string, so asserting on the
	 * pooled recipients would be satisfied by either email on its own. The two are told apart
	 * by the shape wp_mail() was handed: the receipt passes one address as a string
	 * (src/Emails/Types/OrderReceipt.php), the notice passes an array (AdminOrderNotice.php).
	 */
	private function assert_both_emails_were_sent() {
		$captured = Helpers\EDD_Helper_Email::captured_mail();

		$this->assertCount( 2, $captured, 'The receipt and the admin notice must both be sent.' );

		$receipts = array_values(
			array_filter(
				$captured,
				function ( $message ) {
					return is_string( $message['to'] );
				}
			)
		);
		$notices  = array_values(
			array_filter(
				$captured,
				function ( $message ) {
					return is_array( $message['to'] );
				}
			)
		);

		$this->assertCount( 1, $receipts, 'Exactly one email must be addressed to a single recipient.' );
		$this->assertSame( $this->customer_email, $receipts[0]['to'], 'The receipt must go to the order customer.' );

		$this->assertCount( 1, $notices, 'Exactly one email must be addressed to the store.' );

		foreach ( edd_get_admin_notice_emails() as $admin_email ) {
			$this->assertContains(
				$admin_email,
				array_map( 'trim', $notices[0]['to'] ),
				'The store must receive the admin notice.'
			);
		}
	}

	/**
	 * Flattens the recipients of the captured messages.
	 *
	 * wp_mail() accepts a string or an array for `to`, and the two emails here do not use
	 * the same one.
	 *
	 * @param array $messages The captured wp_mail() argument arrays.
	 * @return array
	 */
	private function recipients_of( array $messages ) {
		$recipients = array();

		foreach ( $messages as $message ) {
			foreach ( (array) $message['to'] as $to ) {
				$recipients = array_merge( $recipients, array_map( 'trim', explode( ',', $to ) ) );
			}
		}

		return $recipients;
	}
}
