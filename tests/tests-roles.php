<?php
namespace EDD\Tests;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * @group edd_roles
 */
class Tests_Roles extends EDD_UnitTestCase {

	/**
	 * A real user of each role the absence assertions run against.
	 *
	 * @var array
	 */
	protected static $users = array();

	/**
	 * Create one user per role under test.
	 */
	public static function wpSetUpBeforeClass() {
		EDD()->roles->add_roles();
		EDD()->roles->add_caps();

		/*
		 * EDD_Roles::add_caps() grants through WP_Roles::add_cap(), which updates the
		 * stored roles but not the role objects WP_User reads, so the capabilities are
		 * invisible to user_can() until the roles are rebuilt.
		 */
		wp_roles()->for_site();

		foreach ( array( 'shop_accountant', 'shop_worker', 'shop_vendor', 'subscriber' ) as $role ) {
			self::$users[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
		}
	}

	public function test_roles() {

		global $wp_roles;

		$this->assertArrayHasKey( 'shop_manager', (array) $wp_roles->role_names );
		$this->assertArrayHasKey( 'shop_accountant', (array) $wp_roles->role_names );
		$this->assertArrayHasKey( 'shop_worker', (array) $wp_roles->role_names );
		$this->assertArrayHasKey( 'shop_vendor', (array) $wp_roles->role_names );
	}

	public function test_shop_manager_caps() {
		global $wp_roles;

		if ( class_exists( 'WP_Roles' ) ) {
			if ( ! isset( $wp_roles ) ) {
				$wp_roles = new \WP_Roles();
			}
		}

		$this->assertArrayHasKey( 'read', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'delete_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'unfiltered_html', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'upload_files', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'export', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'import', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'delete_others_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'delete_others_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'delete_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'delete_private_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'delete_private_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'delete_published_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'delete_published_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_others_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_others_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_private_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_private_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_published_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'edit_published_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'manage_categories', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'manage_links', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'moderate_comments', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'publish_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'publish_posts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'read_private_pages', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'view_shop_sensitive_data', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'export_shop_reports', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'manage_shop_settings', (array) $wp_roles->roles['shop_manager']['capabilities'] );
		$this->assertArrayHasKey( 'manage_shop_discounts', (array) $wp_roles->roles['shop_manager']['capabilities'] );
	}

	public function test_administrator_caps() {
		global $wp_roles;

		if ( class_exists( 'WP_Roles' ) ) {
			if ( ! isset( $wp_roles ) ) {
				$wp_roles = new \WP_Roles();
			}
		}

		$this->assertArrayHasKey( 'view_shop_sensitive_data', (array) $wp_roles->roles['administrator']['capabilities'] );
		$this->assertArrayHasKey( 'export_shop_reports', (array) $wp_roles->roles['administrator']['capabilities'] );
		$this->assertArrayHasKey( 'manage_shop_settings', (array) $wp_roles->roles['administrator']['capabilities'] );
		$this->assertArrayHasKey( 'manage_shop_discounts', (array) $wp_roles->roles['administrator']['capabilities'] );
	}

	public function test_shop_accountant_caps() {
		global $wp_roles;

		if ( class_exists( 'WP_Roles' ) ) {
			if ( ! isset( $wp_roles ) ) {
				$wp_roles = new \WP_Roles();
			}
		}

		$this->assertArrayHasKey( 'read', (array) $wp_roles->roles['shop_accountant']['capabilities'] );
		$this->assertArrayHasKey( 'edit_posts', (array) $wp_roles->roles['shop_accountant']['capabilities'] );
		$this->assertArrayHasKey( 'delete_posts', (array) $wp_roles->roles['shop_accountant']['capabilities'] );
		$this->assertArrayHasKey( 'read_private_products', (array) $wp_roles->roles['shop_accountant']['capabilities'] );
		$this->assertArrayHasKey( 'view_shop_reports', (array) $wp_roles->roles['shop_accountant']['capabilities'] );
		$this->assertArrayHasKey( 'export_shop_reports', (array) $wp_roles->roles['shop_accountant']['capabilities'] );
		$this->assertArrayHasKey( 'edit_shop_payments', (array) $wp_roles->roles['shop_accountant']['capabilities'] );
	}

	public function test_shop_vendor_caps() {
		global $wp_roles;

		if ( class_exists( 'WP_Roles' ) ) {
			if ( ! isset( $wp_roles ) ) {
				$wp_roles = new \WP_Roles();
			}
		}

		$this->assertArrayHasKey( 'edit_product', (array) $wp_roles->roles['shop_vendor']['capabilities'] );
		$this->assertArrayHasKey( 'delete_product', (array) $wp_roles->roles['shop_vendor']['capabilities'] );
		$this->assertArrayHasKey( 'delete_products', (array) $wp_roles->roles['shop_vendor']['capabilities'] );
		$this->assertArrayHasKey( 'publish_products', (array) $wp_roles->roles['shop_vendor']['capabilities'] );
		$this->assertArrayHasKey( 'edit_published_products', (array) $wp_roles->roles['shop_vendor']['capabilities'] );
		$this->assertArrayHasKey( 'upload_files', (array) $wp_roles->roles['shop_vendor']['capabilities'] );
		$this->assertArrayHasKey( 'assign_product_terms', (array) $wp_roles->roles['shop_vendor']['capabilities'] );
	}

	/*
	 * Capability absences.
	 *
	 * These assert through user_can() against a real user of each role rather than
	 * through $wp_roles->roles[...], because EDD_Roles::meta_caps() can grant a
	 * capability through map_meta_cap that never appears in the stored role array.
	 * An absence from that array is not an absence for the user.
	 */

	public function test_shop_accountant_does_not_hold_delete_shop_payments() {
		$this->assertTrue( user_can( self::$users['shop_accountant'], 'edit_shop_payments' ) );
		$this->assertFalse( user_can( self::$users['shop_accountant'], 'delete_shop_payments' ) );
	}

	public function test_shop_accountant_does_not_hold_publish_shop_payments() {
		$this->assertFalse( user_can( self::$users['shop_accountant'], 'publish_shop_payments' ) );
	}

	public function test_shop_accountant_does_not_hold_manage_shop_settings() {
		$this->assertFalse( user_can( self::$users['shop_accountant'], 'manage_shop_settings' ) );
	}

	public function test_shop_worker_holds_delete_shop_payments() {
		$this->assertTrue( user_can( self::$users['shop_worker'], 'delete_shop_payments' ) );
	}

	public function test_shop_worker_does_not_hold_view_shop_reports() {
		$this->assertFalse( user_can( self::$users['shop_worker'], 'view_shop_reports' ) );
	}

	public function test_shop_worker_does_not_hold_manage_shop_settings() {
		$this->assertFalse( user_can( self::$users['shop_worker'], 'manage_shop_settings' ) );
	}

	public function test_shop_vendor_holds_no_payment_capabilities() {
		$user_id = self::$users['shop_vendor'];

		$this->assertTrue(
			user_can( $user_id, 'edit_products' ),
			'Fixture: the Shop Vendor must hold its own product capabilities, or these absences are vacuous.'
		);

		$payment_caps = EDD()->roles->get_core_caps()['shop_payment'];
		$this->assertNotEmpty( $payment_caps );

		foreach ( $payment_caps as $cap ) {
			$this->assertFalse(
				user_can( $user_id, $cap ),
				sprintf( 'The Shop Vendor unexpectedly holds %s.', $cap )
			);
		}
	}

	public function test_subscriber_holds_no_edd_capabilities() {
		global $post_type_meta_caps;

		$user_id = self::$users['subscriber'];

		$this->assertTrue( user_can( $user_id, 'read' ), 'Fixture: the Subscriber must exist and hold its own role.' );

		$caps = array(
			'view_shop_reports',
			'view_shop_sensitive_data',
			'export_shop_reports',
			'manage_shop_settings',
			'manage_shop_discounts',
		);

		foreach ( EDD()->roles->get_core_caps() as $cap_group ) {
			$caps = array_merge( $caps, $cap_group );
		}

		/*
		 * edit_product, read_product and delete_product are registered as post type
		 * meta capabilities, so WordPress resolves them against one download rather
		 * than against a role. Checking them with no object only measures
		 * map_meta_cap()'s missing-object path, which says nothing about the role.
		 */
		$caps = array_diff( $caps, array_keys( (array) $post_type_meta_caps ) );

		$this->assertGreaterThan( 50, count( $caps ) );

		foreach ( $caps as $cap ) {
			$this->assertFalse(
				user_can( $user_id, $cap ),
				sprintf( 'The Subscriber unexpectedly holds %s.', $cap )
			);
		}
	}
}
