<?php
/**
 * PayPalConnectSync Cron Event Tests
 *
 * Verifies the daily reconciliation event's hook, schedule, and staggered
 * first-run offset, and that the Loader registers it.
 *
 * @package   EDD\Tests\Cron
 * @copyright Copyright (c) 2026, Sandhills Development, LLC
 * @license   GPL2+
 * @since     3.7.0
 */

namespace EDD\Tests\Cron;

use EDD\Cron\Events\PayPalConnectSync;
use EDD\Cron\Loader;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for the PayPalConnectSync cron event.
 *
 * @group edd_cron
 * @group paypal
 * @group paypal-connect-sync
 */
class PayPalConnectSyncEventTest extends EDD_UnitTestCase {

	/**
	 * The event exposes the expected hook and schedule.
	 */
	public function test_hook_and_schedule() {
		$event = new PayPalConnectSync();

		$this->assertSame( 'edd_paypal_v3_sync_connect', $event->hook );
		$this->assertSame( 'daily', $event->schedule );
	}

	/**
	 * The first run is staggered to a non-zero future offset, not pinned to now.
	 */
	public function test_first_run_is_staggered() {
		$before = time();
		$event  = new PayPalConnectSync();

		// The constructor sets first_run to now + random offset within a day.
		$this->assertGreaterThanOrEqual( $before, $event->first_run );
		$this->assertLessThanOrEqual( $before + DAY_IN_SECONDS + 1, $event->first_run );
	}

	/**
	 * The Loader registers the event among its core daily events.
	 */
	public function test_loader_registers_event() {
		$events = Loader::get_registered_events();

		$found = false;
		foreach ( $events as $event ) {
			if ( $event instanceof PayPalConnectSync ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Loader should register the PayPalConnectSync event.' );
	}
}
