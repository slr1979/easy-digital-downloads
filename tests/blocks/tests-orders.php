<?php
namespace EDD\Tests\Blocks;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Blocks\Orders as Orders_Block;
use EDD\Tests\Helpers;

/**
 * Tests for the Orders block.
 *
 * @group blocks
 */
class Orders extends EDD_UnitTestCase {

	protected static $customer_id;

	protected static $customer;

	/**
	 * A Subscriber who owns no orders, used to probe the ownership scope.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Two other customers' completed orders that the Subscriber must never see.
	 *
	 * @var \EDD\Orders\Order
	 */
	protected static $other_order_1;

	protected static $other_order_2;

	/**
	 * A variable-priced download purchased (at a specific tier) on the second
	 * other order, and the resulting tier name.
	 *
	 * @var \WP_Post
	 */
	protected static $variable_download;

	protected static $variable_tier_name;

	public static function wpSetupBeforeClass() {
		require_once EDD_PLUGIN_DIR . 'includes/blocks/includes/orders/orders.php';

		// Create a customer for the current user.
		self::$customer_id = parent::edd()->customer->create( array( 'user_id' => get_current_user_id() ) );
		self::$customer    = new \EDD_Customer( self::$customer_id );

		// A Subscriber who owns no orders or purchases.
		self::$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Two other customers, each holding a completed order the Subscriber does not own.
		$other_user_1         = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::$other_order_1  = parent::edd()->order->create_and_get(
			array(
				'customer_id' => parent::edd()->customer->create( array( 'user_id' => $other_user_1 ) ),
				'user_id'     => $other_user_1,
				'email'       => get_userdata( $other_user_1 )->user_email,
				'status'      => 'complete',
			)
		);

		$other_user_2        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::$other_order_2 = parent::edd()->order->create_and_get(
			array(
				'customer_id' => parent::edd()->customer->create( array( 'user_id' => $other_user_2 ) ),
				'user_id'     => $other_user_2,
				'email'       => get_userdata( $other_user_2 )->user_email,
				'status'      => 'complete',
			)
		);

		// Add a variable-priced tier to the second order, so a tier name is assertable.
		self::$variable_download  = Helpers\EDD_Helper_Download::create_variable_download();
		self::$variable_tier_name = edd_get_download_name( self::$variable_download->ID, 1 );
		parent::edd()->order_item->create_and_get(
			array(
				'order_id'     => self::$other_order_2->id,
				'product_id'   => self::$variable_download->ID,
				'product_name' => self::$variable_tier_name,
				'price_id'     => 1,
				'status'       => 'complete',
			)
		);
	}

	public function setUp(): void {
		Helpers\EDD_Helper_Download::add_download_files();
	}

	public function tearDown(): void {
		Helpers\EDD_Helper_Download::remove_download_files();
		unset( $_GET['edd_blocks_is_block_editor'] );

		// Restore the admin identity the rest of this class' fixtures assume.
		wp_set_current_user( 1 );
	}

	public function test_get_purchased_products_default_args_no_orders() {
		$products = Orders_Block\get_purchased_products(
			array(
				'search'     => false,
				'variations' => true,
				'nofiles'    => __( 'No downloadable files found.', 'easy-digital-downloads' ),
				'hide_empty' => true,
			)
		);

		$this->assertFalse( $products );
	}

	public function test_get_purchased_products_has_orders() {
		$order = parent::edd()->order->create_and_get(
			array(
				'customer_id' => self::$customer_id,
				'user_id'     => get_current_user_id(),
				'email'       => self::$customer->email,
				'status'      => 'complete',
			)
		);

		$products = Orders_Block\get_purchased_products(
			array(
				'search'     => false,
				'variations' => true,
				'nofiles'    => __( 'No downloadable files found.', 'easy-digital-downloads' ),
				'hide_empty' => true,
			)
		);

		$this->assertSame( 1, count( $products ) );

		// Clean up.
		parent::edd()->order->delete( $order->ID );
	}

