<?php
/**
 * Security regression tests for EDD\Elementor\Utils\Page::is_edit_mode().
 *
 * Locks in the fix that stopped a forged elementor-preview request from a user
 * WITHOUT edit capability being treated as edit mode. is_edit_mode() now
 * delegates to Elementor's Preview::is_preview_mode() (which requires the
 * elementor-preview param AND an edit-capable current user AND a matching post
 * id), so neither a raw REQUEST_URI substring nor the proper query param can
 * forge edit mode without the capability. A legitimate editor still reads true.
 *
 * Runs under --extra elementor: the negative cases need real Elementor loaded so
 * Preview::is_preview_mode() exists on the restored singleton.
 *
 * @package     EDD\Tests\Elementor\Utils
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor\Utils;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Elementor\Support\RealElementorWidgetFixture;
use EDD\Elementor\Utils\Page;

/**
 * Edit-mode forgery regression coverage for Page::is_edit_mode().
 *
 * @covers \EDD\Elementor\Utils\Page::is_edit_mode
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class Tests_Page_Edit_Mode extends EDD_UnitTestCase {

	use RealElementorWidgetFixture;

	/**
	 * The REQUEST_URI captured before a test forged it, restored in teardown.
	 *
	 * @var string|null
	 */
	private $edd_prev_request_uri = null;

	/**
	 * The current user id captured before a test changed it, restored in teardown.
	 *
	 * @var int
	 */
	private $edd_prev_user = 0;

	/**
	 * Restore the real Elementor singleton and start from a front-end request.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->edd_boot_real_elementor();

		$this->edd_prev_request_uri = $_SERVER['REQUEST_URI'] ?? null;
		$this->edd_prev_user        = get_current_user_id();

		// Baseline: a plain front-end request, not editing.
		$this->edd_clear_edit_mode();
	}

	/**
	 * Undo any forged globals and restore the real singleton.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function tear_down() {
		// Restore the front-end/editor state the fixture manages (user, post, $_GET).
		$this->edd_clear_edit_mode();

		// Restore the real singleton before EDD teardown deletes posts.
		if ( ! empty( $GLOBALS['edd_elementor_real_plugin'] ) ) {
			\Elementor\Plugin::$instance = $GLOBALS['edd_elementor_real_plugin'];
		}

		// Restore the globals this class touched directly.
		wp_set_current_user( $this->edd_prev_user );

		if ( null === $this->edd_prev_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->edd_prev_request_uri;
		}

		unset( $_GET['elementor-preview'] );

		parent::tear_down();
	}

	/**
	 * A forged elementor-preview substring in the raw REQUEST_URI, from a user
	 * without edit capability, must NOT read as edit mode.
	 *
	 * Reproduces the pre-fix attack: the raw $_SERVER['REQUEST_URI'] carried the
	 * 'elementor-preview' substring with no query param and no capability. The
	 * fixed code never inspects REQUEST_URI, so this stays false.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function test_is_edit_mode_false_for_forged_request_uri_without_capability() {
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'subscriber' ) )
		);
		$this->assertFalse( current_user_can( 'edit_posts' ), 'A subscriber must not have edit_posts.' );

		unset( $_GET['elementor-preview'] );
		$_SERVER['REQUEST_URI'] = '/checkout/?x=elementor-preview';

		$this->assertFalse( Page::is_edit_mode() );
	}

	/**
	 * A forged request from a logged-out visitor via the raw REQUEST_URI substring
	 * must NOT read as edit mode.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function test_is_edit_mode_false_for_forged_request_uri_when_logged_out() {
		wp_set_current_user( 0 );

		unset( $_GET['elementor-preview'] );
		$_SERVER['REQUEST_URI'] = '/checkout/?x=elementor-preview';

		$this->assertFalse( Page::is_edit_mode() );
	}

	/**
	 * The proper ?elementor-preview=<id> param, from a user without edit
	 * capability, must NOT read as edit mode: it fails the capability gate inside
	 * Preview::is_preview_mode().
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function test_is_edit_mode_false_for_forged_preview_param_without_capability() {
		add_post_type_support( 'page', 'elementor' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'subscriber' ) )
		);
		$this->assertFalse( current_user_can( 'edit_posts' ), 'A subscriber must not have edit_posts.' );

		$GLOBALS['post']           = get_post( $post_id );
		$_GET['elementor-preview'] = (string) $post_id;
		$_SERVER['REQUEST_URI']    = '/?elementor-preview=' . $post_id;

		$this->assertFalse( Page::is_edit_mode() );
	}

	/**
	 * A legitimate editor previewing the post (edit-capable admin + preview param +
	 * matching queried post) MUST read as edit mode. Locks the positive path in
	 * alongside the negative cases.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function test_is_edit_mode_true_for_legitimate_editor_preview() {
		$this->edd_force_edit_mode();

		$this->assertTrue( Page::is_edit_mode() );
	}
}
