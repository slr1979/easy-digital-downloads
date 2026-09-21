<?php
/**
 * PayPal Apple Pay domain-association tests.
 *
 * Covers the terminal-ineligibility guard and transient-failure cooldown
 * that stop DomainAssociation::install() from retrying on every admin page
 * load when the merchant's PayPal account isn't approved for Apple Pay.
 *
 * @package   EDD\Tests\Gateways\PayPal
 * @copyright Copyright (c) 2026, Sandhills Development, LLC
 * @license   GPL2+
 * @since     3.7.0
 */

namespace EDD\Tests\Gateways\PayPal;

use EDD\Gateways\PayPal\V3\ApplePay\DomainAssociation;
use EDD\Gateways\PayPal\V3\ApplePay\DomainSubscriber;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for the Apple Pay domain-association retry guards.
 *
 * @group gateways
 * @group paypal
 * @group paypal-applepay
 */
class ApplePayDomainTest extends EDD_UnitTestCase {

	/**
	 * Temporary document root used so write_to_docroot() can succeed.
	 *
	 * @var string
	 */
	private $docroot = '';

	/**
	 * The original $_SERVER['DOCUMENT_ROOT'] to restore on teardown.
	 *
	 * @var string|null
	 */
	private $original_docroot = null;

	/**
	 * The current user as the harness left it, restored afterwards.
	 *
	 * @var int
	 */
	private $original_user = 0;

	/**
	 * Set up V3 connection options and a writable document root.
	 */
	public function setUp(): void {
		parent::setUp();

		// Live mode so should_verify() passes its test-mode/dev checks.
		add_filter( 'edd_is_test_mode', '__return_false' );
		add_filter( 'edd_is_dev_environment', '__return_false' );

		update_option( 'edd_paypal_live_commerce_version', 'v3' );
		update_option( 'edd_paypal_live_store_id', 'test-store-id' );
		update_option( 'edd_paypal_live_hmac_key', str_repeat( 'a', 64 ) );
		update_option( 'edd_paypal_live_merchant_id', 'MERCHANT_ID' );

		// These cases change the acting user, and the harness does not reset it between tests.
		$this->original_user = get_current_user_id();

		// Registering a domain requires the capability that administers the store, so the cases
		// below act as someone who holds it. test_verify_domain_requires_a_capability covers the refusal.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->docroot          = sys_get_temp_dir() . '/edd-applepay-' . uniqid();
		$this->original_docroot = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = $this->docroot;
		mkdir( $this->docroot, 0777, true );
	}

	/**
	 * Clean up options, filters, HTTP mocks, and the temporary docroot.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_filter( 'edd_is_test_mode', '__return_false' );
		remove_filter( 'edd_is_dev_environment', '__return_false' );

		delete_option( 'edd_paypal_live_commerce_version' );
		delete_option( 'edd_paypal_live_store_id' );
		delete_option( 'edd_paypal_live_hmac_key' );
		delete_option( 'edd_paypal_live_merchant_id' );
		delete_option( DomainAssociation::HOST_OPTION );
		delete_option( DomainAssociation::ERROR_OPTION );
		delete_option( DomainAssociation::INELIGIBLE_OPTION );
		delete_option( DomainAssociation::RETRY_OPTION );
		delete_transient( DomainAssociation::CONTENT_TRANSIENT );

		if ( null === $this->original_docroot ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->original_docroot;
		}

		$this->remove_docroot();

		wp_set_current_user( $this->original_user );

		parent::tearDown();
	}

	/**
	 * Recursively removes the temporary document root.
	 *
	 * @return void
	 */
	private function remove_docroot() {
		if ( '' === $this->docroot || ! is_dir( $this->docroot ) ) {
			return;
		}

		$well_known = $this->docroot . '/.well-known';
		$file       = $well_known . '/apple-developer-merchantid-domain-association';

		if ( file_exists( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( $well_known ) ) {
			rmdir( $well_known );
		}
		rmdir( $this->docroot );
	}

	/**
	 * Mocks the Connect HTTP layer: the domain-association GET returns file
	 * content, and the register-domain POST returns the supplied result.
	 *
	 * @param array $register_response The wp_remote_request return for the
	 *                                 register-domain POST.
	 * @param int   $register_count    Reference incremented each time the POST fires.
	 * @return void
	 */
	private function mock_connect( array $register_response, &$register_count ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $register_response, &$register_count ) {
				if ( false !== strpos( $url, '/applepay/register-domain' ) ) {
					$register_count++;
					return $register_response;
				}

				if ( false !== strpos( $url, '/applepay/domain-association' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'file' => 'APPLE-PAY-FILE-CONTENT' ) ),
					);
				}

				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * The premise: this route runs on admin_init, so it has to hold its own capability check.
	 */
	public function test_the_route_is_registered_on_admin_init() {
		$this->assertArrayHasKey(
			'admin_init',
			DomainSubscriber::get_subscribed_events(),
			'The verify route runs on admin_init.'
		);
	}

