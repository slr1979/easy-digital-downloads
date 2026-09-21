<?php
/**
 * Refund form parser tests.
 *
 * The refund form's nonce is checked in two places: the AJAX handler which owns
 * authorization, and the PayPal pre-flight which runs ahead of it. Both read it through
 * FormParser, so these cover that check directly rather than only through its callers.
 *
 */

namespace EDD\Tests\Orders;

use EDD\Orders\Refunds\FormParser;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * @group edd_orders
 * @group edd_refunds
 */
class RefundFormParser extends EDD_UnitTestCase {

	public function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * A payload with no nonce field at all is not verified.
	 */
	public function test_verify_nonce_rejects_a_payload_without_the_field() {
		$this->assertFalse( FormParser::verify_nonce( array() ) );
		$this->assertFalse( FormParser::verify_nonce( array( 'refund_order_item' => array() ) ) );
	}

	/**
	 * An empty nonce value is not verified.
	 */
	public function test_verify_nonce_rejects_an_empty_value() {
		$this->assertFalse( FormParser::verify_nonce( array( FormParser::NONCE_ACTION => '' ) ) );
	}

	/**
	 * A value which is not a nonce is not verified.
	 */
	public function test_verify_nonce_rejects_a_malformed_value() {
		$this->assertFalse( FormParser::verify_nonce( array( FormParser::NONCE_ACTION => 'not-a-nonce' ) ) );
	}

	/**
	 * A nonce created for a different action is not verified.
	 */
	public function test_verify_nonce_rejects_another_actions_nonce() {
		$this->assertFalse(
			FormParser::verify_nonce( array( FormParser::NONCE_ACTION => wp_create_nonce( 'edd_process_refund_elsewhere' ) ) )
		);
	}

	/**
	 * A nonce created by a different user is not verified.
	 *
	 * wp_create_nonce() binds the user ID into the hash, so this is what stops a token
	 * captured from one session being replayed by another.
	 */
	public function test_verify_nonce_rejects_another_users_nonce() {
		wp_set_current_user( $this->factory->user->create() );
		$theirs = wp_create_nonce( FormParser::NONCE_ACTION );

		wp_set_current_user( $this->factory->user->create() );

		$this->assertFalse( FormParser::verify_nonce( array( FormParser::NONCE_ACTION => $theirs ) ) );
	}

	/**
	 * The control: the current user's nonce for this action is verified.
	 */
	public function test_verify_nonce_accepts_the_current_users_nonce() {
		wp_set_current_user( $this->factory->user->create() );

		$this->assertTrue(
			FormParser::verify_nonce( array( FormParser::NONCE_ACTION => wp_create_nonce( FormParser::NONCE_ACTION ) ) )
		);
	}
}
