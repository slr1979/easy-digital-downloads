<?php
/**
 * Tests for the block utility class.
 *
 * @package EDD\Tests\Blocks
 */

namespace EDD\Tests\Blocks;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Blocks\Utility as Block_Utility;

/**
 * Tests for the Blocks Utility class.
 *
 * @group blocks
 */
class Utility extends EDD_UnitTestCase {

	/**
	 * Reset the global action counter so the did_action() guard starts fresh
	 * for each test. WordPress keeps these counts for the whole process, so
	 * without this the first test to fire the hook would poison the rest.
	 */
	public function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wp_actions']['edd_checkout_form_top'] );
	}

	/**
	 * The method strips the two default callbacks process-wide, so restore them
	 * for any other test that expects the legacy checkout wiring intact.
	 */
	public function tearDown(): void {
		add_action( 'edd_checkout_form_top', 'edd_show_payment_icons' );
		add_action( 'edd_checkout_form_top', 'edd_discount_field', -1 );
		unset( $_GET['edd_blocks_is_block_editor'] );
		parent::tearDown();
	}

	public function test_do_checkout_form_top_fires_action_with_attributes() {
		$received    = null;
		$block_attrs = array( 'showDiscount' => true );
		$capture     = function ( $attributes ) use ( &$received ) {
			$received = $attributes;
		};
		add_action( 'edd_checkout_form_top', $capture );

		// Buffer the real checkout listeners' markup so it does not pollute output.
		ob_start();
		Block_Utility::do_checkout_form_top( $block_attrs );
		ob_get_clean();

		remove_action( 'edd_checkout_form_top', $capture );

		$this->assertSame( $block_attrs, $received );
	}

	public function test_do_checkout_form_top_only_fires_once() {
		$count   = 0;
		$counter = function () use ( &$count ) {
			++$count;
		};
		add_action( 'edd_checkout_form_top', $counter );

		// Buffer the real checkout listeners' markup so it does not pollute output.
		ob_start();
		Block_Utility::do_checkout_form_top( array() );
		Block_Utility::do_checkout_form_top( array() );
		ob_get_clean();

		remove_action( 'edd_checkout_form_top', $counter );

		$this->assertSame( 1, $count );
	}

	public function test_do_checkout_form_top_skips_when_action_already_fired() {
		// Simulate the hook having already fired earlier in the request. This is
		// exactly what did_action() reads, without running the real callbacks.
		$GLOBALS['wp_actions']['edd_checkout_form_top'] = 1;

		$fired = false;
		$spy   = function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'edd_checkout_form_top', $spy );

		Block_Utility::do_checkout_form_top( array() );

		remove_action( 'edd_checkout_form_top', $spy );

		$this->assertFalse( $fired );
	}

	public function test_do_checkout_form_top_removes_default_callbacks() {
		// Buffer the real checkout listeners' markup so it does not pollute output.
		ob_start();
		Block_Utility::do_checkout_form_top( array() );
		ob_get_clean();

		$this->assertFalse( has_action( 'edd_checkout_form_top', 'edd_show_payment_icons' ) );
		$this->assertFalse( has_action( 'edd_checkout_form_top', 'edd_discount_field' ) );
	}

	public function test_do_checkout_form_top_renders_user_details_with_empty_attributes() {
		// Payment-info declares no attributes; firing the hook with an empty array
		// must not suppress the user-details fieldset personal-info expects.
		ob_start();
		Block_Utility::do_checkout_form_top( array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'edd-blocks__user-details', $output );
	}

	/**
	 * A Subscriber's own request parameter is not a capability. With no capability
	 * argument passed, the default now requires one, so the raw value can no
	 * longer be handed back unexamined.
	 */
	public function test_is_block_editor_refuses_a_subscriber_without_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_GET['edd_blocks_is_block_editor'] = '1';

		$this->assertFalse( (bool) Block_Utility::is_block_editor() );
	}

	/**
	 * The block-context branch yields a boolean rather than the $_GET string, but
	 * it must still run the capability check, not return before it.
	 */
	public function test_is_block_editor_applies_capability_on_the_block_context_branch() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$block          = new \stdClass();
		$block->context = array( 'edd/previewMode' => true );

		$this->assertFalse( (bool) Block_Utility::is_block_editor( 'edit_posts', $block ) );
	}

	/**
	 * The deprecated wrapper delegates without a capability argument, so its own
	 * default has to match the method's or the back-compat path is always false.
	 */
	public function test_deprecated_is_block_editor_allows_a_privileged_preview() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		// Assert the fixture: an Editor holds the capability the new default requires.
		$this->assertTrue( current_user_can( 'edit_posts' ) );

		$_GET['edd_blocks_is_block_editor'] = md5( get_userdata( $editor_id )->user_email );

		$this->assertTrue( \EDD\Blocks\Functions\is_block_editor() );
	}

	/**
	 * The deprecated wrapper is gated too: the request parameter is not enough.
	 */
	public function test_deprecated_is_block_editor_refuses_a_subscriber() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertFalse( current_user_can( 'edit_posts' ) );

		$_GET['edd_blocks_is_block_editor'] = md5( get_userdata( $subscriber_id )->user_email );

		$this->assertFalse( \EDD\Blocks\Functions\is_block_editor() );
	}
}
