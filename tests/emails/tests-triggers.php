<?php
/**
 * Email trigger authorization tests.
 *
 * Resending a receipt mails a customer's order details and their working download links.
 * These tests assert that the request has to prove intent before that happens, and that
 * the recipient is the customer the order belongs to rather than anything the request
 * asks for.
 *
 */

namespace EDD\Tests\Emails;

use EDD\Tests\Helpers;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

// Required explicitly rather than left to the composer classmap, which CI restores from a
// cache keyed on composer.lock and therefore does not regenerate for a new helper file.
require_once dirname( __DIR__ ) . '/helpers/class-helper-email.php';

/**
 * @group edd_emails
 */
class Triggers extends EDD_UnitTestCase {

	/**
	 * A complete order and its customer's address.
	 *
	 * @var int
	 */
	protected $order_id;

	/**
	 * @var string
	 */
	protected $customer_email;

	/**
	 * The REQUEST_URI to restore after each test, or null if there was none.
	 *
	 * @var string|null
	 */
	protected $original_request_uri;

	public function setUp(): void {
		parent::setUp();

		// A fresh test database has no email rows, so the receipt has to be installed
		// and enabled before anything will send.
		Helpers\EDD_Helper_Email::enable( 'order_receipt' );
		Helpers\EDD_Helper_Email::start_capturing_mail();

		// The handler redirects when it finishes, and building that URL reads the current
		// request. A full-suite run has no request context, so supply one.
		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$_SERVER['REQUEST_URI']     = '/wp-admin/edit.php?post_type=download&page=edd-payment-history';

		$this->order_id = Helpers\EDD_Helper_Payment::create_simple_payment();
		edd_update_order_status( $this->order_id, 'complete' );

		$order                = edd_get_order( $this->order_id );
		$customer             = edd_get_customer( $order->customer_id );
		$this->customer_email = $customer ? $customer->email : $order->email;
	}

