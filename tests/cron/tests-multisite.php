<?php
/**
 * Multisite tests for the Passes cron component.
 *
 * The weekly pass/license check runs once per network, so the component only
 * subscribes its events on the main site. Child sites subscribe to nothing.
 *
 * @package   EDD\Tests\Cron\Components
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.1
 */

namespace EDD\Tests\Cron;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\Multisite as MultisiteHelper;

/**
 * Passes cron component multisite tests.
 *
 * @since 3.7.1
 */
class PassesMultisite extends EDD_UnitTestCase {

	use MultisiteHelper;

	/**
	 * Sets up each test, skipping on single-site.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->skip_if_not_multisite();
	}

	/**
	 * Cleans up blog context after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->restore_blogs();
		parent::tearDown();
	}

	/**
	 * The main site subscribes to the weekly pass check.
	 *
	 * @return void
	 */
	public function test_subscribes_on_main_site() {
		$events = \EDD\Cron\Components\Passes::get_subscribed_events();

		$hook = edd_is_pro() ? 'edd_pro_weekly_scheduled_events' : 'edd_weekly_scheduled_events';

		$this->assertArrayHasKey( $hook, $events );
		$this->assertEquals( 'weekly_license_check', $events[ $hook ] );
	}

	/**
	 * A child site subscribes to no pass events.
	 *
	 * @return void
	 */
	public function test_does_not_subscribe_on_child_site() {
		$this->create_and_switch_to_blog();

		$this->assertSame( array(), \EDD\Cron\Components\Passes::get_subscribed_events() );
	}
}
