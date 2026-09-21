<?php
/**
 * Tests for the Download History exporter.
 */

namespace EDD\Tests\Exports;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Admin\Exports\Exporters\DownloadHistory;

/**
 * Tests for the Download History exporter.
 *
 * @group edd_exports
 */
class DownloadHistoryExport extends EDD_UnitTestCase {

	/**
	 * Exporter instance.
	 *
	 * @var DownloadHistory
	 */
	protected $exporter;

	/**
	 * Filters added by a test, removed again in tear_down().
	 *
	 * @var array
	 */
	private $added_filters = array();

	/**
	 * ID of the user allowed to export.
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Order fixture shared by both logs.
	 *
	 * @var \EDD\Orders\Order
	 */
	private static $order;

	/**
	 * Download fixture shared by both logs.
	 *
	 * @var int
	 */
	private static $download_id;

	/**
	 * First file-download log fixture.
	 *
	 * @var \EDD\Logs\File_Download_Log
	 */
	private static $first_log;

	/**
	 * Second file-download log fixture, distinct from the first.
	 *
	 * @var \EDD\Logs\File_Download_Log
	 */
	private static $second_log;

	/**
	 * Set up fixtures once for the class.
	 */
	public static function wpSetUpBeforeClass() {
		self::$user_id = parent::factory()->user->create( array( 'role' => 'administrator' ) );
		$user = new \WP_User( self::$user_id );
		$user->add_cap( 'export_shop_reports' );

		self::$order = parent::edd()->order->create_and_get(
			array(
				'email' => 'order-customer@example.test',
			)
		);
		self::$download_id = parent::factory()->post->create(
			array(
				'post_type'  => 'download',
				'post_title' => 'Download History Export Fixture',
			)
		);

		self::$first_log = parent::edd()->file_download_log->create_and_get(
			array(
				'customer_id' => 0,
				'order_id'    => self::$order->id,
				'product_id'  => self::$download_id,
				'file_id'     => 1,
				'ip'          => '203.0.113.11',
			)
		);

		self::$second_log = parent::edd()->file_download_log->create_and_get(
			array(
				'customer_id' => 0,
				'order_id'    => self::$order->id,
				'product_id'  => self::$download_id,
				'file_id'     => 1,
				'ip'          => '203.0.113.12',
			)
		);
	}

	/**
	 * Set up the exporter for each test.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$user_id );
		$this->exporter = new DownloadHistory();
	}

	/**
	 * Remove any filters a test added.
	 */
	public function tear_down() {
		foreach ( $this->added_filters as list( $tag, $callback, $priority ) ) {
			remove_filter( $tag, $callback, $priority );
		}
		$this->added_filters = array();

		parent::tear_down();
	}

	public function test_row_log_id_matches_its_own_log_not_the_order_or_product() {
		$row = $this->find_row_by_ip( '203.0.113.11' );

		$this->assertNotNull( $row, 'Fixture log should appear in export data.' );
		$this->assertSame( self::$first_log->id, $row['ID'] );

		// Round-trip through the real lookup rather than comparing ids, since order,
		// download and log ids come from independent auto-increment sequences and can
		// coincidentally match depending on what else has run in the same test suite.
		$log = edd_get_file_download_log( $row['ID'] );
		$this->assertSame( '203.0.113.11', $log->ip );
	}

	public function test_different_logs_produce_different_row_log_ids() {
		$first  = $this->find_row_by_ip( '203.0.113.11' );
		$second = $this->find_row_by_ip( '203.0.113.12' );

		$this->assertSame( self::$first_log->id, $first['ID'] );
		$this->assertSame( self::$second_log->id, $second['ID'] );
		$this->assertNotSame( $first['ID'], $second['ID'] );
	}

	public function test_log_id_is_the_first_exported_column() {
		$columns = $this->exporter->get_columns();

		$this->assertSame( 'ID', array_key_first( $columns ) );

		$lines = $this->csv_lines();
		$line  = $this->find_line_containing( $lines, self::$first_log->ip );

		$this->assertNotNull( $line, 'Fixture log should appear in the CSV output.' );

		$fields = $this->csv_fields( $line );

		$this->assertCount( count( $columns ), $fields );
		$this->assertSame( (string) self::$first_log->id, $fields[0] );
		$this->assertSame( self::$first_log->ip, $fields[3] );
		$this->assertSame( get_the_title( self::$download_id ), $fields[5] );
	}

