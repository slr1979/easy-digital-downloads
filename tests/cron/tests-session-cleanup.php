<?php
namespace EDD\Tests\Cron;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Cron\Components\Sessions;
use EDD\Cron\Schedulers\ActionScheduler as ActionSchedulerClass;
use EDD\Database\Queries\Session;

/**
 * Session Cleanup Tests
 *
 * Tests for the batch continuation behavior of Sessions::remove_expired_sessions().
 *
 * @group edd_cron
 * @group edd_cron_session_cleanup
 */
class SessionCleanup extends EDD_UnitTestCase {

	/**
	 * The cleanup component under test.
	 *
	 * @var Sessions
	 */
	protected $component;

	/**
	 * The continuation hook name.
	 *
	 * @var string
	 */
	protected $hook = 'edd_cleanup_sessions';

	/**
	 * The continuation args used to isolate follow-up actions.
	 *
	 * @var array
	 */
	protected $continuation_args = array( 'continuation' => 1 );

	/**
	 * The batch size forced via the cleanup filter, or null when not forced.
	 *
	 * @var int|null
	 */
	protected $forced_batch_size = null;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! ActionSchedulerClass::is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		$this->component = new Sessions();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Remove any seeded sessions.
		$this->delete_all_sessions();

		// Remove any scheduled continuation actions.
		if ( ActionSchedulerClass::is_available() ) {
			as_unschedule_all_actions( $this->hook, null, 'edd' );
		}

		// Remove any forced batch-size filter.
		remove_filter( 'edd/cron/sessions/cleanup_batch_size', array( $this, 'force_cleanup_batch_size' ) );
		$this->forced_batch_size = null;

		parent::tearDown();
	}

	/**
	 * Filter callback that forces the session cleanup batch size.
	 *
	 * @return int The forced batch size.
	 */
	public function force_cleanup_batch_size(): int {
		return (int) $this->forced_batch_size;
	}

	/**
	 * A full batch deletes batch_size rows and schedules exactly one continuation.
	 */
	public function test_full_batch_deletes_batch_size_and_schedules_continuation() {
		// Force a small batch so a tiny seed exercises the continuation path without
		// inserting hundreds of rows.
		$this->forced_batch_size = 5;
		add_filter( 'edd/cron/sessions/cleanup_batch_size', array( $this, 'force_cleanup_batch_size' ) );

		$this->seed_expired_sessions( 12 );

		$this->component->remove_expired_sessions();

		// One full batch of 5 is deleted, so 7 of the 12 seeded rows remain.
		$this->assertEquals( 7, $this->count_expired_sessions(), 'A full batch should delete exactly the batch size (5 rows).' );

		// Exactly one continuation action should be pending.
		$this->assertCount(
			1,
			$this->get_pending_continuations(),
			'A full batch should schedule exactly one continuation.'
		);
	}

	/**
	 * A short batch deletes everything and schedules no continuation.
	 */
	public function test_short_batch_deletes_all_and_schedules_no_continuation() {
		$this->seed_expired_sessions( 300 );

		$this->component->remove_expired_sessions();

		$this->assertEquals( 0, $this->count_expired_sessions(), 'A short batch should delete all expired rows.' );

		$this->assertCount(
			0,
			$this->get_pending_continuations(),
			'A short batch should not schedule a continuation.'
		);
	}

	/**
	 * With a continuation already pending, a full-batch run does not add a second.
	 */
	public function test_runaway_guard_prevents_second_continuation() {
		// Force a small batch so a tiny seed exercises the guard without inserting
		// hundreds of rows.
		$this->forced_batch_size = 5;
		add_filter( 'edd/cron/sessions/cleanup_batch_size', array( $this, 'force_cleanup_batch_size' ) );

		// Pre-seed one pending continuation to simulate an existing queued run.
		as_enqueue_async_action( $this->hook, $this->continuation_args, 'edd' );
		$this->assertCount( 1, $this->get_pending_continuations(), 'Precondition: one continuation should be pending.' );

		$this->seed_expired_sessions( 12 );

		$this->component->remove_expired_sessions();

		// The batch itself must still be deleted (12 seeded - 5 batch = 7 remain).
		$this->assertEquals( 7, $this->count_expired_sessions(), 'Deletion regression guard: the batch itself should still be deleted.' );

		// Still only one continuation should be pending.
		$this->assertCount(
			1,
			$this->get_pending_continuations(),
			'The runaway guard should prevent a second continuation.'
		);
	}

	/**
	 * A deleted row's session_key is not served from the sessions cache after cleanup.
	 */
	public function test_deleted_session_is_not_served_from_cache() {
		$this->seed_expired_sessions( 300 );

		$query = new Session();

		// Find one seeded key and prime the cache by reading it through the query.
		$sessions = $query->query(
			array(
				'number'  => 1,
				'orderby' => 'session_id',
				'order'   => 'ASC',
			)
		);
		$this->assertNotEmpty( $sessions, 'Precondition: at least one session should be seeded.' );

		$key    = $sessions[0]->session_key;
		$cached = $query->get_item_by( 'session_key', $key );
		$this->assertNotEmpty( $cached, 'Precondition: the session should be readable before cleanup.' );

		$this->component->remove_expired_sessions();

		// After per-row deletion, the cache group is invalidated so the key resolves to nothing.
		$after = ( new Session() )->get_item_by( 'session_key', $key );
		$this->assertEmpty( $after, 'A deleted session should not be served from cache.' );
	}

	/**
	 * Seed a number of already-expired sessions.
	 *
	 * @param int $count The number of sessions to create.
	 */
	protected function seed_expired_sessions( int $count ) {
		$query = new Session();
		$now   = time() - HOUR_IN_SECONDS;

		for ( $i = 0; $i < $count; $i++ ) {
			$query->add_item(
				array(
					'session_key'    => 'seed_' . $i . '_' . wp_generate_password( 12, false ),
					'session_value'  => 'seed',
					'session_expiry' => $now,
				)
			);
		}
	}

	/**
	 * Count the sessions that are currently expired.
	 *
	 * @return int
	 */
	protected function count_expired_sessions(): int {
		$query = new Session();

		return (int) $query->query(
			array(
				'count'                   => true,
				'session_expiry__compare' => array(
					'relation' => 'AND',
					array(
						'value'   => time(),
						'compare' => '<',
					),
				),
			)
		);
	}

	/**
	 * Delete every seeded session from the table.
	 */
	protected function delete_all_sessions() {
		$query = new Session();
		$ids   = $query->query(
			array(
				'number' => 100000,
				'fields' => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			$query->delete_item( $id );
		}
	}

	/**
	 * Get the pending continuation actions.
	 *
	 * @return array
	 */
	protected function get_pending_continuations(): array {
		return as_get_scheduled_actions(
			array(
				'hook'     => $this->hook,
				'args'     => $this->continuation_args,
				'group'    => 'edd',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 5,
			),
			'ids'
		);
	}
}
