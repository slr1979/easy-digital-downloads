<?php
/**
 * Background upgrade handlers must only run in a cron context.
 *
 * These handlers are wired as plain EventManagement\EventManager subscribers rather than through
 * Cron\Components\Component::subscribe(), so each one carries its own context check. Only the Action
 * Scheduler half of that check is observable in-process; the WP-Cron half depends on request state
 * PHPUnit cannot reproduce.
 *
 * @package EDD\Tests\Cron
 * @since   3.7.1
 */

namespace EDD\Tests\Cron;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Upgrades\Orders\MigrateAfterActionsDate;

/**
 * UpgradeCronGuard Tests
 *
 * @group edd_cron
 */
class UpgradeCronGuard extends EDD_UnitTestCase {

	/**
	 * The upgrade key the handler marks on completion.
	 *
	 * @var string
	 */
	private $upgrade_name = 'migrate_order_actions_date';

	/**
	 * Completed upgrades as they stood before the test ran.
	 *
	 * @var array
	 */
	private $original_upgrades;

	public function setUp(): void {
		parent::setUp();

		$this->original_upgrades = get_option( 'edd_completed_upgrades', array() );

		// The handler's only observable effect on an empty queue is marking itself complete,
		// so it has to start incomplete for either assertion below to mean anything.
		update_option(
			'edd_completed_upgrades',
			array_values( array_diff( $this->original_upgrades, array( $this->upgrade_name ) ) )
		);
	}

	public function tearDown(): void {
		update_option( 'edd_completed_upgrades', $this->original_upgrades );

		parent::tearDown();
	}

	/**
	 * A finished Action Scheduler job must not leave the rest of the request looking like cron.
	 *
	 * A balanced before/after pair is the state to refuse: a job ran, and nothing is executing now.
	 */
	public function test_handler_refuses_after_an_action_scheduler_job_has_finished() {
		$this->assertFalse( edd_has_upgrade_completed( $this->upgrade_name ), 'Fixture: the upgrade must start incomplete.' );
		$this->assertFalse( wp_doing_cron(), 'Fixture: this must not look like a cron request.' );

		// A job ran and finished, so the counts balance and nothing is executing now.
		do_action( 'action_scheduler_before_execute' );
		do_action( 'action_scheduler_after_execute' );

		$this->assertGreaterThan( 0, did_action( 'action_scheduler_before_execute' ), 'Fixture: the sticky signal must be set.' );

		( new MigrateAfterActionsDate() )->process_step();

		$this->assertFalse(
			edd_has_upgrade_completed( $this->upgrade_name ),
			'The handler must not run once the job that was executing has finished.'
		);
	}

	/**
	 * The paired positive: a job that is still executing is a legitimate cron context.
	 *
	 * Without this a guard that refused everywhere would satisfy the case above.
	 */
	public function test_handler_runs_while_an_action_scheduler_job_is_executing() {
		$this->assertFalse( edd_has_upgrade_completed( $this->upgrade_name ), 'Fixture: the upgrade must start incomplete.' );

		// Fired without its pair, so a job is mid-execution.
		do_action( 'action_scheduler_before_execute' );

		( new MigrateAfterActionsDate() )->process_step();

		$this->assertTrue(
			edd_has_upgrade_completed( $this->upgrade_name ),
			'A handler must still run inside an Action Scheduler job.'
		);
	}
}