	public function tearDown(): void {
		Helpers\EDD_Helper_Email::stop_capturing_mail();

		if ( is_null( $this->original_request_uri ) ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * A resend request that cannot prove intent must not send anything.
	 */
	public function test_resend_requires_a_nonce() {
		$this->set_current_user_role( 'shop_worker' );

		$died = false;
		try {
			( new \EDD\Emails\Triggers() )->resend_order_receipt( array( 'purchase_id' => $this->order_id ) );
		} catch ( \WPDieException $e ) {
			$died = true;
		}

		$this->assertTrue( $died, 'The request must be refused.' );
		$this->assertEmpty( Helpers\EDD_Helper_Email::captured_mail(), 'No mail may be sent by a request that cannot prove intent.' );
	}

	/**
	 * An address which does not belong to this order's customer must be refused.
	 */
	public function test_resend_refuses_an_address_not_belonging_to_the_customer() {
		$this->set_current_user_role( 'shop_worker' );

		$died = false;
		try {
			( new \EDD\Emails\Triggers() )->resend_order_receipt(
				array(
					'purchase_id' => $this->order_id,
					'email'       => 'somebody-else@example.test',
					'_wpnonce'    => wp_create_nonce( 'edd-resend-receipt' ),
				)
			);
		} catch ( \WPDieException $e ) {
			$died = true;
		}

		$this->assertTrue( $died, 'The request must be refused.' );
		$this->assertEmpty( Helpers\EDD_Helper_Email::captured_mail(), 'No mail may be sent to an address outside the order.' );
	}

	/**
	 * The store owner may still resend to another address belonging to the customer.
	 *
	 * This is the behavior the order details screen offers when a customer has more than one
	 * address on file, so the allow list has to permit it.
	 */
	public function test_resend_allows_another_address_belonging_to_the_customer() {
		$this->set_current_user_role( 'shop_worker' );

		$secondary = 'secondary-address@example.test';
		edd_add_customer_email_address(
			array(
				'customer_id' => edd_get_order( $this->order_id )->customer_id,
				'email'       => $secondary,
			)
		);

		( new \EDD\Emails\Triggers() )->resend_order_receipt(
			array(
				'purchase_id' => $this->order_id,
				'email'       => $secondary,
				'_wpnonce'    => wp_create_nonce( 'edd-resend-receipt' ),
			)
		);

		$this->assertCount( 1, Helpers\EDD_Helper_Email::captured_mail(), 'Exactly one receipt should be sent.' );
		$this->assertSame(
			$secondary,
			Helpers\EDD_Helper_Email::captured_mail()[0]['to'],
			'The receipt must go to the selected address.'
		);
	}

	/**
	 * The order details screen must offer every address the handler will accept.
	 *
	 * The UI list and the allow list come from the same method, so a customer with a second
	 * address sees it offered and can have the receipt sent there.
	 */
	public function test_order_details_offers_every_allowed_address() {
		require_once EDD_PLUGIN_DIR . 'includes/admin/payments/orders.php';

		$this->set_current_user_role( 'administrator' );

		$secondary = 'also-mine@example.test';
		edd_add_customer_email_address(
			array(
				'customer_id' => edd_get_order( $this->order_id )->customer_id,
				'email'       => $secondary,
			)
		);

		$order   = edd_get_order( $this->order_id );
		$allowed = $order->get_receipt_emails();

		$this->assertContains( $secondary, $allowed, 'The secondary address must be allowed.' );
		$this->assertContains( $this->customer_email, $allowed, 'The primary address must be allowed.' );

		ob_start();
		edd_order_details_email( $order );
		$html = ob_get_clean();

		foreach ( $allowed as $email ) {
			$this->assertStringContainsString(
				rawurlencode( $email ),
				$html,
				sprintf( 'The screen must offer %s.', $email )
			);
		}
	}

	/**
	 * The control: a properly nonced resend still mails the customer.
	 */
	public function test_resend_mails_the_order_customer() {
		$this->set_current_user_role( 'shop_worker' );

		( new \EDD\Emails\Triggers() )->resend_order_receipt(
			array(
				'purchase_id' => $this->order_id,
				'_wpnonce'    => wp_create_nonce( 'edd-resend-receipt' ),
			)
		);

		$this->assertCount( 1, Helpers\EDD_Helper_Email::captured_mail(), 'The receipt must still be sent.' );
		$this->assertSame( $this->customer_email, Helpers\EDD_Helper_Email::captured_mail()[0]['to'] );
	}

	/**
	 * The capability gate still stands on its own.
	 *
	 * Present so the nonce check cannot be written in a way that lets one gate
	 * substitute for the other.
	 */
	public function test_resend_requires_the_payments_capability() {
		$this->set_current_user_role( 'subscriber' );

		$died = false;
		try {
			( new \EDD\Emails\Triggers() )->resend_order_receipt(
				array(
					'purchase_id' => $this->order_id,
					'_wpnonce'    => wp_create_nonce( 'edd-resend-receipt' ),
				)
			);
		} catch ( \WPDieException $e ) {
			$died = true;
		}

		$this->assertTrue( $died, 'A caller without the capability must be refused.' );
		$this->assertEmpty( Helpers\EDD_Helper_Email::captured_mail(), 'No mail may be sent to an unauthorized caller.' );
	}

	/**
	 * The link the admin UI renders must carry what the handler requires.
	 *
	 * Without this the guard above would make the feature unusable from the UI.
	 */
	public function test_resend_link_carries_a_nonce() {
		require_once EDD_PLUGIN_DIR . 'includes/admin/payments/class-payments-table.php';

		$this->set_current_user_role( 'administrator' );

		$table = new \EDD_Payment_History_Table();
		$html  = $table->column_number( edd_get_order( $this->order_id ) );

		$this->assertStringContainsString( 'edd-action=email_links', $html, 'The resend link should be rendered.' );

		// Scope the assertion to the resend link itself: other row actions on this screen
		// carry their own nonces, so searching the whole row would pass regardless.
		preg_match( '/href="([^"]*edd-action=email_links[^"]*)"/', $html, $matches );

		$this->assertNotEmpty( $matches, 'The resend link href should be found.' );
		$this->assertStringContainsString(
			'_wpnonce',
			html_entity_decode( $matches[1] ),
			'The resend link must carry a nonce.'
		);
	}

	/**
	 * The order details screen's resend link must carry a nonce too.
	 *
	 * Two places in the admin build this link, and the handler's guard applies to both, so
	 * both have to be covered or the feature breaks on one screen.
	 */
	public function test_order_details_resend_link_carries_a_nonce() {
		require_once EDD_PLUGIN_DIR . 'includes/admin/payments/orders.php';

		$this->set_current_user_role( 'administrator' );

		ob_start();
		edd_order_details_email( edd_get_order( $this->order_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'edd-action=email_links', $html, 'The resend link should be rendered.' );

		preg_match( '/href="([^"]*edd-action=email_links[^"]*)"/', $html, $matches );

		$this->assertNotEmpty( $matches, 'The resend link href should be found.' );
		$this->assertStringContainsString(
			'_wpnonce',
			html_entity_decode( $matches[1] ),
			'The order details resend link must carry a nonce.'
		);
	}

	/**
	 * Signs in a fresh user holding the given role.
	 *
	 * The payments capability is granted explicitly for shop_worker so these cases test
	 * the handler's gates rather than how EDD composes its roles.
	 *
	 * @param string $role The role to sign in as.
	 */
	private function set_current_user_role( $role ) {
		$user = new \WP_User( $this->factory->user->create( array( 'role' => $role ) ) );

		if ( 'shop_worker' === $role ) {
			$user->add_cap( 'edit_shop_payments' );
		}

		wp_set_current_user( $user->ID );
	}
}
