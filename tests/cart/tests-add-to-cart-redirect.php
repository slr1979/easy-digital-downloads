<?php
/**
 * Add to Cart Redirect Cleanup Tests
 *
 * Regression guard for #2609: a `?discount=CODE` buy link that also adds an item
 * to the cart must keep the discount in the post-add redirect so it survives to
 * the next page load. #2443 added `discount` to the removable args, which
 * silently dropped coupons on sites whose `edd_pre_add_to_cart` hooks mutate the
 * cart during the add.
 *
 * @package     EDD\Tests\Cart
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Cart;

use EDD\Cart\AddToCartRedirectCleanup;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Add to cart redirect cleanup tests.
 *
 * @group edd_cart
 * @group edd_discounts
 */
class AddToCartRedirect extends EDD_UnitTestCase {

	/**
	 * Original REQUEST_URI, restored after each test.
	 *
	 * @var string
	 */
	private $original_request_uri;

	/**
	 * Original REQUEST_METHOD, restored after each test.
	 *
	 * @var string
	 */
	private $original_request_method;

	public function set_up() {
		parent::set_up();
		$this->original_request_uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$this->original_request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
	}

	public function tear_down() {
		$_SERVER['REQUEST_URI']    = $this->original_request_uri;
		$_SERVER['REQUEST_METHOD'] = $this->original_request_method;
		unset( $_REQUEST['discount'] );
		EDD()->session->set( 'preset_discount', null );
		edd_unset_all_cart_discounts();
		parent::tear_down();
	}

	/**
	 * The discount arg must never be in the removable list: it has to survive the
	 * add-to-cart redirect so a preset discount can be re-applied on the next load
	 * even if a cart-mutation hook dropped it during the add.
	 */
	public function test_discount_is_not_a_removable_arg() {
		$this->assertNotContains(
			'discount',
			edd_cart_removable_query_args(),
			'The discount query arg must survive the add-to-cart redirect (#2609).'
		);
	}

	/**
	 * Mirrors the two steps edd_process_add_to_cart() runs before redirecting on
	 * the straight-to-checkout path: remove_query_arg() against the current request
	 * URI, then grafting the surviving query string onto the checkout URI. The
	 * discount must survive that reconstruction while the action plumbing is stripped.
	 */
	public function test_straight_to_checkout_reconstruction_preserves_discount() {
		$_SERVER['REQUEST_URI'] = '/downloads/?edd_action=add_to_cart&download_id=42&edd_options%5Bprice_id%5D=1&edd_download_quantity=1&discount=SAVE20';

		$query_args     = remove_query_arg( edd_cart_removable_query_args() );
		$query_part     = strpos( $query_args, '?' );
		$url_parameters = false !== $query_part ? substr( $query_args, $query_part ) : '';

		$this->assertStringContainsString( 'discount=SAVE20', $url_parameters, 'Discount must survive the add-to-cart redirect (#2609).' );
		$this->assertStringNotContainsString( 'edd_action', $url_parameters, 'The edd_action plumbing should be stripped from the redirect.' );
		$this->assertStringNotContainsString( 'download_id', $url_parameters, 'The download_id plumbing should be stripped from the redirect.' );
		$this->assertStringNotContainsString( 'edd_options', $url_parameters, 'The edd_options plumbing should be stripped from the redirect.' );
		$this->assertStringNotContainsString( 'edd_download_quantity', $url_parameters, 'The quantity plumbing should be stripped from the redirect.' );
	}

	/**
	 * Because the fix keeps `discount` in the URL, an invalid or expired code now
	 * persists and is re-processed on the next load. That must be a graceful no-op:
	 * `edd_apply_preset_discount()` validates via `edd_is_discount_valid()` (invalid
	 * and expired both fail it), so nothing is applied to the cart and no error is
	 * raised.
	 */
	public function test_invalid_preset_discount_in_url_is_ignored_gracefully() {
		$_REQUEST['discount'] = 'THISCODEDOESNOTEXIST';

		// Mirrors the two init hooks: stash the preset, then attempt to apply it.
		edd_listen_for_cart_discount();
		edd_apply_preset_discount();

		$this->assertEmpty( edd_get_cart_discounts(), 'An invalid discount code in the URL must not be applied to the cart.' );
	}

