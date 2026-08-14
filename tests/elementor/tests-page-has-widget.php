<?php
/**
 * Tests for EDD\Elementor\Utils\Page widget detection.
 *
 * @package     EDD\Elementor\Utils
 * @copyright   Copyright (c) 2025, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor\Utils;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Elementor\Support\RealElementorFixture;
use EDD\Elementor\Utils\Page;

/**
 * @covers EDD\Elementor\Utils\Page
 */
class Tests_Page_Has_Widget extends EDD_UnitTestCase {

	/**
	 * A container-shaped element data array (elType, no widgetType).
	 *
	 * @var array
	 */
	private static $container_element = array(
		array(
			'id'       => 'abc123',
			'elType'   => 'edd-checkout-box',
			'settings' => array(),
			'elements' => array(),
		),
	);

	/**
	 * An element data array without an edd-checkout-box container.
	 *
	 * @var array
	 */
	private static $other_element = array(
		array(
			'id'         => 'def456',
			'elType'     => 'section',
			'widgetType' => 'text-editor',
			'settings'   => array(),
			'elements'   => array(),
		),
	);

	/**
	 * has_widget returns TRUE for a container-shaped element with elType edd-checkout-box.
	 *
	 * @since 3.7.0
	 *
	 * @covers EDD\Elementor\Utils\Page::has_widget
	 */
	public function test_has_widget_returns_true_for_container_el_type() {
		$result = Page::has_widget( 'edd-checkout-box', self::$container_element );
		$this->assertTrue( $result );
	}

	/**
	 * has_widget returns FALSE when no edd-checkout-box container is present.
	 *
	 * @since 3.7.0
	 *
	 * @covers EDD\Elementor\Utils\Page::has_widget
	 */
	public function test_has_widget_returns_false_without_container_el_type() {
		$result = Page::has_widget( 'edd-checkout-box', self::$other_element );
		$this->assertFalse( $result );
	}

	/**
	 * has_widget still returns TRUE for a nested container element.
	 *
	 * @since 3.7.0
	 *
	 * @covers EDD\Elementor\Utils\Page::has_widget
	 */
	public function test_has_widget_returns_true_for_nested_container_el_type() {
		$elements = array(
			array(
				'id'       => 'outer',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'inner',
						'elType'   => 'edd-checkout-box',
						'settings' => array(),
						'elements' => array(),
					),
				),
			),
		);
		$result = Page::has_widget( 'edd-checkout-box', $elements );
		$this->assertTrue( $result );
	}
}

/**
 * Tests for the Page::get_id() singular queried-post fallback.
 *
 * Exercises the real Elementor document path so the fallback that lets
 * suppression engage on a normal front-end pageview is covered end to end.
 *
 * @since 3.7.0
 *
 * @covers EDD\Elementor\Utils\Page
 */
class Tests_Page_Get_Id_Fallback extends EDD_UnitTestCase {

	use RealElementorFixture;

	/**
	 * Restore the real Elementor singleton for each test.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->edd_boot_real_elementor();
	}

	/**
	 * The elements-data tree for a page carrying an edd-checkout-box container.
	 *
	 * @since 3.7.0
	 *
	 * @return array
	 */
	private static function checkout_box_data(): array {
		return array(
			array(
				'id'       => 'rc3box',
				'elType'   => 'edd-checkout-box',
				'settings' => array(),
				'elements' => array(),
			),
		);
	}

	/**
	 * Seed a real Elementor page whose document carries an edd-checkout-box.
	 *
	 * Uses wp_insert_post directly (not the WP factory) so import_id can force a
	 * specific post id for the forced-collision negative test.
	 *
	 * @since 3.7.0
	 *
	 * @param int $import_id Force this post id when greater than zero.
	 * @return int The created post id.
	 */
	private function seed_checkout_page( int $import_id = 0 ): int {
		$args = array(
			'post_status' => 'publish',
			'post_type'   => 'page',
			'post_title'  => 'Checkout',
		);
		if ( $import_id > 0 ) {
			$args['import_id'] = $import_id;
		}

		$post_id = wp_insert_post( $args );

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( self::checkout_box_data() ) ) );

		return (int) $post_id;
	}

	/**
	 * has_widget resolves the box on a normal singular pageview with no explicit elements.
	 *
	 * @since 3.7.0
	 *
	 * @covers EDD\Elementor\Utils\Page::has_widget
	 */
	public function test_get_id_falls_back_to_queried_singular_post() {
		$post_id = $this->seed_checkout_page();
		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_singular() );
		$this->assertTrue( Page::has_widget( 'edd-checkout-box' ) );
	}

	/**
	 * has_widget stays false on a taxonomy archive whose term id collides with a checkout post id.
	 *
	 * The is_singular() guard must keep the term id from reaching get_post();
	 * dropping the guard lets get_queried_object_id() (equal to the checkout post
	 * id here) leak through and this assertion flips to true.
	 *
	 * @since 3.7.0
	 *
	 * @covers EDD\Elementor\Utils\Page::has_widget
	 */
	public function test_get_id_does_not_fall_back_to_taxonomy_term_id() {
		$term_id = self::factory()->category->create();

		// Free the post id matching the term id so the checkout post can claim it.
		wp_delete_post( $term_id, true );

		$post_id = $this->seed_checkout_page( $term_id );

		if ( (int) $post_id !== (int) $term_id ) {
			$this->markTestSkipped( 'Could not force a post id equal to the term id; the forced collision is unavailable.' );
		}

		$this->go_to( '?cat=' . $term_id );

		if ( is_singular() ) {
			$this->markTestSkipped( 'The category archive resolved as singular; the forced collision is unavailable.' );
		}

		if ( (int) get_queried_object_id() !== (int) $term_id ) {
			$this->markTestSkipped( 'The category archive did not resolve to the seeded term id; the forced collision is unavailable.' );
		}

		$this->assertFalse( Page::has_widget( 'edd-checkout-box' ) );
	}
}
