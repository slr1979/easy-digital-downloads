<?php
/**
 * Auto Register acts only on an order the platform built.
 *
 * These hooks fire inline while an order is created, completed or imported, never on a schedule,
 * so a cron-context check does not apply. What marks a real edd_built_order dispatch is the order
 * data edd_build_order() cannot reach its hook without; the rest is the shape of the ID.
 *
 * @package     EDD\Tests\Checkout
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Checkout;

use EDD\Actions\Router;
use EDD\Checkout\AutoRegister;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * AutoRegisterRequestGuard Tests
 *
 * @group edd_checkout
 */
class AutoRegisterRequestGuard extends EDD_UnitTestCase {

	const BUILT_HOOK = 'edd_built_order';
	const FREE_HOOK  = 'edd_free_downloads_post_complete_payment';

	/**
	 * The order ID.
	 *
	 * @var int
	 */
	private $order_id;

	/**
	 * The order's email, which has no WordPress account.
	 *
	 * @var string
	 */
	private $email;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// EDD_UnitTestCase::setUpBeforeClass() makes user 1 an administrator and assigns it to
		// $current_user, which would satisfy the handler's is_user_logged_in() early return.
		wp_set_current_user( 0 );

		edd_update_option( 'logged_in_only', 'auto' );

		// Assert the fixture: create_user_during_import() pins this filter and never unpins it,
		// and while pinned the login half of the behavior cannot be observed at all.
		$this->assertFalse(
			has_filter( 'edd_auto_register_login_user', '__return_false' ),
			'Fixture: the login opt-out filter must not already be pinned.'
		);

		$this->seed_order();
		$this->arm_login();
		$this->wire_subscriber();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		EDD()->session->set( 'edd_purchase', null );
		edd_delete_option( 'logged_in_only' );
		unset( $_GET['edd_action'], $_GET['id'] );