	/**
	 * The cleanup subscriber strips `discount` AND the add-to-cart plumbing from a
	 * landed URL, so the code does not linger after it has been re-applied.
	 */
	public function test_cleanup_removes_discount_and_plumbing() {
		$url     = home_url( '/checkout/?edd_action=add_to_cart&download_id=42&edd_options%5Bprice_id%5D=1&edd_download_quantity=1&discount=SAVE20' );
		$cleaned = AddToCartRedirectCleanup::get_cleaned_url( $url );

		$this->assertStringNotContainsString( 'discount', $cleaned, 'The discount arg must be scrubbed from the landed URL (#2609).' );
		$this->assertStringNotContainsString( 'edd_action', $cleaned, 'The add-to-cart plumbing must be scrubbed from the landed URL.' );
		$this->assertStringNotContainsString( 'download_id', $cleaned, 'The add-to-cart plumbing must be scrubbed from the landed URL.' );
	}

	/**
	 * A URL with nothing to strip yields an empty string, so the subscriber never
	 * issues a pointless redirect (loop prevention).
	 */
	public function test_cleanup_returns_empty_when_nothing_to_strip() {
		$this->assertSame( '', AddToCartRedirectCleanup::get_cleaned_url( home_url( '/checkout/' ) ) );
		$this->assertSame( '', AddToCartRedirectCleanup::get_cleaned_url( home_url( '/checkout/?foo=bar' ) ) );
	}

	/**
	 * The subscriber only acts while the discount arg is present — this is both the
	 * trigger and the loop-breaker after the URL has been scrubbed.
	 */
	public function test_url_has_preset_discount_detects_the_arg() {
		$this->assertTrue( AddToCartRedirectCleanup::url_has_preset_discount( home_url( '/checkout/?discount=SAVE20' ) ) );
		$this->assertFalse( AddToCartRedirectCleanup::url_has_preset_discount( home_url( '/checkout/?edd_action=add_to_cart' ) ) );
		$this->assertFalse( AddToCartRedirectCleanup::url_has_preset_discount( home_url( '/checkout/' ) ) );
	}

	/**
	 * A GET landing carrying `discount` gets a cleaned redirect target; anything
	 * else (non-GET, or no discount arg) yields an empty string so no redirect fires.
	 */
	public function test_get_redirect_target() {
		$discount_url = '/checkout/?edd_action=add_to_cart&download_id=42&discount=SAVE20';

		$target = AddToCartRedirectCleanup::get_redirect_target( 'GET', $discount_url );
		$this->assertStringContainsString( '/checkout/', $target );
		$this->assertStringNotContainsString( 'discount', $target, 'A GET discount landing should redirect to a URL without the discount arg.' );
		$this->assertStringNotContainsString( 'edd_action', $target );

		$this->assertSame( '', AddToCartRedirectCleanup::get_redirect_target( 'POST', $discount_url ), 'Non-GET requests should not be redirected.' );
		$this->assertSame( '', AddToCartRedirectCleanup::get_redirect_target( 'GET', '/checkout/' ), 'A URL with no discount arg should not be redirected.' );
	}

	/**
	 * Exercises the hook wrapper end to end. edd_redirect() is a no-op under unit
	 * tests, so this asserts the guards/branches run cleanly for both a discount
	 * landing and a non-GET request rather than an observable redirect.
	 */
	public function test_clean_url_runs_without_error() {
		$subscriber = new AddToCartRedirectCleanup();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/checkout/?edd_action=add_to_cart&download_id=42&discount=SAVE20';
		$this->assertNull( $subscriber->clean_url() );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertNull( $subscriber->clean_url() );
	}
}
