<?php
/**
 * PayPal v3 ConnectSync Tests
 *
 * Tests the ConnectSync subscriber: license sync, home-URL change scheduling,
 * daily reconciliation branching (register vs refresh-license), credential
 * persistence on re-register, baseline fallback, and URL normalization.
 *
 * @package   EDD\Tests\Gateways\PayPal
 * @copyright Copyright (c) 2026, Sandhills Development, LLC
 * @license   GPL2+
 * @since     3.7.0
 */

namespace EDD\Tests\Gateways\PayPal;

use EDD\Cron\Schedulers\Handler;
use EDD\Gateways\PayPal\V3\ConnectSync;
use EDD\Gateways\PayPal\V3\Credentials;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\URL;

/**
 * Tests for the ConnectSync class.
 *
 * @group gateways
 * @group paypal
 * @group paypal-connect-sync
 */
class ConnectSyncTest extends EDD_UnitTestCase {

	/**
	 * The ConnectSync instance under test.
	 *
	 * @var ConnectSync
	 */
	private $sync;

	/**
	 * Set up fixtures and an admin user.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->clean_up_options();
		$this->sync = new ConnectSync();
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'edd_is_test_mode' );
		remove_all_filters( 'wp_doing_cron' );
		$this->clean_up_options();
		$this->unschedule_sync_hook();
		parent::tearDown();
	}

	/**
	 * Remove all credential and baseline options for both modes.
	 */
	private function clean_up_options() {
		foreach ( array( 'sandbox', 'live' ) as $mode ) {
			delete_option( "edd_paypal_{$mode}_store_id" );
			delete_option( "edd_paypal_{$mode}_hmac_key" );
			delete_option( "edd_paypal_{$mode}_hmac_key_fingerprint" );
			delete_option( "edd_paypal_{$mode}_merchant_id" );
			delete_option( sprintf( ConnectSync::REGISTERED_URL_OPTION, $mode ) );
		}
	}

	/**
	 * Mark a mode as fully v3-onboarded.
	 *
	 * @param string $mode 'sandbox' or 'live'.
	 */
	private function onboard_mode( $mode ) {
		update_option( "edd_paypal_{$mode}_store_id", "store-{$mode}" );
		Credentials::store_hmac_key( $mode, str_repeat( 'a', 64 ) );
		update_option( "edd_paypal_{$mode}_merchant_id", "MERCHANT_{$mode}" );
	}

	/**
	 * Clear any scheduled sync hook left behind by a test.
	 */
	private function unschedule_sync_hook() {
		$scheduler = Handler::get_scheduler();
		$scheduler->unschedule( ConnectSync::SYNC_HOOK, array( 'src' => 'home' ) );
	}