	public function test_filter_can_correct_downloaded_by_using_the_log_id() {
		$corrected_email = 'actual-downloader@example.test';

		$this->add_temp_filter(
			'edd_export_get_data_file_downloads',
			function ( $rows ) use ( $corrected_email ) {
				foreach ( $rows as $key => $row ) {
					if ( self::$second_log->id === $row['ID'] ) {
						$rows[ $key ]['user'] = $corrected_email;
					}
				}

				return $rows;
			}
		);

		$rows          = $this->exporter->get_rows();
		$corrected_row = null;
		$untouched_row = null;

		foreach ( $rows as $row ) {
			if ( self::$second_log->id === $row['ID'] ) {
				$corrected_row = $row;
			}
			if ( self::$first_log->id === $row['ID'] ) {
				$untouched_row = $row;
			}
		}

		$this->assertNotNull( $corrected_row );
		$this->assertSame( $corrected_email, $corrected_row['user'] );
		$this->assertNotNull( $untouched_row );
		$this->assertNotSame( $corrected_email, $untouched_row['user'] );
	}

	public function test_an_extension_can_add_a_column_keyed_off_the_log_id() {
		$this->add_temp_filter(
			'edd_export_get_columns_file_downloads',
			function ( $columns ) {
				$columns['downloaded_by'] = 'Downloaded By';

				return $columns;
			}
		);

		$this->add_temp_filter(
			'edd_export_get_data_file_downloads',
			function ( $rows ) {
				foreach ( $rows as $key => $row ) {
					$rows[ $key ]['downloaded_by'] = 'member-of-' . $row['ID'];
				}

				return $rows;
			}
		);

		$columns = $this->exporter->get_columns();
		$lines   = $this->csv_lines();
		$line    = $this->find_line_containing( $lines, self::$first_log->ip );

		$this->assertNotNull( $line );

		$fields = $this->csv_fields( $line );

		$this->assertCount( count( $columns ), $fields );
		$this->assertSame( 'member-of-' . self::$first_log->id, end( $fields ) );
	}

	/**
	 * Add a filter and remember it so tear_down() can remove it.
	 *
	 * @param string   $tag
	 * @param callable $callback
	 * @param int      $priority
	 */
	private function add_temp_filter( string $tag, callable $callback, int $priority = 10 ): void {
		add_filter( $tag, $callback, $priority );
		$this->added_filters[] = array( $tag, $callback, $priority );
	}

	/**
	 * Find an export row by its IP address.
	 *
	 * @param string $ip
	 * @return array|null
	 */
	private function find_row_by_ip( string $ip ): ?array {
		foreach ( $this->exporter->get_data() as $row ) {
			if ( $ip === $row['ip'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Invoke print_rows() via reflection, since it is protected, and split the result into lines.
	 *
	 * @return array
	 */
	private function csv_lines(): array {
		$reflection = new \ReflectionClass( $this->exporter );
		$method     = $reflection->getMethod( 'print_rows' );
		$method->setAccessible( true );
		$csv = $method->invoke( $this->exporter );

		return array_filter( explode( "\r\n", $csv ) );
	}

	/**
	 * Split one already-quoted CSV line into its fields.
	 *
	 * Each field is individually wrapped in double quotes, so trim() is unsafe here: it would
	 * strip both quotes of a trailing empty field along with the line's own wrapping quote.
	 *
	 * @param string $line
	 * @return array
	 */
	private function csv_fields( string $line ): array {
		return explode( '","', substr( $line, 1, -1 ) );
	}

	/**
	 * Find the CSV line containing a needle.
	 *
	 * @param array  $lines
	 * @param string $needle
	 * @return string|null
	 */
	private function find_line_containing( array $lines, string $needle ): ?string {
		foreach ( $lines as $line ) {
			if ( false !== strpos( $line, $needle ) ) {
				return $line;
			}
		}

		return null;
	}
}