		parent::tearDown();
	}

	/**
	 * A router dispatch must not create an account, claim the order, or log anyone in.
	 *
	 * The handler's work is registering an account for an order's email, signing that account in
	 * and assigning it to the order. None of it belongs to a caller who only named the order.
	 */
	public function test_router_dispatch_does_not_take_over_the_order() {
		do_action( self::BUILT_HOOK, $this->request_args( 'built_order' ) );

		$this->assert_order_is_untouched( 'A request must not run Auto Register.' );
	}

	/**
	 * The router must refuse the hook before any listener runs.
	 *
	 * The other negative cases model the dispatch by calling do_action() with the array shape the
	 * routers build, which is what keeps the handler's own guard measured. This one drives
	 * edd_get_actions() from `$_GET`, where the router blocklist now refuses the hook outright, so
	 * the handler's guard is no longer the only thing standing in front of this path.
	 */
	public function test_router_refuses_to_dispatch_the_built_order_hook() {
		$dispatched = array();
		add_action(
			self::BUILT_HOOK,
			function ( $argument ) use ( &$dispatched ) {
				$dispatched[] = $argument;
			},
			1
		);

		// Assert the fixture on both halves: the refusal has to come from the blocklist, and a
		// dispatcher that routed nothing at all would satisfy the assertions below for free.
		$this->assertTrue(
			Router::is_blocked( self::BUILT_HOOK ),
			'Fixture: the blocklist must be what closes this path.'
		);
		$this->assertSame(
			array( 'routed' ),
			$this->route_sample_action(),
			'Fixture: the dispatcher must still route an action the blocklist does not cover.'
		);

		$_GET['edd_action'] = 'built_order';
		$_GET['id']         = (string) $this->order_id;

		edd_get_actions();

		$this->assertSame( array(), $dispatched, 'A blocked hook must not be dispatched from a request.' );

		$this->assert_order_is_untouched( 'A blocked dispatch must not run Auto Register.' );
	}

	/**
	 * A numeric order ID with no order data must be refused too.
	 *
	 * edd_build_order() cannot reach its dispatch without order data, so its absence means the
	 * call did not come from order building. This isolates that check from the ID-shape check:
	 * the argument here is exactly what a legitimate dispatch passes first.
	 */
	public function test_dispatch_without_order_data_is_refused() {
		do_action( self::BUILT_HOOK, $this->order_id );

		$this->assert_order_is_untouched( 'A dispatch carrying no order data must not run Auto Register.' );
	}

	/**
	 * The Free Downloads completion hook must refuse a router dispatch as well.
	 *
	 * That hook passes only an order ID, so there is no order data to check; the ID-shape check
	 * is the only thing standing in front of it.
	 */
	public function test_free_downloads_refuses_a_request_shaped_order_id() {
		do_action( self::FREE_HOOK, $this->request_args( 'free_downloads_post_complete_payment' ) );

		$this->assert_order_is_untouched( 'A request must not run Auto Register via Free Downloads.' );
	}

	/**
	 * The import hook must refuse a router dispatch.
	 *
	 * create_user_during_import() reaches the same account creation, and also strips core user
	 * registration actions for the rest of the request on its way there.
	 */
	public function test_import_router_dispatch_is_refused() {
		do_action( 'edd_batch_import_order_created', $this->request_args( 'batch_import_order_created' ) );

		$this->assertFalse(
			get_user_by( 'email', $this->email ),
			'A request must not create an account through the import path.'
		);
	}

	/**
	 * A router dispatch on the import hook must not strip core registration actions.
	 *
	 * create_user_during_import() unhooks three core listeners and pins a filter for the rest of
	 * the request before it reaches the account creation, so the ID has to be rejected ahead of
	 * those, not only at the point the account would be created.
	 */
	public function test_import_router_dispatch_does_not_strip_core_actions() {
		// Only the two listeners core actually registers are asserted on; nothing registers
		// edd_new_user_notification on edd_insert_user, so its survival would prove nothing.
		$stripped = array(
			'edd_customer_post_attach_payment' => 'edd_connect_guest_customer_to_existing_user',
			'user_register'                    => 'edd_add_past_purchases_to_new_user',
		);

		// Assert the fixture: a listener that was never registered cannot be observed to survive.
		foreach ( $stripped as $hook => $callback ) {
			$this->assertNotFalse(
				has_action( $hook, $callback ),
				sprintf( 'Fixture: %s must be listening to %s.', $callback, $hook )
			);
		}
		do_action( 'edd_batch_import_order_created', $this->request_args( 'batch_import_order_created' ) );

		foreach ( $stripped as $hook => $callback ) {
			$this->assertNotFalse(
				has_action( $hook, $callback ),
				sprintf( 'A request must not unhook %s from %s.', $callback, $hook )
			);
		}
		$this->assertFalse(
			has_filter( 'edd_auto_register_login_user', '__return_false' ),
			'A request must not pin the login opt-out filter for the rest of the request.'
		);
	}

	/**
	 * The manual-order hook must not fatal on a router dispatch.
	 *
	 * It is subscribed with three accepted arguments while the routers supply one, and its
	 * parameters had no defaults, so the dispatch raised an uncaught ArgumentCountError.
	 */
	public function test_manual_order_router_dispatch_does_not_fatal() {
		do_action( 'edd_post_add_manual_order', $this->request_args( 'post_add_manual_order' ) );

		$this->assertFalse(
			get_user_by( 'email', $this->email ),
			'A request must not create an account through the manual order path.'
		);
	}

	/**
	 * The manual-order handler must refuse a request-shaped order ID.
	 *
	 * No router can supply the third argument this handler requires, so its own gate already
	 * refuses every dispatch. The shape check is what keeps the guarantee from resting on a
	 * capability check in another file, so it is asserted directly.
	 */
	public function test_manual_order_refuses_a_request_shaped_order_id() {
		do_action(
			'edd_post_add_manual_order',
			$this->request_args( 'post_add_manual_order' ),
			array(),
			array( 'edd-new-customer' => 1 )
		);

		$this->assert_order_is_untouched( 'The manual order handler must not resolve an order from a request array.' );
	}

	/**
	 * A router dispatch must not add an existing user to this site.
	 *
	 * When an account already exists for the order email, can_create_user() refuses to create a
	 * second one, but on multisite it first adds that account to the current site at the default
	 * role. That is a membership change rather than a new account, so no other test here sees it.
	 * The Free Downloads hook is used because the ID-shape check is its only guard, which is what
	 * puts this side effect behind that check rather than behind the order-data one.
	 */
	public function test_router_dispatch_does_not_add_an_existing_user_to_the_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Site membership only exists on multisite.' );
		}

		$email   = 'network-' . wp_generate_password( 8, false ) . '@example.org';
		$user_id = wpmu_create_user( 'network' . wp_generate_password( 8, false ), wp_generate_password( 24 ), $email );
		$blog_id = get_current_blog_id();

		$this->assertNotFalse( $user_id, 'Fixture: the network user must exist.' );
		remove_user_from_blog( $user_id, $blog_id );

		$customer_id = edd_add_customer(
			array(
				'email' => $email,
				'name'  => 'Network Member',
			)
		);
		$order_id    = edd_add_order(
			array(
				'status'      => 'complete',
				'type'        => 'sale',
				'email'       => $email,
				'customer_id' => $customer_id,
				'gateway'     => 'manual',
				'mode'        => 'live',
				'currency'    => 'USD',
				'payment_key' => 'auto-register-guard-ms-' . wp_generate_password( 20, false ),
				'subtotal'    => 20,
				'total'       => 20,
			)
		);

		// Assert the fixture on both halves: the branch is only reached when the account exists,
		// and a user who already belongs to the site cannot be observed being added to it.
		$this->assertNotEmpty( $order_id, 'Fixture: the order must exist.' );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', $email ), 'Fixture: the account must exist.' );
		$this->assertFalse(
			is_user_member_of_blog( $user_id, $blog_id ),
			'Fixture: the user must not already belong to this site.'
		);

		do_action(
			self::FREE_HOOK,
			array(
				'edd_action' => 'free_downloads_post_complete_payment',
				'id'         => $order_id,
			)
		);

		$this->assertFalse(
			is_user_member_of_blog( $user_id, $blog_id ),
			'A request must not add an existing user to this site.'
		);
	}

	/**
	 * A real order-building dispatch must still register and assign the account.
	 *
	 * Without this the whole file would be satisfied by a guard that disabled Auto Register.
	 */
	public function test_real_order_built_registers_and_assigns_user() {
		do_action( self::BUILT_HOOK, $this->order_id, array( 'email' => $this->email ) );

		$user = get_user_by( 'email', $this->email );

		$this->assertInstanceOf( \WP_User::class, $user, 'Order building must still register the buyer.' );
		$this->assertSame(
			(int) $user->ID,
			$this->read_order_user_id(),
			'Order building must still assign the new user to the order.'
		);
	}

	/**
	 * A real edd_build_order() run must still register and assign the account.
	 *
	 * The case above fires the hook by hand. This one goes through order building itself, so the
	 * new guard is measured against the arguments the platform actually passes rather than a
	 * stand-in for them.
	 */
	public function test_edd_build_order_registers_and_assigns_user() {
		$email = 'buyer-' . wp_generate_password( 8, false ) . '@example.org';

		$order_id = edd_build_order(
			array(
				'status'    => 'complete',
				'email'     => $email,
				'user_info' => array(
					'first_name' => 'Real',
					'last_name'  => 'Buyer',
					'email'      => $email,
				),
			)
		);

		// Assert the fixture: an order that was never built cannot show the handler ran.
		$this->assertNotEmpty( $order_id, 'Fixture: order building must produce an order.' );

		$user = get_user_by( 'email', $email );

		$this->assertInstanceOf( \WP_User::class, $user, 'Order building must register the buyer.' );
		$this->assertSame(
			(int) $user->ID,
			$this->read_order_user_id( $order_id ),
			'Order building must assign the new user to the order.'
		);
	}

	/**
	 * A real Free Downloads dispatch must still register the account.
	 */
	public function test_real_free_downloads_dispatch_registers_user() {
		do_action( self::FREE_HOOK, $this->order_id );

		$this->assertInstanceOf(
			\WP_User::class,
			get_user_by( 'email', $this->email ),
			'A Free Downloads completion must still register the buyer.'
		);
	}

	/**
	 * Creates a completed order whose email has no WordPress account and no assigned user.
	 */
	private function seed_order() {
		$this->email = 'buyer-' . wp_generate_password( 8, false ) . '@example.org';

		$customer_id = edd_add_customer(
			array(
				'email' => $this->email,
				'name'  => 'Order Buyer',
			)
		);

		$this->order_id = edd_add_order(
			array(
				'status'      => 'complete',
				'type'        => 'sale',
				'email'       => $this->email,
				'customer_id' => $customer_id,
				'gateway'     => 'manual',
				'mode'        => 'live',
				'currency'    => 'USD',
				'payment_key' => 'auto-register-guard-' . wp_generate_password( 20, false ),
				'subtotal'    => 20,
				'total'       => 20,
			)
		);

		// Assert the fixture on every axis the handler branches on. Without these, "nothing
		// happened" would also be true of an order the handler would have skipped anyway.
		$this->assertNotEmpty( $customer_id, 'Fixture: the customer must exist.' );
		$this->assertNotEmpty( $this->order_id, 'Fixture: the order must exist.' );
		$this->assertNotNull( $this->read_order_row(), 'Fixture: the order row must be readable.' );

		// get_order_data() returns false on a missing customer_id and can_create_user() then
		// refuses without a word, so both fields are read back rather than assumed.
		$order = edd_get_order( $this->order_id );
		$this->assertSame( $this->email, $order->email, 'Fixture: the order must carry the email.' );
		$this->assertEquals( $customer_id, $order->customer_id, 'Fixture: the order must have a customer.' );

		$this->assertTrue( AutoRegister::is_enabled(), 'Fixture: Auto Register must be enabled.' );
		$this->assertFalse( is_user_logged_in(), 'Fixture: the requester must be logged out.' );
		$this->assertFalse(
			get_user_by( 'email', $this->email ),
			'Fixture: no account may exist for this email, or can_create_user() refuses anyway.'
		);
		$this->assertEmpty(
			$this->read_order_user_id(),
			'Fixture: the order must have no user assigned, or can_create_user() refuses anyway.'
		);
	}

	/**
	 * Gives the current session a purchase, which is what makes the handler log the user in.
	 *
	 * should_log_user_in() is satisfied by any visitor who has been through a checkout of their
	 * own in this session, so this is the requester's own session state rather than the order
	 * owner's.
	 */
	private function arm_login() {
		edd_set_purchase_session( array( 'purchase_key' => 'requester-own-session' ) );

		$this->assertNotEmpty(
			edd_get_purchase_session(),
			'Fixture: the session must carry a purchase, or the login step never runs.'
		);
	}

	/**
	 * Wires the subscriber using whatever it declares, so the test survives the fix.
	 *
	 * Other listeners on these hooks are dropped so that only this subscriber is measured;
	 * WP_UnitTestCase_Base::tear_down() restores $wp_filter from its snapshot after every test.
	 */
	private function wire_subscriber() {
		$events = AutoRegister::get_subscribed_events();
		$hooks  = array(
			self::BUILT_HOOK,
			self::FREE_HOOK,
			'edd_post_add_manual_order',
			'edd_batch_import_order_created',
		);

		$subscriber = new AutoRegister();

		foreach ( $hooks as $hook ) {
			$this->assertArrayHasKey(
				$hook,
				$events,
				sprintf( 'Fixture: AutoRegister must subscribe to %s.', $hook )
			);

			remove_all_actions( $hook );

			$subscription = (array) $events[ $hook ];
			$method       = $subscription[0];
			$priority     = isset( $subscription[1] ) ? $subscription[1] : 10;
			$accepted     = isset( $subscription[2] ) ? $subscription[2] : 1;

			$this->assertTrue(
				method_exists( $subscriber, $method ),
				sprintf( 'Fixture: AutoRegister::%s() must exist.', $method )
			);

			add_action( $hook, array( $subscriber, $method ), $priority, $accepted );
		}
	}

	/**
	 * Reads the order's assigned user ID, treating a missing row as unassigned.
	 *
	 * @return int
	 */
	private function read_order_user_id( $order_id = 0 ) {
		$row = $this->read_order_row( $order_id );

		return $row ? (int) $row->user_id : 0;
	}

	/**
	 * Reads the order row straight from the table, as ground truth.
	 *
	 * Returns null when there is no row, which is what separates "the order is unassigned" from
	 * "the reader is looking at the wrong table".
	 *
	 * @return object|null
	 */
	private function read_order_row( $order_id = 0 ) {
		global $wpdb;

		$order_id = $order_id ? (int) $order_id : (int) $this->order_id;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id, user_id FROM {$wpdb->prefix}edd_orders WHERE id = %d", $order_id )
		);
	}

	/**
	 * Routes a sample action the blocklist does not cover, and reports what arrived.
	 *
	 * A refusal only means something if the dispatcher would otherwise have routed the request, so
	 * this separates a blocked hook from a dispatcher that has stopped working altogether.
	 *
	 * @return array
	 */
	private function route_sample_action() {
		$routed = array();
		add_action(
			'edd_auto_register_sample_action',
			function () use ( &$routed ) {
				$routed[] = 'routed';
			}
		);

		$_GET['edd_action'] = 'auto_register_sample_action';
		edd_get_actions();

		return $routed;
	}

	/**
	 * The request array shape EDD's action routers produce.
	 *
	 * @param string $action The edd_action value.
	 * @return array
	 */
	private function request_args( $action ) {
		return array(
			'edd_action' => $action,
			'id'         => $this->order_id,
		);
	}

	/**
	 * Asserts that no account was created and nothing was taken over.
	 *
	 * @param string $message Context for the failure.
	 */
	private function assert_order_is_untouched( $message ) {
		$this->assertFalse(
			get_user_by( 'email', $this->email ),
			$message . ' No account may be created for the order email.'
		);
		$this->assertSame(
			0,
			$this->read_order_user_id(),
			$message . ' The order must not be reassigned to a new user.'
		);
		$this->assertSame(
			0,
			get_current_user_id(),
			$message . ' The requester must not end up authenticated.'
		);
	}
}
