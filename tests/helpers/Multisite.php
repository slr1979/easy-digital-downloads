<?php
/**
 * Shared scaffolding for multisite-only unit tests.
 *
 * Provides a skip guard so tests are skipped on single-site installs, and a
 * helper to create and switch to a child blog with clean restoration.
 *
 * @package   EDD\Tests\Helpers
 * @copyright (c) 2026, Sandhills Development, LLC
 * @license https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since 3.7.1
 */

namespace EDD\Tests\Helpers;

/**
 * Multisite test helper trait.
 *
 * @since 3.7.1
 */
trait Multisite {

	/**
	 * The rewrite object as it stood before this test created a blog.
	 *
	 * @var \WP_Rewrite|null
	 */
	private $original_wp_rewrite;

	/**
	 * Skips the current test unless the install is multisite.
	 *
	 * @since 3.7.1
	 * @return void
	 */
	protected function skip_if_not_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}
	}

	/**
	 * Creates a child blog and switches to it.
	 *
	 * @since 3.7.1
	 * @return int The new blog ID.
	 */
	protected function create_and_switch_to_blog(): int {
		// Site initialization reaches wp_guess_url(), which reads REQUEST_URI directly.
		$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

		// A new site gets a pretty permalink structure, which the shared rewrite
		// object keeps after the switch is unwound.
		$this->original_wp_rewrite = $this->original_wp_rewrite ?? clone $GLOBALS['wp_rewrite'];

		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );

		return $blog_id;
	}

	/**
	 * Unwinds every blog switch still on the stack.
	 *
	 * Call from tearDown() so blog context never leaks between tests.
	 *
	 * @since 3.7.1
	 * @return void
	 */
	protected function restore_blogs(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		if ( $this->original_wp_rewrite ) {
			$GLOBALS['wp_rewrite']     = $this->original_wp_rewrite;
			$this->original_wp_rewrite = null;
		}
	}
}
