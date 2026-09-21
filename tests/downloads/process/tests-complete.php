<?php
namespace EDD\Tests\Downloads\Process;

use EDD\Tests\Helpers;

/**
 * Download Process Tests for completed orders
 *
 * Tests for file downloads and downloading permissions for completed orders.
 *
 * @group edd_downloads
 */
class Complete extends Helpers\Process_Download {
	/**
	 * Fully `complete` order should be able to download files
	 *
	 */
	public function test_order_with_item_should_return_true() {
		// A fixed-price product has no tier, so a null price ID is exactly what its own
		// order item carries.
		$download = Helpers\EDD_Helper_Download::create_simple_download();
		$order    = parent::edd()->order->create_and_get( array( 'status' => 'complete' ) );

		edd_add_order_item(
			array(
				'order_id'     => $order->id,
				'product_id'   => $download->ID,
				'product_name' => $download->post_title,
				'status'       => 'complete',
				'amount'       => 20,
				'subtotal'     => 20,
				'total'        => 20,
				'quantity'     => 1,
			)
		);

		$this->assertTrue( edd_order_grants_access_to_download_files( array(
			'order_id'   => $order->id,
			'product_id' => $download->ID,
		) ) );
	}

	public function test_order_with_item_variable_price_should_return_true() {
		// Specifying price ID
		$this->assertTrue( edd_order_grants_access_to_download_files( array(
			'order_id'   => self::$order->id,
			'product_id' => self::$variable_download->ID,
			'price_id'   => 0,
		) ) );
	}

	public function test_order_with_variable_price_item_and_no_price_id_should_return_false() {
		$this->assertFalse( edd_order_grants_access_to_download_files( array(
			'order_id'   => self::$order->id,
			'product_id' => self::$variable_download->ID,
			'price_id'   => null,
		) ) );
	}

	/**
	 * A variable-priced product whose own order item carries no price ID still grants access.
	 * A fixed-price purchase later made variable, or a pre-3.0 migrated order, produces exactly
	 * this shape.
	 */
	public function test_order_with_variable_price_item_and_null_own_price_id_should_return_true() {
		$order = parent::edd()->order->create_and_get( array( 'status' => 'complete' ) );

		edd_add_order_item(
			array(
				'order_id'     => $order->id,
				'product_id'   => self::$variable_download->ID,
				'product_name' => self::$variable_download->post_title,
				'status'       => 'complete',
				'amount'       => 20,
				'subtotal'     => 20,
				'total'        => 20,
				'quantity'     => 1,
			)
		);

		// Assert the fixture: the order item's own price ID is actually null, not defaulted
		// to a tier.
		$order = edd_get_order( $order->id );
		$this->assertNull( $order->items[0]->price_id );

		$this->assertTrue( edd_order_grants_access_to_download_files( array(
			'order_id'   => $order->id,
			'product_id' => self::$variable_download->ID,
		) ) );
	}

	/**
	 * If specifying a price ID that was not purchased in this order, files cannot be downloaded
	 *
	 */
	public function test_order_with_wrong_price_id_should_return_false() {
		$this->assertFalse( edd_order_grants_access_to_download_files( array(
			'order_id'   => self::$order->id,
			'product_id' => self::$variable_download->ID,
			'price_id'   => 2,
		) ) );
	}

	public function test_bundled_download_should_be_deliverable() {
		$this->assertTrue(
			edd_order_grants_access_to_download_files(
				array(
					'order_id'   => self::$order->id,
					'product_id' => self::$bundled_download->ID,
				)
			)
		);
	}

	/**
	 * Test that a completed order can download the file.
	 */
	public function test_order_can_download() {
		// Add our hook to 'fake' files to download.
		Helpers\EDD_Helper_Download::add_download_files();

		$args = array(
			'download' => self::$order->items[0]->product_id,
			'email'    => self::$order->email,
			'expire'   => current_time( 'timestamp' ) + HOUR_IN_SECONDS,
			'file_key' => 0,
			'price_id' => false,
			'key'      => self::$order->payment_key,
			'eddfile'  => sprintf( '%d:%d:%d', self::$order->id, 0, rawurlencode( '' ) ),
			'ttl'      => '',
		);
		$args['token'] = edd_get_download_token( add_query_arg( array_filter( $args ), untrailingslashit( site_url() ) ) );
		$args['oiid']  = self::$order->items[0]->id;

		$file_download_url = edd_get_download_file_url( self::$order->items[0], self::$order->email, 0 );
		$this->go_to( $file_download_url );
		$process_signed_url = edd_process_signed_download_url( $args );

		$this->assertTrue( $process_signed_url['has_access'] );

		// Remove our hook to 'fake' files to download.
		Helpers\EDD_Helper_Download::remove_download_files();
	}

