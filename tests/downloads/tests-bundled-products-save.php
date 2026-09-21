<?php
/**
 * Tests for edd_sanitize_bundled_products_save().
 *
 * A bundle's children must all be products the saving user has rights over — the sanitizer
 * enforces that alongside its existing `0` and self-reference rejection.
 */

namespace EDD\Tests\Downloads;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * @group edd_downloads
 */
class BundledProductsSave extends EDD_UnitTestCase {

	/**
	 * A Shop Vendor who owns two of its own products.
	 *
	 * @var int
	 */
	protected static $vendor_id;

	/**
	 * The Shop Vendor's own product, used as the "self" being edited.
	 *
	 * @var int
	 */
	protected static $vendor_product_id;

	/**
	 * A second product the Shop Vendor owns, used as a legitimate bundle child.
	 *
	 * @var int
	 */
	protected static $vendor_product_2_id;

	/**
	 * An administrator who owns a paid, a private, and a variable-priced product.
	 *
	 * @var int
	 */
	protected static $admin_id;

	protected static $admin_paid_id;

	protected static $admin_private_id;

	protected static $admin_variable_id;

	public static function wpSetUpBeforeClass() {
		// metabox.php is only required by EDD's bootstrap when is_admin() (or WP_CLI) is
		// true, which it is not for this test run.
		require_once EDD_PLUGIN_DIR . 'includes/admin/downloads/metabox.php';

		self::$vendor_id = self::factory()->user->create( array( 'role' => 'shop_vendor' ) );

		// Shop Vendor's role-level product capabilities come from EDD_Roles::add_caps(),
		// which runs once at plugin activation (before this class exists) and only
		// updates the roles option, not the WP_Role objects WP_User::has_cap() already
		// cached at that same activation request. Granting them again here, directly on
		// the user, is how tests/gateways/paypal/tests-refund-flow.php works around the
		// same gap for edit_shop_payments.
		$vendor = new \WP_User( self::$vendor_id );
		foreach ( array( 'edit_product', 'edit_products', 'edit_published_products' ) as $cap ) {
			$vendor->add_cap( $cap );
		}

		self::$vendor_product_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_author' => self::$vendor_id,
				'post_status' => 'publish',
			)
		);

		self::$vendor_product_2_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_author' => self::$vendor_id,
				'post_status' => 'publish',
			)
		);

		self::$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$admin = new \WP_User( self::$admin_id );
		$admin->add_cap( 'edit_others_products' );

		self::$admin_paid_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_author' => self::$admin_id,
				'post_status' => 'publish',
			)
		);
		update_post_meta( self::$admin_paid_id, 'edd_price', '20.00' );

		self::$admin_private_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_author' => self::$admin_id,
				'post_status' => 'private',
			)
		);

		self::$admin_variable_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_author' => self::$admin_id,
				'post_status' => 'publish',
			)
		);
		update_post_meta( self::$admin_variable_id, '_variable_pricing', 1 );
		update_post_meta(
			self::$admin_variable_id,
			'edd_variable_prices',
			array(
				1 => array(
					'name'   => 'Standard',
					'amount' => '10.00',
				),
			)
		);
	}

	public function tearDown(): void {
		unset( $GLOBALS['post'] );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * A Shop Vendor cannot bundle in a paid or private product owned by another author.
	 */
	public function test_bundle_children_reject_products_the_editor_cannot_edit() {
		wp_set_current_user( self::$vendor_id );
		$this->set_editing_post( self::$vendor_product_id );

		$result = edd_sanitize_bundled_products_save(
			array( self::$admin_paid_id, self::$admin_private_id )
		);

		$this->assertNotContains( (string) self::$admin_paid_id, (array) $result );
		$this->assertNotContains( (string) self::$admin_private_id, (array) $result );
		$this->assertEmpty( get_post_meta( self::$vendor_product_id, '_edd_bundled_products', true ) );
	}

	/**
	 * The same rejection applies to a variable-priced child submitted in the composite
	 * "<id>_<price_id>" form, not just the plain id.
	 */
	public function test_bundle_children_reject_variable_price_children_by_id() {
		wp_set_current_user( self::$vendor_id );
		$this->set_editing_post( self::$vendor_product_id );

		$result = edd_sanitize_bundled_products_save(
			array( self::$admin_variable_id . '_1' )
		);

		$this->assertNotContains( self::$admin_variable_id . '_1', (array) $result );
	}

	/**
	 * Control: a Shop Vendor can still bundle in a product it owns itself.
	 */
	public function test_bundle_children_keep_products_the_editor_owns() {
		wp_set_current_user( self::$vendor_id );
		$this->set_editing_post( self::$vendor_product_id );

		$result = edd_sanitize_bundled_products_save(
			array( self::$vendor_product_2_id )
		);

		$this->assertContains( (string) self::$vendor_product_2_id, (array) $result );
	}

	/**
	 * Control: an administrator (edit_others_products) can bundle in any product.
	 */
	public function test_bundle_children_allow_any_product_for_edit_others_products() {
		wp_set_current_user( self::$admin_id );
		// The bundle being edited must differ from both submitted children, or the
		// self-reference check (unrelated to this fix) would reject one of them.
		$this->set_editing_post( self::$admin_variable_id );

		$result = edd_sanitize_bundled_products_save(
			array( self::$admin_paid_id, self::$admin_private_id )
		);

		$this->assertContains( (string) self::$admin_paid_id, (array) $result );
		$this->assertContains( (string) self::$admin_private_id, (array) $result );
	}

	/**
	 * Puts a post in the global scope so get_the_ID(), which the sanitizer uses for
	 * its self-reference check, resolves to the product being "edited".
	 *
	 * @param int $post_id The product being edited.
	 */
	private function set_editing_post( $post_id ) {
		$GLOBALS['post'] = get_post( $post_id );
	}
}
