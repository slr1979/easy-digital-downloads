<?php
/**
 * Tests for the local file location boundary.
 *
 * @package     EDD\Tests\Downloads
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Downloads;

use EDD\Downloads\Process;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Utils\FileSystem;

/**
 * FileLocation tests.
 *
 * @since 3.7.1
 * @group edd_downloads
 */
class FileLocation extends EDD_UnitTestCase {

	/**
	 * Files created by a test.
	 *
	 * @var array
	 */
	private $files = array();

	/**
	 * Directories created by a test, deepest first.
	 *
	 * @var array
	 */
	private $directories = array();

	/**
	 * Attachments created by a test.
	 *
	 * @var array
	 */
	private $attachments = array();

	/**
	 * The upload_dir callback relocate_uploads() added, if any.
	 *
	 * @var callable|null
	 */
	private $upload_dir_filter;

	public function tear_down(): void {
		foreach ( $this->attachments as $attachment_id ) {
			delete_post_meta( $attachment_id, '_wp_attached_file' );
			wp_delete_post( $attachment_id, true );
		}

		foreach ( $this->files as $file ) {
			// A dangling symlink fails file_exists() but is still there to remove.
			if ( file_exists( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}

		foreach ( $this->directories as $directory ) {
			$this->remove_directory( $directory );
		}

		$this->attachments = array();
		$this->files       = array();
		$this->directories = array();

		if ( $this->upload_dir_filter ) {
			remove_filter( 'upload_dir', $this->upload_dir_filter );
			$this->upload_dir_filter = null;
		}

		remove_filter( 'edd_symlink_file_downloads', '__return_true' );

		parent::tear_down();
	}

	public function test_a_file_in_the_uploads_tree_is_allowed() {
		$file = $this->make_file( trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/boundary-product.zip' );

		$this->assertTrue( $this->is_path_allowed( $file ) );
	}

	/**
	 * A file elsewhere in the content directory is allowed.
	 *
	 * Serving product files from a directory of the store's own choosing under the content
	 * directory is supported, and this is the assertion that says so.
	 */
	public function test_a_file_elsewhere_in_the_content_directory_is_allowed() {
		$file = $this->make_file( trailingslashit( WP_CONTENT_DIR ) . 'boundary-files/product.zip' );

		$this->assertTrue( $this->is_path_allowed( $file ) );
	}

	public function test_a_content_directory_sibling_is_refused() {
		$file = $this->make_file( wp_normalize_path( WP_CONTENT_DIR ) . '-backup/database.sql' );

		$this->assertStringStartsWith(
			wp_normalize_path( ABSPATH ),
			$file,
			'The path has to be under ABSPATH for this case to mean anything.'
		);
		$this->assertFalse( $this->is_path_allowed( $file ) );
	}

	public function test_a_file_above_the_content_directory_is_refused() {
		$file = $this->make_file( trailingslashit( ABSPATH ) . 'boundary-secret.txt' );

		$this->assertFalse( $this->is_path_allowed( $file ) );
	}

	public function test_a_path_that_does_not_resolve_is_refused() {
		$this->assertFalse( $this->is_path_allowed( trailingslashit( WP_CONTENT_DIR ) . 'boundary-absent.zip' ) );
	}

	/**
	 * The uploads tree is allowed when it sits outside the content directory.
	 *
	 * Some hosts place uploads outside the content directory, and product files there have to
	 * keep being served.
	 */
	public function test_the_uploads_tree_is_allowed_when_it_sits_outside_the_content_directory() {
		$basedir = $this->relocate_uploads();
		$file    = $this->make_file( trailingslashit( $basedir ) . 'edd/product.zip' );

		$this->assertSame( $basedir, wp_upload_dir( null, false )['basedir'], 'The uploads directory has to be relocated.' );
		$this->assertTrue( $this->is_path_allowed( $file ) );
	}

	public function test_relocate_uploads_only_removes_its_own_filter() {
		// A passthrough rather than __return_true: this stays registered while relocate_uploads()
		// adds a filter that reads $uploads as an array, and a bool there is a fatal on PHP 8.
		$other = function ( $uploads ) {
			return $uploads;
		};
		add_filter( 'upload_dir', $other );

		$this->relocate_uploads();

		if ( $this->upload_dir_filter ) {
			remove_filter( 'upload_dir', $this->upload_dir_filter );
			$this->upload_dir_filter = null;
		}

		$this->assertNotFalse( has_filter( 'upload_dir', $other ) );

		remove_filter( 'upload_dir', $other );
	}

	public function test_a_path_taken_from_an_attachment_is_subject_to_the_boundary() {
		$file          = $this->make_file( trailingslashit( ABSPATH ) . 'boundary-attached.txt' );
		$attachment_id = $this->make_attachment( $file );

		$this->assertSame( $file, wp_normalize_path( get_attached_file( $attachment_id ) ) );
		$this->assertFalse( $this->is_path_allowed( get_attached_file( $attachment_id ) ) );
	}

	public function test_a_store_can_still_allow_a_location_of_its_own() {
		$file = $this->make_file( trailingslashit( ABSPATH ) . 'boundary-elsewhere.zip' );

		add_filter( 'edd_local_file_location_is_allowed', '__return_true' );
		$allowed = $this->is_path_allowed( $file );
		remove_filter( 'edd_local_file_location_is_allowed', '__return_true' );

		$this->assertTrue( $allowed );
	}

	public function test_symlink_delivery_is_held_to_the_boundary() {
		$secret = $this->make_file( trailingslashit( ABSPATH ) . 'boundary-secret-target.txt' );
		$link   = $this->make_symlink( $secret, trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/boundary-escape.zip' );

		$this->assertSame( $secret, wp_normalize_path( realpath( $link ) ), 'Fixture: the symlink must resolve to the outside target.' );

		add_filter( 'edd_symlink_file_downloads', '__return_true' );
		$url = trailingslashit( wp_upload_dir()['baseurl'] ) . 'edd/boundary-escape.zip';

		$this->expectException( \WPDieException::class );

		edd_deliver_download( $url, true );
	}

	/**
	 * The same delivery method still symlinks a file that is genuinely inside the boundary.
	 *
	 * The control for the test above. A header() warning is a test-environment limitation and is
	 * swallowed; the created symlink is what proves delivery went ahead.
	 */
	public function test_symlink_delivery_still_serves_a_file_inside_uploads() {
		$file = $this->make_file( trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/boundary-allowed.zip' );
		$link = $this->generated_symlink_path( $file );

		add_filter( 'edd_symlink_file_downloads', '__return_true' );
		$url = trailingslashit( wp_upload_dir()['baseurl'] ) . 'edd/boundary-allowed.zip';

		try {
			edd_deliver_download( $url, true );
		} catch ( \WPDieException $e ) {
			$this->fail( 'A file inside the boundary must not be refused: ' . $e->getMessage() );
		} catch ( \PHPUnit\Framework\Error\Warning $e ) {
			// Expected here; not what this test is checking.
		} finally {
			$this->files[] = $link;
		}

		$this->assertFileExists( $link, 'The symlink must be created, which only happens once the file is allowed.' );
		$this->assertSame( $file, wp_normalize_path( realpath( $link ) ), 'The link must point at the file that was delivered.' );
	}

	/**
	 * A URL which translates to a path with nothing on disk is delivered without a symlink.
	 *
	 * PHPUnit cannot read the Location value, so what is asserted is that the symlink route did
	 * not run: no symlink, and no transient claiming one.
	 */
	public function test_a_url_with_nothing_on_disk_is_delivered_without_a_symlink() {
		$url  = trailingslashit( content_url() ) . 'edd-deliver/boundary-missing.zip';
		$path = edd_get_local_path_from_url( $url );

		$this->assertNotSame( $url, $path, 'Fixture: the URL has to translate to a local path.' );
		$this->assertFalse( realpath( $path ), 'Fixture: the translated path must have nothing on disk.' );
		$this->assertTrue( edd_is_local_file( $url ), 'Fixture: the request has to be read as a local file.' );

		add_filter( 'edd_symlink_file_downloads', '__return_true' );

		$this->assertTrue( edd_symlink_file_downloads(), 'Fixture: symlink delivery has to be on.' );

		$link = $this->generated_symlink_path( $path );

		try {
			edd_deliver_download( $url, true );
		} catch ( \WPDieException $e ) {
			$this->fail( 'A URL with nothing on disk has to be delivered: ' . $e->getMessage() );
		} catch ( \PHPUnit\Framework\Error\Warning $e ) {
			// The harness has already sent output, so the Location header warns.
		} finally {
			$this->files[] = $link;
		}

		$this->assertFalse( is_link( $link ), 'Delivery without a symlink creates none.' );
		$this->assertFalse( get_transient( md5( basename( $link ) ) ), 'Delivery without a symlink claims none.' );
	}

	/**
	 * A requested value that is not a URL keeps the symlink route when nothing is on disk.
	 *
	 * A root-relative content URL makes the translation match an absolute path as well, and such a
	 * path is not something a Location header can send anywhere. The transient the symlink route
	 * claims is what shows it ran; the file standing in for the link keeps the route off the disk.
	 */
	public function test_a_requested_path_which_is_not_a_url_keeps_the_symlink_route() {
		$content_url = function () {
			return '/wp-content';
		};
		add_filter( 'content_url', $content_url );
		add_filter( 'edd_symlink_file_downloads', '__return_true' );

		$requested = trailingslashit( WP_CONTENT_DIR ) . 'edd-deliver/boundary-not-a-url.zip';

		try {
			$path = edd_get_local_path_from_url( $requested );

			$this->assertNotSame( $requested, $path, 'Fixture: the translation has to change the value.' );
			$this->assertFalse( realpath( $path ), 'Fixture: the translated path must have nothing on disk.' );
			$this->assertFalse( filter_var( $requested, FILTER_VALIDATE_URL ), 'Fixture: the request must not be a URL.' );
			$this->assertTrue( edd_is_local_file( $requested ), 'Fixture: the request has to be read as a local file.' );

			$link = $this->make_file( $this->generated_symlink_path( $path ) );

			edd_deliver_download( $requested, true );
		} catch ( \WPDieException $e ) {
			$this->fail( 'A path the caller already checked must not be refused here: ' . $e->getMessage() );
		} catch ( \PHPUnit\Framework\Error\Warning $e ) {
			// The harness has already sent output, so the Location header warns.
		} finally {
			remove_filter( 'content_url', $content_url );
		}

		$this->assertNotFalse(
			get_transient( md5( basename( $link ) ) ),
			'The symlink route runs for a requested value that is not a URL.'
		);
	}

	public function test_the_uploads_branch_translates_a_site_url_to_its_path() {
		$uploads = 'boundary-uploads';
		$basedir = $this->relocate_uploads();
		$file    = $this->make_file( trailingslashit( $basedir ) . 'product.zip' );
		$url     = site_url( '/' . $uploads . '/product.zip' );

		$this->assertStringNotContainsString( content_url(), $url, 'Fixture: no other branch may claim this URL.' );

		$this->assertSame( $file, wp_normalize_path( edd_get_local_path_from_url( $url, $uploads ) ) );
	}

	/**
	 * An absolute path that carries the UPLOADS value is left as it is: the branch translates
	 * URLs only.
	 */
	public function test_the_uploads_branch_leaves_an_absolute_path_alone() {
		$uploads = 'boundary-uploads-constant-marker';
		$file    = $this->make_file( trailingslashit( wp_upload_dir()['basedir'] ) . $uploads . '/already-absolute.zip' );

		$this->assertStringContainsString( $uploads, $file, 'Fixture: the path carries the UPLOADS value.' );

		$this->assertSame( $file, edd_get_local_path_from_url( $file, $uploads ) );
	}

	public function test_symlink_delivery_still_honors_the_stores_own_allow_filter() {
		$file = $this->make_file( trailingslashit( ABSPATH ) . 'boundary-symlink-allowed.zip' );
		$link = $this->make_symlink( $file, trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/boundary-filtered.zip' );

		add_filter( 'edd_symlink_file_downloads', '__return_true' );
		add_filter( 'edd_local_file_location_is_allowed', '__return_true' );
		$url = trailingslashit( wp_upload_dir()['baseurl'] ) . 'edd/boundary-filtered.zip';

		$generated = $this->generated_symlink_path( $link );

		try {
			edd_deliver_download( $url, true );
		} catch ( \WPDieException $e ) {
			$this->fail( 'The store\'s own allow filter must still be honored: ' . $e->getMessage() );
		} catch ( \PHPUnit\Framework\Error\Warning $e ) {
			// Expected here; not what this test is checking.
		} finally {
			remove_filter( 'edd_local_file_location_is_allowed', '__return_true' );
			$this->files[] = $generated;
		}

		$this->assertFileExists( $generated, 'The symlink must be created, which only happens once the filter allows the file.' );
		$this->assertSame( $file, wp_normalize_path( realpath( $generated ) ), 'The link must point at the target the filter allowed.' );
	}

	public function test_symlink_delivery_leaves_an_already_resolved_path_to_the_callers_own_check() {
		$file = $this->make_file( trailingslashit( ABSPATH ) . 'boundary-preresolved.txt' );

		add_filter( 'edd_symlink_file_downloads', '__return_true' );

		$link = $this->generated_symlink_path( $file );

		try {
			edd_deliver_download( $file, true );
		} catch ( \WPDieException $e ) {
			$this->fail( 'A path the resolver left unchanged must not be refused here: ' . $e->getMessage() );
		} catch ( \PHPUnit\Framework\Error\Warning $e ) {
			// Expected here; not what this test is checking.
		} finally {
			$this->files[] = $link;
		}

		$this->assertFileExists( $link, 'The symlink must be created, which only happens once delivery goes ahead.' );
		$this->assertSame( $file, wp_normalize_path( realpath( $link ) ), 'The link must point at the untranslated path it was given.' );
	}

	public function test_symlink_delivery_targets_the_validated_source() {
		$safe   = $this->make_file( trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/boundary-race-safe.zip' );
		$secret = $this->make_file( trailingslashit( ABSPATH ) . 'boundary-race-secret.txt' );
		$source = $this->make_symlink( $safe, trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/boundary-race.zip' );
		$link   = $this->generated_symlink_path( $source );

		add_filter( 'edd_symlink_file_downloads', '__return_true' );

		$hook     = 'set_transient_' . md5( basename( $link ) );
		$callback = function () use ( $secret, $source ) {
			unlink( $source );
			symlink( $secret, $source );
		};
		add_action( $hook, $callback );

		$url = trailingslashit( wp_upload_dir()['baseurl'] ) . 'edd/boundary-race.zip';

		try {
			edd_deliver_download( $url, true );
		} catch ( \WPDieException $e ) {
			$this->fail( 'The created link must still be delivered: ' . $e->getMessage() );
		} catch ( \PHPUnit\Framework\Error\Warning $e ) {
			// Expected here; not what this test is checking.
		} finally {
			remove_action( $hook, $callback );
			$this->files[] = $link;
		}

		$this->assertSame(
			$safe,
			wp_normalize_path( realpath( $link ) ),
			'The created link must still point to the source that was validated.'
		);
	}

	/**
	 * A path parse_url() cannot read is still held to the boundary.
	 *
	 * The guard in front of the directory check reads a `path` key, and parse_url() answers
	 * false rather than an array for some values a filesystem will still open.
	 */
	public function test_an_unparseable_path_is_refused() {
		$unparseable_path = '///x';

		// Fixture: this is the shape that matters, and it is openable.
		$this->assertFalse( parse_url( $unparseable_path ), 'Fixture: parse_url must not produce an array for this value.' );

		$this->assertFalse(
			edd_local_file_location_is_allowed( parse_url( $unparseable_path ), array( 'http', 'https' ), $unparseable_path ),
			'A value the parser cannot read must not be allowed.'
		);
	}

	public function test_other_unparseable_shapes_are_refused() {
		foreach ( array( ':80', '//:1/x' ) as $unparseable_path ) {
			$this->assertFalse( parse_url( $unparseable_path ), "Fixture: parse_url must not produce an array for {$unparseable_path}." );
			$this->assertFalse(
				edd_local_file_location_is_allowed( parse_url( $unparseable_path ), array( 'http', 'https' ), $unparseable_path ),
				"{$unparseable_path} must not be allowed."
			);
		}
	}

	/**
	 * A URL the store serves by redirect is still not treated as a local path.
	 *
	 * The control for the two above: widening the guard must not start holding remote URLs to
	 * a filesystem boundary they were never subject to.
	 */
	public function test_a_remote_url_is_still_not_held_to_the_boundary() {
		$url = 'https://files.example.test/product.zip';

		$this->assertTrue(
			edd_local_file_location_is_allowed( parse_url( $url ), array( 'http', 'https' ), $url ),
			'A URL served by redirect is not a local path.'
		);
	}

	/**
	 * An unresolvable path is refused wherever the process happens to be running.
	 */
	public function test_an_unresolvable_path_is_refused_from_inside_the_content_directory() {
		$cwd = getcwd();
		chdir( WP_CONTENT_DIR );

		try {
			$this->assertFalse( Process::is_in_allowed_directory( '' ), 'An empty path is not in an allowed directory.' );
			$this->assertFalse( Process::is_in_allowed_directory( false ), 'A failed resolution is not in an allowed directory.' );
			$this->assertFalse( $this->is_path_allowed( '' ), 'An empty path is refused by the boundary.' );
		} finally {
			chdir( $cwd );
		}
	}

	public function test_resolver_reports_an_existing_absolute_path_directly() {
		$file = $this->make_file( trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/resolve-absolute.zip' );

		$resolved = $this->resolve_path( $file );

		$this->assertTrue( $resolved['reads_from_disk'], 'An absolute path is read from disk.' );
		$this->assertSame( $file, $resolved['path'], 'The path is passed through unchanged.' );
	}

	public function test_resolver_translates_a_content_url_to_a_path() {
		$file = $this->make_file( trailingslashit( WP_CONTENT_DIR ) . 'edd-resolve/resolve-url.zip' );

		$resolved = $this->resolve_path( trailingslashit( content_url() ) . 'edd-resolve/resolve-url.zip' );

		$this->assertTrue( $resolved['reads_from_disk'], 'A translated URL is read from disk.' );
		$this->assertSame( $file, wp_normalize_path( $resolved['path'] ), 'The URL resolves to its own file.' );
	}

	public function test_resolver_translates_an_https_content_url_to_a_path() {
		$file = $this->make_file( trailingslashit( WP_CONTENT_DIR ) . 'edd-resolve/resolve-https.zip' );

		$resolved = $this->resolve_path( set_url_scheme( trailingslashit( content_url() ) . 'edd-resolve/resolve-https.zip', 'https' ) );

		$this->assertTrue( $resolved['reads_from_disk'], 'A translated HTTPS URL is read from disk.' );
		$this->assertSame( $file, wp_normalize_path( $resolved['path'] ), 'The URL resolves to its own file.' );
	}

	public function test_resolver_translates_an_uploads_constant_url() {
		$uploads = 'boundary-uploads-constant-marker';
		$file    = $this->make_file( ABSPATH . $uploads . '/resolver.zip' );
		$url     = site_url( '/' . $uploads . '/resolver.zip' );

		$resolved = $this->resolve_path( $url, $uploads );

		$this->assertTrue( $resolved['reads_from_disk'], 'A translated UPLOADS URL is read from disk.' );
		$this->assertSame( $file, wp_normalize_path( $resolved['path'] ), 'The URL resolves to its own file.' );
	}

	/**
	 * A value no branch translates is handed back untouched, and is not a path read from disk.
	 */
	public function test_resolver_leaves_an_untranslatable_value_alone() {
		$resolved = $this->resolve_path( 'https://files.example.test/product.zip' );

		$this->assertFalse( $resolved['reads_from_disk'], 'Nothing translated it, so it is not read from disk.' );
		$this->assertSame( 'https://files.example.test/product.zip', $resolved['path'], 'The value is unchanged.' );
	}

	/**
	 * A content URL whose file does not exist reports direct with no path.
	 *
	 * Long-standing behavior of this resolution, kept deliberately: the caller checks the path
	 * for emptiness rather than relying on the flag alone.
	 */
	public function test_resolver_reports_no_path_when_a_translated_url_does_not_resolve() {
		$resolved = $this->resolve_path( trailingslashit( content_url() ) . 'edd-resolve/not-here.zip' );

		$this->assertTrue( $resolved['reads_from_disk'] );
		$this->assertEmpty( $resolved['path'], 'A translation which does not resolve yields no path.' );
	}

	/**
	 * The value Amazon S3 supplies through `edd_requested_file` is served by redirect and
	 * resolves no path.
	 */
	public function test_a_signed_remote_url_is_allowed_without_a_resolved_path() {
		$url = 'https://bucket.s3.amazonaws.com/product.zip?X-Amz-Signature=abc';

		$this->assertTrue( $this->is_delivery_allowed( $url ) );
	}

	public function test_delivery_allows_an_absolute_path_inside_the_boundary() {
		$file = $this->make_file( trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/process-resolve-allowed.zip' );

		$this->assertTrue( $this->is_delivery_allowed( $file, $file ) );
	}

	public function test_delivery_refuses_an_absolute_path_outside_the_boundary() {
		$file = $this->make_file( trailingslashit( ABSPATH ) . 'process-resolve-refused.txt' );

		$this->assertFalse( $this->is_delivery_allowed( $file, $file ) );
	}

	public function test_delivery_allows_a_translated_url_inside_the_boundary() {
		$file = $this->make_file( trailingslashit( WP_CONTENT_DIR ) . 'edd-resolve/process-resolve-url.zip' );
		$url  = trailingslashit( content_url() ) . 'edd-resolve/process-resolve-url.zip';

		$path = $this->resolve_path( $url )['path'];

		$this->assertSame( $file, wp_normalize_path( $path ) );
		$this->assertTrue( $this->is_delivery_allowed( $url, $path ) );
	}

	/**
	 * A URL that translates to a path outside the boundary is refused, even though the raw
	 * URL itself carries no scheme the first check would refuse on its own.
	 */
	public function test_delivery_refuses_a_translated_url_outside_the_boundary() {
		$secret = $this->make_file( trailingslashit( ABSPATH ) . 'process-resolve-secret.txt' );
		$this->make_symlink( $secret, trailingslashit( WP_CONTENT_DIR ) . 'edd-resolve/process-resolve-escape.zip' );
		$url = trailingslashit( content_url() ) . 'edd-resolve/process-resolve-escape.zip';

		$path = $this->resolve_path( $url )['path'];

		$this->assertSame( $secret, wp_normalize_path( realpath( $path ) ), 'Fixture: the URL must resolve to the outside target.' );
		$this->assertFalse( $this->is_delivery_allowed( $url, $path ) );
	}

	/**
	 * The boundary filter runs once for a path resolution left it unchanged, so a store's own
	 * `edd_local_file_location_is_allowed` callback is not asked about the same value twice.
	 */
	public function test_delivery_checks_the_boundary_once_for_an_absolute_path() {
		$file = $this->make_file( trailingslashit( wp_upload_dir()['basedir'] ) . 'edd/process-resolve-once.zip' );

		$calls    = 0;
		$counter  = function ( $allowed ) use ( &$calls ) {
			++$calls;
			return $allowed;
		};
		add_filter( 'edd_local_file_location_is_allowed', $counter );

		$this->is_delivery_allowed( $file, $file );

		remove_filter( 'edd_local_file_location_is_allowed', $counter );

		$this->assertSame( 1, $calls, 'An absolute path is checked once, not re-checked against its own unchanged value.' );
	}

	/**
	 * The boundary filter runs again for a path resolution translated, since that is a
	 * genuinely different value than the one first checked.
	 */
	public function test_delivery_checks_the_boundary_again_for_a_translated_path() {
		$this->make_file( trailingslashit( WP_CONTENT_DIR ) . 'edd-resolve/process-resolve-twice.zip' );
		$url = trailingslashit( content_url() ) . 'edd-resolve/process-resolve-twice.zip';

		$path = $this->resolve_path( $url )['path'];

		$calls   = 0;
		$counter = function ( $allowed ) use ( &$calls ) {
			++$calls;
			return $allowed;
		};
		add_filter( 'edd_local_file_location_is_allowed', $counter );

		$this->is_delivery_allowed( $url, $path );

		remove_filter( 'edd_local_file_location_is_allowed', $counter );

		$this->assertSame( 2, $calls, 'A translated path is checked once for the request and once for the path it resolved to.' );
	}

	/**
	 * Under the redirect method nothing is resolved, so a same-site URL is served by redirect
	 * and the web server answers for it. Dropbox File Store and Free Downloads force that method.
	 */
	public function test_a_same_site_url_is_allowed_when_no_path_is_resolved() {
		$secret = $this->make_file( trailingslashit( ABSPATH ) . 'process-resolve-redirect-secret.txt' );
		$this->make_symlink( $secret, trailingslashit( WP_CONTENT_DIR ) . 'edd-resolve/process-resolve-redirect-escape.zip' );
		$url = trailingslashit( content_url() ) . 'edd-resolve/process-resolve-redirect-escape.zip';

		$this->assertFalse(
			$this->is_delivery_allowed( $url, $this->resolve_path( $url )['path'] ),
			'Fixture: a resolved path to this URL must sit outside the boundary.'
		);
		$this->assertTrue( $this->is_delivery_allowed( $url ) );
	}

	/**
	 * Runs a requested file through path resolution alone, the way Process::resolve_path() does.
	 *
	 * @param string            $requested_file The path or URL to resolve.
	 * @param string|false|null $uploads        The UPLOADS constant to read, false for none; null reads the constant.
	 * @return array
	 */
	private function resolve_path( $requested_file, $uploads = null ) {
		return \EDD\Downloads\Process::resolve_path(
			$requested_file,
			parse_url( $requested_file ),
			$uploads
		);
	}

	/**
	 * Runs a requested file through the delivery decision, the way edd_process_download() does.
	 *
	 * @param string      $requested_file The path or URL requested.
	 * @param string|null $resolved_path  The local path resolution produced, if the method resolves one.
	 * @return bool
	 */
	private function is_delivery_allowed( $requested_file, $resolved_path = null ) {
		return \EDD\Downloads\Process::is_delivery_allowed(
			$requested_file,
			parse_url( $requested_file ),
			$resolved_path
		);
	}

	/**
	 * Runs an absolute path through the boundary.
	 *
	 * @param string $path The path to test.
	 * @return bool
	 */
	private function is_path_allowed( $path ) {
		return edd_local_file_location_is_allowed(
			array( 'path' => $path ),
			\EDD\Downloads\Process::DIRECT_URL_SCHEMES,
			$path
		);
	}

	/**
	 * Creates a file, and any directory it needs, for the duration of the test.
	 *
	 * @param string $path Absolute path to create.
	 * @return string The normalized path.
	 */
	private function make_file( $path ) {
		$path      = wp_normalize_path( $path );
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
			array_unshift( $this->directories, $directory );
		}

		$handle = FileSystem::fopen( $path, 'w' );
		fwrite( $handle, 'boundary' );
		fclose( $handle );

		$this->files[] = $path;

		return $path;
	}

	/**
	 * Creates a symlink, and any directory it needs, for the duration of the test.
	 *
	 * @param string $target The absolute path the symlink points to.
	 * @param string $path   Absolute path of the symlink itself.
	 * @return string The normalized path of the symlink.
	 */
	private function make_symlink( $target, $path ) {
		$path      = wp_normalize_path( $path );
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
			array_unshift( $this->directories, $directory );
		}

		symlink( $target, $path );

		$this->files[] = $path;

		return $path;
	}

	/**
	 * Removes a directory and anything left inside it.
	 *
	 * @param string $directory The directory to remove.
	 */
	private function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( array_diff( scandir( $directory ), array( '.', '..' ) ) as $entry ) {
			$path = trailingslashit( $directory ) . $entry;

			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $directory );
	}

	/**
	 * Creates an attachment whose file is the given path.
	 *
	 * @param string $path Absolute path the attachment names.
	 * @return int
	 */
	private function make_attachment( $path ) {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => $path,
				'post_parent'    => 0,
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Boundary',
			)
		);

		update_post_meta( $attachment_id, '_wp_attached_file', $path );

		$this->attachments[] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * Points the uploads directory outside the content directory.
	 *
	 * @return string The new base directory.
	 */
	private function relocate_uploads() {
		$basedir = wp_normalize_path( trailingslashit( ABSPATH ) . 'boundary-uploads' );

		wp_mkdir_p( $basedir );
		array_unshift( $this->directories, $basedir );

		$this->upload_dir_filter = function ( $uploads ) use ( $basedir ) {
			$uploads['basedir'] = $basedir;
			$uploads['path']    = $basedir . $uploads['subdir'];

			return $uploads;
		};
		add_filter( 'upload_dir', $this->upload_dir_filter );

		return $basedir;
	}

	/**
	 * The path `edd_deliver_download()` generates its own symlink at for a given source file.
	 *
	 * @param string $file The resolved local path passed to edd_deliver_download().
	 * @return string
	 */
	private function generated_symlink_path( $file ) {
		$ext       = edd_get_file_extension( $file );
		$parts     = explode( '.', $file );
		$name      = basename( $parts[0] );
		$md5       = md5( $file );
		$file_name = $name . '_' . substr( $md5, 0, -15 ) . '.' . $ext;

		return edd_get_symlink_dir() . '/' . $file_name;
	}
}
