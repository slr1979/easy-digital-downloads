<?php

namespace EDD\Tests\Session;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers;

/**
 * Purchase data session tests.
 *
 * @group edd_sessions
 */
class PurchaseData extends EDD_UnitTestCase {

	/**
	 * The REQUEST_URI captured before a test forged it, restored in teardown.
	 *
	 * @var string|null
	 */
	private $original_request_uri;

	public function setUp(): void {
		parent::setUp();
		$_POST = array();
		wp_set_current_user( 0 );

		$this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;

		/*
		 * start() refuses a checkout that validation has already errored on, and both the
		 * error bag and edd_resume_payment live in the session, which outlives a single
		 * test file. A resume payment left behind by an earlier test makes
		 * edd_recovery_verify_logged_in() raise recovery_requires_login here, so both are
		 * reset before every test in this file.
		 */
		edd_clear_errors();
		EDD()->session->set( 'edd_resume_payment', null );
	}

	public function tearDown(): void {
		parent::tearDown();
		wp_set_current_user( 0 );
		$_POST = array();
		edd_set_purchase_session( null );
		edd_clear_errors();
		edd_empty_cart();
		EDD()->session->set( 'email_validated', null );
		EDD()->session->set( 'edd_resume_payment', null );

		if ( is_null( $this->original_request_uri ) ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}
	}

	public function test_edd_get_purchase_session_logged_in_user() {

		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		$_POST = array(
			'edd_first' => 'John',
			'edd_last'  => 'Doe',
			'edd_email' => 'john@doe.example',
		);
		$purchase_session = \EDD\Sessions\PurchaseData::start( false );
		unset( $purchase_session['card_info'] );
		unset( $purchase_session['post_data'] );

		$this->assertEquals( $user_id, $purchase_session['user_info']['id'] );
		$this->assertEquals( $_POST['edd_email'], $purchase_session['user_info']['email'] );
		$this->assertEquals( $_POST['edd_email'], $purchase_session['user_email'] );

		$this->assertEquals( $purchase_session, \EDD\Sessions\PurchaseData::get() );
	}

	public function test_edd_get_purchase_session_guest() {

		$_POST = array(
			'edd_first' => 'John',
			'edd_last'  => 'Doe',
			'edd_email' => 'guest@edd.local',
		);

		$purchase_session = \EDD\Sessions\PurchaseData::start();
		unset( $purchase_session['card_info'] );
		unset( $purchase_session['post_data'] );

		$this->assertEmpty( $purchase_session['user_info']['id'] );
		$this->assertEquals( $_POST['edd_email'], $purchase_session['user_info']['email'] );
		$this->assertEquals( $_POST['edd_email'], $purchase_session['user_email'] );

		$this->assertEquals( $purchase_session, \EDD\Sessions\PurchaseData::get() );
	}

	public function test_edd_get_purchase_session_get() {

		$_POST = array(
			'edd_first' => 'John',
			'edd_last'  => 'Doe',
			'edd_email' => 'guest@edd.local',
		);

		$purchase_session = \EDD\Sessions\PurchaseData::get();

		$this->assertEmpty( $purchase_session['user_info']['id'] );
		$this->assertEquals( $_POST['edd_email'], $purchase_session['user_info']['email'] );
		$this->assertEquals( $_POST['edd_email'], $purchase_session['user_email'] );
	}

	public function test_edd_get_purchase_session_add_to_cart_is_null() {

		$_POST = array(
			'edd_first' => 'John',
			'edd_last'  => 'Doe',
			'edd_email' => 'guest2@edd.local',
		);

		$this->assertNotEmpty( \EDD\Sessions\PurchaseData::get() );

		$download = Helpers\EDD_Helper_Download::create_simple_download();
		edd_add_to_cart( $download->ID );

		$this->assertEmpty( edd_get_purchase_session() );
	}