	/**
	 * A caller with no capability must not drive domain registration.
	 */
	public function test_verify_domain_requires_a_capability() {
		wp_set_current_user( 0 );

		$register_count = 0;
		$this->mock_connect( array( 'response' => array( 'code' => 200 ), 'body' => '{}' ), $register_count );

		// Nothing is installed, so the flow would run and register: the capability is the only
		// thing left to refuse it. Planting a valid baseline instead would make this pass
		// whatever the gate does, because the host no longer varies with the request.
		$this->assertFalse( get_option( DomainAssociation::HOST_OPTION ), 'Fixture: no host may be stored yet.' );
		$this->assertDirectoryDoesNotExist( $this->docroot . '/.well-known', 'Fixture: nothing may be installed yet.' );

		( new DomainSubscriber() )->verify_domain();

		$this->assertSame( 0, $register_count, 'No registration may be attempted for a caller with no capability.' );
		$this->assertFalse( get_option( DomainAssociation::HOST_OPTION ), 'No host may be stored.' );
		$this->assertFalse( get_option( DomainAssociation::ERROR_OPTION ), 'No error may be recorded.' );
		$this->assertFalse( get_option( DomainAssociation::RETRY_OPTION ), 'No retry may be scheduled.' );
	}

	/**
	 * The paired positive: a caller who administers the store still reaches the flow.
	 */
	public function test_verify_domain_runs_for_a_shop_settings_manager() {
		$this->assertTrue( current_user_can( 'manage_shop_settings' ), 'Fixture: the actor must hold the capability.' );

		$register_count = 0;
		$this->mock_connect( array( 'response' => array( 'code' => 200 ), 'body' => '{}' ), $register_count );

		( new DomainSubscriber() )->verify_domain();

		$this->assertSame( 1, $register_count, 'A store administrator must still be able to register the domain.' );
	}

	/**
	 * The domain registered is the site's own, not whatever the request asked for.
	 */
	public function test_the_registered_domain_comes_from_the_site_address() {
		$original_host        = $_SERVER['HTTP_HOST'] ?? null;
		$_SERVER['HTTP_HOST'] = 'other.example';

		$submitted = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$submitted ) {
				if ( false !== strpos( $url, '/applepay/register-domain' ) ) {
					$body      = json_decode( $args['body'] ?? '{}', true );
					$submitted = $body['domain'] ?? ( $body['domain_name'] ?? null );

					return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
				}
				if ( false !== strpos( $url, '/applepay/domain-association' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'file' => 'APPLE-PAY-FILE-CONTENT' ) ),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		( new DomainSubscriber() )->verify_domain();

		if ( is_null( $original_host ) ) {
			unset( $_SERVER['HTTP_HOST'] );
		} else {
			$_SERVER['HTTP_HOST'] = $original_host;
		}
		$expected = wp_parse_url( home_url(), PHP_URL_HOST );

		$this->assertNotNull( $submitted, 'Fixture: the registration request must have been built.' );
		$this->assertSame( $expected, $submitted, 'The registered domain must come from the site address.' );
		$this->assertSame( $expected, get_option( DomainAssociation::HOST_OPTION ), 'The stored host must be the site address.' );
	}

	/**
	 * Re-verify drops the host PayPal actually holds, not the one derived now.
	 *
	 * A store that registered under a different host before the domain came from the site
	 * address would otherwise leave that registration in place.
	 */
	public function test_reverify_deregisters_the_recorded_host() {
		update_option( DomainAssociation::HOST_OPTION, 'previously-registered.example' );

		$deregistered = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$deregistered ) {
				// Deregistration is a DELETE to the same path registration POSTs to, so the
				// method is what tells them apart.
				if ( false !== strpos( $url, '/applepay/register-domain' ) ) {
					if ( 'DELETE' === strtoupper( $args['method'] ?? '' ) ) {
						$body         = json_decode( $args['body'] ?? '{}', true );
						$deregistered = $body['domain'] ?? null;
					}

					return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
				}
				if ( false !== strpos( $url, '/applepay/domain-association' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'file' => 'APPLE-PAY-FILE-CONTENT' ) ),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		DomainAssociation::reverify();

		$this->assertSame(
			'previously-registered.example',
			$deregistered,
			'Re-verify must drop the host that was recorded as registered.'
		);
	}

