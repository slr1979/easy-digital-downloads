<?php
/**
 * Multisite tests for pro license/pass storage.
 *
 * The pro license is stored network-wide (site options), so it must be
 * readable from every child site. A regular (single-site) license is stored
 * per blog.
 *
 * @package   EDD\Tests\Licensing
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.1
 */

namespace EDD\Tests\Licensing;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\Multisite as MultisiteHelper;
use EDD\Tests\Helpers\Licenses as LicenseData;

/**
 * Pro license multisite storage tests.
 *
 * @since 3.7.1
 */
class Multisite extends EDD_UnitTestCase {

	use MultisiteHelper;

	/**
	 * The pass handler.
	 *
	 * @var \EDD\Admin\PassHandler\Handler
	 */
	private $pass_handler;

	/**
	 * Sets up each test, skipping on single-site.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->skip_if_not_multisite();
		$this->pass_handler = new \EDD\Admin\PassHandler\Handler();
	}

	/**
	 * Cleans up blog context and license data after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->restore_blogs();
		LicenseData::delete_pro_license();
		delete_option( 'edd_test_product_license_active' );
		parent::tearDown();
	}

	/**
	 * The pro license set on the main site is readable from a child site.
	 *
	 * @return void
	 */
	public function test_pro_license_shared_across_child_site() {
		$license = LicenseData::get_pro_license();

		$this->create_and_switch_to_blog();

		$this->assertEquals( $license->key, $this->pass_handler->get_pro_license()->key );
		$this->assertEquals( $license->payment_id, $this->pass_handler->get_pro_license()->payment_id );
	}

	/**
	 * On a child site the pro key lives in site options, not blog options.
	 *
	 * This is the exact regression: reading the key with get_option() (a blog
	 * option) returns nothing on a child site, while the site option and the
	 * license getter both return it.
	 *
	 * @return void
	 */
	public function test_pro_license_key_is_site_option_not_blog_option() {
		$license = LicenseData::get_pro_license();

		$this->create_and_switch_to_blog();

		// A blog-level read misses the key entirely on the child site.
		$this->assertFalse( get_option( 'edd_pro_license_key', false ) );

		// The site (network) option and the license getter both find it.
		$this->assertEquals( $license->key, get_site_option( 'edd_pro_license_key' ) );
		$this->assertEquals( $license->key, $this->pass_handler->get_pro_license()->key );
	}

	/**
	 * The License getter and the pass handler resolve identically on a child site.
	 *
	 * @return void
	 */
	public function test_pro_license_getter_matches_on_child_site() {
		LicenseData::get_pro_license();

		$this->create_and_switch_to_blog();

		$direct = new \EDD\Licensing\License( 'pro' );

		$this->assertNotEmpty( $direct->key );
		$this->assertEquals( $this->pass_handler->get_pro_license()->key, $direct->key );
	}

	/**
	 * A non-pro (single-site) license is stored per blog, not network-wide.
	 *
	 * The License class only routes pro storage through site options; every
	 * other product uses blog options, so its data must NOT appear on a child
	 * site. This proves the pro/site-option behavior is deliberate, not incidental.
	 *
	 * @return void
	 */
	public function test_non_pro_license_is_not_shared_across_child_site() {
		$license = new \EDD\Licensing\License( 'Test Product' );
		$license->save( (object) array( 'license' => 'valid' ) );

		// Stored as a blog option on the main site.
		$this->assertNotFalse( get_option( 'edd_test_product_license_active' ) );

		$this->create_and_switch_to_blog();

		// The child site has its own blog options, so the data is absent.
		$this->assertFalse( get_option( 'edd_test_product_license_active', false ) );
	}
}
