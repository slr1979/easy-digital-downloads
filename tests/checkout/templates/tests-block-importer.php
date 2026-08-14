<?php
/**
 * Block Importer Tests
 *
 * @package EDD\Tests\Checkout\Templates
 * @since   3.7.0
 */

namespace EDD\Tests\Checkout\Templates;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Pro\Checkout\Templates\Importer\BlockImporter;
use EDD\Checkout\Templates\Config\Constants;

/**
 * BlockImporter tests.
 *
 * Tests the functionality of the Block Editor template importer.
 * Note: block import is not yet implemented, so import tests verify
 * the "not implemented" behavior.
 *
 * @group checkout-templates
 */
class BlockImporterTest extends EDD_UnitTestCase {

	/**
	 * Test page ID.
	 *
	 * @var int
	 */
	protected static $test_page_id;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected static $admin_user_id;

	/**
	 * Set up test fixtures before class runs.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Create admin user.
		self::$admin_user_id = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);

		// Create a test page.
		self::$test_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Test Checkout Page',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Test can_import returns false until Block Editor import is implemented.
	 *
	 * Block import is not yet implemented. The can_import() method returns false
	 * to prevent users from attempting to import until the feature is ready.
	 */
	public function test_can_import_returns_false_until_implemented() {
		$importer = new BlockImporter();

		// Block import is not yet implemented.
		$this->assertFalse( $importer->can_import() );
	}

	/**
	 * Test get_editor_slug returns correct value.
	 */
	public function test_get_editor_slug() {
		$importer = new BlockImporter();

		$this->assertSame( 'blocks', $importer->get_editor_slug() );
	}

	/**
	 * Test get_editor_slug matches the shared editor-slug constant.
	 *
	 * Guards against the slug drifting from the value used by the REST route
	 * enum and importer switches, which would silently break imports.
	 */
	public function test_get_editor_slug_matches_constant() {
		$importer = new BlockImporter();

		$this->assertSame( Constants::EDITOR_BLOCKS, $importer->get_editor_slug() );
	}

	/**
	 * Test get_min_version returns correct value.
	 */
	public function test_get_min_version() {
		$importer = new BlockImporter();

		$this->assertSame( '6.7', $importer->get_min_version() );
	}

	/**
	 * Test import returns not implemented error (block import is not yet implemented).
	 */
	public function test_import_returns_not_implemented_error() {
		wp_set_current_user( self::$admin_user_id );

		$importer = new BlockImporter();
		$template = array(
			'id'      => 'test-template',
			'name'    => 'Test Template',
			'content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
		);

		$result = $importer->import( $template, self::$test_page_id );

		$this->assertWPError( $result );
		$this->assertSame( 'not_implemented', $result->get_error_code() );
		$this->assertSame( 501, $result->get_error_data()['status'] );
	}

	/**
	 * Test get_edit_url returns correct format.
	 */
	public function test_get_edit_url_format() {
		$importer = new BlockImporter();

		$edit_url = $importer->get_edit_url( self::$test_page_id );

		$this->assertStringContainsString( 'post=' . self::$test_page_id, $edit_url );
		$this->assertStringContainsString( 'action=edit', $edit_url );
		$this->assertStringContainsString( 'post.php', $edit_url );
	}
}
