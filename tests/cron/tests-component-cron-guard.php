<?php
/**
 * Cron component handlers must only run in a cron context.
 *
 * Cron components have to be wired on every request, so that their hooks exist by the time a
 * scheduled event fires. Each test here fires the real hook rather than calling the component
 * method, because the guard lives in the wiring: a direct method call is deliberately still
 * allowed, so that admin tools and WP-CLI can run a job on purpose.
 *
 * Every negative case is paired with the positive, so that a guard which simply disabled the
 * job everywhere could not satisfy this file.
 *
 * @package EDD\Tests\Cron
 * @since   3.7.1
 */

namespace EDD\Tests\Cron;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Cron\Events\SingleEvent;

/**
 * ComponentCronGuard Tests
 *
 * @group edd_cron
 */
class ComponentCronGuard extends EDD_UnitTestCase {

	/**
	 * Mail captured during a test.
	 *
	 * @var array
	 */
	private $captured_mail = array();

	/**
	 * Probe hooks registered by a test, removed on teardown.
	 *
	 * @var array
	 */
	private $probe_hooks = array();

	/**
	 * The telemetry check-in timestamp, as it was before the test changed it.
	 *
	 * @var mixed
	 */
	private $original_last_checkin;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->captured_mail = array();

		// These hooks fan out to telemetry and license checks; keep the suite off the network.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 1 );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 1, 2 );

