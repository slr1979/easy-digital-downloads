<?php
/**
 * PayPal Webhook Sync Cron Event Tests
 *
 * The listener is wired on every request, so that the hook exists by the time the scheduled
 * event fires. Each case fires the hook rather than calling the function, because the guard
 * lives in the wiring: a direct call is deliberately still allowed, so the connect screen can
 * sync on demand.
 *
 * @package   EDD\Tests\Cron
 * @copyright Copyright (c) 2026, Sandhills Development, LLC
 * @license   GPL2+
 * @since     3.7.1
 */

namespace EDD\Tests\Cron;

use EDD\Gateways\PayPal\API;
use EDD\Gateways\PayPal\Webhooks;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for the PayPal webhook sync cron event.
 *
 * @group edd_cron
 * @group paypal
 */
class PayPalWebhookSyncEventTest extends EDD_UnitTestCase {

	/**
	 * The hook the sync event is scheduled and fired on.
	 *
	 * @var string
	 */
	const HOOK = 'edd/paypal/webhooks/sync';

	/**
	 * The option the handler records an incomplete sync in.
	 *
	 * @var string
	 */
	const FAILURE_OPTION = 'edd_paypal_webhook_sync_failed';

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();

		// The sync sends a PATCH to PayPal; keep the suite off the network.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 1 );

		delete_option( self::FAILURE_OPTION );
		delete_option( 'edd_paypal_commerce_webhook_id_' . API::MODE_LIVE );
		delete_option( 'edd_paypal_commerce_webhook_id_' . API::MODE_SANDBOX );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 1 );

		// Added by simulate_cron(); never leave it set for the next test.
		remove_filter( 'wp_doing_cron', '__return_true' );

		delete_option( self::FAILURE_OPTION );

		parent::tear_down();
	}

	/**
	 * Short-circuits outbound HTTP so the sync never leaves the test runner.
	 *
	 * @param mixed $preempt The preemptive response.
	 * @return \WP_Error
	 */
	public function block_http( $preempt ) {
		return new \WP_Error( 'edd_test_http_blocked', 'Outbound HTTP is blocked in this test.' );
	}

	/**
	 * An ordinary request must leave the webhook registration alone.
	 */
	public function test_sync_event_does_not_run_outside_cron() {
		$this->assert_fixture();

		do_action( self::HOOK );

		$this->assertFalse(
			get_option( self::FAILURE_OPTION ),
			'An ordinary request must not run the webhook sync.'
		);
	}

	/**
	 * The paired positive: cron itself must still run the sync.
	 *
	 * With no webhook configured the sync cannot complete, and the handler records that. The
	 * flag appearing is therefore proof the handler reached the sync at all, which is what
	 * distinguishes this from a guard that refused everywhere.
	 */
	public function test_sync_event_runs_inside_cron() {
		$this->assert_fixture();

		$this->simulate_cron();
		do_action( self::HOOK );

		$this->assertNotFalse(
			get_option( self::FAILURE_OPTION ),
			'Cron must still run the webhook sync.'
		);
	}

	/**
	 * Test event under Action Scheduler.
	 */
	public function test_sync_event_runs_under_action_scheduler() {
		$this->assert_fixture();

		do_action( 'action_scheduler_before_execute', 1 );
		do_action( self::HOOK );
		do_action( 'action_scheduler_after_execute', 1 );

		$this->assertNotFalse(
			get_option( self::FAILURE_OPTION ),
			'Action Scheduler must still run the webhook sync.'
		);
	}

	/**
	 * The connect screen syncs on demand, outside of cron, and must keep working.
	 *
	 * The sync clears the flag before doing anything else, so a cleared flag is the observable
	 * that the function body ran.
	 */
	public function test_sync_is_still_callable_outside_cron() {
		add_option( self::FAILURE_OPTION, time(), '', false );
		$this->assertNotFalse( get_option( self::FAILURE_OPTION ), 'Fixture: the flag must be set before the call.' );

		$threw = false;
		try {
			Webhooks\sync_webhook();
		} catch ( \Exception $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'Fixture: with no webhook configured the sync cannot complete.' );
		$this->assertFalse(
			get_option( self::FAILURE_OPTION ),
			'An on-demand sync must still run outside of cron.'
		);
	}

	/**
	 * Asserts the preconditions every case above depends on.
	 *
	 * Without these, "the flag is absent" would also pass against a hook that had no listener,
	 * or against a sync that failed for a reason of its own.
	 */
	private function assert_fixture() {
		$this->assertNotFalse(
			has_action( self::HOOK ),
			sprintf( 'Fixture: %s must have a listener.', self::HOOK )
		);
		$this->assertEmpty(
			Webhooks\get_webhook_id(),
			'Fixture: no webhook may be configured, so the sync records a failure rather than calling PayPal.'
		);
		$this->assertFalse(
			get_option( self::FAILURE_OPTION ),
			'Fixture: the flag must start absent.'
		);
	}

	/**
	 * Makes the current request look like a WP-Cron run.
	 *
	 * The filter is removed in tear_down(). An Action Scheduler context cannot be simulated
	 * this way, because did_action() is sticky for the whole process: it has to be entered and
	 * left in the same test, as test_sync_event_runs_under_action_scheduler() does.
	 */
	private function simulate_cron() {
		add_filter( 'wp_doing_cron', '__return_true' );
	}
}
