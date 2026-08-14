<?php
/**
 * Tests for the checkout UserDetails inner-block duplicate-render guard.
 *
 * @package EDD\Tests\Blocks\Checkout
 */

namespace EDD\Tests\Blocks\Checkout;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Blocks\Checkout\Elements\UserDetails as Element;

/**
 * When the checkout renders with inner blocks, the checkout-personal-info block
 * renders UserDetails itself. UserDetails is also subscribed to edd_checkout_form_top,
 * so without a guard the personal info fields would render a second time. The guard
 * must suppress the hook-based render only when BOTH conditions hold: the inner-blocks
 * signal has fired AND a personal-info inner block is present on the page.
 *
 * @group blocks
 */
class UserDetails extends EDD_UnitTestCase {

	/**
	 * The signal action the guard checks with did_action().
	 *
	 * @var string
	 */
	private $hook = 'edd_blocks_checkout_inner_blocks';

	public function tearDown(): void {
		// did_action() reads $wp_actions directly; reset it so the simulated
		// fired/not-fired state never leaks into other tests.
		unset( $GLOBALS['wp_actions'][ $this->hook ] );
		unset( $_POST['current_page'] );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		parent::tearDown();
	}

	/**
	 * Signal fired, but the checkout omits the personal-info block. UserDetails must
	 * render as the fallback so the customer still gets the personal info fields.
	 */
	public function test_renders_when_signal_fired_but_no_personal_info_block() {
		$this->set_current_page( '<!-- wp:edd/checkout --><!-- wp:edd/checkout-payment-info /--><!-- /wp:edd/checkout -->' );
		$this->set_signal_fired( true );

		$this->assertStringContainsString( 'edd-blocks__user-details', $this->render_user_details() );
	}

	/**
	 * No inner-blocks signal (legacy checkout). UserDetails must always render, even
	 * if a personal-info block happens to be present on the page.
	 */
	public function test_renders_when_signal_not_fired() {
		$this->set_current_page( '<!-- wp:edd/checkout --><!-- wp:edd/checkout-personal-info /--><!-- /wp:edd/checkout -->' );
		$this->set_signal_fired( false );

		$this->assertStringContainsString( 'edd-blocks__user-details', $this->render_user_details() );
	}

	/**
	 * Creates a published page and points the AJAX current_page at it, so the
	 * guard's has_block( null, ... ) check resolves against that content.
	 *
	 * @param string $content The post content.
	 * @return int The created page ID.
	 */
	private function set_current_page( $content ) {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		$_POST['current_page'] = $post_id;
		add_filter( 'wp_doing_ajax', '__return_true' );

		return $post_id;
	}

	/**
	 * Forces did_action() for the signal to report fired or not, independent of
	 * test execution order.
	 *
	 * @param bool $fired Whether the signal should report as fired.
	 * @return void
	 */
	private function set_signal_fired( $fired ) {
		if ( $fired ) {
			$GLOBALS['wp_actions'][ $this->hook ] = 1;
		} else {
			unset( $GLOBALS['wp_actions'][ $this->hook ] );
		}
	}

	/**
	 * Captures the output of UserDetails::render() with non-empty attributes.
	 *
	 * @return string The rendered output.
	 */
	private function render_user_details() {
		ob_start();
		Element::render( array( 'logged_in' => false ) );

		return ob_get_clean();
	}
}
