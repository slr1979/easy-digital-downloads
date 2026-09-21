<?php

namespace EDD\Tests\Checkout;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Checkout error check tests.
 *
 * @group edd_checkout
 */
class Errors extends EDD_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		EDD()->session->set( 'email_validated', null );
		EDD()->session->set( 'checkout_email', null );
		edd_clear_errors();
	}

	public function tearDown(): void {
		edd_delete_option( 'banned_emails' );
		edd_clear_errors();
		EDD()->session->set( 'email_validated', null );
		EDD()->session->set( 'checkout_email', null );
		wp_set_current_user( 0 );
		$_POST = array();
	}

	public function test_edd_check_purchase_email_no_banned() {
		edd_check_purchase_email( array(), array() );
		$this->assertEmpty( edd_get_errors() );
	}

	public function test_edd_check_purchase_email_banned() {
		edd_update_option( 'banned_emails', array( 'test@edd.local' ) );
		edd_check_purchase_email( array(), array( 'edd_email' => 'test@edd.local' ) );

		$this->assertArrayHasKey( 'email_banned', edd_get_errors() );
	}

	public function test_edd_check_purchase_email_not_banned() {
		edd_update_option( 'banned_emails', array( 'test@edd.local' ) );
		edd_check_purchase_email( array(), array( 'edd_email' => 'newemail@edd.local' ) );

		$this->assertEmpty( edd_get_errors() );
	}

	public function test_edd_check_purchase_email_no_edd_email() {
		edd_update_option( 'banned_emails', array( 'test@edd.local' ) );
		edd_check_purchase_email( array(), array() );

		$this->assertEmpty( edd_get_errors() );
	}

	public function test_existing_user_is_error() {
		wp_logout();
		$user_id = $this->factory->user->create();
		$user    = get_user_by( 'id', $user_id );
		edd_add_customer(
			array(
				'email'   => $user->user_email,
				'user_id' => $user->ID,
				'name'    => $user->display_name,
			)
		);
		$errors  = new \EDD\Checkout\Errors();
		$errors->check_existing_users(
			$user,
			array(
				'guest_user_data' => array(
					'user_email' => $user->user_email,
				),
			),
			array()
		);

		$this->assertArrayHasKey( 'email_used', edd_get_errors() );
	}

	public function test_existing_email_different_customer_is_error() {
		wp_logout();
		$user_id = $this->factory->user->create();
		$user    = get_user_by( 'id', $user_id );
		$customer_id = edd_add_customer(
			array(
				'email'   => $user->user_email,
				'user_id' => $user->ID,
				'name'    => $user->display_name,
			)
		);
		$user_2_id = $this->factory->user->create();
		$user_2    = get_user_by( 'id', $user_2_id );
		edd_add_customer_email_address(
			array(
				'email'       => $user_2->user_email,
				'customer_id' => $customer_id,
			)
		);
		// log this user in
		wp_set_current_user( $user_2_id );
		$_POST['email'] = $user_2->user_email;
		$errors   = new \EDD\Checkout\Errors();
		$response = $errors->check_email_ajax();

		$this->assertInstanceOf( 'WP_Error', $response );

		edd_checkout_check_existing_email( array(), array() );

		$this->assertArrayHasKey( 'edd-customer-email-exists', edd_get_errors() );
	}

	public function test_existing_email_deleted_customer_is_okay() {
		wp_logout();
		$user_id = $this->factory->user->create();
		$user    = get_user_by( 'id', $user_id );
		$customer_id = edd_add_customer(
			array(
				'email'   => $user->user_email,
				'user_id' => $user->ID,
				'name'    => $user->display_name,
			)
		);
		$user_2_id = $this->factory->user->create();
		$user_2    = get_user_by( 'id', $user_2_id );
		edd_add_customer_email_address(
			array(
				'email'       => $user_2->user_email,
				'customer_id' => $customer_id,
				'type'        => 'secondary',
			)
		);
		edd_delete_customer( $customer_id );
		// log this user in
		wp_set_current_user( $user_2_id );
		$_POST['email'] = $user_2->user_email;
		$errors = new \EDD\Checkout\Errors();

		$this->assertTrue( $errors->check_email_ajax() );

		edd_checkout_check_existing_email( array(), array() );

		$this->assertEmpty( edd_get_errors() );
	}

	/**
	 * The validated-email marker names one address, so it must not wave through a
	 * checkout submitting a different one.
	 */
	public function test_validated_marker_does_not_cover_a_different_address_for_a_guest() {
		wp_set_current_user( 0 );
		$user = $this->create_registered_customer();

		// The marker was issued for an unrelated address that really was free.
		EDD()->session->set( 'email_validated', 'throwaway@edd.local' );
		$this->assertSame( 'throwaway@edd.local', EDD()->session->get( 'email_validated' ) );

		$errors = new \EDD\Checkout\Errors();
		$errors->check_existing_users(
			$user,
			array(
				'guest_user_data' => array(
					'user_email' => $user->user_email,
				),
			),
			array()
		);

		$errors = edd_get_errors();
		$this->assertIsArray( $errors, 'The existing-account check did not run at all.' );
		$this->assertArrayHasKey( 'email_used', $errors );
	}

	/**
	 * The marker still short-circuits the check for the address it was issued for,
	 * which is the whole point of the AJAX pre-check.
	 */
	public function test_validated_marker_still_covers_its_own_address_for_a_guest() {
		wp_set_current_user( 0 );
		$user = $this->create_registered_customer();

		EDD()->session->set( 'email_validated', $user->user_email );

		$errors = new \EDD\Checkout\Errors();
		$errors->check_existing_users(
			$user,
			array(
				'guest_user_data' => array(
					'user_email' => $user->user_email,
				),
			),
			array()
		);

		$this->assertEmpty( edd_get_errors() );
	}

	/**
	 * The logged in counterpart carries its own copy of the same marker check, so it
	 * needs the same treatment.
	 */
	public function test_validated_marker_does_not_cover_a_different_address_when_logged_in() {
		$registered = $this->create_registered_customer();

		$other_user_id = $this->factory->user->create();
		wp_set_current_user( $other_user_id );

		EDD()->session->set( 'email_validated', 'throwaway@edd.local' );

		edd_checkout_check_existing_email(
			array(
				'logged_in_user' => array(
					'user_email' => $registered->user_email,
				),
			),
			array()
		);

		$errors = edd_get_errors();
		$this->assertIsArray( $errors, 'The existing-email check did not run at all.' );
		$this->assertArrayHasKey( 'edd-customer-email-exists', $errors );
	}

	/**
	 * And the logged in counterpart still honors the marker for its own address.
	 */
	public function test_validated_marker_still_covers_its_own_address_when_logged_in() {
		$registered = $this->create_registered_customer();

		$other_user_id = $this->factory->user->create();
		wp_set_current_user( $other_user_id );

		EDD()->session->set( 'email_validated', $registered->user_email );

		edd_checkout_check_existing_email(
			array(
				'logged_in_user' => array(
					'user_email' => $registered->user_email,
				),
			),
			array()
		);

		$this->assertEmpty( edd_get_errors() );
	}

	/**
	 * Creates a registered user with a customer record attached.
	 *
	 * @return \WP_User
	 */
	private function create_registered_customer() {
		$user_id = $this->factory->user->create();
		$user    = get_user_by( 'id', $user_id );

		$customer_id = edd_add_customer(
			array(
				'email'   => $user->user_email,
				'user_id' => $user->ID,
				'name'    => $user->display_name,
			)
		);

		// Assert the fixture before asserting anything about the behavior.
		$this->assertNotEmpty( $customer_id );
		$this->assertNotEmpty( $user->user_email );

		return $user;
	}

	/**
	 * The address a shopper entered is recorded even when the check refuses it.
	 *
	 * A logged-out returning customer is told to log in, so `email_validated` is never
	 * written for them. Anything that needs their address later still has to be able to
	 * read one from the server rather than from a request.
	 */
	public function test_submitted_address_is_recorded_when_the_check_refuses_it() {
		wp_logout();
		$user = $this->create_registered_customer();

		$_POST['email'] = $user->user_email;
		$errors         = new \EDD\Checkout\Errors();
		$response       = $errors->check_email_ajax();

		// Assert the fixture: this address must be the refused kind, or the test proves nothing.
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'email_used', $response->get_error_code() );

		$this->assertNull(
			EDD()->session->get( 'email_validated' ),
			'A refused address must not be marked validated.'
		);
		$this->assertSame(
			$user->user_email,
			EDD()->session->get( 'checkout_email' ),
			'The address the shopper entered must still be recorded.'
		);
	}

	/**
	 * A malformed address is not recorded at all.
	 */
	public function test_a_malformed_address_is_not_recorded() {
		wp_logout();

		$_POST['email'] = 'not-an-email';
		( new \EDD\Checkout\Errors() )->check_email_ajax();

		$this->assertNull( EDD()->session->get( 'checkout_email' ) );
	}
}
