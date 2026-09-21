<?php
namespace EDD\Tests\Checkout;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for edd_get_checkout_uri().
 */
class CheckoutFunctions extends EDD_UnitTestCase {

	/**
	 * Reset the request state modified by the AJAX tests.
	 */
	public function tearDown(): void {
		unset( $_POST['current_page'] );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $GLOBALS['post'] );

		parent::tearDown();
	}

	/**
	 * On a normal (non-AJAX) checkout page, the URI is the current page's permalink.
	 */
	public function test_checkout_uri_on_purchase_page_returns_page_permalink() {
		$checkout_page = edd_get_option( 'purchase_page' );

		$this->go_to( get_permalink( $checkout_page ) );

		$this->assertSame( get_permalink( $checkout_page ), edd_get_checkout_uri() );
	}

	/**
	 * A secondary checkout page (not the purchase_page option) returns its own
	 * permalink, not the purchase_page permalink.
	 */
	public function test_checkout_uri_on_secondary_checkout_page_returns_current_page_permalink() {
		$page_id = $this->factory->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[download_checkout]',
			)
		);

		$this->go_to( get_permalink( $page_id ) );
		do_action( 'template_redirect' );

		$this->assertSame( get_permalink( $page_id ), edd_get_checkout_uri() );
	}

	/**
	 * On a non-AJAX checkout page, the URI comes from the main query's queried
	 * object — not the global $post, which any secondary loop on the page
	 * (related downloads, widgets) can leave pointing at another post.
	 */
	public function test_checkout_uri_on_checkout_page_ignores_mutated_global_post() {
		$checkout_page = edd_get_option( 'purchase_page' );

		$this->go_to( get_permalink( $checkout_page ) );

		// Simulate an unclosed secondary loop leaving the global $post on a download.
		$download_id = $this->factory->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
			)
		);
		$GLOBALS['post'] = get_post( $download_id );

		$this->assertSame( get_permalink( $checkout_page ), edd_get_checkout_uri() );
	}

	/**
	 * During AJAX, the URI comes from the posted current_page — not from the
	 * global $post, which is not reliably the checkout page in an admin-ajax
	 * context (it can be a cart download, producing remove-from-cart links
	 * that point at the product instead of checkout).
	 *
	 * @link https://github.com/awesomemotive/easy-digital-downloads-pro/issues/2617
	 */
	public function test_checkout_uri_during_ajax_uses_posted_current_page_not_global_post() {
		$page_id = $this->factory->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[download_checkout]',
			)
		);

		$download_id = $this->factory->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
			)
		);

		// Simulate an AJAX cart re-render where a stray query left the global $post on a download.
		$GLOBALS['post']       = get_post( $download_id );
		$_POST['current_page'] = $page_id;
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( get_permalink( $page_id ), edd_get_checkout_uri() );
	}

	/**
	 * During AJAX without a current_page, the URI falls back to the
	 * purchase_page option.
	 */
	public function test_checkout_uri_during_ajax_without_current_page_falls_back_to_purchase_page() {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( get_permalink( edd_get_option( 'purchase_page' ) ), edd_get_checkout_uri() );
	}

	/**
	 * During AJAX with a current_page that is not a checkout page, the URI
	 * falls back to the purchase_page option.
	 */
	public function test_checkout_uri_during_ajax_with_non_checkout_current_page_falls_back_to_purchase_page() {
		$page_id = $this->factory->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'No checkout here.',
			)
		);

		$_POST['current_page'] = $page_id;
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( get_permalink( edd_get_option( 'purchase_page' ) ), edd_get_checkout_uri() );
	}

	/**
	 * Query args passed to edd_get_checkout_uri() are appended to the URI.
	 */
	public function test_checkout_uri_appends_query_args() {
		$checkout_uri = edd_get_checkout_uri( array( 'payment-mode' => 'test-gateway' ) );

		$this->assertStringContainsString( 'payment-mode=test-gateway', $checkout_uri );
	}
}
