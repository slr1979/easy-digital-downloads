<?php

namespace EDD\Tests\Downloads;

use EDD\Tests\Helpers;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * @group edd_downloads
 * @group edd_functions
 */
class DownloadLimit extends EDD_UnitTestCase {

	/**
	 * @var WP_Post
	 */
	protected static $simple_download;

	protected static $download_no_limit;

	/**
	 * A download whose file download limit is one.
	 *
	 * @var int
	 */
	protected static $limited_download_id;

	/**
	 * A completed order the limited download is requested against.
	 *
	 * @var int
	 */
	protected static $order_id;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		edd_update_option( 'file_download_limit', 2 );
		$simple_download = Helpers\EDD_Helper_Download::create_simple_download();
		self::$simple_download = edd_get_download( $simple_download->ID );

		$download_no_limit = Helpers\EDD_Helper_Download::create_simple_download();
		delete_post_meta( $download_no_limit->ID, '_edd_download_limit' );
		self::$download_no_limit = edd_get_download( $download_no_limit->ID );

		$limited_download = Helpers\EDD_Helper_Download::create_simple_download();
		update_post_meta( $limited_download->ID, '_edd_download_limit', 1 );
		self::$limited_download_id = $limited_download->ID;

		self::$order_id = Helpers\EDD_Helper_Payment::create_simple_payment();