	public function test_get_purchased_products_has_orders_all_not_deliverable() {
		$order = parent::edd()->order->create_and_get(
			array(
				'customer_id' => self::$customer_id,
				'user_id'     => get_current_user_id(),
				'email'       => self::$customer->email,
				'status'      => 'refunded',
			)
		);


		$products = Orders_Block\get_purchased_products(
			array(
				'search'     => false,
				'variations' => true,
				'nofiles'    => __( 'No downloadable files found.', 'easy-digital-downloads' ),
				'hide_empty' => true,
			)
		);

		$this->assertFalse( $products );

		// Clean up.
		parent::edd()->order->delete( $order->ID );
	}

	public function test_get_purchased_products_has_orders_single_not_deliverable() {
		$order = parent::edd()->order->create_and_get(
			array(
				'customer_id' => self::$customer_id,
				'user_id'     => get_current_user_id(),
				'email'       => self::$customer->email,
				'status'      => 'completed',
			)
		);

		edd_update_order_item(
			$order->items[0]->id,
			array(
				'status' => 'refunded',
			)
		);

		$products = Orders_Block\get_purchased_products(
			array(
				'search'     => false,
				'variations' => true,
				'nofiles'    => __( 'No downloadable files found.', 'easy-digital-downloads' ),
				'hide_empty' => true,
			)
		);

		$this->assertFalse( $products );

		// Clean up.
		parent::edd()->order->delete( $order->ID );
	}

	/**
	 * A Subscriber's own request parameter must not widen the query to other
	 * customers' orders.
	 */
	public function test_purchased_products_ignores_block_editor_param_for_subscriber() {
		wp_set_current_user( self::$subscriber_id );
		$_GET['edd_blocks_is_block_editor'] = '1';

		$products = Orders_Block\get_purchased_products(
			array(
				'search'     => false,
				'variations' => true,
				'nofiles'    => __( 'No downloadable files found.', 'easy-digital-downloads' ),
				'hide_empty' => true,
			)
		);

		$this->assertFalse( $products );
	}

	/**
	 * The email-hash marker alone must not widen the query.
	 */
	public function test_purchased_products_scope_survives_the_email_hash_marker() {
		wp_set_current_user( self::$subscriber_id );
		$_GET['edd_blocks_is_block_editor'] = md5( get_userdata( self::$subscriber_id )->user_email );

		$products = Orders_Block\get_purchased_products(
			array(
				'search'     => false,
				'variations' => true,
				'nofiles'    => __( 'No downloadable files found.', 'easy-digital-downloads' ),
				'hide_empty' => true,
			)
		);

		$this->assertFalse( $products );
	}

	/**
	 * Control: without the parameter, a Subscriber with no orders sees nothing.
	 */
	public function test_purchased_products_scopes_to_the_viewer_without_the_param() {
		wp_set_current_user( self::$subscriber_id );

		$products = Orders_Block\get_purchased_products(
			array(
				'search'     => false,
				'variations' => true,
				'nofiles'    => __( 'No downloadable files found.', 'easy-digital-downloads' ),
				'hide_empty' => true,
			)
		);

		$this->assertFalse( $products );
	}

	/**
	 * Control: the legitimate block-editor preview, gated on edit_shop_payments,
	 * must keep working.
	 */
	public function test_purchased_products_allows_a_privileged_preview() {
		$privileged_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user                = new \WP_User( $privileged_user_id );
		$user->add_cap( 'edit_shop_payments' );

		wp_set_current_user( $privileged_user_id );
		$_GET['edd_blocks_is_block_editor'] = md5( $user->user_email );

		$products = Orders_Block\get_purchased_products(
			array(
				'search'     => false,
				'variations' => true,
				'nofiles'    => __( 'No downloadable files found.', 'easy-digital-downloads' ),
				'hide_empty' => true,
			)
		);

		$this->assertNotFalse( $products );
		$this->assertArrayHasKey( 'Simple Download', $products );
		$this->assertArrayHasKey( self::$variable_tier_name, $products );
	}
}
