<?php
/**
 * Restore safety-revision routing tests (Elementor runtime).
 *
 * The REST restore path must create its pre-restore safety revision through
 * ElementorImporter::create_revision() rather than a bare wp_save_post_revision().
 * Only the former wraps the save in Elementor's change-detection filter
 * (wp_save_post_revision_post_has_changed => Document::handle_revisions_changed),
 * so an _elementor_data-only change is still backed up. A bare call diffs
 * post_content only and can silently no-op, leaving no restore point.
 *
 * These assertions need the real Elementor runtime (create_revision only attaches
 * the change-detection filter when Plugin::$instance->documents->get() returns a
 * document), so the whole class runs under --extra elementor and skips cleanly in
 * the default suite.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Elementor\Support\RealElementorFixture;
use EDD\Pro\REST\Controllers\CheckoutTemplates;

/**
 * Restore safety-revision routing coverage.
 *
 * @covers \EDD\Pro\REST\Controllers\CheckoutTemplates::restore_template
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class CheckoutTemplatesRestoreRevision extends EDD_UnitTestCase {

	use RealElementorFixture;

	/**
	 * Original purchase_page option value.
	 *
	 * @var int
	 */
	private $original_purchase_page;

	/**
	 * Restore the real Elementor singleton and act as an administrator.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->edd_boot_real_elementor();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->original_purchase_page = edd_get_option( 'purchase_page', 0 );
	}

	/**
	 * Restore the purchase_page option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		edd_update_option( 'purchase_page', $this->original_purchase_page );

		parent::tearDown();
	}

	/**
	 * Test the restore path wraps its safety revision in Elementor's change filter.
	 *
	 * With the fix the controller routes through create_revision(), which attaches
	 * Document::handle_revisions_changed to wp_save_post_revision_post_has_changed
	 * for the duration of the save. A spy on that filter records whether Elementor's
	 * callback was registered when the safety revision was written. The bare
	 * wp_save_post_revision() the controller used before never attaches it, so this
	 * assertion is red without the fix and green with it.
	 *
	 * @since 3.7.0
	 */
	public function test_restore_safety_revision_uses_elementor_change_detection() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Elementor Restore Revision Page',
				'post_status'  => 'publish',
				'post_content' => 'Static checkout content.',
			)
		);

		// Make the page a real Elementor document so create_revision() can resolve
		// one and attach the change-detection filter.
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta(
			$page_id,
			'_elementor_data',
			wp_slash( wp_json_encode( array( array( 'id' => 'a1', 'elType' => 'container', 'elements' => array() ) ) ) )
		);

		edd_update_option( 'purchase_page', $page_id );

		if ( ! wp_revisions_enabled( get_post( $page_id ) ) ) {
			$this->markTestSkipped( 'Revisions are disabled in this environment.' );
		}

		// Seed a prior revision so wp_save_post_revision()'s change check (which
		// applies the filter under test) runs during the safety-revision step.
		$revision_id = wp_save_post_revision( $page_id );
		if ( ! $revision_id ) {
			$revisions = wp_get_post_revisions( $page_id, array( 'numberposts' => 1 ) );
			if ( empty( $revisions ) ) {
				$this->markTestSkipped( 'No revisions could be created in this environment.' );
			}
			$revision_id = reset( $revisions )->ID;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $page_id );
		if ( ! $document ) {
			$this->markTestSkipped( 'Elementor did not resolve a document for the page.' );
		}

		// Spy: record whether Elementor's change-detection callback is registered
		// at the moment the safety revision's change check applies the filter.
		$elementor_filter_active = false;
		add_filter(
			'wp_save_post_revision_post_has_changed',
			function( $post_has_changed ) use ( $document, &$elementor_filter_active ) {
				if ( false !== has_filter( 'wp_save_post_revision_post_has_changed', array( $document, 'handle_revisions_changed' ) ) ) {
					$elementor_filter_active = true;
				}

				return $post_has_changed;
			},
			1,
			1
		);

		$request = new \WP_REST_Request( 'POST', '/edd/v3/checkout-templates/restore' );
		$request->set_param( 'revision_id', $revision_id );

		$controller = new CheckoutTemplates();
		$controller->restore_template( $request );

		remove_all_filters( 'wp_save_post_revision_post_has_changed' );

		$this->assertTrue(
			$elementor_filter_active,
			'The restore safety revision must route through create_revision() so Elementor change detection is applied.'
		);
	}
}
