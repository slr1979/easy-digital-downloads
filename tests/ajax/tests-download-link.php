<?php
/**
 * Tests for the order details file download link AJAX handler.
 *
 * @package     EDD\Tests\Ajax
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Ajax;

defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use EDD\Tests\Helpers;
use EDD\Tests\PHPUnit\Ajax_UnitTestCase;

/**
 * Tests for the order details file download link AJAX handler.
 *
 * @since 3.7.1
 */
class DownloadLinkAjax extends Ajax_UnitTestCase {

	/**
	 * A download carrying a non-zero file download limit, not on the order fixture.
	 *
	 * @var int
	 */
	protected static $download_not_on_order;

	/**
	 * A completed order.
	 *
	 * @var int
	 */
	protected static $order_id;

	/**
	 * The product ID of the first item on the order fixture.
	 *
	 * @var int
	 */
	protected static $purchased_download;

	/**
	 * Creates the fixtures and requires the handler's file once.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once EDD_PLUGIN_DIR . 'includes/admin/payments/actions.php';

		$download = Helpers\EDD_Helper_Download::create_simple_download();
		update_post_meta( $download->ID, '_edd_download_limit', 1 );
		self::$download_not_on_order = $download->ID;

		self::$order_id = Helpers\EDD_Helper_Payment::create_simple_payment();

		$order_items              = edd_get_order_items(
			array(
				'order_id' => self::$order_id,
				'number'   => 1,
			)
		);
		self::$purchased_download = $order_items[0]->product_id;
		update_post_meta( self::$purchased_download, '_edd_download_limit', 1 );

		// The download factory seeds an override row keyed to order 1, which a fresh
		// install's first order always is; clear it so the fixtures start unraised.
		delete_post_meta( self::$download_not_on_order, '_edd_download_limit_override_' . self::$order_id );
		delete_post_meta( self::$purchased_download, '_edd_download_limit_override_' . self::$order_id );
	}

	/**
	 * Re-attaches the handler's hook and switches to a capable administrator.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		add_action( 'wp_ajax_edd_get_file_download_link', 'edd_ajax_generate_file_download_link' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Granted explicitly: the handler must clear this gate before the nonce check can mean anything.
		$user = new \WP_User( $user_id );
		$user->add_cap( 'view_shop_reports' );

		wp_set_current_user( $user_id );
	}

	/**
	 * Clears any override meta a test may have raised.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_post_meta( self::$download_not_on_order, '_edd_download_limit_override_' . self::$order_id );
		delete_post_meta( self::$purchased_download, '_edd_download_limit_override_' . self::$order_id );

		parent::tear_down();
	}

	/**
	 * The handler must be attached to its AJAX hook.
	 *
	 * @return void
	 */
	public function test_the_handler_is_on_its_hook() {
		$this->assertNotFalse( has_action( 'wp_ajax_edd_get_file_download_link', 'edd_ajax_generate_file_download_link' ) );
	}

	/**
	 * A request with no nonce must be refused before the override is raised.
	 *
	 * @return void
	 */
	public function test_handler_requires_a_nonce() {
		$this->assertTrue(
			current_user_can( apply_filters( 'edd_view_customers_role', 'view_shop_reports' ) ),
			'The actor must clear the capability gate, so that only the nonce can refuse the request.'
		);
		$this->assertNotEmpty(
			edd_get_file_download_limit( self::$purchased_download ),
			'The download needs a limit, or the override path is never reached and the assertion below is vacuous.'
		);

		$_POST = array(
			'payment_id'  => self::$order_id,
			'download_id' => self::$purchased_download,
		);

		$this->assertFalse(
			check_ajax_referer( 'edd_get_file_download_link', 'nonce', false ),
			'The seeded request carries no nonce, so the nonce check is the one that must refuse it.'
		);
		$this->assertSame(
			'-1',
			$this->response(),
			'The nonce check must be what refuses, not a later gate: -1 is its response, -2 and -3 belong to the order and item checks.'
		);
		$this->assertEmpty(
			get_post_meta( self::$purchased_download, '_edd_download_limit_override_' . self::$order_id, true ),
			'The refusal has to happen before the override is raised.'
		);
	}

	/**
	 * A nonce minted for another action must not pass, so the check cannot be
	 * satisfied by any value merely being present.
	 *
	 * @return void
	 */
	public function test_handler_refuses_a_nonce_for_a_different_action() {
		$_POST = array(
			'payment_id'  => self::$order_id,
			'download_id' => self::$purchased_download,
			'nonce'       => wp_create_nonce( 'edd_some_other_action' ),
		);

		$this->assertSame(
			'-1',
			$this->response(),
			'The nonce check must be what refuses, not a later gate.'
		);
		$this->assertEmpty(
			get_post_meta( self::$purchased_download, '_edd_download_limit_override_' . self::$order_id, true ),
			'The refusal has to happen before the override is raised.'
		);
	}