	/**
	 * The sync hook must not run the reconcile job for an ordinary HTTP request.
	 *
	 * ConnectSync is a SubscriberInterface listener rather than a Cron\Components\Component, so
	 * the guard in Component::subscribe() does not reach it and it carries its own check. It
	 * also takes no arguments, so it cannot fail to find a subject the way a handler expecting
	 * a scalar ID would.
	 */
	public function test_sync_hook_refuses_outside_cron() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );

		$called = false;
		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			}
		);

		// Assert the fixture twice over: the listener has to be registered, and a direct call
		// has to actually reach the Connect API. Without both, the assertion below would pass
		// against an unonboarded no-op rather than measuring the guard.
		$this->assertNotFalse(
			has_action( ConnectSync::SYNC_HOOK ),
			'Fixture: ConnectSync must be listening to its sync hook.'
		);
		$this->sync->reconcile();
		$this->assertTrue( $called, 'Fixture: an onboarded mode must reach the Connect API.' );

		$called = false;
		do_action( ConnectSync::SYNC_HOOK, array( 'edd_action' => 'paypal_v3_sync_connect' ) );

		$this->assertFalse(
			$called,
			'An HTTP request must not run the PayPal Connect reconcile job.'
		);
	}

	/**
	 * The paired positive: a real cron run must still reconcile.
	 */
	public function test_sync_hook_runs_inside_cron() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );

		$called = false;
		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			}
		);

		$this->assertNotFalse( has_action( ConnectSync::SYNC_HOOK ) );

		add_filter( 'wp_doing_cron', '__return_true' );
		do_action( ConnectSync::SYNC_HOOK );

		$this->assertTrue( $called, 'Cron must still run the PayPal Connect reconcile job.' );
	}

	/**
	 * ConnectSync implements SubscriberInterface and subscribes to the expected hooks.
	 */
	public function test_subscribed_events() {
		$this->assertInstanceOf( 'EDD\EventManagement\SubscriberInterface', $this->sync );

		$events = ConnectSync::get_subscribed_events();
		$this->assertArrayHasKey( 'edd/license/saved', $events );
		$this->assertArrayHasKey( 'edd/license/deleted', $events );
		$this->assertArrayHasKey( 'update_option_home', $events );
		$this->assertArrayHasKey( ConnectSync::SYNC_HOOK, $events );
	}

	/**
	 * on_home_url_changed schedules a deferred reconcile when the URL changes.
	 */
	public function test_on_home_url_changed_schedules_when_changed() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );

		update_option( 'home', 'https://new.example.com' );

		$scheduler = Handler::get_scheduler();
		$this->assertNotFalse( $scheduler->has_scheduled( ConnectSync::SYNC_HOOK, array( 'src' => 'home' ) ) );
	}

	/**
	 * on_home_url_changed does not schedule when the value is unchanged after
	 * trailing-slash normalization.
	 */
	public function test_on_home_url_changed_skips_when_unchanged() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );

		$this->sync->on_home_url_changed( 'https://example.com', 'https://example.com/' );

		$scheduler = Handler::get_scheduler();
		$this->assertFalse( $scheduler->has_scheduled( ConnectSync::SYNC_HOOK, array( 'src' => 'home' ) ) );
	}

	/**
	 * on_home_url_changed does not schedule when no PayPal v3 mode is onboarded.
	 */
	public function test_on_home_url_changed_skips_when_not_onboarded() {
		$this->sync->on_home_url_changed( 'https://old.example.com', 'https://new.example.com' );

		$scheduler = Handler::get_scheduler();
		$this->assertFalse( $scheduler->has_scheduled( ConnectSync::SYNC_HOOK, array( 'src' => 'home' ) ), 'No sync should be scheduled when no v3 mode is onboarded.' );
	}

	/**
	 * reconcile makes no Connect API call for a mode that is not onboarded.
	 */
	public function test_reconcile_skips_unonboarded_mode() {
		$called = false;
		add_filter( 'pre_http_request', function() use ( &$called ) {
			$called = true;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array() ),
			);
		} );

		$this->sync->reconcile();

		$this->assertFalse( $called, 'No Connect API request should be made when no mode is onboarded.' );
	}

	/**
	 * reconcile calls refresh-license (not register) when the Connect API URL matches.
	 */
	public function test_reconcile_refreshes_license_when_url_matches() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );

		$endpoints = array();
		add_filter( 'pre_http_request', function( $status, $args, $url ) use ( &$endpoints ) {
			$endpoints[] = $url;
			if ( false !== strpos( $url, '/status' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'site_url' => home_url() ) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'license_status' => 'valid' ) ),
			);
		}, 10, 3 );

		$this->sync->reconcile();

		$joined = implode( ' ', $endpoints );
		$this->assertStringContainsString( '/refresh-license', $joined );
		$this->assertStringNotContainsString( '/v3/stores/register', $joined );
	}

	/**
	 * reconcile re-registers and persists re-issued credentials when the Connect API
	 * URL has drifted from home_url().
	 */
	public function test_reconcile_reregisters_on_url_drift_and_persists_credentials() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );

		$registered = false;
		add_filter( 'pre_http_request', function( $status, $args, $url ) use ( &$registered ) {
			if ( false !== strpos( $url, '/status' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'site_url' => 'https://stale-domain.example' ) ),
				);
			}
			if ( false !== strpos( $url, '/v3/stores/register' ) ) {
				$registered = true;
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array(
						'store_id' => 'rotated-store-id',
						'hmac_key' => str_repeat( 'b', 64 ),
					) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array() ),
			);
		}, 10, 3 );

		$this->sync->reconcile();

		$this->assertTrue( $registered, 'register_store should be called on URL drift.' );
		// Re-issued credentials are persisted.
		$this->assertSame( 'rotated-store-id', Credentials::get_store_id( 'sandbox' ) );
		$this->assertSame( str_repeat( 'b', 64 ), Credentials::get_hmac_key( 'sandbox' ) );
		// The new URL becomes the baseline.
		$this->assertSame( home_url(), ConnectSync::get_registered_url( 'sandbox' ) );
	}

	/**
	 * reconcile falls back to the Connect API status GET when no local baseline exists
	 * and re-registers when that status reports a drifted URL.
	 */
	public function test_reconcile_uses_status_when_no_baseline() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );

		// No baseline option is set, so the status GET must drive the decision.
		$this->assertSame( '', ConnectSync::get_registered_url( 'sandbox' ) );

		$status_checked = false;
		add_filter( 'pre_http_request', function( $status, $args, $url ) use ( &$status_checked ) {
			if ( false !== strpos( $url, '/status' ) ) {
				$status_checked = true;
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'site_url' => home_url() ) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'license_status' => 'valid' ) ),
			);
		}, 10, 3 );

		$this->sync->reconcile();

		$this->assertTrue( $status_checked, 'The Connect API status GET should be consulted when no baseline exists.' );
		// Status matched home_url(), so the baseline is recorded.
		$this->assertSame( home_url(), ConnectSync::get_registered_url( 'sandbox' ) );
	}

	/**
	 * reconcile does not re-register when home_url() looks like a staging host,
	 * even though the Connect API's stored URL has drifted — this guards
	 * against a production clone silently hijacking the Connect API
	 * registration.
	 */
	public function test_reconcile_skips_reregister_for_staging_host() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );
		update_option( 'home', 'https://clone.wpengine.com' );

		$registered = false;
		add_filter( 'pre_http_request', function( $status, $args, $url ) use ( &$registered ) {
			if ( false !== strpos( $url, '/status' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'site_url' => 'https://production.example.com' ) ),
				);
			}
			if ( false !== strpos( $url, '/v3/stores/register' ) ) {
				$registered = true;
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array() ),
			);
		}, 10, 3 );

		$this->sync->reconcile();

		$this->assertFalse( $registered, 'register_store should never be called for a staging-looking host.' );
		$this->assertSame( '', ConnectSync::get_registered_url( 'sandbox' ), 'The baseline should not be updated when re-register is skipped.' );
	}

	/**
	 * A failure on one mode does not prevent the other mode from being reconciled.
	 */
	public function test_reconcile_isolates_mode_failures() {
		add_filter( 'edd_is_test_mode', '__return_true' );
		$this->onboard_mode( 'sandbox' );
		$this->onboard_mode( 'live' );

		$live_refreshed = false;
		add_filter( 'pre_http_request', function( $status, $args, $url ) use ( &$live_refreshed ) {
			$mode = $args['headers']['X-EDD-PayPal-Mode'] ?? '';

			// Sandbox status GET hard-fails.
			if ( 'sandbox' === $mode && false !== strpos( $url, '/status' ) ) {
				return new \WP_Error( 'http_request_failed', 'Boom.' );
			}
			if ( 'sandbox' === $mode ) {
				return new \WP_Error( 'http_request_failed', 'Boom.' );
			}

			// Live succeeds.
			if ( false !== strpos( $url, '/status' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'site_url' => home_url() ) ),
				);
			}
			if ( false !== strpos( $url, '/refresh-license' ) ) {
				$live_refreshed = true;
			}
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'license_status' => 'valid' ) ),
			);
		}, 10, 3 );

		$this->sync->reconcile();

		$this->assertTrue( $live_refreshed, 'Live mode should still reconcile after sandbox fails.' );
	}

	/**
	 * sync_license posts refresh-license for each onboarded mode with the license key.
	 */
	public function test_sync_license_refreshes_each_onboarded_mode() {
		$this->onboard_mode( 'sandbox' );
		$this->onboard_mode( 'live' );

		$refreshed_modes = array();
		add_filter( 'pre_http_request', function( $status, $args, $url ) use ( &$refreshed_modes ) {
			if ( false !== strpos( $url, '/refresh-license' ) ) {
				$refreshed_modes[] = $args['headers']['X-EDD-PayPal-Mode'] ?? '';
			}
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'license_status' => 'valid' ) ),
			);
		}, 10, 3 );

		$this->sync->sync_license();

		$this->assertContains( 'sandbox', $refreshed_modes );
		$this->assertContains( 'live', $refreshed_modes );
	}

	/**
	 * sync_license skips modes that are not onboarded.
	 */
	public function test_sync_license_skips_unonboarded_modes() {
		$this->onboard_mode( 'sandbox' );

		$refreshed_modes = array();
		add_filter( 'pre_http_request', function( $status, $args, $url ) use ( &$refreshed_modes ) {
			if ( false !== strpos( $url, '/refresh-license' ) ) {
				$refreshed_modes[] = $args['headers']['X-EDD-PayPal-Mode'] ?? '';
			}
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'license_status' => 'valid' ) ),
			);
		}, 10, 3 );

		$this->sync->sync_license();

		$this->assertContains( 'sandbox', $refreshed_modes );
		$this->assertNotContains( 'live', $refreshed_modes );
	}

	/**
	 * URL::normalize treats trailing-slash and scheme-case differences as equal.
	 */
	public function test_normalize_url_equivalence() {
		$a = URL::normalize( 'HTTPS://Example.com/' );
		$b = URL::normalize( 'https://example.com' );

		$this->assertSame( $a, $b );
	}

	/**
	 * URL::normalize keeps genuinely different hosts distinct.
	 */
	public function test_normalize_url_distinguishes_hosts() {
		$a = URL::normalize( 'https://example.com' );
		$b = URL::normalize( 'https://other.example.com' );

		$this->assertNotSame( $a, $b );
	}

	/**
	 * URL::normalize reduces a Unicode IDN host and its Punycode equivalent to the same string.
	 */
	public function test_normalize_url_idn_unicode_and_punycode_are_equal() {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'idn_to_ascii() is not available.' );
		}

		$unicode  = URL::normalize( 'https://münchen.de' );
		$punycode = URL::normalize( 'https://xn--mnchen-3ya.de' );

		$this->assertSame( $unicode, $punycode );
	}

	/**
	 * get_registered_url round-trips through the persisted option.
	 */
	public function test_registered_url_round_trip() {
		$this->assertSame( '', ConnectSync::get_registered_url( 'sandbox' ) );

		update_option( sprintf( ConnectSync::REGISTERED_URL_OPTION, 'sandbox' ), 'https://example.com' );

		$this->assertSame( 'https://example.com', ConnectSync::get_registered_url( 'sandbox' ) );
	}
}