	/**
	 * A 403 applepay_not_available response flags the account ineligible.
	 */
	public function test_install_flags_ineligible_on_terminal_error() {
		$count = 0;
		$this->mock_connect(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 'applepay_not_available',
							'message' => 'This account is not subscribed to Apple Pay.',
						),
					)
				),
			),
			$count
		);

		$threw = false;
		try {
			DomainAssociation::install();
		} catch ( \RuntimeException $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'install() should throw on a terminal error.' );
		$this->assertSame( '1', get_option( DomainAssociation::INELIGIBLE_OPTION ) );
		// Terminal failures must not schedule a retry.
		$this->assertEmpty( get_option( DomainAssociation::RETRY_OPTION, '' ) );
	}

	/**
	 * verify_domain() does not call install() while the ineligible flag is set.
	 */
	public function test_verify_domain_skips_when_ineligible() {
		update_option( DomainAssociation::INELIGIBLE_OPTION, '1' );

		$count = 0;
		$this->mock_connect(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'status' => 'COMPLETED' ) ),
			),
			$count
		);

		$subscriber = new DomainSubscriber();
		$subscriber->verify_domain();

		$this->assertSame( 0, $count, 'No registration HTTP call should fire when ineligible.' );
		$this->assertEmpty( get_option( DomainAssociation::HOST_OPTION, '' ) );
	}

	/**
	 * uninstall() clears both the ineligible flag and the retry timestamp.
	 */
	public function test_uninstall_clears_guards() {
		update_option( DomainAssociation::INELIGIBLE_OPTION, '1' );
		update_option( DomainAssociation::RETRY_OPTION, time() + HOUR_IN_SECONDS );

		DomainAssociation::uninstall();

		$this->assertEmpty( get_option( DomainAssociation::INELIGIBLE_OPTION, '' ) );
		$this->assertEmpty( get_option( DomainAssociation::RETRY_OPTION, '' ) );
	}

	/**
	 * reverify() clears the ineligible flag even when the re-install fails.
	 */
	public function test_reverify_clears_ineligible_flag() {
		update_option( DomainAssociation::INELIGIBLE_OPTION, '1' );

		// Make every Connect call fail so reverify() throws after clearing guards.
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'Connection timed out.' );
			},
			10,
			3
		);

		try {
			DomainAssociation::reverify();
		} catch ( \Throwable $e ) {
			// Expected — the deregister/install calls fail in the test harness.
		}

		$this->assertEmpty( get_option( DomainAssociation::INELIGIBLE_OPTION, '' ) );
	}

	/**
	 * A transient failure schedules a future retry and the cooldown is honored.
	 */
	public function test_transient_failure_sets_cooldown_and_is_respected() {
		$count = 0;
		$this->mock_connect(
			array(
				'response' => array( 'code' => 503 ),
				'body'     => 'Service Unavailable',
			),
			$count
		);

		$subscriber = new DomainSubscriber();
		$subscriber->verify_domain();

		// First attempt fired the registration POST and recorded a future retry.
		$this->assertSame( 1, $count, 'The first verify should attempt registration.' );
		$next_retry = (int) get_option( DomainAssociation::RETRY_OPTION, 0 );
		$this->assertGreaterThan( time(), $next_retry, 'A future retry should be scheduled.' );
		$this->assertEmpty( get_option( DomainAssociation::INELIGIBLE_OPTION, '' ), 'A transient failure is not terminal.' );

		// Second attempt within the cooldown window must not call install().
		$subscriber->verify_domain();
		$this->assertSame( 1, $count, 'A second verify within the cooldown should not retry.' );
	}

	/**
	 * A transient fetch failure (5xx on the domain-association GET) also sets the cooldown.
	 */
	public function test_fetch_failure_sets_cooldown() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/applepay/domain-association' ) ) {
					return array(
						'response' => array( 'code' => 503 ),
						'body'     => 'Service Unavailable',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$subscriber = new DomainSubscriber();
		$subscriber->verify_domain();

		$next_retry = (int) get_option( DomainAssociation::RETRY_OPTION, 0 );
		$this->assertGreaterThan( time(), $next_retry, 'A fetch-side failure should schedule a retry.' );
		$this->assertEmpty( get_option( DomainAssociation::INELIGIBLE_OPTION, '' ), 'A fetch failure is not terminal.' );
	}

	/**
	 * uninstall() clears a previously scheduled retry timestamp.
	 */
	public function test_uninstall_clears_cooldown() {
		update_option( DomainAssociation::RETRY_OPTION, time() + 2 * HOUR_IN_SECONDS );

		DomainAssociation::uninstall();

		$this->assertEmpty( get_option( DomainAssociation::RETRY_OPTION, '' ) );
	}
}