		// The download helper seeds a limit override; the limit cases start with none.
		delete_post_meta( self::$limited_download_id, '_edd_download_limit_override_' . self::$order_id );
	}

	public function test_download_limit_is_20() {
		$this->assertEquals( 20, self::$simple_download->file_download_limit );
	}

	public function test_get_download_limit_is_20() {
		$this->assertEquals( 20, self::$simple_download->get_file_download_limit() );
	}

	public function test_get_file_download_limit() {
		$this->assertEquals( 20, edd_get_file_download_limit( self::$simple_download->ID ) );
	}

	public function test_get_file_download_limit_override() {
		$this->assertEquals( 1, edd_get_file_download_limit_override( self::$simple_download->ID, 1 ) );
	}

	public function test_is_file_at_download_limit() {
		$this->assertFalse( edd_is_file_at_download_limit( self::$simple_download->ID, 1, 1 ) );
	}

	public function test_get_file_download_limit_setting_is_2() {
		$this->assertEquals( 2, self::$download_no_limit->get_file_download_limit() );
	}

	public function test_get_file_download_limit_setting_is_0() {
		edd_delete_option( 'file_download_limit' );
		$download = edd_get_download( self::$download_no_limit->ID );
		$this->assertEquals( 0, $download->get_file_download_limit() );
	}

	/**
	 * The log row is what releases a refused download, so recording one has to hand back its ID.
	 */
	public function test_record_download_in_log_returns_the_new_row_id() {
		$log_id = edd_record_download_in_log( self::$limited_download_id, 0, array(), '127.0.0.1', self::$order_id, 0 );

		$this->assertGreaterThan( 0, $log_id );
		$this->assertInstanceOf( 'EDD\Logs\File_Download_Log', edd_get_file_download_log( $log_id ) );
	}

	/**
	 * A file download limit of one allows exactly one download.
	 */
	public function test_limit_binds_at_the_configured_count() {
		$download_id = self::$limited_download_id;

		$this->assertEquals( 1, edd_get_file_download_limit( $download_id ) );
		$this->assertEquals( 0, edd_get_file_download_limit_override( $download_id, self::$order_id ) );
		$this->assertSame( 0, $this->count_download_logs() );

		$this->assertFalse( edd_is_file_at_download_limit( $download_id, self::$order_id, 0, 0 ) );

		edd_record_download_in_log( $download_id, 0, array(), '127.0.0.1', self::$order_id, 0 );

		$this->assertSame( 1, $this->count_download_logs() );
		$this->assertTrue( edd_is_file_at_download_limit( $download_id, self::$order_id, 0, 0 ) );
	}

	/**
	 * A download is measured against the rows written ahead of its own, not against a count taken
	 * before anything was written, so a second request cannot be allowed by the first request's slot.
	 *
	 * This asserts the ordering, which is deterministic. Requests that genuinely overlap are not
	 * reproducible here and are not covered by a test anywhere yet.
	 */
	public function test_reserving_before_the_count_refuses_the_second_request() {
		$download_id = self::$limited_download_id;

		$this->assertEquals( 1, edd_get_file_download_limit( $download_id ) );
		$this->assertEquals( 0, edd_get_file_download_limit_override( $download_id, self::$order_id ) );
		$this->assertSame( 0, $this->count_download_logs() );

		// The first request takes the only slot the file allows.
		$first = edd_record_download_in_log( $download_id, 0, array(), '127.0.0.1', self::$order_id, 0 );
		$this->assertGreaterThan( 0, $first );
		$this->assertFalse( edd_file_download_log_exceeds_limit( $first ) );

		// A second request writing its row before the first was counted is still refused.
		$second = edd_record_download_in_log( $download_id, 0, array(), '127.0.0.1', self::$order_id, 0 );
		$this->assertGreaterThan( 0, $second );
		$this->assertTrue( edd_file_download_log_exceeds_limit( $second ) );

		// A refused download does not stay in the buyer's download history.
		edd_delete_file_download_log( $second );
		$this->assertSame( 1, $this->count_download_logs() );
	}

	/**
	 * A resent receipt raises the limit for one order, and that order can still download.
	 */
	public function test_limit_override_still_permits_the_download() {
		$download_id = self::$limited_download_id;

		$this->assertEquals( 1, edd_get_file_download_limit( $download_id ) );
		$this->assertSame( 0, $this->count_download_logs() );

		// The log is at the file's limit.
		$this->assertGreaterThan( 0, edd_record_download_in_log( $download_id, 0, array(), '127.0.0.1', self::$order_id, 0 ) );
		$this->assertSame( 1, $this->count_download_logs() );
		$this->assertTrue( edd_is_file_at_download_limit( $download_id, self::$order_id, 0, 0 ) );

		// Resending the receipt raises the limit for this order only.
		edd_set_file_download_limit_override( $download_id, self::$order_id );
		$this->assertEquals( 2, edd_get_file_download_limit_override( $download_id, self::$order_id ) );
		$this->assertFalse( edd_is_file_at_download_limit( $download_id, self::$order_id, 0, 0 ) );

		// The overridden order downloads again.
		$allowed = edd_record_download_in_log( $download_id, 0, array(), '127.0.0.1', self::$order_id, 0 );
		$this->assertGreaterThan( 0, $allowed );
		$this->assertFalse( edd_file_download_log_exceeds_limit( $allowed ) );
		$this->assertSame( 2, $this->count_download_logs() );

		// The download after that one is beyond the raised limit.
		$refused = edd_record_download_in_log( $download_id, 0, array(), '127.0.0.1', self::$order_id, 0 );
		$this->assertGreaterThan( 0, $refused );
		$this->assertTrue( edd_file_download_log_exceeds_limit( $refused ) );
	}

	/**
	 * The file delivery path consults the download's own log entry, so a slot that was taken while
	 * the request was in flight refuses the download instead of delivering it.
	 */
	public function test_delivery_refuses_a_download_whose_slot_was_taken() {
		$download_id  = self::$limited_download_id;
		$original_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$limit_checks = 0;
		$delivered    = false;

		// The order has to grant access to the file, or the request never reaches the download log.
		edd_add_order_item(
			array(
				'order_id'   => self::$order_id,
				'product_id' => $download_id,
				'price_id'   => 0,
				'status'     => 'complete',
				'quantity'   => 1,
				'amount'     => 20,
				'subtotal'   => 20,
			)
		);
		$this->assertTrue(
			edd_order_grants_access_to_download_files(
				array(
					'order_id'   => self::$order_id,
					'product_id' => $download_id,
					'price_id'   => 0,
				)
			)
		);

		// The file is already at its limit of one download.
		$this->assertEquals( 1, edd_get_file_download_limit( $download_id ) );
		$this->assertGreaterThan( 0, edd_record_download_in_log( $download_id, 0, array(), '127.0.0.1', self::$order_id, 0 ) );
		$this->assertSame( 1, $this->count_download_logs() );

		// A signed URL is honored without a session, so the token check is not what is under test.
		add_filter( 'edd_validate_url_token', '__return_true' );

		// The request arrives while the limit still reads as unreached, as a simultaneous request does.
		add_filter(
			'edd_is_file_at_download_limit',
			function ( $at_limit ) use ( &$limit_checks ) {
				++$limit_checks;

				return 1 === $limit_checks ? false : $at_limit;
			}
		);

		// Stop short of writing the file to the client.
		add_action(
			'edd_process_download_headers',
			function () use ( &$delivered ) {
				$delivered = true;
				throw new \RuntimeException( 'The file was delivered.' );
			}
		);

		$_GET                   = array(
			'eddfile' => sprintf( '%d:%d:0', self::$order_id, $download_id ),
			'ttl'     => current_time( 'timestamp' ) + HOUR_IN_SECONDS,
			'token'   => 'signature-not-under-test',
		);
		$_SERVER['REQUEST_URI'] = '/index.php?' . http_build_query( $_GET );

		$message = '';
		try {
			edd_process_download();
		} catch ( \WPDieException $e ) {
			$message = $e->getMessage();
		} catch ( \RuntimeException $e ) {
			$message = $e->getMessage();
		} finally {
			$_SERVER['REQUEST_URI'] = $original_uri;
		}

		$this->assertFalse( $delivered, 'The file was delivered past its download limit.' );
		$this->assertSame( 'Sorry but you have hit your download limit for this file.', $message );

		// The refused download is not left behind in the buyer's download history.
		$this->assertSame( 1, $this->count_download_logs() );
	}

	/**
	 * The limit filter can tell the pre-flight check apart from the check on a recorded download.
	 */
	public function test_limit_filter_receives_the_log_entry_it_measured() {
		$download_id = self::$limited_download_id;
		$measured    = array();

		add_filter(
			'edd_is_file_at_download_limit',
			function ( $at_limit, $product_id, $order_id, $file_id, $price_id, $before_log_id ) use ( &$measured ) {
				$measured[] = $before_log_id;

				return $at_limit;
			},
			10,
			6
		);

		edd_is_file_at_download_limit( $download_id, self::$order_id, 0, 0 );

		$log_id = edd_record_download_in_log( $download_id, 0, array(), '127.0.0.1', self::$order_id, 0 );
		$this->assertGreaterThan( 0, $log_id );

		edd_file_download_log_exceeds_limit( $log_id );

		$this->assertSame( array( null, $log_id ), $measured );
	}

	/**
	 * A refused download is answered with the download limit error rather than the file.
	 */
	public function test_download_limit_error_ends_the_request() {
		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessage( 'Sorry but you have hit your download limit for this file.' );

		edd_die_file_download_limit_reached();
	}

	/**
	 * The override key is built from whatever order ID it is handed, so an ID that
	 * resolves to nothing must not create a row.
	 */
	public function test_override_is_not_written_for_an_order_that_does_not_exist() {
		$download_id  = self::$limited_download_id;
		$absent_order = 424242;

		$this->assertFalse( edd_get_order( $absent_order ), 'The order must not exist for this test to mean anything.' );
		$this->assertEmpty(
			get_post_meta( $download_id, '_edd_download_limit_override_' . $absent_order, true ),
			'The override must be absent before the call.'
		);

		edd_set_file_download_limit_override( $download_id, $absent_order );

		$this->assertEmpty(
			get_post_meta( $download_id, '_edd_download_limit_override_' . $absent_order, true ),
			'No override row may be created for an order that does not exist.'
		);
	}

	/**
	 * The paired positive: raising the override is the purpose of the call, so a real
	 * order must still get one.
	 */
	public function test_override_is_written_for_an_order_that_exists() {
		$download_id = self::$limited_download_id;

		$this->assertInstanceOf( 'EDD\Orders\Order', edd_get_order( self::$order_id ) );

		delete_post_meta( $download_id, '_edd_download_limit_override_' . self::$order_id );

		edd_set_file_download_limit_override( $download_id, self::$order_id );

		$this->assertEquals( 2, edd_get_file_download_limit_override( $download_id, self::$order_id ) );

		edd_set_file_download_limit_override( $download_id, self::$order_id );

		$this->assertEquals( 3, edd_get_file_download_limit_override( $download_id, self::$order_id ) );
	}

	/**
	 * Counts the file download log rows for the limited download fixture.
	 *
	 * @return int
	 */
	private function count_download_logs() {
		return edd_count_file_download_logs(
			array(
				'product_id' => self::$limited_download_id,
				'file_id'    => 0,
				'order_id'   => self::$order_id,
				'price_id'   => 0,
			)
		);
	}
}