	/**
	 * A user without the reports capability must be refused, regardless of the nonce.
	 *
	 * @return void
	 */
	public function test_a_user_without_the_reports_capability_is_refused() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertTrue( user_can( $subscriber_id, 'read' ), 'The actor must have capabilities at all.' );
		$this->assertFalse( user_can( $subscriber_id, 'view_shop_reports' ), 'The actor must lack this one capability for the test to mean anything.' );

		// The nonce is user-scoped, so issue it after the role switch.
		$_POST = array(
			'payment_id'  => self::$order_id,
			'download_id' => self::$purchased_download,
			'nonce'       => wp_create_nonce( 'edd_get_file_download_link' ),
		);

		$this->assertSame( '-1', $this->response(), 'A user without the capability must be refused.' );
		$this->assertEmpty(
			get_post_meta( self::$purchased_download, '_edd_download_limit_override_' . self::$order_id, true ),
			'The refusal has to happen before the override is raised.'
		);
	}

	/**
	 * An order that does not resolve must be refused before the override is raised.
	 *
	 * @return void
	 */
	public function test_handler_refuses_an_order_that_does_not_exist() {
		$absent_order = 424242;
		$this->assertFalse( edd_get_order( $absent_order ), 'The order must not exist for this test to mean anything.' );

		$_POST = array(
			'payment_id'  => $absent_order,
			'download_id' => self::$download_not_on_order,
			'nonce'       => wp_create_nonce( 'edd_get_file_download_link' ),
		);

		$this->assertSame( '-2', $this->response(), 'An order that does not resolve must be refused.' );
		$this->assertEmpty(
			get_post_meta( self::$download_not_on_order, '_edd_download_limit_override_' . $absent_order, true ),
			'No override row may be created for an order that does not exist.'
		);
	}

	/**
	 * A product the order never bought must be refused, so no link is minted for it.
	 *
	 * @return void
	 */
	public function test_handler_refuses_a_product_that_is_not_on_the_order() {
		$this->assertNotEmpty(
			edd_get_order_items( array( 'order_id' => self::$order_id, 'number' => 1 ) ),
			'The order must have items, or an empty result would refuse for the wrong reason.'
		);
		$this->assertEmpty(
			edd_get_order_items(
				array(
					'order_id'   => self::$order_id,
					'product_id' => self::$download_not_on_order,
					'number'     => 1,
				)
			),
			'The product must not be on the order for this test to mean anything.'
		);

		$_POST = array(
			'payment_id'  => self::$order_id,
			'download_id' => self::$download_not_on_order,
			'nonce'       => wp_create_nonce( 'edd_get_file_download_link' ),
		);

		$this->assertSame( '-3', $this->response(), 'A product that is not on the order must be refused.' );
		$this->assertEmpty(
			get_post_meta( self::$download_not_on_order, '_edd_download_limit_override_' . self::$order_id, true ),
			'The refusal has to happen before the override is raised.'
		);
	}

	/**
	 * A purchased product must mint signed download links and raise the override.
	 *
	 * @return void
	 */
	public function test_a_purchased_product_returns_its_signed_links() {
		$this->assertNotEmpty(
			edd_get_download_files( self::$purchased_download ),
			'The download needs files, or a link can never be minted and the assertions below are vacuous.'
		);
		$this->assertNotEmpty(
			edd_get_order_items(
				array(
					'order_id'   => self::$order_id,
					'product_id' => self::$purchased_download,
					'number'     => 1,
				)
			),
			'The product must be on the order for this test to mean anything.'
		);

		$_POST = array(
			'payment_id'  => self::$order_id,
			'download_id' => self::$purchased_download,
			'nonce'       => wp_create_nonce( 'edd_get_file_download_link' ),
		);

		$response = $this->response();

		$this->assertStringContainsString( 'eddfile=', $response, 'A purchased product must mint a signed download link.' );
		$this->assertNotContains( $response, array( '-1', '-2', '-3', '-4' ), 'A purchased product must not be refused.' );
		$this->assertNotEmpty(
			get_post_meta( self::$purchased_download, '_edd_download_limit_override_' . self::$order_id, true ),
			'Minting a link for a limited download must raise the override.'
		);
	}

	/**
	 * Dispatches the handler and returns the body of whatever it exits with.
	 *
	 * @return string
	 */
	private function response() {
		try {
			return $this->_handleAjax( 'edd_get_file_download_link' );
		} catch ( \WPAjaxDieStopException $e ) {
			return $e->getMessage();
		}
	}
}
