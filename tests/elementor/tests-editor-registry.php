<?php
/**
 * Reason-specific unavailable-message tests for the checkout-template editor resolution, against REAL Elementor.
 *
 * Runs under --extra elementor: the RealElementorFixture confirms a real
 * \Elementor\Plugin is installed. Elementor is at/above the version floor, but
 * the Flexbox Container experiment is toggled OFF, so the REST controller must
 * surface the container-disabled message rather than the "update Elementor to
 * the minimum version" message.
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
use EDD\Checkout\Templates\Config\Constants;
use EDD\Checkout\Templates\Config\EditorRegistry;

/**
 * Container-branch coverage for the reason-specific unavailable message.
 *
 * @covers \EDD\Pro\REST\Controllers\CheckoutTemplates::resolve_editor_importer
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class EditorRegistryTest extends EDD_UnitTestCase {

	use RealElementorFixture;

	/**
	 * Require real Elementor and act as an administrator.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->edd_require_real_elementor();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Restore the singleton pointer for the next Elementor test class.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		\Elementor\Plugin::$instance = null;

		parent::tearDown();
	}

	/**
	 * Container-off surfaces the container message, never the version floor.
	 *
	 * @since 3.7.0
	 * @return void
	 */
	public function test_container_disabled_message_omits_version_floor(): void {
		// Elementor is loaded at/above the floor, but the Flexbox Container
		// experiment is OFF. Install a throwaway real Plugin whose only wired
		// member is an experiments manager reporting the feature inactive.
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

		$controller = new CheckoutTemplates();
		$result     = $controller->resolve_editor_importer( Constants::EDITOR_ELEMENTOR );

		$this->assertWPError( $result );

		$message = $result->get_error_message();

		// Positive: it is the container message (cites the feature name).
		$this->assertStringContainsString( 'Flexbox Container', $message );

		// Negative: a container failure must not cite the version floor.
		$this->assertStringNotContainsString( EditorRegistry::MIN_ELEMENTOR_VERSION, $message );
	}
}
