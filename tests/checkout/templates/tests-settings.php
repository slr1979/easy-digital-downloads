<?php
/**
 * Settings Tests
 *
 * @package EDD\Tests\Checkout\Templates
 * @since   3.7.0
 */

namespace EDD\Tests\Checkout\Templates;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Admin\Checkout\Templates\Settings;

/**
 * Settings tests.
 *
 * Unit tests for the Settings class that handles
 * the Browse Templates button on the EDD settings page.
 *
 * @group checkout-templates
 */
class SettingsTest extends EDD_UnitTestCase {

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->settings = new Settings();
	}

	/**
	 * Tear down each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET = array();
		global $pagenow, $typenow;
		$pagenow = null;
		$typenow = null;
		\EDD\Admin\Utils\Page::clear_cache();
		parent::tear_down();
	}

	/**
	 * Test Settings implements SubscriberInterface.
	 */
	public function test_implements_subscriber_interface() {
		$this->assertInstanceOf(
			'EDD\EventManagement\SubscriberInterface',
			$this->settings
		);
	}

	/**
	 * Test get_subscribed_events returns array.
	 */
	public function test_get_subscribed_events_returns_array() {
		$events = Settings::get_subscribed_events();

		$this->assertIsArray( $events );
	}

	/**
	 * Test get_subscribed_events contains edd_after_setting_output.
	 */
	public function test_get_subscribed_events_contains_setting_output_hook() {
		$events = Settings::get_subscribed_events();

		$this->assertArrayHasKey( 'edd_after_setting_output', $events );
	}

	/**
	 * Test add_templates_button only modifies purchase_page setting.
	 */
	public function test_add_templates_button_only_for_purchase_page() {
		// Non-purchase_page setting should return unchanged HTML.
		$html   = '<select name="edd_settings[shop_page]"></select>';
		$args   = array( 'id' => 'edd_settings[shop_page]' );
		$result = $this->settings->add_templates_button( $html, $args );

		$this->assertSame( $html, $result );
	}

	/**
	 * Test add_templates_button returns original HTML for empty ID.
	 */
	public function test_add_templates_button_returns_original_for_empty_id() {
		$html   = '<select></select>';
		$args   = array( 'id' => '' );
		$result = $this->settings->add_templates_button( $html, $args );

		$this->assertSame( $html, $result );
	}

	/**
	 * Test add_templates_button returns original HTML when ID missing.
	 */
	public function test_add_templates_button_returns_original_when_id_missing() {
		$html   = '<select></select>';
		$args   = array();
		$result = $this->settings->add_templates_button( $html, $args );

		$this->assertSame( $html, $result );
	}

	/**
	 * Test add_templates_button adds mount point for purchase_page.
	 *
	 * Note: This test simulates being on the settings page by setting up
	 * the admin context.
	 */
	public function test_add_templates_button_adds_mount_point_on_settings_page() {
		// Set current user as admin.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'download_page_edd-settings' );
		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = 'download';
		$_GET['page'] = 'edd-settings';
		\EDD\Admin\Utils\Page::clear_cache();

		$html   = '<select name="edd_settings[purchase_page]"></select>';
		$args   = array( 'id' => 'edd_settings[purchase_page]' );
		$result = $this->settings->add_templates_button( $html, $args );

		// Should contain the mount point.
		$this->assertStringContainsString( 'edd-checkout-templates-root', $result );
		$this->assertStringContainsString( 'edd-checkout-templates-mount', $result );
	}

	/**
	 * Test add_templates_button returns original when not on settings page.
	 */
	public function test_add_templates_button_returns_original_when_not_settings_page() {
		// Set a different screen.
		set_current_screen( 'edit-download' );
		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = 'download';
		unset( $_GET['page'] );
		\EDD\Admin\Utils\Page::clear_cache();

		$html   = '<select name="edd_settings[purchase_page]"></select>';
		$args   = array( 'id' => 'edd_settings[purchase_page]' );
		$result = $this->settings->add_templates_button( $html, $args );

		// Should return original HTML since we're not on settings page.
		$this->assertSame( $html, $result );
	}

	/**
	 * Test mount point HTML structure.
	 */
	public function test_mount_point_html_structure() {
		// Set up admin context.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'download_page_edd-settings' );
		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = 'download';
		$_GET['page'] = 'edd-settings';
		\EDD\Admin\Utils\Page::clear_cache();

		$html   = '<select name="edd_settings[purchase_page]"></select>';
		$args   = array( 'id' => 'edd_settings[purchase_page]' );
		$result = $this->settings->add_templates_button( $html, $args );

		// Check for proper div structure.
		$this->assertStringContainsString( '<div id="edd-checkout-templates-root"', $result );
		$this->assertStringContainsString( '</div>', $result );
	}

	/**
	 * Test original HTML is preserved.
	 */
	public function test_original_html_is_preserved() {
		// Set up admin context.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'download_page_edd-settings' );
		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = 'download';
		$_GET['page'] = 'edd-settings';
		\EDD\Admin\Utils\Page::clear_cache();

		$html   = '<select name="edd_settings[purchase_page]"><option value="1">Page 1</option></select>';
		$args   = array( 'id' => 'edd_settings[purchase_page]' );
		$result = $this->settings->add_templates_button( $html, $args );

		// Original HTML should still be present.
		$this->assertStringContainsString( $html, $result );
	}
}