	/**
	 * Test that a completed order can download the file with a price ID.
	 */
	public function test_order_can_download_with_price_id_condition_all() {
		// Add our hook to 'fake' files to download.
		Helpers\EDD_Helper_Download::add_download_files();

		$args = array(
			'download' => self::$order->items[1]->product_id,
			'email'    => self::$order->email,
			'expire'   => current_time( 'timestamp' ) + HOUR_IN_SECONDS,
			'file_key' => 0,
			'price_id' => self::$order->items[1]->price_id,
			'key'      => self::$order->payment_key,
			'eddfile'  => sprintf( '%d:%d:%d', self::$order->id, 0, rawurlencode( '' ) ),
			'ttl'      => '',
			'oiid'      => self::$order->items[1]->id,
		);
		$args['token'] = edd_get_download_token( add_query_arg( array_filter( $args ), untrailingslashit( site_url() ) ) );

		$file_download_url = edd_get_download_file_url( self::$order->items[1], self::$order->email, 0 );
		$this->go_to( $file_download_url );
		$process_signed_url = edd_process_signed_download_url( $args );

		$this->assertTrue( $process_signed_url['has_access'] );

		// Remove our hook to 'fake' files to download.
		Helpers\EDD_Helper_Download::remove_download_files();
	}

	/**
	 * Test that a completed order cannot download the file with a different Price ID than purchased.
	 */
	public function test_order_cannot_download_different_price_id() {
		// Add our hook to 'fake' files to download.
		Helpers\EDD_Helper_Download::add_download_files();

		$args = array(
			'download' => self::$order->items[1]->product_id,
			'email'    => self::$order->email,
			'expire'   => current_time( 'timestamp' ) + HOUR_IN_SECONDS,
			'file_key' => 0,
			'price_id' => self::$order->items[1]->price_id + 1,
			'key'      => self::$order->payment_key,
			'eddfile'  => sprintf( '%d:%d:%d', self::$order->id, 0, rawurlencode( '' ) ),
			'ttl'      => '',
		);
		$args['token'] = edd_get_download_token( add_query_arg( array_filter( $args ), untrailingslashit( site_url() ) ) );

		$file_download_url = edd_get_download_file_url( self::$order, self::$order->email, 0, self::$order->items[1]->product_id, self::$order->items[1]->price_id + 1 );
		$this->go_to( $file_download_url );
		$process_signed_url = edd_process_signed_download_url( $args );

		$this->assertFalse( $process_signed_url['has_access'] );

		// Remove our hook to 'fake' files to download.
		Helpers\EDD_Helper_Download::remove_download_files();
	}

	/**
	 * Test that a completed order can download the file with a price ID.
	 */
	public function test_order_can_download_with_purchased_bundle() {
		// Add our hook to 'fake' files to download.
		Helpers\EDD_Helper_Download::add_download_files();

		$args = array(
			'download' => self::$bundled_download->get_bundled_downloads()[1],
			'email'    => self::$order->email,
			'expire'   => current_time( 'timestamp' ) + HOUR_IN_SECONDS,
			'file_key' => 0,
			'key'      => self::$order->payment_key,
			'eddfile'  => sprintf( '%d:%d:%d', self::$order->id, 0, rawurlencode( '' ) ),
			'ttl'      => '',
		);
		$args['token'] = edd_get_download_token( add_query_arg( array_filter( $args ), untrailingslashit( site_url() ) ) );

		$file_download_url = edd_get_download_file_url( self::$order->items[0], self::$order->email, 0, self::$bundled_download->get_bundled_downloads()[1] );
		$this->go_to( $file_download_url );
		$process_signed_url = edd_process_signed_download_url( $args );

		$this->assertTrue( $process_signed_url['has_access'] );

		// Remove our hook to 'fake' files to download.
		Helpers\EDD_Helper_Download::remove_download_files();
	}
}
