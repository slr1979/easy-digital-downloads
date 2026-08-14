<?php

/**
 * Styles tests.
 *
 * @package     EDD\Tests\General
 */

namespace EDD\Tests\General;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Assets\Styles as EDD_Styles;

/**
 * Tests for EDD\Assets\Styles::get_stylesheet_path().
 *
 * @group assets
 */
class StylesheetPath extends EDD_UnitTestCase {

	public function tearDown(): void {
		wp_deregister_style( 'edd-styles' );
		parent::tearDown();
	}

	public function test_get_stylesheet_path_returns_plugin_css_by_default() {
		$this->assertSame(
			EDD_PLUGIN_DIR . 'assets/build/css/frontend/edd.min.css',
			EDD_Styles::get_stylesheet_path()
		);
	}

	public function test_get_stylesheet_path_file_exists() {
		$this->assertFileExists( EDD_Styles::get_stylesheet_path() );
	}

	/**
	 * get_stylesheet_path() mirrors get_stylesheet()'s lookup so the block editor
	 * iframe filter can inline the same file the frontend enqueues by URL. If the
	 * two ever diverge, the iframe would show different styles than the frontend.
	 */
	public function test_get_stylesheet_path_matches_registered_stylesheet_url() {
		EDD_Styles::register();

		global $wp_styles;
		$style = $wp_styles->registered['edd-styles'];

		$this->assertSame(
			basename( EDD_Styles::get_stylesheet_path() ),
			basename( $style->src )
		);
	}
}
