<?php
/**
 * Tests for the FileType validator family.
 *
 * Covers the shared validation flow in EDD\Utils\Validators\FileType\Base via
 * its CSV and JSON subclasses.
 *
 * @package   EDD\Tests\Utils
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.6.9
 */

namespace EDD\Tests\Utils;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\Validators\FileType\CSV;
use EDD\Utils\Validators\FileType\JSON;

/**
 * @coversDefaultClass \EDD\Utils\Validators\FileType\Base
 *
 * @group file-type-validator
 */
class FileTypeValidator extends EDD_UnitTestCase {

	/**
	 * Paths of temp files created during a test, removed in tearDown().
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Clean up any temp files created during the test.
	 */
	public function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();

		parent::tearDown();
	}

	/**
	 * Writes contents to a temporary file and returns its path.
	 *
	 * @param string $contents The contents to write.
	 * @return string The temp file path.
	 */
	private function make_file( $contents ) {
		$path = tempnam( get_temp_dir(), 'edd-filetype-' );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * A .csv file with comma-separated contents is accepted.
	 *
	 * @covers ::is_valid
	 */
	public function test_csv_accepts_valid_csv() {
		$path = $this->make_file( "name,email\nJohn,john@example.com\n" );

		$this->assertTrue( ( new CSV() )->is_valid( $path, 'orders.csv' ) );
	}

	/**
	 * A disallowed extension is rejected regardless of contents.
	 *
	 * @covers ::is_valid
	 */
	public function test_csv_rejects_disallowed_extension() {
		$path = $this->make_file( "name,email\nJohn,john@example.com\n" );

		$this->assertFalse( ( new CSV() )->is_valid( $path, 'data.php' ) );
	}

	/**
	 * The accepted delimited-text extensions are all valid.
	 *
	 * @covers ::is_valid
	 * @dataProvider data_csv_accepted_extensions
	 *
	 * @param string $filename The filename to validate.
	 */
	public function test_csv_accepts_delimited_text_extensions( $filename ) {
		$path = $this->make_file( "name,email\nJohn,john@example.com\n" );

		$this->assertTrue( ( new CSV() )->is_valid( $path, $filename ) );
	}

	/**
	 * Data provider for the accepted delimited-text extensions.
	 *
	 * @return array
	 */
	public function data_csv_accepted_extensions() {
		return array(
			'csv' => array( 'orders.csv' ),
			'txt' => array( 'orders.txt' ),
		);
	}

	/**
	 * Contents that are not a text format are rejected even with a .csv name.
	 *
	 * @covers ::is_valid
	 * @covers ::is_mime_allowed
	 */
	public function test_csv_rejects_non_text_contents() {
		if ( ! is_callable( 'mime_content_type' ) ) {
			$this->markTestSkipped( 'fileinfo is not available.' );
		}

		$png  = "\x89PNG\r\n\x1a\n" . str_repeat( "\0", 64 );
		$path = $this->make_file( $png );

		$this->assertFalse( ( new CSV() )->is_valid( $path, 'data.csv' ) );
	}

	/**
	 * A missing file is rejected.
	 *
	 * @covers ::is_valid
	 */
	public function test_csv_rejects_missing_file() {
		$this->assertFalse( ( new CSV() )->is_valid( get_temp_dir() . 'does-not-exist.csv', 'does-not-exist.csv' ) );
	}

	/**
	 * sanitize_filename() folds inner extensions so only one trailing extension remains.
	 *
	 * @covers \EDD\Utils\Validators\FileType\Base::sanitize_filename
	 * @dataProvider data_csv_sanitize_filename
	 *
	 * @param string $filename The client-supplied filename.
	 * @param string $expected The expected safe filename.
	 */
	public function test_csv_sanitize_filename( $filename, $expected ) {
		$this->assertSame( $expected, CSV::sanitize_filename( $filename ) );
	}

	/**
	 * Data provider for sanitize_filename().
	 *
	 * @return array
	 */
	public function data_csv_sanitize_filename() {
		return array(
			'plain csv'          => array( 'orders.csv', 'orders.csv' ),
			'plain txt'          => array( 'orders.txt', 'orders.txt' ),
			'double extension'   => array( 'shell.php.csv', 'shell-php.csv' ),
			'multiple dots'      => array( 'orders.2024.export.csv', 'orders-2024-export.csv' ),
			'phtml inner'        => array( 'orders.phtml.tsv', 'orders-phtml.tsv' ),
		);
	}

	/**
	 * A .json file with valid JSON contents is accepted.
	 *
	 * @covers ::is_valid
	 */
	public function test_json_accepts_valid_json() {
		$path = $this->make_file( '{"edd_settings":{"key":"value"}}' );

		$this->assertTrue( ( new JSON() )->is_valid( $path, 'settings.json' ) );
	}

	/**
	 * An extension other than .json is rejected regardless of contents.
	 *
	 * @covers ::is_valid
	 */
	public function test_json_rejects_disallowed_extension() {
		$path = $this->make_file( '{"edd_settings":{}}' );

		$this->assertFalse( ( new JSON() )->is_valid( $path, 'data.txt' ) );
	}

	/**
	 * A .json file whose contents are not valid JSON is rejected.
	 *
	 * @covers ::is_valid
	 * @covers \EDD\Utils\Validators\FileType\JSON::validate_contents
	 */
	public function test_json_rejects_invalid_contents() {
		$path = $this->make_file( 'this is not valid json {{{' );

		$this->assertFalse( ( new JSON() )->is_valid( $path, 'settings.json' ) );
	}

	/**
	 * A missing file is rejected for JSON validation.
	 *
	 * @covers ::is_valid
	 */
	public function test_json_rejects_missing_file() {
		$this->assertFalse( ( new JSON() )->is_valid( get_temp_dir() . 'does-not-exist.json', 'does-not-exist.json' ) );
	}
}