	/**
	 * A checkout the store has already decided to refuse must not write to the
	 * customer record it was submitted against.
	 */
	public function test_rejected_checkout_writes_nothing() {
		$fixture = $this->create_registered_customer();
		$this->fill_cart();

		$_POST = $this->get_submitted_checkout( 'registered@edd.local' );

		$purchase_data = \EDD\Sessions\PurchaseData::start( false );

		// The store refused this checkout, and that refusal is the only error in play.
		$this->assertSame( array( 'email_used' ), array_keys( edd_get_errors() ) );
		$this->assertNull( $purchase_data );

		// And nothing was written to the record it was submitted against.
		$this->assertSame( 'Bob Buyer', edd_get_customer( $fixture['customer_id'] )->name );
		$this->assertCount( 1, $this->get_customer_addresses( $fixture['customer_id'] ) );
	}

	/**
	 * A validated-email marker issued for some other address does not skip the
	 * refusal for a registered customer's address.
	 */
	public function test_validated_marker_for_another_address_does_not_skip_the_refusal() {
		$fixture = $this->create_registered_customer();
		$this->fill_cart();

		EDD()->session->set( 'email_validated', 'throwaway@edd.local' );
		$this->assertSame( 'throwaway@edd.local', EDD()->session->get( 'email_validated' ) );

		$_POST = $this->get_submitted_checkout( 'registered@edd.local' );

		$purchase_data = \EDD\Sessions\PurchaseData::start( false );

		$errors = edd_get_errors();
		$this->assertIsArray( $errors, 'The existing-account check did not run at all.' );
		$this->assertSame( array( 'email_used' ), array_keys( $errors ) );
		$this->assertNull( $purchase_data );
		$this->assertSame( 'Bob Buyer', edd_get_customer( $fixture['customer_id'] )->name );
		$this->assertCount( 1, $this->get_customer_addresses( $fixture['customer_id'] ) );
	}

	/**
	 * Any error in the bag after the user error checks stops the customer write,
	 * not just the email_used check.
	 */
	public function test_start_returns_before_the_customer_write_on_any_user_error() {
		$customer_id = $this->create_guest_customer();

		$this->fill_cart();

		$_POST = $this->get_submitted_checkout( 'guest-customer@edd.local' );

		add_action( 'edd_checkout_user_error_checks', array( $this, 'set_synthetic_error' ) );
		$purchase_data = \EDD\Sessions\PurchaseData::start( false );
		remove_action( 'edd_checkout_user_error_checks', array( $this, 'set_synthetic_error' ) );

		// The synthetic error is the only error in play, so it is what stopped the write.
		$this->assertSame( array( 'synthetic_error' ), array_keys( edd_get_errors() ) );
		$this->assertNull( $purchase_data );
		$this->assertSame( 'Prior Guest', edd_get_customer( $customer_id )->name );
	}

	/**
	 * An error raised on the field validation hook also stops the customer write,
	 * without skipping the second hook on the way there.
	 *
	 * The second hook must still fire: \EDD\Gateways\PayPal\send_ajax_errors() is
	 * registered on it and is what returns a validation message to the form-based
	 * PayPal Commerce buttons.
	 */
	public function test_start_returns_on_a_field_error_without_skipping_the_user_checks() {
		$customer_id = $this->create_guest_customer();
		$this->fill_cart();

		$_POST = $this->get_submitted_checkout( 'guest-customer@edd.local' );

		$user_checks_before = did_action( 'edd_checkout_user_error_checks' );

		add_action( 'edd_checkout_error_checks', array( $this, 'set_synthetic_error' ) );
		$purchase_data = \EDD\Sessions\PurchaseData::start( false );
		remove_action( 'edd_checkout_error_checks', array( $this, 'set_synthetic_error' ) );

		// The synthetic error is the only error in play, so it is what stopped the flow.
		$this->assertSame( array( 'synthetic_error' ), array_keys( edd_get_errors() ) );
		$this->assertNull( $purchase_data );
		$this->assertSame( 'Prior Guest', edd_get_customer( $customer_id )->name );

		// The gateways that serialize checkout errors on the second hook still get their turn.
		$this->assertGreaterThan( $user_checks_before, did_action( 'edd_checkout_user_error_checks' ) );
	}

