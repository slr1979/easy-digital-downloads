<?php
/**
 * Coverage for the Container-experiment fallback, against REAL Elementor.
 *
 * The composable checkout requires Elementor containers, so with the Container
 * experiment OFF the legacy monolith widget is exposed again and the checkout-box
 * editor script is not enqueued. Runs under --extra elementor: the ON case uses the
 * real singleton (the fixture forces the Container experiment active); the OFF case
 * points Page::is_container_active() at a throwaway, uninitialized real Plugin whose
 * only wired member is an experiments manager reporting the feature inactive.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Subscribers\EditorAssets;
use EDD\Tests\Elementor\Support\RealElementorFixture;

/**
 * Coverage for the Container-experiment fallback.
 *
 * @covers \EDD\Elementor\Widgets\Checkout::show_in_panel
 * @covers \EDD\Elementor\Subscribers\EditorAssets::enqueue_editor_scripts
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class ContainerExperimentFallbackCoverage extends EDD_UnitTestCase {

	use RealElementorFixture;

	/**
	 * Restore the real Elementor singleton before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->edd_boot_real_elementor();
	}

	/**
	 * Restore the real singleton and drop the checkout-box editor script between tests.
	 */
	public function tear_down() {
		if ( ! empty( $GLOBALS['edd_elementor_real_plugin'] ) ) {
			\Elementor\Plugin::$instance = $GLOBALS['edd_elementor_real_plugin'];
		}

		wp_dequeue_script( 'edd-elementor-checkout-box' );
		wp_deregister_script( 'edd-elementor-checkout-box' );

		parent::tear_down();
	}

	/**
	 * With the Container experiment OFF the legacy checkout widget is shown in the panel.
	 *
	 * @since 3.7.0
	 */
	public function test_legacy_checkout_shown_in_panel_when_container_off() {
		$widget = new \EDD\Elementor\Widgets\Checkout();

		$this->set_container_experiment_active( false );

		$this->assertTrue(
			$widget->show_in_panel(),
			'With the Container experiment off the composable path is unavailable, so the legacy checkout widget must be exposed in the panel as the fallback.'
		);
	}

	/**
	 * With the Container experiment ON the legacy checkout widget is hidden.
	 *
	 * @since 3.7.0
	 */
	public function test_legacy_checkout_hidden_from_panel_when_container_on() {
		$widget = new \EDD\Elementor\Widgets\Checkout();

		$this->set_container_experiment_active( true );

		$this->assertFalse(
			$widget->show_in_panel(),
			'With the Container experiment on the composable edd-checkout-box supersedes the legacy widget, so it is hidden from the panel.'
		);
	}

	/**
	 * With the Container experiment OFF the checkout-box editor script is not enqueued.
	 *
	 * @since 3.7.0
	 */
	public function test_editor_box_script_skipped_when_container_off() {
		$this->set_container_experiment_active( false );

		( new EditorAssets() )->enqueue_editor_scripts();

		$this->assertFalse(
			wp_script_is( 'edd-elementor-checkout-box', 'enqueued' ),
			'With the Container experiment off the checkout-box editor script has no element type to register, so it must not enqueue.'
		);
	}

	/**
	 * With the Container experiment ON the checkout-box editor script is enqueued.
	 *
	 * @since 3.7.0
	 */
	public function test_editor_box_script_enqueued_when_container_on() {
		$this->set_container_experiment_active( true );

		( new EditorAssets() )->enqueue_editor_scripts();

		$this->assertTrue(
			wp_script_is( 'edd-elementor-checkout-box', 'enqueued' ),
			'With the Container experiment on the checkout-box editor script must enqueue.'
		);
	}

	/**
	 * Point Page::is_container_active() at the requested Container-experiment state.
	 *
	 * Active: the real singleton booted in setUp already forces the Container
	 * experiment on. Inactive: install a throwaway, uninitialized real Plugin whose
	 * only wired member is an experiments manager returning false.
	 *
	 * @since 3.7.0
	 *
	 * @param bool $active Whether the Container experiment reports active.
	 * @return void
	 */
	private function set_container_experiment_active( bool $active ): void {
		if ( $active ) {
			$this->edd_boot_real_elementor();

			return;
		}

		$plugin              = ( new \ReflectionClass( \Elementor\Plugin::class ) )->newInstanceWithoutConstructor();
		$plugin->experiments = new class() {
			/**
			 * Report the Container experiment inactive.
			 *
			 * @param string $feature The feature slug.
			 * @return bool
			 */
			public function is_feature_active( $feature ) {
				return false;
			}
		};

		\Elementor\Plugin::$instance = $plugin;
	}
}
