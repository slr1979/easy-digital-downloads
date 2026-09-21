<?php
/**
 * Tests for the {download_list} email tag.
 *
 * @package     EDD\Tests\Emails
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Emails;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Payment;
use EDD\Tests\Helpers\EDD_Helper_Download;

/**
 * Tests for the {download_list} email tag.
 *
 * @group edd_emails
 */
class DownloadList extends EDD_UnitTestCase {

	const MARKUP = '<img src=x onerror=alert(1)>';

	public static function wpSetUpBeforeClass() {
		require_once EDD_PLUGIN_DIR . 'includes/emails/tags.php';
	}

	public function tearDown(): void {
		remove_all_filters( 'edd_use_skus' );

		parent::tearDown();
	}

	/**
	 * The product name is escaped.
	 */
	public function test_product_name_is_escaped() {
		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		edd_update_order_item( $items[0]->id, array( 'product_name' => self::MARKUP . 'MXNAME' ) );

		$list = $this->render( $order_id );

		$this->assertStringNotContainsString( self::MARKUP, $list );
		$this->assertStringContainsString( 'MXNAME', $list, 'The name is still shown.' );

		edd_destroy_order( $order_id );
	}

	/**
	 * The SKU is escaped.
	 */
	public function test_sku_is_escaped() {
		add_filter( 'edd_use_skus', '__return_true' );

		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		$this->write_raw_meta( $items[0]->product_id, 'edd_sku', self::MARKUP . 'MXSKU' );

		$list = $this->render( $order_id );

		$this->assertStringContainsString( 'MXSKU', $list, 'The SKU is shown.' );
		$this->assertStringNotContainsString( self::MARKUP, $list );

		edd_destroy_order( $order_id );
	}