		// Store::send() also listens to the weekly event, and Pro opts every store into telemetry.
		// It is not what these tests measure, and building its payload trips an unrelated dynamic
		// property deprecation on PHP 8.2, so record a check-in as already done for this week.
		$this->original_last_checkin = get_option( 'edd_tracking_last_send' );
		update_option( 'edd_tracking_last_send', time(), false );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 1 );
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 1 );
		remove_filter( 'edd_should_send_email_new_user', '__return_true' );

		// Added by simulate_cron(); never leave it set for the next test.
		remove_filter( 'wp_doing_cron', '__return_true' );

		if ( false === $this->original_last_checkin ) {
			delete_option( 'edd_tracking_last_send' );
		} else {
			update_option( 'edd_tracking_last_send', $this->original_last_checkin, false );
		}

		foreach ( $this->probe_hooks as $hook ) {
			remove_all_actions( $hook );
		}
		$this->probe_hooks = array();

		parent::tearDown();
	}

	/**
	 * Short-circuits outbound HTTP so a scheduled event never leaves the test runner.
	 *
	 * @param mixed $preempt The preemptive response.
	 * @return \WP_Error
	 */
	public function block_http( $preempt ) {
		return new \WP_Error( 'edd_test_http_blocked', 'Outbound HTTP is blocked in this test.' );
	}

	/**
	 * Captures and short-circuits mail so "no mail was sent" is assertable.
	 *
	 * @param null|bool $return Short-circuit return value.
	 * @param array     $atts   The wp_mail() arguments.
	 * @return true
	 */
	public function capture_mail( $return, $atts ) {
		$this->captured_mail[] = $atts;

		return true;
	}

	/**
	 * Makes the current request look like a cron run.
	 *
	 * The `wp_doing_cron` filter is used rather than firing
	 * `action_scheduler_before_execute`, because did_action() is sticky for the whole process
	 * and would leak a cron context into every later test in this class.
	 */
	private function simulate_cron() {
		add_filter( 'wp_doing_cron', '__return_true' );
	}

	/**
	 * Asserts the components under test are actually wired before a hook is fired.
	 *
	 * Without this, every "nothing happened" assertion below would also pass against a hook
	 * that had no listeners at all.
	 *
	 * @param string $hook The hook expected to have a listener.
	 */
	private function assert_hook_is_wired( $hook ) {
		$this->assertNotFalse(
			has_action( $hook ),
			sprintf( 'Fixture: %s must have a cron component listening to it.', $hook )
		);
	}

	/**
	 * Seeds the files the exports cleanup acts on.
	 *
	 * @return array Absolute paths, keyed by role.
	 */
	private function seed_export_files() {
		$exports_dir = edd_get_exports_dir();
		$uploads_dir = wp_upload_dir();

		$paths = array(
			'aged_export' => trailingslashit( $exports_dir ) . 'edd-export-cronguard.csv',
			'aged_stray'  => trailingslashit( $uploads_dir['basedir'] ) . 'edd-cronguard-stray.csv',
			'fresh'       => trailingslashit( $exports_dir ) . 'edd-export-cronguard-fresh.csv',
			// Aged, but the cleanup skips it by name; proves the sweep is selective.
			'index'       => trailingslashit( $exports_dir ) . 'index.php',
		);

		foreach ( $paths as $key => $path ) {
			file_put_contents( $path, "id,total\n1,20.00\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( 'fresh' !== $key ) {
				touch( $path, time() - ( 3 * HOUR_IN_SECONDS ) );
			}
		}

		// Assert the fixture: the two aged files must really be older than the two hour window.
		$this->assertGreaterThan( 2 * HOUR_IN_SECONDS, time() - filemtime( $paths['aged_export'] ) );
		$this->assertGreaterThan( 2 * HOUR_IN_SECONDS, time() - filemtime( $paths['aged_stray'] ) );

		return $paths;
	}

	/**
	 * Removes any export fixture files still on disk.
	 *
	 * @param array $paths Paths returned by seed_export_files().
	 */
	private function clean_export_files( array $paths ) {
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	/**
	 * Seeds two users with a saved cart three weeks old.
	 *
	 * @return array User IDs.
	 */
	private function seed_saved_carts() {
		$user_ids = array();

		for ( $i = 0; $i < 2; $i++ ) {
			$user_id = $this->factory->user->create();
			update_user_meta( $user_id, 'edd_cart_token', strtotime( '-3 weeks' ) );
			update_user_meta( $user_id, 'edd_saved_cart', array( 'cronguard' => $i ) );

			// Assert the fixture: both keys must be readable back.
			$this->assertNotEmpty( get_user_meta( $user_id, 'edd_cart_token', true ) );
			$this->assertNotEmpty( get_user_meta( $user_id, 'edd_saved_cart', true ) );

			$user_ids[] = $user_id;
		}

		return $user_ids;
	}

	/**
	 * Reads a user meta value, bypassing the cache.
	 *
	 * delete_saved_carts() deletes with a raw query and never invalidates the user meta cache,
	 * so a cached read would answer with the old value whether or not the job ran. Without this
	 * the negative assertions below would pass against unpatched code.
	 *
	 * @param int    $user_id  The user ID.
	 * @param string $meta_key The meta key.
	 * @return mixed
	 */
	private function get_fresh_user_meta( $user_id, $meta_key ) {
		wp_cache_delete( $user_id, 'user_meta' );

		return get_user_meta( $user_id, $meta_key, true );
	}

	/**
	 * Counts rows in the sessions table.
	 *
	 * @return int
	 */
	private function count_sessions() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}edd_sessions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Inserts expired session rows.
	 *
	 * @return int The number of rows in the table afterwards.
	 */
	private function seed_expired_sessions() {
		global $wpdb;

		for ( $i = 0; $i < 2; $i++ ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"{$wpdb->prefix}edd_sessions",
				array(
					'session_key'    => 'cronguard' . $i . wp_generate_password( 12, false ),
					'session_value'  => maybe_serialize( array( 'cronguard' => $i ) ),
					'session_expiry' => time() - DAY_IN_SECONDS,
				)
			);
		}

		return $this->count_sessions();
	}

	/**
	 * The exports cleanup deletes files, so this is the case whose effect does not recover.
	 */
	public function test_exports_cleanup_refuses_outside_cron() {
		$paths = $this->seed_export_files();
		$this->assert_hook_is_wired( 'edd_daily_scheduled_events' );

		do_action( 'edd_daily_scheduled_events' );

		$this->assertFileExists( $paths['aged_export'], 'An HTTP request must not delete a generated export.' );
		$this->assertFileExists( $paths['aged_stray'], 'An HTTP request must not delete files from the uploads root.' );
		$this->assertFileExists( $paths['fresh'] );

		$this->clean_export_files( $paths );
	}

	/**
	 * The paired positive: cron itself must still clean the exports directory.
	 */
	public function test_exports_cleanup_runs_inside_cron() {
		$paths = $this->seed_export_files();
		$this->assert_hook_is_wired( 'edd_daily_scheduled_events' );

		$this->simulate_cron();
		do_action( 'edd_daily_scheduled_events' );

		$this->assertFileDoesNotExist( $paths['aged_export'], 'Cron must still clean aged exports.' );
		$this->assertFileDoesNotExist( $paths['aged_stray'], 'Cron must still clean aged stray CSVs.' );
		$this->assertFileExists( $paths['fresh'], 'A file inside the two hour window must survive.' );
		$this->assertFileExists( $paths['index'], 'The directory index must survive.' );

		$this->clean_export_files( $paths );
	}

	/**
	 * Saved carts belong to customers; an HTTP request must not delete them.
	 */
	public function test_saved_cart_cleanup_refuses_outside_cron() {
		$user_ids = $this->seed_saved_carts();
		$this->assert_hook_is_wired( 'edd_weekly_scheduled_events' );

		do_action( 'edd_weekly_scheduled_events' );

		foreach ( $user_ids as $user_id ) {
			$this->assertNotEmpty(
				$this->get_fresh_user_meta( $user_id, 'edd_cart_token' ),
				'An HTTP request must not delete a saved cart token.'
			);
			$this->assertNotEmpty(
				$this->get_fresh_user_meta( $user_id, 'edd_saved_cart' ),
				'An HTTP request must not delete a saved cart.'
			);
		}
	}

	/**
	 * The paired positive: cron itself must still delete week-old saved carts.
	 */
	public function test_saved_cart_cleanup_runs_inside_cron() {
		$user_ids = $this->seed_saved_carts();
		$this->assert_hook_is_wired( 'edd_weekly_scheduled_events' );

		$this->simulate_cron();
		do_action( 'edd_weekly_scheduled_events' );

		foreach ( $user_ids as $user_id ) {
			$this->assertEmpty(
				$this->get_fresh_user_meta( $user_id, 'edd_cart_token' ),
				'Cron must still clean up week-old saved carts.'
			);
			$this->assertEmpty( $this->get_fresh_user_meta( $user_id, 'edd_saved_cart' ) );
		}
	}

	/**
	 * Forcing an order to `abandoned` is a state change nobody chose.
	 */
	public function test_abandoned_order_transition_refuses_outside_cron() {
		$order_id = edd_add_order(
			array(
				'status'       => 'pending',
				'type'         => 'sale',
				'email'        => 'cronguard@edd.test',
				'total'        => 20,
				'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
			)
		);

		// Assert the fixture: the order must exist, be pending, and be old enough to qualify.
		$this->assertNotEmpty( $order_id );
		$order = edd_get_order( $order_id );
		$this->assertSame( 'pending', $order->status );
		$this->assertLessThan( strtotime( '-1 week' ), strtotime( $order->date_created ) );

		$this->assert_hook_is_wired( 'edd_weekly_scheduled_events' );

		do_action( 'edd_weekly_scheduled_events' );

		$this->assertSame(
			'pending',
			edd_get_order( $order_id )->status,
			'An HTTP request must not transition an order to abandoned.'
		);
	}

	/**
	 * The paired positive: cron itself must still mark week-old pending orders abandoned.
	 */
	public function test_abandoned_order_transition_runs_inside_cron() {
		$order_id = edd_add_order(
			array(
				'status'       => 'pending',
				'type'         => 'sale',
				'email'        => 'cronguard@edd.test',
				'total'        => 20,
				'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
			)
		);
		$this->assertSame( 'pending', edd_get_order( $order_id )->status );
		$this->assert_hook_is_wired( 'edd_weekly_scheduled_events' );

		$this->simulate_cron();
		do_action( 'edd_weekly_scheduled_events' );

		$this->assertSame(
			'abandoned',
			edd_get_order( $order_id )->status,
			'Cron must still mark week-old pending orders as abandoned.'
		);
	}

	/**
	 * Purging sessions logs shoppers out at a moment nobody chose.
	 */
	public function test_session_purge_refuses_outside_cron() {
		$before = $this->seed_expired_sessions();
		$this->assertGreaterThan( 0, $before, 'Fixture: expired session rows must exist.' );
		$this->assert_hook_is_wired( 'edd_cleanup_sessions' );

		do_action( 'edd_cleanup_sessions' );

		$this->assertSame( $before, $this->count_sessions(), 'An HTTP request must not purge sessions.' );
	}

	/**
	 * The paired positive: cron itself must still purge expired sessions.
	 */
	public function test_session_purge_runs_inside_cron() {
		$before = $this->seed_expired_sessions();
		$this->assertGreaterThan( 0, $before );
		$this->assert_hook_is_wired( 'edd_cleanup_sessions' );

		$this->simulate_cron();
		do_action( 'edd_cleanup_sessions' );

		$this->assertLessThan( $before, $this->count_sessions(), 'Cron must still purge expired sessions.' );
	}

	/**
	 * The structural case: a component written later is guarded without asking to be.
	 *
	 * The assertion is on whether the method ran, not on the shape of the registered callback,
	 * so this states the rule rather than pinning the implementation.
	 */
	public function test_a_new_component_is_guarded_by_the_wiring() {
		$this->probe_hooks[] = 'edd_cronguard_probe_event';

		$probe = $this->get_probe_component();
		$probe->subscribe();

		$this->assert_hook_is_wired( 'edd_cronguard_probe_event' );

		do_action( 'edd_cronguard_probe_event' );
		$this->assertSame( 0, $probe::$runs, 'A new component must not run outside of cron.' );

		$this->simulate_cron();
		do_action( 'edd_cronguard_probe_event' );
		$this->assertSame( 1, $probe::$runs, 'A new component must still run inside cron.' );
	}

	/**
	 * A component may opt a genuinely request-time hook out of the guard.
	 *
	 * This is the mechanism `LogPruning`'s `init` and `EmailSummaries`' `updated_option` rely on,
	 * so it is asserted directly rather than only through those two components.
	 */
	public function test_an_unguarded_event_still_runs_outside_cron() {
		$this->probe_hooks[] = 'edd_cronguard_unguarded_probe_event';

		$probe = $this->get_unguarded_probe_component();
		$probe->subscribe();

		$this->assert_hook_is_wired( 'edd_cronguard_unguarded_probe_event' );

		do_action( 'edd_cronguard_unguarded_probe_event' );

		$this->assertSame(
			1,
			$probe::$runs,
			'A hook a component declares as request-time must keep running outside of cron.'
		);
	}

	/**
	 * The guard, not a type declaration, is what stands between the request array and a
	 * handler that expects a scalar.
	 */
	public function test_new_user_email_handler_refuses_the_request_array() {
		$this->assert_hook_is_wired( 'edd_send_new_user_email' );

		do_action( 'edd_send_new_user_email', array( 'edd_action' => 'send_new_user_email' ) );

		$this->assertSame(
			array(),
			$this->captured_mail,
			'An HTTP request must not send the new user emails.'
		);
	}

	/**
	 * The paired positive: cron itself must still send the new user emails.
	 */
	public function test_new_user_email_handler_runs_inside_cron() {
		$user_id = $this->factory->user->create();
		$this->assert_hook_is_wired( 'edd_send_new_user_email' );

		// Whether this email is switched on is a store setting, and not what this test measures.
		add_filter( 'edd_should_send_email_new_user', '__return_true' );

		$this->simulate_cron();
		do_action( 'edd_send_new_user_email', $user_id );

		$this->assertNotEmpty(
			$this->captured_mail,
			'Cron must still send the new user emails.'
		);
	}

	/**
	 * A settings save must still reschedule the email summary, outside of cron.
	 *
	 * `EmailSummaries` subscribes to `updated_option`, which is an ordinary request-time hook and
	 * is how the admin reschedules the summary. It only schedules, it never sends, so it is
	 * declared unguarded. Guarding it would silently stop the summary from ever being rescheduled.
	 */
	public function test_email_summary_rescheduling_still_runs_on_a_settings_save() {
		$this->assertContains(
			'updated_option',
			\EDD\Cron\Components\EmailSummaries::get_request_time_events(),
			'updated_option must be declared as request-time.'
		);
		$this->assert_hook_is_wired( 'updated_option' );

		$hook = \EDD\Cron\Components\EmailSummaries::CRON_EVENT_NAME;
		SingleEvent::remove( $hook );
		wp_clear_scheduled_hook( $hook );

		// Assert the fixture: nothing may be scheduled before the save.
		$this->assertFalse( (bool) SingleEvent::next_scheduled( $hook ) );

		do_action(
			'updated_option',
			'edd_settings',
			array( 'email_summary_frequency' => 'weekly' ),
			array( 'email_summary_frequency' => 'monthly' )
		);

		$this->assertNotFalse(
			SingleEvent::next_scheduled( $hook ),
			'A settings save outside of cron must still reschedule the email summary.'
		);
	}

	/**
	 * Log pruning's `init` registration must stay unguarded.
	 *
	 * Under Action Scheduler, `init` fires before `action_scheduler_before_execute`, so a guarded
	 * `init` would never register the per-log-type listeners and pruning would never run at all.
	 * That request ordering cannot be reproduced in-process, so this asserts the declaration.
	 * That a declared hook is genuinely wired without the guard is proven behaviourally by
	 * test_an_unguarded_event_still_runs_outside_cron().
	 */
	public function test_log_pruning_registration_is_declared_request_time() {
		$this->assertContains(
			'init',
			\EDD\Cron\Components\LogPruning::get_request_time_events(),
			'LogPruning must declare init as request-time, or pruning never registers.'
		);
	}

	/**
	 * Log pruning listeners are registered dynamically, not through get_subscribed_events().
	 *
	 * `register_pruning_hooks()` runs on `init`, which is deliberately unguarded, so the
	 * listeners it adds have to carry the guard themselves.
	 */
	public function test_log_pruning_listener_refuses_outside_cron() {
		$this->probe_hooks[] = 'edd_prune_logs_gateway_errors';
		$log_id              = $this->seed_prunable_log();

		remove_all_actions( 'edd_prune_logs_gateway_errors' );
		( new \EDD\Cron\Components\LogPruning() )->register_pruning_hooks();
		$this->assert_hook_is_wired( 'edd_prune_logs_gateway_errors' );

		do_action( 'edd_prune_logs_gateway_errors' );

		$this->assertNotEmpty(
			edd_get_log( $log_id ),
			'An HTTP request must not prune logs.'
		);
	}

	/**
	 * The paired positive: cron itself must still prune old logs.
	 */
	public function test_log_pruning_listener_runs_inside_cron() {
		$this->probe_hooks[] = 'edd_prune_logs_gateway_errors';
		$log_id              = $this->seed_prunable_log();

		remove_all_actions( 'edd_prune_logs_gateway_errors' );
		( new \EDD\Cron\Components\LogPruning() )->register_pruning_hooks();
		$this->assert_hook_is_wired( 'edd_prune_logs_gateway_errors' );

		$this->simulate_cron();
		do_action( 'edd_prune_logs_gateway_errors' );

		$this->assertEmpty(
			edd_get_log( $log_id ),
			'Cron must still prune logs older than the configured window.'
		);
	}

	/**
	 * Enables pruning for gateway errors and inserts one log old enough to be pruned.
	 *
	 * @return int The log ID.
	 */
	private function seed_prunable_log() {
		edd_update_option( 'log_pruning_enabled', true );
		edd_update_option(
			'edd_log_pruning_settings',
			array(
				'log_types' => array(
					'gateway_errors' => array(
						'enabled' => true,
						'days'    => 30,
					),
				),
			)
		);

		$log_id = edd_add_log(
			array(
				'title'        => 'Prunable log',
				'type'         => 'gateway_error',
				// edd_add_log() refuses an insert with no object_type.
				'object_type'  => 'cronguard_probe',
				'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
			)
		);

		// Assert the fixture: the log must exist and be old enough to qualify for pruning.
		$this->assertNotEmpty( $log_id );
		$log = edd_get_log( $log_id );
		$this->assertNotEmpty( $log );
		$this->assertLessThan( strtotime( '-30 days' ), strtotime( $log->date_created ) );

		return $log_id;
	}

	/**
	 * A throwaway component that records whether its subscribed method ran.
	 *
	 * @return \EDD\Cron\Components\Component
	 */
	private function get_probe_component() {
		return new class() extends \EDD\Cron\Components\Component {

			/**
			 * The component ID.
			 *
			 * @var string
			 */
			protected static $id = 'cronguard_probe';

			/**
			 * How many times the subscribed method ran.
			 *
			 * @var int
			 */
			public static $runs = 0;

			/**
			 * The subscribed events.
			 *
			 * @return array
			 */
			public static function get_subscribed_events(): array {
				return array( 'edd_cronguard_probe_event' => 'run' );
			}

			/**
			 * Records that the method ran.
			 *
			 * @return void
			 */
			public function run() {
				++self::$runs;
			}
		};
	}

	/**
	 * The same probe, but declaring its hook as request-time.
	 *
	 * @return \EDD\Cron\Components\Component
	 */
	private function get_unguarded_probe_component() {
		return new class() extends \EDD\Cron\Components\Component {

			/**
			 * The component ID.
			 *
			 * @var string
			 */
			protected static $id = 'cronguard_unguarded_probe';

			/**
			 * How many times the subscribed method ran.
			 *
			 * @var int
			 */
			public static $runs = 0;

			/**
			 * The subscribed events.
			 *
			 * @return array
			 */
			public static function get_subscribed_events(): array {
				return array( 'edd_cronguard_unguarded_probe_event' => 'run' );
			}

			/**
			 * This hook is declared as request-time, so it is wired without the guard.
			 *
			 * @return array
			 */
			public static function get_request_time_events(): array {
				return array( 'edd_cronguard_unguarded_probe_event' );
			}

			/**
			 * Records that the method ran.
			 *
			 * @return void
			 */
			public function run() {
				++self::$runs;
			}
		};
	}
}
