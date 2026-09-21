<?php
/**
 * Base test case for EDD AJAX handler tests.
 *
 * @package   EDD\Tests\PHPUnit
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.1
 */

namespace EDD\Tests\PHPUnit;

defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

/**
 * Defines a basic fixture to run AJAX tests.
 *
 * Builds on EDD_UnitTestCase (roles, capabilities, factory, EDD data teardown) and adds the
 * AJAX plumbing: handlers are dispatched with AJAX mode forced on via the `wp_doing_ajax`
 * filter (never the `DOING_AJAX` constant, which production no longer consults), and their
 * output is captured through a `wp_die_ajax_handler` that throws instead of exiting the process.
 *
 * All EDD AJAX unit tests should inherit from this class.
 */
abstract class Ajax_UnitTestCase extends EDD_UnitTestCase {

	/**
	 * The captured output from the most recent AJAX dispatch.
	 *
	 * @var string
	 */
	protected $_last_response = '';

	/**
	 * Forces AJAX mode on and installs the capturing die handler for each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Force AJAX mode via the filter production actually checks (edd_doing_ajax() -> wp_doing_ajax()).
		add_filter( 'wp_doing_ajax', '__return_true' );

		// Capture wp_die()/edd_die() output instead of exiting the test process.
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_die_handler' ), 1, 1 );

		// The cart/session is a persistent singleton not rolled back between tests; start clean.
		edd_empty_cart();
		edd_unset_all_cart_discounts();
	}

	/**
	 * Restores request state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_die_handler' ), 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$_POST    = array();
		$_REQUEST = array();

		// Empty the cart and discounts so state does not leak between tests. has_discounts is a
		// memo on the cart singleton that clearing does not invalidate, so reset it too.
		EDD()->session->set( 'edd_cart', null );
		edd_empty_cart();
		edd_unset_all_cart_discounts();
		EDD()->cart->has_discounts = null;

		// The tax rate is memoized the first time the cart prices an item, so a test that enables
		// taxes would otherwise fix the rate for every test after it.
		EDD()->cart->set_tax_rate( null );

		parent::tearDown();
	}

	/**
	 * Returns the AJAX die handler callback.
	 *
	 * @return callable
	 */
	public function get_die_handler() {
		return array( $this, 'ajax_die_handler' );
	}

	/**
	 * Captures buffered AJAX output and short-circuits the die with an exception.
	 *
	 * Mirrors WP core's WP_Ajax_UnitTestCase: a die with buffered output throws the
	 * "continue" exception (so the response can be inspected), while a die with no output
	 * (e.g. wp_die( '-1' ) on a permission failure) throws the "stop" exception carrying
	 * the scalar message.
	 *
	 * @param string $message The die message.
	 * @return void
	 * @throws \WPAjaxDieContinueException When the handler produced output.
	 * @throws \WPAjaxDieStopException     When the handler produced no output.
	 */
	public function ajax_die_handler( $message ) {
		// Always close our buffer so it never leaks when the stop exception propagates.
		if ( ob_get_level() ) {
			$this->_last_response .= ob_get_clean();
		}

		if ( '' === $this->_last_response ) {
			if ( is_scalar( $message ) ) {
				throw new \WPAjaxDieStopException( (string) $message );
			}
			throw new \WPAjaxDieStopException( '0' );
		}

		throw new \WPAjaxDieContinueException( $message );
	}

	/**
	 * Dispatches an AJAX action and returns the captured response.
	 *
	 * Populates `$_POST['action']`/`$_REQUEST`, runs `wp_ajax_{$action}`, and captures whatever
	 * the handler echoes before it calls edd_die()/wp_die().
	 *
	 * Like WP core's WP_Ajax_UnitTestCase, only the "continue" exception (a die that produced
	 * output) is swallowed; the "stop" exception (a bare die such as `wp_die( '-1' )`) propagates
	 * so callers can inspect its message. Handlers that early-return without dying (e.g. a failed
	 * nonce check) simply yield an empty response.
	 *
	 * @param string $action The AJAX action, without the `wp_ajax_` prefix.
	 * @return string The captured response body.
	 * @throws \WPAjaxDieStopException When the handler dies without producing output.
	 */
	protected function _handleAjax( $action ) {
		$this->_last_response = '';

		$_POST['action'] = $action;
		$_REQUEST        = $_POST;

		$level = ob_get_level();
		ob_start();
		try {
			do_action( 'wp_ajax_' . $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		// On a die, ajax_die_handler() already captured and closed our buffer. If the handler
		// returned without dying, our buffer is still open — capture and close it here.
		if ( ob_get_level() > $level ) {
			$this->_last_response .= ob_get_clean();
		}

		return $this->_last_response;
	}

	/**
	 * Creates a user with the given role and makes it the current user.
	 *
	 * Preserves the current `$_POST` across the user switch, matching WP core's
	 * WP_Ajax_UnitTestCase::_setRole().
	 *
	 * @param string $role The role to assign the new current user.
	 * @return void
	 */
	protected function _setRole( $role ) {
		$post    = $_POST;
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		$_POST = array_merge( $_POST, $post );
	}

	/**
	 * Creates a simple, fixed-price published download for cart/checkout AJAX tests.
	 *
	 * Deliberately not EDD_Helper_Download::create_simple_download(): that returns a WP_Post and
	 * seeds meta these handlers shouldn't see (hidden purchase link, fake sales and earnings).
	 *
	 * @param float $price The download price.
	 * @return int The download ID.
	 */
	protected function create_ajax_download( $price = 20.00 ) {
		$download_id = self::factory()->post->create(
			array(
				'post_title'  => 'AJAX Test Download',
				'post_type'   => 'download',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $download_id, 'edd_price', edd_sanitize_amount( $price ) );
		update_post_meta( $download_id, '_edd_product_type', 'default' );
		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'name'      => 'File 1',
					'file'      => 'http://localhost/file1.jpg',
					'condition' => 'all',
				),
			)
		);

		return $download_id;
	}

	/**
	 * Creates a published variable-priced download with two price options.
	 *
	 * Deliberately not EDD_Helper_Download::create_variable_download(), for the same reason as
	 * create_ajax_download(): it seeds meta these handlers shouldn't see.
	 *
	 * @param array $prices Price option amounts, keyed by price ID.
	 * @return int The download ID.
	 */
	protected function create_ajax_variable_download( $prices = array( 20.00, 100.00 ) ) {
		$download_id = self::factory()->post->create(
			array(
				'post_title'  => 'AJAX Variable Test Download',
				'post_type'   => 'download',
				'post_status' => 'publish',
			)
		);

		$variable_prices = array();
		foreach ( $prices as $price_id => $amount ) {
			$variable_prices[ $price_id ] = array(
				'name'   => 'Option ' . $price_id,
				'amount' => edd_sanitize_amount( $amount ),
			);
		}

		update_post_meta( $download_id, '_variable_pricing', 1 );
		update_post_meta( $download_id, 'edd_variable_prices', $variable_prices );
		update_post_meta( $download_id, '_edd_product_type', 'default' );
		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'name'      => 'File 1',
					'file'      => 'http://localhost/file1.jpg',
					'condition' => 'all',
				),
			)
		);

		return $download_id;
	}
}
