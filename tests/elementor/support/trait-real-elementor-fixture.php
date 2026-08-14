<?php
/**
 * Real-Elementor test fixture.
 *
 * Gives a test a real, fully-initialized \Elementor\Plugin singleton so real
 * Elementor elements (e.g. the edd-checkout-box, which extends the native
 * Container) can be instantiated and registered without fatal — the foundation
 * the real-Elementor stub-retirement migration (M2-M4) builds on.
 *
 * How it works:
 * - The test bootstrap (tests/bootstrap.php, gated behind --extra elementor)
 *   captures the healthy singleton once, right after WP's `init` has run
 *   Plugin::init_components() (which wires experiments / documents /
 *   kits_manager / controls_manager / elements_manager). It stashes that object
 *   in $GLOBALS['edd_elementor_real_plugin'].
 * - Other Elementor test classes null \Elementor\Plugin::$instance in their
 *   teardown. That only drops the STATIC POINTER; the singleton object itself
 *   is untouched. This trait restores the captured reference, which is cheap
 *   and correct — it never rebuilds the singleton (init_components cannot be
 *   re-run) and never re-fires plugins_loaded/init (which would fatally
 *   re-register Elementor's elements).
 * - It additionally forces the composable checkout's required Container experiment
 *   active and guarantees an active kit document, so the real Container ctor's
 *   `kits_manager->get_active_kit()` resolves.
 *
 * @package     EDD\Tests\Elementor\Support
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor\Support;

trait RealElementorFixture {

	/**
	 * Skip the test unless real Elementor is installed (i.e. run with --extra elementor).
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	protected function edd_require_real_elementor(): void {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! class_exists( '\Elementor\Core\Kits\Manager' ) ) {
			$this->markTestSkipped( 'Real Elementor is not installed; run with --extra elementor.' );
		}

		if ( empty( $GLOBALS['edd_elementor_real_plugin'] ) ) {
			$this->markTestSkipped( 'Real Elementor singleton was not captured by the bootstrap.' );
		}
	}

	/**
	 * Restore the real, fully-initialized Elementor singleton and make it usable
	 * for real element instantiation.
	 *
	 * @since 3.7.0
	 *
	 * @return \Elementor\Plugin The real, initialized Elementor plugin singleton.
	 */
	protected function edd_boot_real_elementor(): \Elementor\Plugin {
		$this->edd_require_real_elementor();

		// Restore the captured singleton reference (undo any prior test's null-out).
		\Elementor\Plugin::$instance = $GLOBALS['edd_elementor_real_plugin'];

		$plugin = \Elementor\Plugin::$instance;

		// Force the composable checkout's required Container experiment active for this process.
		$this->edd_activate_container_experiment( $plugin );

		// Guarantee an active kit document so the Container ctor resolves.
		$this->edd_ensure_active_kit( $plugin );

		return $plugin;
	}

	/**
	 * Force the Container experiment to report active.
	 *
	 * Mutates the in-memory feature default (the actual state falls back to the
	 * default when no per-feature option overrides it) and writes the option so
	 * a fresh read also resolves active.
	 *
	 * @since 3.7.0
	 *
	 * @param \Elementor\Plugin $plugin The Elementor plugin singleton.
	 * @return void
	 */
	protected function edd_activate_container_experiment( \Elementor\Plugin $plugin ): void {
		if ( empty( $plugin->experiments ) ) {
			return;
		}

		$plugin->experiments->set_feature_default_state(
			'container',
			\Elementor\Core\Experiments\Manager::STATE_ACTIVE
		);

		update_option(
			$plugin->experiments->get_feature_option_key( 'container' ),
			\Elementor\Core\Experiments\Manager::STATE_ACTIVE
		);
	}

	/**
	 * Ensure a valid, published active kit document exists.
	 *
	 * EDD's per-class teardown force-deletes posts, so a kit created earlier may
	 * be gone while its option still points at the deleted ID. Recreate it when
	 * the active kit post is missing.
	 *
	 * @since 3.7.0
	 *
	 * @param \Elementor\Plugin $plugin The Elementor plugin singleton.
	 * @return void
	 */
	protected function edd_ensure_active_kit( \Elementor\Plugin $plugin ): void {
		if ( empty( $plugin->kits_manager ) ) {
			return;
		}

		$active_id = $plugin->kits_manager->get_active_id();

		if ( empty( $active_id ) || ! get_post( $active_id ) ) {
			delete_option( \Elementor\Core\Kits\Manager::OPTION_ACTIVE );
			\Elementor\Core\Kits\Manager::create_default_kit();
		}
	}
}
