<?php
/**
 * Multisite tests for the PHP session manager key.
 *
 * On multisite the logged-in session key is suffixed with the current blog ID
 * so a user's cart/session data on one sub-site never collides with another.
 *
 * @package   EDD\Tests\Sessions
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.1
 */

namespace EDD\Tests\Sessions;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\Multisite as MultisiteHelper;

/**
 * PHP session manager multisite key tests.
 *
 * @since 3.7.1
 */
class Multisite extends EDD_UnitTestCase {

	use MultisiteHelper;

	/**
	 * The PHP session manager.
	 *
	 * @var \EDD\Sessions\Managers\PHP
	 */
	private $manager;

	/**
	 * Sets up each test, skipping on single-site.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->skip_if_not_multisite();
		$this->manager = new \EDD\Sessions\Managers\PHP();
	}

	/**
	 * Cleans up blog context and the current user after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->restore_blogs();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The logged-in session key is suffixed with the current blog ID.
	 *
	 * @return void
	 */
	public function test_session_key_includes_blog_id() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$this->assertEquals(
			'edd_' . $user_id . '_' . get_current_blog_id(),
			$this->manager->get_logged_in_user_key()
		);
	}

	/**
	 * The same user gets a different session key on a different blog.
	 *
	 * @return void
	 */
	public function test_session_key_changes_across_child_site() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$main_key = $this->manager->get_logged_in_user_key();

		$blog_id  = $this->create_and_switch_to_blog();
		$blog_key = $this->manager->get_logged_in_user_key();

		$this->assertNotEquals( $main_key, $blog_key );
		$this->assertEquals( 'edd_' . $user_id . '_' . $blog_id, $blog_key );
	}
}