	/**
	 * An accepted checkout still cannot rewrite the identity of a customer record
	 * the requester is not logged in as.
	 *
	 * PurchaseData::set() is called directly here because that is how the PayPal
	 * REST checkout controllers reach it, with no error in the bag at all.
	 */
	public function test_guest_cannot_rewrite_an_existing_customer_identity() {
		$fixture = $this->create_registered_customer();
		$this->fill_cart();

		$purchase_data = \EDD\Sessions\PurchaseData::set(
			$this->get_valid_data(),
			$this->get_user_data( 0, 'registered@edd.local', 'Submitted', 'Name' )
		);

		// No error was raised, and the purchase still resolves to the submitted
		// address, so an order would attach to that customer's record.
		$this->assertEmpty( edd_get_errors() );
		$this->assertSame( 'registered@edd.local', $purchase_data['user_email'] );

		$this->assertSame( 'Bob Buyer', edd_get_customer( $fixture['customer_id'] )->name );
		$this->assertCount( 1, $this->get_customer_addresses( $fixture['customer_id'] ) );
	}

	/**
	 * The customer a record belongs to can still update their own name and add a
	 * new address at checkout.
	 */
	public function test_customer_can_still_update_their_own_record() {
		$fixture = $this->create_registered_customer();
		wp_set_current_user( $fixture['user_id'] );
		$this->fill_cart();

		\EDD\Sessions\PurchaseData::set(
			$this->get_valid_data(),
			$this->get_user_data( $fixture['user_id'], 'registered@edd.local', 'Robert', 'Buyer' )
		);

		$this->assertSame( 'Robert Buyer', edd_get_customer( $fixture['customer_id'] )->name );
		$this->assertCount( 2, $this->get_customer_addresses( $fixture['customer_id'] ) );
	}

	/**
	 * The PayPal REST checkout path runs the same user error checks a form
	 * submission does, and refuses when they raise an error.
	 *
	 * No PayPal request is made or mocked: PurchaseUser::resolve() is the local
	 * function the REST controllers call before PurchaseData::set().
	 */
	public function test_paypal_purchase_user_runs_the_user_error_checks() {
		$fixture = $this->create_registered_customer();
		$this->fill_cart();
		$this->make_request_rest();

		$user_checks_before = did_action( 'edd_checkout_user_error_checks' );

		// The form flow's JSON error sender stays on this hook throughout: it is the
		// sender's own REST guard, not this caller, that keeps the envelope intact.
		$ajax_errors = 'EDD\Gateways\PayPal\send_ajax_errors';
		$this->assertNotFalse( has_action( 'edd_checkout_user_error_checks', $ajax_errors ) );

		$resolved = \EDD\Gateways\PayPal\V3\PurchaseUser::resolve(
			array(),
			'registered@edd.local',
			'Submitted',
			'Name',
			$this->get_submitted_address()
		);

		$this->assertGreaterThan( $user_checks_before, did_action( 'edd_checkout_user_error_checks' ) );
		$this->assertWPError( $resolved );
		$this->assertStringContainsString( 'Email already used', $resolved->get_error_message() );
		$this->assertSame( 'Bob Buyer', edd_get_customer( $fixture['customer_id'] )->name );

		// And it was never unhooked to get that.
		$this->assertNotFalse( has_action( 'edd_checkout_user_error_checks', $ajax_errors ) );
	}

	/**
	 * Any error raised by the user error checks refuses the PayPal REST path, not
	 * just the email_used check.
	 */
	public function test_paypal_purchase_user_returns_error_for_any_user_error_check() {
		$customer_id = $this->create_guest_customer();

		$this->fill_cart();
		$this->make_request_rest();

		add_action( 'edd_checkout_user_error_checks', array( $this, 'set_synthetic_error' ) );
		$resolved = \EDD\Gateways\PayPal\V3\PurchaseUser::resolve(
			array(),
			'guest-customer@edd.local',
			'Submitted',
			'Name',
			$this->get_submitted_address()
		);
		remove_action( 'edd_checkout_user_error_checks', array( $this, 'set_synthetic_error' ) );

		$this->assertWPError( $resolved );
		$this->assertSame( 'Prior Guest', edd_get_customer( $customer_id )->name );
	}

