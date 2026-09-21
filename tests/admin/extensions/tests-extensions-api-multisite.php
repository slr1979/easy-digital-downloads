<?php
/**
 * Multisite tests for extension product-data caching.
 *
 * Extension/product data is cached network-wide in a site option. On multisite
 * the API also clears any stale per-blog copy of the same option so a child
 * site never serves outdated data left over from before the network migration.
 *
 * @package   EDD\Tests\Admin\Extensions
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.1
 */

namespace EDD\Tests\Admin\Extensions;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\Multisite as MultisiteHelper;

/**
 * ExtensionsAPI multisite caching tests.
 *
 * @since 3.7.1
 */
class Multisite extends EDD_UnitTestCase {

	use MultisiteHelper;

	/**
	 * A category term ID that matches no seeded product, so the caching path
	 * runs without touching the per-item pass-manager code.
	 *
	 * @var int
	 */
	private $term_id = 999999;

	/**
	 * The cached option name derived from the query body.
	 *
	 * @var string
	 */
	private $option_name = 'edd_extension_category_999999_data';

	/**
	 * Sets up each test, skipping on single-site.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->skip_if_not_multisite();
	}

	/**
	 * Cleans up blog context and seeded options after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( $this->option_name );
		delete_site_option( $this->option_name );
		delete_site_option( 'edd_all_extension_data' );
		$this->restore_blogs();
		parent::tearDown();
	}

	/**
	 * The product-data cache is written to the network option and any stale
	 * per-blog copy is removed.
	 *
	 * @return void
	 */
	public function test_caches_to_site_option_and_clears_stale_blog_option() {
		// Seed fresh "all product data" so no remote request is made.
		update_site_option(
			'edd_all_extension_data',
			array(
				'timeout'  => time() + HOUR_IN_SECONDS,
				'products' => array( (object) array( 'categories' => array() ) ),
			)
		);

		$this->create_and_switch_to_blog();

		// A stale blog-level copy of the cache exists on the child site.
		update_option( $this->option_name, array( 'stale' => true ) );
		$this->assertNotFalse( get_option( $this->option_name ), 'Precondition: stale blog option should exist.' );

		$api = new \EDD\Admin\Extensions\ExtensionsAPI();
		$api->get_product_data( array( 'category' => $this->term_id ) );

		// The stale blog option is cleared, and the cache now lives network-wide.
		$this->assertFalse( get_option( $this->option_name, false ) );
		$this->assertArrayHasKey( 'timeout', get_site_option( $this->option_name ) );
	}
}