	/**
	 * The file name is escaped.
	 */
	public function test_file_name_is_escaped() {
		$download_id = EDD_Helper_Download::create_simple_download()->ID;

		$this->write_raw_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'index'         => '0',
					'attachment_id' => 0,
					'name'          => self::MARKUP . 'MXFILE',
					'file'          => 'https://example.test/file.zip',
					'condition'     => 'all',
				),
			)
		);

		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		edd_update_order_item( $items[0]->id, array( 'product_id' => $download_id ) );

		$list = $this->render( $order_id );

		$this->assertStringContainsString( 'MXFILE', $list, 'The file name is shown.' );
		$this->assertStringNotContainsString( self::MARKUP, $list );

		edd_destroy_order( $order_id );
	}

	/**
	 * Product notes are held to the tags the receipt renders.
	 */
	public function test_product_notes_are_held_to_the_allowed_tags() {
		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		$this->write_raw_meta( $items[0]->product_id, 'edd_product_notes', self::MARKUP . 'MXNOTES' );

		$list = $this->render( $order_id );

		$this->assertStringContainsString( 'MXNOTES', $list, 'The notes are shown.' );
		$this->assertStringNotContainsString( 'onerror', $list );

		edd_destroy_order( $order_id );
	}

	/**
	 * The formatting a store legitimately uses reaches the email.
	 *
	 * The control for the case above: escaping the note outright would satisfy that one too,
	 * and would print the tags to the reader as text.
	 */
	public function test_product_notes_keep_the_formatting_a_store_uses() {
		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		$this->write_raw_meta(
			$items[0]->product_id,
			'edd_product_notes',
			'<strong>Important</strong> see the <a href="https://example.org/docs">docs</a>'
		);

		$list = $this->render( $order_id );

		$this->assertStringContainsString( '<strong>Important</strong>', $list );
		$this->assertStringContainsString( 'https://example.org/docs', $list );

		edd_destroy_order( $order_id );
	}

	/**
	 * edd_get_file_name() returns the stored value.
	 *
	 * Callers escape at output, so the accessor returns the stored value unaltered.
	 */
	public function test_file_name_accessor_returns_the_stored_value() {
		$this->assertSame(
			'Bob & Co <Pro>',
			edd_get_file_name(
				array(
					'name' => 'Bob & Co <Pro>',
					'file' => 'https://example.test/f.zip',
				)
			)
		);
		$this->assertSame(
			'f.zip',
			edd_get_file_name(
				array(
					'name' => '',
					'file' => 'https://example.test/f.zip',
				)
			)
		);
	}

	/**
	 * The SKU meta is sanitized on write.
	 */
	public function test_sku_meta_is_sanitized() {
		$download_id = EDD_Helper_Download::create_simple_download()->ID;

		update_post_meta( $download_id, 'edd_sku', self::MARKUP . 'MXSTORED' );

		$this->assertStringNotContainsString( '<img', get_post_meta( $download_id, 'edd_sku', true ) );
	}

	/**
	 * The product notes meta is held to the allowed tags on write, keeping what a store wrote.
	 */
	public function test_product_notes_meta_is_sanitized() {
		$download_id = EDD_Helper_Download::create_simple_download()->ID;

		update_post_meta(
			$download_id,
			'edd_product_notes',
			"First line\nSecond line<strong>Bold</strong>" . self::MARKUP
		);

		$stored = get_post_meta( $download_id, 'edd_product_notes', true );

		$this->assertStringNotContainsString( 'onerror', $stored );
		$this->assertStringContainsString( '<strong>Bold</strong>', $stored, 'Formatting survives the write.' );
		$this->assertStringContainsString( "First line\nSecond line", $stored, 'Newlines survive.' );
	}

	/**
	 * A bundled product's title and file names are escaped.
	 */
	public function test_bundled_product_values_are_escaped() {
		$bundle = EDD_Helper_Download::create_bundled_download();
		$child  = edd_get_bundled_products( $bundle->ID );

		// kses would strip the value on save for a user without unfiltered_html, which would
		// satisfy the assertion below without the output being escaped.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		wp_update_post(
			array(
				'ID'         => is_array( $child ) ? reset( $child ) : $child,
				'post_title' => self::MARKUP . 'MXBUNDLED',
			)
		);

		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		edd_update_order_item( $items[0]->id, array( 'product_id' => $bundle->ID ) );

		$list = $this->render( $order_id );

		$this->assertStringContainsString( 'MXBUNDLED', $list, 'The bundled title is shown.' );
		$this->assertStringNotContainsString( self::MARKUP, $list );

		wp_set_current_user( 0 );
		edd_destroy_order( $order_id );
	}

	/**
	 * File names are escaped when links are not shown.
	 */
	public function test_file_names_are_escaped_without_links() {
		add_filter( 'edd_email_show_links', '__return_false' );

		$download_id = EDD_Helper_Download::create_simple_download()->ID;

		$this->write_raw_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'index'         => '0',
					'attachment_id' => 0,
					'name'          => self::MARKUP . 'MXNOLINK',
					'file'          => 'https://example.test/file.zip',
					'condition'     => 'all',
				),
			)
		);

		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		edd_update_order_item( $items[0]->id, array( 'product_id' => $download_id ) );

		$list = $this->render( $order_id );

		remove_filter( 'edd_email_show_links', '__return_false' );

		$this->assertStringContainsString( 'MXNOLINK', $list, 'The file name is shown.' );
		$this->assertStringNotContainsString( self::MARKUP, $list );

		edd_destroy_order( $order_id );
	}

	/**
	 * A bundled product's file names are escaped when links are not shown.
	 */
	public function test_bundled_file_names_are_escaped_without_links() {
		add_filter( 'edd_email_show_links', '__return_false' );

		$bundle      = EDD_Helper_Download::create_bundled_download();
		$bundled     = edd_get_bundled_products( $bundle->ID );
		$bundle_item = (int) edd_get_bundle_item_id( reset( $bundled ) );

		$this->write_raw_meta(
			$bundle_item,
			'edd_download_files',
			array(
				array(
					'index'         => '0',
					'attachment_id' => 0,
					'name'          => self::MARKUP . 'MXBUNDLEDFILE',
					'file'          => 'https://example.test/file.zip',
					'condition'     => 'all',
				),
			)
		);

		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		edd_update_order_item( $items[0]->id, array( 'product_id' => $bundle->ID ) );

		$list = $this->render( $order_id );

		remove_filter( 'edd_email_show_links', '__return_false' );

		$this->assertStringContainsString( 'MXBUNDLEDFILE', $list, 'The bundled file name is shown.' );
		$this->assertStringNotContainsString( self::MARKUP, $list );

		edd_destroy_order( $order_id );
	}

	/**
	 * The theme-facing download links escape the file name.
	 */
	public function test_purchase_download_links_escape_the_file_name() {
		$download_id = EDD_Helper_Download::create_simple_download()->ID;

		$this->write_raw_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'index'         => '0',
					'attachment_id' => 0,
					'name'          => self::MARKUP . 'MXLINKS',
					'file'          => 'https://example.test/file.zip',
					'condition'     => 'all',
				),
			)
		);

		$order_id = EDD_Helper_Payment::create_simple_payment();
		$items    = edd_get_order( $order_id )->get_items();

		edd_update_order_item( $items[0]->id, array( 'product_id' => $download_id ) );

		$links = edd_get_purchase_download_links( $order_id );

		$this->assertStringContainsString( 'MXLINKS', $links, 'The file name is shown.' );
		$this->assertStringNotContainsString( self::MARKUP, $links );

		edd_destroy_order( $order_id );
	}

	/**
	 * The file key in the files meta is sanitized on write.
	 */
	public function test_the_file_key_is_sanitized() {
		$download_id = EDD_Helper_Download::create_simple_download()->ID;

		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'index'         => '0',
					'attachment_id' => 0,
					'name'          => 'A file',
					'file'          => '  https://example.test/f.zip' . self::MARKUP . '  ',
					'condition'     => 'all',
				),
			)
		);

		$files = get_post_meta( $download_id, 'edd_download_files', true );

		$this->assertStringNotContainsString( '<img', $files[0]['file'] );
		$this->assertStringContainsString( 'https://example.test/f.zip', $files[0]['file'] );
	}

	/**
	 * The file downloads log escapes the file name it renders.
	 */
	public function test_the_file_download_log_escapes_the_file_name() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once EDD_PLUGIN_DIR . 'includes/admin/reporting/class-base-logs-list-table.php';
		require_once EDD_PLUGIN_DIR . 'includes/admin/reporting/class-file-downloads-logs-list-table.php';

		$order_id = EDD_Helper_Payment::create_simple_payment();
		$order    = edd_get_order( $order_id );
		$items    = $order->get_items();

		$log_id = edd_add_file_download_log(
			array(
				'product_id'  => $items[0]->product_id,
				'file_id'     => 0,
				'order_id'    => $order_id,
				'price_id'    => 0,
				'customer_id' => $order->customer_id,
				'ip'          => '10.0.0.1',
			)
		);

		edd_add_file_download_log_meta( $log_id, 'file_name', self::MARKUP . 'MXLOG' );

		$table = new \EDD_File_Downloads_Log_Table();
		$rows  = $table->get_logs();

		$names = wp_list_pluck( $rows, 'file' );

		$this->assertNotEmpty( $names, 'The log must produce a row.' );
		$this->assertStringContainsString( 'MXLOG', implode( ' ', $names ), 'The file name is shown.' );
		$this->assertStringNotContainsString( self::MARKUP, implode( ' ', $names ) );

		edd_destroy_order( $order_id );
	}

	/**
	 * Writes meta without its registered sanitizer, so output tests exercise output only.
	 *
	 * @param int    $post_id The product.
	 * @param string $key     The meta key.
	 * @param mixed  $value   The value to store as given.
	 */
	private function write_raw_meta( $post_id, $key, $value ) {
		// register_meta() with an object_subtype hooks the subtype-specific filter, so both
		// have to come off for the value to be stored as given.
		remove_all_filters( "sanitize_post_meta_{$key}" );
		remove_all_filters( "sanitize_post_meta_{$key}_for_download" );
		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Renders the tag for an order.
	 *
	 * @param int $order_id The order.
	 * @return string
	 */
	private function render( $order_id ) {
		return edd_email_tag_download_list( $order_id, edd_get_order( $order_id ) );
	}
}
