<?php
/**
 * Tests for the Stripe Apple Pay domain handlers.
 *
 * @package     EDD\Tests\Stripe
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Stripe;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Asserts who may drive Apple Pay domain registration, and which host it registers.
 *
 * Both handlers run on `admin_init`, so each holds its own capability check, and the host they
 * register is the site's own.
 *
 * @group edd_stripe
 * @group edd_stripe_apple_pay
 */
class ApplePayDomain extends EDD_UnitTestCase {

	/**
	 * The current user as the harness left it.
	 *
	 * @var int
	 */
	private $original_user = 0;

	/**
	 * DOCUMENT_ROOT as it was found.
	 *
	 * @var string|null
	 */
	private $original_docroot = null;

	/**
	 * A temporary document root, so a write is observable and contained.
	 *
	 * @var string
	 */
	private $docroot = '';

	public function setUp(): void {
		parent::setUp();

		$this->original_user    = get_current_user_id();
		$this->original_docroot = $_SERVER['DOCUMENT_ROOT'] ?? null;

		add_filter( 'edd_is_test_mode', '__return_false' );
		add_filter( 'edd_is_dev_environment', '__return_false' );

		edd_update_option( 'stripe_connect_account_id', 'acct_test' );

		$this->docroot            = sys_get_temp_dir() . '/edd-stripe-applepay-' . uniqid();
		$_SERVER['DOCUMENT_ROOT'] = $this->docroot;
		mkdir( $this->docroot, 0777, true );

		wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		remove_filter( 'edd_is_test_mode', '__return_false' );
		remove_filter( 'edd_is_dev_environment', '__return_false' );
		remove_all_actions( 'edds_pre_stripe_api_request' );

		edd_delete_option( 'stripe_connect_account_id' );
		edd_delete_option( 'stripe_prb_apple_pay_domain' );
		edd_delete_option( 'stripe_apple_pay_domain_error' );

		if ( is_null( $this->original_docroot ) ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->original_docroot;
		}

		$this->remove_docroot();
		wp_set_current_user( $this->original_user );

		parent::tearDown();
	}

	/**
	 * The premise: both handlers are on admin_init, and the document root starts clean so any
	 * write is this test's.
	 */
	public function test_the_handlers_are_registered_on_admin_init() {
		$this->assertNotFalse( has_action( 'admin_init', 'edds_apple_pay_check_domain' ), 'The check handler must be registered.' );
		$this->assertNotFalse( has_action( 'admin_init', 'edds_apple_pay_verify_domain' ), 'The verify handler must be registered.' );
		$this->assertDirectoryDoesNotExist( $this->docroot . '/.well-known', 'Fixture: the document root must start clean.' );
	}

	/**
	 * A caller with no capability must not cause a document-root write or a gateway call.
	 */
	public function test_verify_domain_requires_a_capability() {
		$requests = 0;
		add_action(
			'edds_pre_stripe_api_request',
			function () use ( &$requests ) {
				$requests++;
			}
		);

		edds_apple_pay_verify_domain();

		$this->assertDirectoryDoesNotExist(
			$this->docroot . '/.well-known',
			'No document-root directory may be created for a caller with no capability.'
		);
		$this->assertSame( 0, $requests, 'No gateway request may be made.' );
		$this->assertEmpty( edd_get_option( 'stripe_apple_pay_domain_error', '' ), 'No error may be recorded.' );
	}

	/**
	 * The lower-impact sibling: the stored domain must not be adopted from the request either.
	 */
	public function test_check_domain_requires_a_capability() {
		edd_update_option( 'stripe_apple_pay_domain_error', 'something went wrong' );

		$requests = 0;
		add_action(
			'edds_pre_stripe_api_request',
			function () use ( &$requests ) {
				$requests++;
			}
		);

		edds_apple_pay_check_domain();

		$this->assertSame( 0, $requests, 'No gateway request may be made.' );
		$this->assertEmpty(
			edd_get_option( 'stripe_prb_apple_pay_domain', '' ),
			'No host may be adopted for a caller with no capability.'
		);
	}

	/**
	 * The host the gateway is asked to register comes from the site address, not the request.
	 */
	public function test_the_registered_domain_comes_from_the_site_address() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'manage_shop_settings' ), 'Fixture: the actor must hold the capability.' );

		$original_host        = $_SERVER['HTTP_HOST'] ?? null;
		$_SERVER['HTTP_HOST'] = 'other.example';

		$this->assertSame(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			edds_apple_pay_domain(),
			'The registered host must come from the site address even when the request says otherwise.'
		);

		if ( is_null( $original_host ) ) {
			unset( $_SERVER['HTTP_HOST'] );
		} else {
			$_SERVER['HTTP_HOST'] = $original_host;
		}
	}

	/**
	 * Removes the temporary document root.
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
}
