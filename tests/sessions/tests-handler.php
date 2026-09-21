<?php
namespace EDD\Tests\Sessions;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Payment;

class Handler extends EDD_UnitTestCase {

	private static $order;
	private static $user_id;
	private static $customer_id;
	private static $checkout_page_id;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$order_id    = EDD_Helper_Payment::create_simple_payment();
		self::$order = edd_get_order( $order_id );

		// Create a test user and customer for set_customer tests
		self::$user_id = wp_create_user( 'testuser', 'password', 'test@example.com' );
		self::$customer_id = edd_add_customer( array(
			'user_id'    => self::$user_id,
			'name'       => 'John Doe',
			'email'      => 'test@example.com',
		) );

		// Create checkout page for set_customer tests
		self::$checkout_page_id = wp_insert_post( array(
			'post_title'   => 'Checkout',
			'post_content' => '[download_checkout]',
			'post_status'  => 'publish',
			'post_type'    => 'page'
		) );

		// Set the checkout page in EDD options
		edd_update_option( 'purchase_page', self::$checkout_page_id );
	}

	public function test_session_type_is_db() {
		$this->assertfalse( EDD()->session->use_php_sessions() );
	}

	public function test_session_component_exists() {
		$component = edd_get_component( 'session' );
		$this->assertInstanceOf( '\\EDD\\Component', $component );
	}

	public function test_set() {
		$this->assertEquals( 'bar', EDD()->session->set( 'foo', 'bar' ) );
	}

	public function test_get() {
		$this->assertEquals( 'bar', EDD()->session->get( 'foo' ) );
	}

	public function test_set_new_order_purchase_key_in_session() {
		$purchase_session =array(
			'purchase_key' => self::$order->payment_key,
		);
		edd_set_purchase_session( $purchase_session );

		$session = edd_get_purchase_session();
		$this->assertEquals( self::$order->payment_key, $session['purchase_key'] );
	}

	public function test_should_start_session() {

		$blacklist = EDD()->session->get_blacklist();

		foreach( $blacklist as $uri ) {
			$this->go_to( '/' . $uri );
			$this->assertFalse( EDD()->session->should_start_session() );
		}
	}

	public function test_use_php_sessions_is_false() {
		delete_option( 'edd_session_handling' );
		$this->assertfalse( EDD()->session->use_php_sessions() );
	}

	public function test_set_customer_returns_defaults_when_not_logged_in() {
		// Ensure user is logged out
		wp_set_current_user( 0 );

		EDD()->session->set( 'customer', null );

		$result = \EDD\Sessions\Customer::set();

		// Should return default values when not logged in
		$expected_defaults = array(
			'customer_id' => '',
			'user_id'     => '',
			'name'        => '',
			'first_name'  => '',
			'last_name'   => '',
			'email'       => '',
			'address'     => array(
				'country' => '',
				'state'   => '',
				'city'    => '',
			),
		);

		$this->assertEquals( $expected_defaults, $result );
		$this->assertEquals( $expected_defaults, \EDD\Sessions\Customer::get() );
	}

	public function test_set_customer_overwrites_existing_session_data() {
		// Set existing customer data
		$existing_customer = array( 'id' => 999, 'email' => 'existing@example.com' );
		EDD()->session->set( 'customer', $existing_customer );

		// Login user
		wp_set_current_user( self::$user_id );

		$result = \EDD\Sessions\Customer::set();

		// Customer data should be overwritten with actual customer data
		$customer = edd_get_customer( self::$customer_id );
		$this->assertEquals( $customer->id, $result['customer_id'] );
		$this->assertEquals( $customer->email, $result['email'] );
		$this->assertNotEquals( $existing_customer, $result );
	}

	public function test_set_customer_returns_defaults_when_no_customer_found() {
		// Create a user without an EDD customer record
		$user_without_customer = wp_create_user( 'nocustomer', 'password', 'nocustomer@example.com' );

		// Login user without customer
		wp_set_current_user( $user_without_customer );

		$result = \EDD\Sessions\Customer::set();

		// Should return WP_User data when no EDD customer found
		$expected_result = array(
			'customer_id' => '',
			'user_id'     => (string) $user_without_customer,
			'name'        => 'nocustomer',
			'first_name'  => '',
			'last_name'   => '',
			'email'       => 'nocustomer@example.com',
			'address'     => array(
				'country' => '',
				'state'   => '',
				'city'    => '',
			),
		);

		$this->assertEquals( $expected_result, $result );

		// Clean up
		wp_delete_user( $user_without_customer );
	}

	public function test_set_customer_handles_single_name() {
		// Create customer with single name
		$single_name_user_id = wp_create_user( 'singlename', 'password', 'single@example.com' );
		$single_name_customer_id = edd_add_customer( array(
			'user_id' => $single_name_user_id,
			'name'    => 'Madonna',
			'email'   => 'single@example.com',
		) );

		// Login user
		wp_set_current_user( $single_name_user_id );

		$result = \EDD\Sessions\Customer::set();

		// Verify single name handling
		$this->assertEquals( 'Madonna', $result['first_name'] );
		$this->assertEquals( '', $result['last_name'] );
		$this->assertEquals( 'Madonna', $result['name'] );

		// Clean up
		edd_delete_customer( $single_name_customer_id );
		wp_delete_user( $single_name_user_id );
	}

	public function test_set_customer_handles_multiple_space_name() {
		// Create customer with multiple spaces in name
		$multi_space_user_id = wp_create_user( 'multispace', 'password', 'multi@example.com' );
		$multi_space_customer_id = edd_add_customer( array(
			'user_id' => $multi_space_user_id,
			'name'    => 'Mary Jane Watson Smith',
			'email'   => 'multi@example.com',
		) );

		// Login user
		wp_set_current_user( $multi_space_user_id );

		$result = \EDD\Sessions\Customer::set();

		// Verify multiple space name handling (explode with limit 2 should split into first name and rest)
		$this->assertEquals( 'Mary', $result['first_name'] );
		$this->assertEquals( 'Jane Watson Smith', $result['last_name'] );
		$this->assertEquals( 'Mary Jane Watson Smith', $result['name'] );

		// Clean up
		edd_delete_customer( $multi_space_customer_id );
		wp_delete_user( $multi_space_user_id );
	}

	public function test_get_customer_returns_session_data_when_exists() {
		wp_set_current_user( 0 );

		// Set customer data in session
		$customer_data = array(
			'customer_id' => 123,
			'user_id'     => 456,
			'name'        => 'Test User',
			'first_name'  => 'Test',
			'last_name'   => 'User',
			'email'       => 'test@example.com',
		);
		EDD()->session->set( 'customer', $customer_data );

		$result = \EDD\Sessions\Customer::get();

		// Every stored value is returned untouched.
		foreach ( $customer_data as $key => $value ) {
			$this->assertSame( $value, $result[ $key ] );
		}

		// The address key the session never held is filled in from the defaults.
		$this->assertSame(
			array(
				'country' => '',
				'state'   => '',
				'city'    => '',
			),
			$result['address']
		);
	}

	public function test_get_customer_calls_set_when_logged_in_and_no_session_data() {
		// Clear customer session
		EDD()->session->set( 'customer', null );

		// Login user
		wp_set_current_user( self::$user_id );

		$result = \EDD\Sessions\Customer::get();

		// Should have called set() and returned customer data
		$customer = edd_get_customer( self::$customer_id );
		$this->assertEquals( $customer->id, $result['customer_id'] );
		$this->assertEquals( $customer->email, $result['email'] );
	}

	public function test_get_customer_returns_defaults_when_not_logged_in_and_no_session_data() {
		// Clear customer session and logout
		EDD()->session->set( 'customer', null );
		wp_set_current_user( 0 );

		$result = \EDD\Sessions\Customer::get();

		$expected_defaults = array(
			'customer_id' => '',
			'user_id'     => '',
			'name'        => '',
			'first_name'  => '',
			'last_name'   => '',
			'email'       => '',
			'address'     => array(
				'country' => '',
				'state'   => '',
				'city'    => '',
			),
		);

		$this->assertEquals( $expected_defaults, $result );
	}

	public function test_set_customer_logged_in_user_without_edd_customer_during_checkout() {
		// Create a WordPress user without an EDD customer record
		$user_id = wp_create_user( 'checkoutuser', 'password', 'checkout@example.com' );

		// Add some user meta that would be populated in a real scenario
		update_user_meta( $user_id, 'first_name', 'Checkout' );
		update_user_meta( $user_id, 'last_name', 'User' );

		// Login the user
		wp_set_current_user( $user_id );

		// Clear any existing customer session data
		EDD()->session->set( 'customer', null );

		// This should trigger the maybe_set_customer_data method
		// and should now populate user data without causing deprecation warnings
		$result = \EDD\Sessions\Customer::set();

		// Should return WP_User data since no EDD customer exists but user is logged in
		$expected_result = array(
			'customer_id' => '',
			'user_id'     => (string) $user_id,
			'name'        => 'checkoutuser',
			'first_name'  => 'Checkout',
			'last_name'   => 'User',
			'email'       => 'checkout@example.com',
			'address'     => array(
				'country' => '',
				'state'   => '',
				'city'    => '',
			),
		);

		$this->assertEquals( $expected_result, $result );

		// Clean up
		wp_delete_user( $user_id );
	}

	public function test_set_customer_with_address_data() {
		// Clear customer session
		EDD()->session->set( 'customer', null );

		// Set address data
		$address_data = array(
			'address' => array(
				'state'   => 'CA',
				'country' => 'US',
				'city'    => 'Los Angeles',
			),
		);

		$result = \EDD\Sessions\Customer::set( $address_data );

		// Should include address data in result
		$this->assertArrayHasKey( 'address', $result );
		$this->assertEquals( 'CA', $result['address']['state'] );
		$this->assertEquals( 'US', $result['address']['country'] );
		$this->assertEquals( 'Los Angeles', $result['address']['city'] );
	}

	/**
	 * `set()` merged the address twice: once before the customer lookup and once after.
	 * The customer merge never carries an address, so the second pass had nothing to do.
	 * This covers the logged-in branch where that second pass lived.
	 */
	public function test_set_customer_merges_address_with_defaults_for_a_logged_in_customer() {
		wp_set_current_user( self::$user_id );
		EDD()->session->set( 'customer', null );

		$result = \EDD\Sessions\Customer::set(
			array(
				'address' => array( 'country' => 'CA' ),
			)
		);

		// Assert the fixture: the logged-in customer really was found and merged in.
		$customer = edd_get_customer( self::$customer_id );
		$this->assertEquals( $customer->id, $result['customer_id'] );

		$this->assertSame( 'CA', $result['address']['country'] );
		$this->assertArrayHasKey( 'state', $result['address'] );
		$this->assertArrayHasKey( 'city', $result['address'] );
	}

	public function test_set_customer_merges_address_with_defaults() {
		// Clear customer session
		EDD()->session->set( 'customer', null );

		// Set partial address data
		$address_data = array(
			'address' => array(
				'country' => 'CA',
			),
		);

		$result = \EDD\Sessions\Customer::set( $address_data );

		// Should merge with defaults
		$this->assertEquals( 'CA', $result['address']['country'] );
		$this->assertEquals( '', $result['address']['state'] );
	}

	public function test_get_customer_includes_address_data() {
		wp_set_current_user( 0 );

		// Set customer data with address in session
		$customer_data = array(
			'customer_id' => 123,
			'user_id'     => 456,
			'name'        => 'Test User',
			'first_name'  => 'Test',
			'last_name'   => 'User',
			'email'       => 'test@example.com',
			'address'     => array(
				'state'   => 'NY',
				'country' => 'US',
			),
		);
		EDD()->session->set( 'customer', $customer_data );

		$result = \EDD\Sessions\Customer::get();

		$this->assertArrayHasKey( 'address', $result );
		$this->assertEquals( 'NY', $result['address']['state'] );
		$this->assertEquals( 'US', $result['address']['country'] );

		// The city the session never held is filled in from the address defaults.
		$this->assertSame( '', $result['address']['city'] );
	}

	/**
	 * A `customer` session written directly, by an integration or gateway that stores only
	 * the field it knows about, never passes through `Customer::set()`. That makes `get()`
	 * the only place the defaults can be applied.
	 */
	public function test_get_customer_merges_defaults_when_session_written_directly() {
		wp_set_current_user( 0 );

		EDD()->session->set( 'customer', array( 'email' => 'guest@example.com' ) );

		// Assert the fixture: the session really does hold only the one key.
		$this->assertSame( array( 'email' => 'guest@example.com' ), EDD()->session->get( 'customer' ) );

		$result = \EDD\Sessions\Customer::get();

		foreach ( array( 'customer_id', 'user_id', 'name', 'first_name', 'last_name', 'email', 'address' ) as $key ) {
			$this->assertArrayHasKey( $key, $result, "Key {$key} is missing from the normalized session data." );
		}

		// The value that was stored survives the merge.
		$this->assertSame( 'guest@example.com', $result['email'] );
	}

	/**
	 * The nested address array needs its own merge: `wp_parse_args()` treats an existing
	 * `address` key as a complete value and does not recurse into it.
	 */
	public function test_get_customer_merges_address_defaults_when_session_written_directly() {
		wp_set_current_user( 0 );

		EDD()->session->set(
			'customer',
			array(
				'email'   => 'guest@example.com',
				'address' => array( 'country' => 'US' ),
			)
		);

		// Assert the fixture: the stored address really is partial.
		$this->assertSame( array( 'country' => 'US' ), EDD()->session->get( 'customer' )['address'] );

		$result = \EDD\Sessions\Customer::get();

		$this->assertSame( 'US', $result['address']['country'] );
		$this->assertArrayHasKey( 'state', $result['address'] );
		$this->assertArrayHasKey( 'city', $result['address'] );
	}

	/**
	 * Normalizing must not overwrite stored values with the empty defaults.
	 */
	public function test_get_customer_prefers_session_values_over_defaults() {
		wp_set_current_user( 0 );

		$stored = array(
			'customer_id' => 42,
			'first_name'  => 'Ada',
			'email'       => 'ada@example.com',
			'address'     => array( 'city' => 'London' ),
		);
		EDD()->session->set( 'customer', $stored );

		$result = \EDD\Sessions\Customer::get();

		$this->assertSame( 42, $result['customer_id'] );
		$this->assertSame( 'Ada', $result['first_name'] );
		$this->assertSame( 'ada@example.com', $result['email'] );
		$this->assertSame( 'London', $result['address']['city'] );

		// The keys the session did not carry come back empty, not absent.
		$this->assertSame( '', $result['last_name'] );
		$this->assertSame( '', $result['address']['country'] );
	}

	/**
	 * `Handler::sanitize()` stores a non-array value as a string, which the `array` return
	 * type on `get()` cannot satisfy.
	 */
	public function test_get_customer_returns_an_array_when_the_session_holds_a_scalar() {
		wp_set_current_user( 0 );

		EDD()->session->set( 'customer', 'unexpected' );

		// Assert the fixture: the session really is holding a scalar.
		$this->assertIsString( EDD()->session->get( 'customer' ) );

		// Left unguarded, wp_parse_str() would keep the scalar as a key of its own.
		$this->assertSame(
			array(
				'customer_id' => '',
				'user_id'     => '',
				'name'        => '',
				'first_name'  => '',
				'last_name'   => '',
				'email'       => '',
				'address'     => array(
					'country' => '',
					'state'   => '',
					'city'    => '',
				),
			),
			\EDD\Sessions\Customer::get()
		);
	}

	/**
	 * The guards removed in 008b861a62 also backfilled a logged-in user's details on every
	 * read. `maybe_set_customer_data()` only runs on the write path, so a session written
	 * directly renders empty name fields for a customer whose account already has them.
	 */
	public function test_get_customer_backfills_user_data_for_a_logged_in_user() {
		$user_id = wp_create_user( 'backfilluser', 'password', 'backfill@example.com' );
		update_user_meta( $user_id, 'first_name', 'Grace' );
		update_user_meta( $user_id, 'last_name', 'Hopper' );
		wp_set_current_user( $user_id );

		// An integration writes only the email it knows about.
		EDD()->session->set( 'customer', array( 'email' => 'backfill@example.com' ) );

		// Assert the fixture: the account really does carry the names.
		$this->assertSame( 'Grace', get_user_meta( $user_id, 'first_name', true ) );
		$this->assertSame( 'Hopper', get_user_meta( $user_id, 'last_name', true ) );

		$result = \EDD\Sessions\Customer::get();

		$this->assertSame( 'Grace', $result['first_name'] );
		$this->assertSame( 'Hopper', $result['last_name'] );
		$this->assertSame( (string) $user_id, (string) $result['user_id'] );

		wp_delete_user( $user_id );
	}

	/**
	 * The backfill fills gaps only: an integration that deliberately stored a different
	 * address, for a gift purchase or a separate contact address, keeps it.
	 */
	public function test_get_customer_does_not_clobber_a_stored_email_for_a_logged_in_user() {
		$user_id = wp_create_user( 'noclobberuser', 'password', 'account@example.com' );
		update_user_meta( $user_id, 'first_name', 'Grace' );
		wp_set_current_user( $user_id );

		EDD()->session->set( 'customer', array( 'email' => 'someone.else@example.com' ) );

		$result = \EDD\Sessions\Customer::get();

		// The stored email survives; the empty name is still filled from the account.
		$this->assertSame( 'someone.else@example.com', $result['email'] );
		$this->assertSame( 'Grace', $result['first_name'] );

		wp_delete_user( $user_id );
	}

	/**
	 * The backfill costs several queries, so it runs once per session rather than on every
	 * read. A session already carrying the current user has been through it and is served
	 * as stored, even where that leaves a value empty.
	 */
	public function test_get_customer_skips_the_backfill_once_the_session_carries_the_current_user() {
		$user_id = wp_create_user( 'skipbackfilluser', 'password', 'skip@example.com' );
		update_user_meta( $user_id, 'first_name', 'Grace' );
		wp_set_current_user( $user_id );

		// A completed session, minus a name the customer cleared on the checkout form.
		EDD()->session->set(
			'customer',
			array(
				'user_id'    => $user_id,
				'email'      => 'skip@example.com',
				'first_name' => '',
			)
		);

		$result = \EDD\Sessions\Customer::get();

		$this->assertSame( '', $result['first_name'] );

		wp_delete_user( $user_id );
	}

	/**
	 * Another user's ID is not a gap, so the backfill would never fill it and the gate would
	 * stay open for every later read. The read sets the ID rather than filling it.
	 */
	public function test_get_customer_replaces_another_users_id_for_a_logged_in_user() {
		$user_id = wp_create_user( 'convergeuser', 'password', 'converge@example.com' );
		wp_set_current_user( $user_id );

		// A session left behind by someone else, complete apart from whose it is.
		$stale_user_id = $user_id + 1000;
		EDD()->session->set(
			'customer',
			array(
				'user_id'    => $stale_user_id,
				'email'      => 'converge@example.com',
				'first_name' => 'Grace',
			)
		);

		// Assert the fixture: the stored ID really is not the current user.
		$this->assertSame( $stale_user_id, (int) EDD()->session->get( 'customer' )['user_id'] );

		$result = \EDD\Sessions\Customer::get();

		$this->assertSame( $user_id, (int) $result['user_id'] );

		// The gate is closed for the next read: the stored session carries the current user.
		$this->assertSame( $user_id, (int) EDD()->session->get( 'customer' )['user_id'] );

		wp_delete_user( $user_id );
	}

	/**
	 * A guest has nothing to backfill from, so the read stays a pure defaults merge and
	 * leaves the stored session alone. `maybe_set_customer_data()` would persist it.
	 */
	public function test_get_customer_does_not_write_the_session_for_a_guest() {
		wp_set_current_user( 0 );

		EDD()->session->set( 'customer', array( 'email' => 'guest@example.com' ) );

		$result = \EDD\Sessions\Customer::get();

		$this->assertSame( 'guest@example.com', $result['email'] );
		$this->assertSame( '', $result['first_name'] );
		$this->assertSame( '', $result['user_id'] );

		// The read normalized its return value without persisting it.
		$this->assertSame( array( 'email' => 'guest@example.com' ), EDD()->session->get( 'customer' ) );
	}

	/**
	 * A logged-out visitor on a session that still carries a user ID, left behind by a
	 * logout, fails the current-user check. Only the logged-in test keeps the read from
	 * persisting a session for a guest.
	 */
	public function test_get_customer_does_not_write_the_session_for_a_guest_with_a_stale_user_id() {
		wp_set_current_user( 0 );

		$stale = array(
			'user_id' => 99,
			'email'   => 'guest@example.com',
		);
		EDD()->session->set( 'customer', $stale );

		// Assert the fixture: the stored user ID is not the current (absent) user.
		$this->assertNotSame( get_current_user_id(), (int) EDD()->session->get( 'customer' )['user_id'] );

		\EDD\Sessions\Customer::get();

		$this->assertSame( $stale, EDD()->session->get( 'customer' ) );
	}

	/**
	 * The address array is only merged when it is actually an array; a scalar `address`
	 * would otherwise be run through `wp_parse_args()` as a query string.
	 */
	public function test_get_customer_replaces_a_scalar_address_with_the_defaults() {
		wp_set_current_user( 0 );

		EDD()->session->set(
			'customer',
			array(
				'email'   => 'guest@example.com',
				'address' => 'unexpected',
			)
		);

		$result = \EDD\Sessions\Customer::get();

		$this->assertIsArray( $result['address'] );
		$this->assertArrayHasKey( 'country', $result['address'] );
		$this->assertArrayHasKey( 'state', $result['address'] );
		$this->assertArrayHasKey( 'city', $result['address'] );
	}
}
