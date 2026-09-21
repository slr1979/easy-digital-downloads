<?php
namespace EDD\Tests\Downloads\Process;

use EDD\Tests\Helpers;

/**
 * Variable price file entitlement tests.
 *
 * A purchase of one price option is entitled to that option's files plus the files carrying no
 * option restriction, and to nothing else. These assert that rule on the surfaces which list a
 * purchase's files, at the file download boundary which honors the links, and that callers which
 * want a product's whole file list (the download editor, the exporters, the APIs) still get it.
 *
 * @group edd_downloads
 */
class VariablePriceEntitlement extends Helpers\Process_Download {

	/**
	 * A variable priced product with two options and four files.
	 *
	 * The inherited variable priced fixture restricts one file to option 0 and shares the other,
	 * so it cannot show a second option's file being withheld, a file saved with no condition at
	 * all, or an item recording no price option. This carries all three.
	 *
	 * @var int
	 */
	protected static $download_id;

	/**
	 * A complete order for price option 0.
	 *
	 * @var int
	 */
	protected static $order_for_option_zero;

	/**
	 * A complete order whose item records no price option.
	 *
	 * @var int
	 */
	protected static $order_without_option;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::create_download();

		self::$order_for_option_zero = self::create_order_for_price_id( 0 );
		self::$order_without_option  = self::create_order_for_price_id( null );
	}

	public static function tearDownAfterClass(): void {
		edd_destroy_order( self::$order_for_option_zero );
		edd_destroy_order( self::$order_without_option );
		wp_delete_post( self::$download_id, true );

		parent::tearDownAfterClass();
	}

	/**
	 * The fixture the rest of these depend on: four files, two of them restricted per option.
	 */
	public function test_fixture_files_carry_the_expected_conditions() {
		$files = self::download()->get_files();

		$this->assertCount( 4, $files );
		$this->assertEquals( 0, $files[0]['condition'] );
		$this->assertEquals( 1, $files[1]['condition'] );
		$this->assertSame( 'all', $files[2]['condition'] );
		$this->assertArrayNotHasKey( 'condition', $files[3] );
	}

	/**
	 * A purchase of one option is entitled to that option's files and the unrestricted ones.
	 */
	public function test_purchased_option_receives_its_own_files_and_the_unrestricted_ones() {
		$files = self::download()->get_files_for_price_id( 0 );

		$this->assertSame( array( 0, 2, 3 ), array_keys( $files ) );
	}

	/**
	 * A purchase recording no price option is entitled only to the unrestricted files.
	 *
	 * A store which sold a product at a single price and later switched it to variable pricing has
	 * valid order items recording no price option, so those purchases land here.
	 */
	public function test_purchase_with_no_price_option_receives_only_the_unrestricted_files() {
		$files = self::download()->get_files_for_price_id( null );

		$this->assertSame( array( 2, 3 ), array_keys( $files ) );
	}

	/**
	 * A file saved before the product gained price options carries no condition and is delivered.
	 *
	 * That is the file a single price product's purchases were sold, so withholding it would deny
	 * a legitimate download.
	 */
	public function test_files_saved_with_no_condition_are_delivered_to_every_option() {
		$this->assertArrayHasKey( 3, self::download()->get_files_for_price_id( null ) );
		$this->assertArrayHasKey( 3, self::download()->get_files_for_price_id( 0 ) );
		$this->assertArrayHasKey( 3, self::download()->get_files_for_price_id( 1 ) );
	}

	/**
	 * get_files() still returns the product's whole list when no option is named.
	 *
	 * The download editor, the exporters, the REST and legacy APIs and the direct file boundary
	 * all rely on this, so narrowing it would hide files from the editor.
	 */
	public function test_get_files_returns_the_whole_list_when_no_option_is_named() {
		$this->assertCount( 4, self::download()->get_files() );
		$this->assertCount( 4, self::download()->get_files( null ) );
		$this->assertCount( 4, edd_get_download_files( self::$download_id ) );
		$this->assertCount( 4, edd_get_download_files( self::$download_id, null ) );
	}

	/**
	 * get_files() resolves a named option exactly as it has since 2.2.
	 *
	 * A file carrying no condition names no price option, so it is not returned for one. That
	 * differs from get_files_for_price_id() on purpose: this is the released contract.
	 */
	public function test_get_files_with_a_named_option_is_unchanged() {
		$this->assertSame( array( 0, 2 ), array_keys( self::download()->get_files( 0 ) ) );
		$this->assertSame( array( 1, 2 ), array_keys( self::download()->get_files( 1 ) ) );
	}

	/**
	 * The answer does not depend on which call came first on the same object.
	 *
	 * get_files() memoizes, so the whole list is what is cached and the price option is applied
	 * per call.
	 */
	public function test_resolution_does_not_depend_on_the_order_of_calls() {
		$download = self::download();

		$this->assertCount( 4, $download->get_files() );
		$this->assertSame( array( 2, 3 ), array_keys( $download->get_files_for_price_id( null ) ) );
		$this->assertSame( array( 0, 2, 3 ), array_keys( $download->get_files_for_price_id( 0 ) ) );
		$this->assertCount( 4, $download->get_files() );
	}

	/**
	 * A fixed price product is unaffected.
	 *
	 * A fixed price purchase legitimately records no price option, so resolving against one must
	 * not withhold its files.
	 */
	public function test_fixed_price_download_is_unaffected() {
		$simple = Helpers\EDD_Helper_Download::create_simple_download();

		$this->assertNotEmpty( edd_get_download_files( $simple->ID ) );
		$this->assertNotEmpty( ( new \EDD_Download( $simple->ID ) )->get_files_for_price_id( null ) );

		Helpers\EDD_Helper_Download::delete_download( $simple->ID );
	}

	/**
	 * A product flagged variable priced with no price options delivers its files.
	 *
	 * There is no price option there that could have been missing, which is the same reading
	 * EDD_Cart::get_item_price() takes of that state.
	 */
	public function test_variable_flag_without_price_options_delivers_its_files() {
		delete_post_meta( self::$download_id, 'edd_variable_prices' );

		$this->assertTrue( (bool) edd_has_variable_prices( self::$download_id ) );
		$this->assertEmpty( edd_get_variable_prices( self::$download_id ) );
		$this->assertCount( 4, self::download()->get_files_for_price_id( null ) );

		self::set_price_options();
	}

	/**
	 * An order item resolves its own files against the option it was bought at.
	 */
	public function test_order_item_resolves_its_files_against_its_own_price_option() {
		$item = self::order_item( self::$order_for_option_zero );

		$this->assertSame( array( 0, 2, 3 ), array_keys( $item->get_download_files() ) );
	}

	/**
	 * A bundle child is given the whole product.
	 *
	 * Order::get_items_with_bundles() and the user downloads block build children from a bundle's
	 * own contents rather than from a row, and a bundle entry naming no price option means the
	 * whole product.
	 */
	public function test_bundle_child_is_given_the_whole_product() {
		$bundle_child = new \EDD\Orders\Order_Item(
			array(
				'order_id'   => self::$order_for_option_zero,
				'product_id' => self::$download_id,
				'status'     => 'complete',
			)
		);

		$this->assertFalse( $bundle_child->exists() );
		$this->assertCount( 4, $bundle_child->get_download_files() );
	}

	/**
	 * The links offered for a purchase are the ones its price option is entitled to.
	 *
	 * This is the wiring, at one of the surfaces which produces real download links.
	 */
	public function test_purchase_download_links_offer_only_the_entitled_files() {
		$links = edd_get_purchase_download_links( self::$order_for_option_zero );

		$this->assertStringContainsString( 'Option Zero File', $links );
		$this->assertStringContainsString( 'Shared File', $links );
		$this->assertStringContainsString( 'Unconditioned File', $links );
		$this->assertStringNotContainsString( 'Option One File', $links );
	}

	/**
	 * The file download boundary honors a link only for the option which bought it.
	 *
	 * A signed link stays valid for its whole TTL, so this is what refuses one which should never
	 * have been offered rather than leaving it live in an inbox.
	 */
	public function test_download_access_is_refused_for_another_options_file() {
		$this->assertFalse( self::grants_access( self::$order_for_option_zero, 0, 1 ) );
	}

	/**
	 * The control: the purchased option's own file, and the unrestricted ones, are honored.
	 */
	public function test_download_access_is_granted_for_the_purchased_options_files() {
		$this->assertTrue( self::grants_access( self::$order_for_option_zero, 0, 0 ) );
		$this->assertTrue( self::grants_access( self::$order_for_option_zero, 0, 2 ) );
		$this->assertTrue( self::grants_access( self::$order_for_option_zero, 0, 3 ) );
	}

	/**
	 * An item recording no price option is honored only for the unrestricted files.
	 */
	public function test_download_access_for_an_item_with_no_price_option() {
		$this->assertFalse( self::grants_access( self::$order_without_option, null, 0 ) );
		$this->assertFalse( self::grants_access( self::$order_without_option, null, 1 ) );
		$this->assertTrue( self::grants_access( self::$order_without_option, null, 2 ) );
		$this->assertTrue( self::grants_access( self::$order_without_option, null, 3 ) );
	}

	/**
	 * A caller which names no file key gets the same answer it always did.
	 *
	 * edd_order_grants_access_to_download_files() answers whether an order covers a product at all
	 * for callers which are not resolving one file.
	 */
	public function test_access_without_a_file_key_is_unchanged() {
		$this->assertTrue(
			edd_order_grants_access_to_download_files(
				array(
					'order_id'   => self::$order_for_option_zero,
					'product_id' => self::$download_id,
					'price_id'   => 0,
				)
			)
		);
	}

	/**
	 * A file key the product does not have is left alone.
	 *
	 * Only a file the product actually has can be withheld from a price option. A bundle has no
	 * files of its own and an extension may supply a list through `edd_download_files` that the
	 * product's meta never held, so refusing an unrecognized key here would deny downloads this
	 * rule has nothing to say about.
	 */
	public function test_access_is_unchanged_for_a_file_key_the_product_does_not_have() {
		$this->assertTrue( self::grants_access( self::$order_for_option_zero, 0, 99 ) );
	}

	/**
	 * Asks the download boundary whether an order may download one file.
	 *
	 * @param int      $order_id The order.
	 * @param int|null $price_id The price option the signed link carries.
	 * @param int      $file_key The file the signed link names.
	 * @return bool
	 */
	private static function grants_access( $order_id, $price_id, $file_key ) {
		return edd_order_grants_access_to_download_files(
			array(
				'order_id'   => $order_id,
				'product_id' => self::$download_id,
				'price_id'   => $price_id,
				'file_key'   => $file_key,
			)
		);
	}

	/**
	 * A fresh download object for the fixture product.
	 *
	 * @return \EDD_Download
	 */
	private static function download() {
		return new \EDD_Download( self::$download_id );
	}

	/**
	 * The first order item on an order.
	 *
	 * @param int $order_id The order.
	 * @return \EDD\Orders\Order_Item
	 */
	private static function order_item( $order_id ) {
		$items = edd_get_order( $order_id )->get_items();

		return reset( $items );
	}

	/**
	 * A variable priced product whose files are restricted per price option.
	 */
	private static function create_download() {
		self::$download_id = wp_insert_post(
			array(
				'post_title'  => 'Entitlement Fixture',
				'post_type'   => 'download',
				'post_status' => 'publish',
			)
		);

		update_post_meta( self::$download_id, '_variable_pricing', 1 );
		update_post_meta(
			self::$download_id,
			'edd_download_files',
			array(
				array(
					'name'      => 'Option Zero File',
					'file'      => 'http://localhost/option-zero.zip',
					'condition' => 0,
				),
				array(
					'name'      => 'Option One File',
					'file'      => 'http://localhost/option-one.zip',
					'condition' => 1,
				),
				array(
					'name'      => 'Shared File',
					'file'      => 'http://localhost/shared.zip',
					'condition' => 'all',
				),
				array(
					'name' => 'Unconditioned File',
					'file' => 'http://localhost/unconditioned.zip',
				),
			)
		);

		self::set_price_options();
	}

	/**
	 * Gives the fixture product its two price options.
	 */
	private static function set_price_options() {
		update_post_meta(
			self::$download_id,
			'edd_variable_prices',
			array(
				array(
					'name'   => 'Low',
					'amount' => 10,
				),
				array(
					'name'   => 'High',
					'amount' => 100,
				),
			)
		);
	}

	/**
	 * A complete order for the fixture product at one price option.
	 *
	 * @param int|null $price_id The price option the item records.
	 * @return int The order ID.
	 */
	private static function create_order_for_price_id( $price_id ) {
		$order_id = edd_add_order(
			array(
				'status'   => 'complete',
				'email'    => 'entitlement@edd.test',
				'currency' => 'USD',
				'total'    => 20,
			)
		);

		edd_add_order_item(
			array(
				'order_id'     => $order_id,
				'product_id'   => self::$download_id,
				'product_name' => 'Entitlement Fixture',
				'price_id'     => $price_id,
				'status'       => 'complete',
				'quantity'     => 1,
				'amount'       => 20,
				'subtotal'     => 20,
				'total'        => 20,
			)
		);

		return $order_id;
	}
}