	/**
	 * On a REST request the JSON error sender returns without ending the request,
	 * and leaves the error for the controller to put in its own response.
	 *
	 * This covers every REST caller of the checkout hooks, including the ones this
	 * plugin does not own.
	 */
	public function test_send_ajax_errors_leaves_a_rest_request_to_its_controller() {
		$this->make_request_rest();
		edd_set_error( 'synthetic_error', 'synthetic' );

		// Assert the fixture: there is an error for it to send, under the gateway it sends for.
		$this->assertSame( array( 'synthetic_error' ), array_keys( edd_get_errors() ) );

		\EDD\Gateways\PayPal\send_ajax_errors( array(), array( 'gateway' => 'paypal_commerce' ), array() );

		$this->assertSame( array( 'synthetic_error' ), array_keys( edd_get_errors() ) );
	}

	/**
	 * The form submission it exists for still gets its JSON response, which is what
	 * ends the request.
	 *
	 * wp_doing_ajax() is filtered on because that is what it is on the admin-ajax
	 * request this runs on. That also sends wp_die() through wp_die_ajax_handler,
	 * which the suite's shims do not cover, so it is pointed at the same handler here.
	 */
	public function test_send_ajax_errors_still_sends_json_for_a_form_submission() {
		$_SERVER['REQUEST_URI'] = '/checkout/';
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_test_die_handler' ) );
		edd_set_error( 'synthetic_error', 'synthetic' );

		// Assert the fixture: this is the request shape the guard must not catch.
		$this->assertFalse( \EDD\Utils\Request::is_request( 'rest' ) );
		$this->assertTrue( wp_doing_ajax() );

		$ended = false;
		ob_start();
		try {
			\EDD\Gateways\PayPal\send_ajax_errors( array(), array( 'gateway' => 'paypal_commerce' ), array() );
		} catch ( \WPDieException $e ) {
			$ended = true;
		} finally {
			$response = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'get_test_die_handler' ) );
		}

		// It ended the request, and took the errors with it into the JSON response.
		$this->assertTrue( $ended, 'send_ajax_errors() did not end the request for a form submission.' );
		$this->assertEmpty( edd_get_errors() );

		$decoded = json_decode( $response, true );
		$this->assertFalse( $decoded['success'] );
		$this->assertStringContainsString( 'edd_error_synthetic_error', $decoded['data'] );
	}

	/**
	 * Returns the suite's throwing wp_die handler, for the Ajax branch of wp_die().
	 *
	 * @return string
	 */
	public function get_test_die_handler() {
		return '_edd_test_die_handler';
	}

	/**
	 * Raises an error on whichever validation hook a test registers it on, so the
	 * tests can state the rule as "any error stops the write".
	 *
	 * @return void
	 */
	public function set_synthetic_error() {
		edd_set_error( 'synthetic_error', 'synthetic' );
	}

	/**
	 * Creates a registered customer with one billing address on file.
	 *
	 * @param string $email The email address for the user and customer.
	 * @return array The WordPress user ID and the EDD customer ID.
	 */
	private function create_registered_customer( $email = 'registered@edd.local' ) {
		$user_id     = $this->factory->user->create( array( 'user_email' => $email ) );
		$customer_id = edd_add_customer(
			array(
				'email'   => $email,
				'user_id' => $user_id,
				'name'    => 'Bob Buyer',
			)
		);
		edd_add_customer_address(
			array(
				'customer_id' => $customer_id,
				'type'        => 'billing',
				'address'     => '1 Test Street',
				'city'        => 'Testville',
				'region'      => 'AZ',
				'country'     => 'US',
				'postal_code' => '85001',
				'is_primary'  => true,
			)
		);

		// Assert the fixture before asserting anything about the behavior.
		$this->assertNotEmpty( $customer_id );
		$this->assertSame( 'Bob Buyer', edd_get_customer( $customer_id )->name );
		$this->assertCount( 1, $this->get_customer_addresses( $customer_id ) );

		return array(
			'user_id'     => $user_id,
			'customer_id' => $customer_id,
		);
	}

	/**
	 * Creates a customer record with no linked WordPress user, so nothing about the
	 * checkout can raise the existing-account error.
	 *
	 * @return int The EDD customer ID.
	 */
	private function create_guest_customer() {
		$customer_id = edd_add_customer(
			array(
				'email' => 'guest-customer@edd.local',
				'name'  => 'Prior Guest',
			)
		);

		// Assert the fixture before asserting anything about the behavior.
		$this->assertNotEmpty( $customer_id );
		$this->assertSame( 'Prior Guest', edd_get_customer( $customer_id )->name );
		$this->assertSame( 0, (int) edd_get_customer( $customer_id )->user_id );

		return $customer_id;
	}

	/**
	 * Makes the request look like the REST request the PayPal controllers run in.
	 *
	 * @return void
	 */
	private function make_request_rest() {
		$_SERVER['REQUEST_URI'] = '/' . trailingslashit( rest_get_url_prefix() ) . 'edd/v3/paypal/order';

		// Assert the fixture: the code under test reads this through Request.
		$this->assertTrue( \EDD\Utils\Request::is_request( 'rest' ) );
	}

	/**
	 * Puts a product in the cart so checkout validation has something to run against.
	 *
	 * @return void
	 */
	private function fill_cart() {
		$download = Helpers\EDD_Helper_Download::create_simple_download();
		edd_add_to_cart( $download->ID );

		$this->assertNotEmpty( edd_get_cart_contents() );
	}

	/**
	 * Gets every address on a customer's record.
	 *
	 * @param int $customer_id The customer ID.
	 * @return array
	 */
	private function get_customer_addresses( $customer_id ) {
		return edd_get_customer_addresses(
			array(
				'customer_id' => $customer_id,
				'number'      => 99,
			)
		);
	}

	/**
	 * The checkout fields a request submits, with a name and address of its own.
	 *
	 * @param string $email The email address submitted with the checkout.
	 * @return array
	 */
	private function get_submitted_checkout( $email ) {
		return array(
			'edd_email'       => $email,
			'edd_first'       => 'Submitted',
			'edd_last'        => 'Name',
			'card_address'    => '9 Submitted Lane',
			'card_city'       => 'Submittedville',
			'card_state'      => 'CA',
			'card_zip'        => '90001',
			'billing_country' => 'US',
		);
	}

	/**
	 * The address a request submits, in the shape PurchaseData::set() consumes.
	 *
	 * @return array
	 */
	private function get_submitted_address() {
		return array(
			'line1'   => '9 Submitted Lane',
			'city'    => 'Submittedville',
			'state'   => 'CA',
			'country' => 'US',
			'zip'     => '90001',
		);
	}

	/**
	 * Minimal valid data for a direct PurchaseData::set() call.
	 *
	 * @return array
	 */
	private function get_valid_data() {
		return array(
			'gateway'  => 'manual',
			'discount' => 'none',
			'cc_info'  => array(),
		);
	}

	/**
	 * Resolved user data for a direct PurchaseData::set() call.
	 *
	 * @param int    $user_id    The resolved user ID, or 0 for a guest.
	 * @param string $email      The submitted email address.
	 * @param string $first_name The submitted first name.
	 * @param string $last_name  The submitted last name.
	 * @return array
	 */
	private function get_user_data( $user_id, $email, $first_name, $last_name ) {
		return array(
			'user_id'    => $user_id,
			'user_email' => $email,
			'user_first' => $first_name,
			'user_last'  => $last_name,
			'address'    => $this->get_submitted_address(),
		);
	}
}
