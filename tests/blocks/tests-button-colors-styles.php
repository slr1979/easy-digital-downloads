<?php
/**
 * Tests for EDD\Blocks\Styles\add_to_global_styles().
 *
 * @package     EDD\Tests\Blocks
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Blocks;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

if ( ! function_exists( '\EDD\Blocks\Styles\add_to_global_styles' ) ) {
	require_once EDD_PLUGIN_DIR . 'includes/blocks/includes/styles.php';
}

/**
 * Tests for the button color inline style the blocks package prints.
 *
 * @since 3.7.1
 *
 * @group blocks
 */
class ButtonColorsStyles extends EDD_UnitTestCase {

	public function tear_down(): void {
		edd_delete_option( 'button_colors' );
		wp_deregister_style( 'edd-blocks' );

		parent::tear_down();
	}

	public function test_a_button_color_that_is_not_a_hex_color_is_not_printed() {
		edd_update_option( 'button_colors', array( 'background' => 'red;} body{visibility:hidden' ) );
		wp_deregister_style( 'edd-blocks' );

		\EDD\Blocks\Styles\add_to_global_styles();

		$after = wp_styles()->get_data( 'edd-blocks', 'after' );
		$css   = ! empty( $after ) ? implode( '', (array) $after ) : '';

		$this->assertTrue( wp_style_is( 'edd-blocks', 'registered' ) );
		$this->assertStringNotContainsString( 'visibility', $css );
	}

	public function test_a_hex_button_color_is_printed() {
		edd_update_option( 'button_colors', array( 'background' => '#ff0000' ) );
		wp_deregister_style( 'edd-blocks' );

		\EDD\Blocks\Styles\add_to_global_styles();

		$after = wp_styles()->get_data( 'edd-blocks', 'after' );
		$css   = implode( '', (array) $after );

		$this->assertStringContainsString( '--edd-blocks-button-background:#ff0000', $css );
	}

	/**
	 * A color is a single value, so a stored list is not printed.
	 */
	public function test_a_button_color_stored_as_a_list_is_not_printed() {
		edd_update_option( 'button_colors', array( 'background' => array( '#ff0000' ) ) );
		wp_deregister_style( 'edd-blocks' );

		$stored = edd_get_option( 'button_colors' );
		$this->assertIsArray( $stored['background'], 'Fixture: the stored color must be a list.' );

		\EDD\Blocks\Styles\add_to_global_styles();

		$after = wp_styles()->get_data( 'edd-blocks', 'after' );
		$css   = ! empty( $after ) ? implode( '', (array) $after ) : '';

		$this->assertTrue( wp_style_is( 'edd-blocks', 'registered' ) );
		$this->assertStringNotContainsString( '--edd-blocks-button-background', $css );
	}

	/**
	 * Only the text and background keys are ever read, so a color stored under
	 * any other key must not reach the printed style.
	 */
	public function test_a_button_color_under_an_unknown_key_is_not_printed() {
		edd_update_option(
			'button_colors',
			array(
				'x:#fff;} body{display:none;} body{a' => '#ffffff',
				'background'                          => '#ff0000',
			)
		);
		wp_deregister_style( 'edd-blocks' );

		\EDD\Blocks\Styles\add_to_global_styles();

		$after = wp_styles()->get_data( 'edd-blocks', 'after' );
		$css   = implode( '', (array) $after );

		$this->assertStringContainsString( '--edd-blocks-button-background:#ff0000', $css );
		$this->assertStringNotContainsString( 'display:none', $css );
	}
}
