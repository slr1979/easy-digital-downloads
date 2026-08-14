<?php
/**
 * Tests for the block editor iframe styles filter.
 *
 * @package EDD\Tests\Blocks
 */

namespace EDD\Tests\Blocks;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Assets\Styles;
use EDD\Utils\FileSystem;

// admin/scripts.php is only required by edd-blocks.php when is_admin() is true at
// 'plugins_loaded', which the test bootstrap doesn't guarantee, so load it directly.
if ( ! function_exists( '\EDD\Blocks\Admin\Scripts\add_edd_styles_block_editor' ) ) {
	require_once EDD_PLUGIN_DIR . 'includes/blocks/includes/admin/scripts.php';
}

/**
 * Tests for EDD\Blocks\Admin\Scripts\add_edd_styles_block_editor().
 *
 * @group blocks
 */
class EditorStyles extends EDD_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Styles::register();
	}

	public function tearDown(): void {
		wp_deregister_style( 'edd-styles' );
		parent::tearDown();
	}

	private function filter( $editor_settings = array() ) {
		return \EDD\Blocks\Admin\Scripts\add_edd_styles_block_editor( $editor_settings );
	}

	public function test_adds_one_styles_entry_when_edd_styles_is_registered() {
		$settings = $this->filter();

		$this->assertCount( 1, $settings['styles'] );
	}

	public function test_added_entry_css_is_not_empty() {
		$settings = $this->filter();

		$this->assertNotEmpty( $settings['styles'][0]['css'] );
	}

	/**
	 * The filter reads the file at get_stylesheet_path() directly. If it ever
	 * drifted from the stylesheet actually registered under 'edd-styles', the
	 * iframe would silently show stale or incorrect styling.
	 */
	public function test_added_entry_css_matches_the_registered_stylesheet_file() {
		$settings = $this->filter();

		$this->assertSame(
			FileSystem::get_contents( Styles::get_stylesheet_path() ),
			$settings['styles'][0]['css']
		);
	}

	public function test_added_entry_base_url_matches_registered_style_src() {
		global $wp_styles;
		$style = $wp_styles->registered['edd-styles'];

		$settings = $this->filter();

		$this->assertSame( $style->src, $settings['styles'][0]['baseURL'] );
	}

	public function test_added_entry_is_typed_as_theme_and_not_global_styles() {
		$settings = $this->filter();

		$this->assertSame( 'theme', $settings['styles'][0]['__unstableType'] );
		$this->assertFalse( $settings['styles'][0]['isGlobalStyles'] );
	}

	public function test_preserves_existing_styles_entries() {
		$existing = array( 'css' => '.existing{color:red;}' );

		$settings = $this->filter( array( 'styles' => array( $existing ) ) );

		$this->assertCount( 2, $settings['styles'] );
		$this->assertSame( $existing, $settings['styles'][0] );
	}

	/**
	 * This is the bail path that keeps the filter from erroring when styles are
	 * disabled via the edd_get_option( 'disable_styles' ) setting, since register()
	 * never registers the 'edd-styles' handle in that case.
	 */
	public function test_returns_settings_unchanged_when_edd_styles_is_not_registered() {
		wp_deregister_style( 'edd-styles' );

		$settings = $this->filter( array( 'foo' => 'bar' ) );

		$this->assertSame( array( 'foo' => 'bar' ), $settings );
	}
}
