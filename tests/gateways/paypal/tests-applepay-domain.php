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
