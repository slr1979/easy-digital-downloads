<?php
/**
 * File download logs list table tests.
 *
 * @package     EDD\Tests\Admin\Reporting
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Admin\Reporting;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * File Downloads Log Table Tests
 *
 * @group edd_logs
 * @group edd_admin
 *
 * @coversDefaultClass \EDD_File_Downloads_Log_Table
 */
class FileDownloadsLogTable extends EDD_UnitTestCase {

	/**
	 * The log the rows under test are built from.
	 *
	 * @var \EDD\Logs\File_Download_Log
	 */
	protected static $log;

	/**
	 * Set up fixtures once.
	 */
	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once EDD_PLUGIN_DIR . 'includes/admin/reporting/class-base-logs-list-table.php';
		require_once EDD_PLUGIN_DIR . 'includes/admin/reporting/class-file-downloads-logs-list-table.php';

		self::$log = parent::edd()->file_download_log->create_and_get();
	}

	public function set_up() {
		parent::set_up();

		set_current_screen( 'edit.php' );
	}

	/**
	 * @covers ::get_columns()
	 */
	public function test_columns_are_unchanged_with_no_filters_registered() {
		$table = new \EDD_File_Downloads_Log_Table();

		$this->assertSame(
			array( 'ID', 'download', 'customer', 'payment_id', 'file', 'ip', 'user_agent', 'date' ),
			array_keys( $table->get_columns() )
		);
	}

	/**
	 * @covers ::get_logs()
	 */
	public function test_row_keys_are_unchanged_with_no_filters_registered() {
		$rows = $this->get_rows_for_fixture_log();

		$this->assertSame(
			array( 'ID', 'download', 'customer', 'payment_id', 'price_id', 'file', 'ip', 'user_agent', 'date' ),
			array_keys( $rows[0] )
		);
	}

	/**
	 * @covers ::get_columns()
	 */
	public function test_columns_filter_can_add_a_column() {
		add_filter(
			'edd/logs/file_downloads/columns',
			function ( $columns ) {
				$columns['downloaded_by'] = 'Downloaded By';

				return $columns;
			}
		);

		$table = new \EDD_File_Downloads_Log_Table();

		$this->assertArrayHasKey( 'downloaded_by', $table->get_columns() );
	}

	/**
	 * @covers ::get_logs()
	 */
	public function test_row_filter_receives_the_log() {
		$received_log_id = 0;

		add_filter(
			'edd/logs/file_downloads/row',
			function ( $row, $log ) use ( &$received_log_id ) {
				$received_log_id = $log->id;

				return $row;
			},
			10,
			2
		);

		$this->get_rows_for_fixture_log();

		$this->assertSame( (int) self::$log->id, (int) $received_log_id );
	}

	/**
	 * @covers ::get_logs()
	 */
	public function test_row_filter_can_populate_an_added_column() {
		add_filter(
			'edd/logs/file_downloads/columns',
			function ( $columns ) {
				$columns['downloaded_by'] = 'Downloaded By';

				return $columns;
			}
		);

		add_filter(
			'edd/logs/file_downloads/row',
			function ( $row ) {
				$row['downloaded_by'] = 'A group member';

				return $row;
			}
		);

		$table = new \EDD_File_Downloads_Log_Table();
		$rows  = $table->get_logs( array( 'id' => self::$log->id ) );

		$this->assertSame( 'A group member', $table->column_default( $rows[0], 'downloaded_by' ) );
	}

	/**
	 * @covers ::get_logs()
	 */
	public function test_row_filter_can_name_a_different_customer_without_changing_the_log() {
		$other_customer_id = parent::edd()->customer->create();

		add_filter(
			'edd/logs/file_downloads/row',
			function ( $row ) use ( $other_customer_id ) {
				$row['customer'] = new \EDD_Customer( $other_customer_id );

				return $row;
			}
		);

		$rows = $this->get_rows_for_fixture_log();

		$this->assertSame( (int) $other_customer_id, (int) $rows[0]['customer']->id );
		$this->assertSame(
			(int) self::$log->customer_id,
			(int) edd_get_file_download_log( self::$log->id )->customer_id
		);
	}

	/**
	 * Runs the table's row builder against the fixture log.
	 *
	 * @return array
	 */
	private function get_rows_for_fixture_log() {
		$table = new \EDD_File_Downloads_Log_Table();

		return $table->get_logs( array( 'id' => self::$log->id ) );
	}
}
