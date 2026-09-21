<?php
/**
 * Tests for the AI MCP tools tab.
 *
 * @package     EDD\Tests\Admin\Tools
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Admin\Tools;

use EDD\Admin\Tools\Ai;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * AI MCP tab tests.
 *
 * @since 3.7.1
 */
class AiMcp extends EDD_UnitTestCase {

	/**
	 * Undo the WPVibe stand-in state, so a failure mid-test does not leak.
	 */
	public function tear_down() {
		$this->deactivate_wpvibe();

		parent::tear_down();
	}

	public function test_core_cards_are_present() {
		$titles = wp_list_pluck( $this->get_cards(), 'title' );

		$this->assertContains( 'Orders', $titles );
		$this->assertContains( 'Customers', $titles );
		$this->assertContains( 'Reports', $titles );
	}

	public function test_every_card_is_normalized() {
		foreach ( $this->get_cards() as $card ) {
			$this->assertSame( array( 'icon', 'title', 'items' ), array_keys( $card ) );
			$this->assertNotEmpty( $card['icon'] );
			$this->assertNotEmpty( $card['title'] );
			$this->assertNotEmpty( $card['items'] );
		}
	}

	public function test_an_extension_can_add_a_card() {
		$callback = function ( $cards ) {
			$cards[] = array(
				'icon'  => 'update',
				'title' => 'Subscriptions',
				'items' => array( 'Find a subscription by customer' ),
			);

			return $cards;
		};

		add_filter( 'edd/ai/mcp_cards', $callback );

		$cards  = $this->get_cards();
		$titles = wp_list_pluck( $cards, 'title' );
		$this->assertContains( 'Subscriptions', $titles );

		$added = end( $cards );
		$this->assertSame( 'update', $added['icon'] );
		$this->assertSame( array( 'Find a subscription by customer' ), $added['items'] );

		remove_filter( 'edd/ai/mcp_cards', $callback );

		$this->assertNotContains( 'Subscriptions', wp_list_pluck( $this->get_cards(), 'title' ) );
	}

	public function test_unusable_cards_are_discarded() {
		$callback = function ( $cards ) {
			$cards[] = array( 'title' => 'No Items', 'items' => array() );
			$cards[] = array( 'items' => array( 'No title' ) );
			$cards[] = 'not an array';

			return $cards;
		};

		add_filter( 'edd/ai/mcp_cards', $callback );

		$titles = wp_list_pluck( $this->get_cards(), 'title' );
		$this->assertNotContains( 'No Items', $titles );
		$this->assertCount( count( $titles ), array_filter( $titles ) );

		remove_filter( 'edd/ai/mcp_cards', $callback );
	}

	public function test_a_card_without_an_icon_gets_a_default() {
		$callback = function ( $cards ) {
			$cards[] = array(
				'title' => 'Iconless',
				'items' => array( 'Something' ),
			);

			return $cards;
		};

		add_filter( 'edd/ai/mcp_cards', $callback );

		$cards = $this->get_cards();
		$added = end( $cards );
		$this->assertSame( 'admin-generic', $added['icon'] );

		remove_filter( 'edd/ai/mcp_cards', $callback );
	}

	public function test_an_active_but_unconnected_wpvibe_still_offers_set_up() {
		$this->activate_wpvibe();
		update_option( 'wpvibe_last_active', 0 );

		// The fixture: WPVibe is active, and reports the site as not connected.
		$this->assertTrue( $this->is_wpvibe_active() );
		$this->assertFalse( \WPVibe_White_Label::site_is_connected() );

		$markup = $this->render_wpvibe_button();

		$this->assertStringContainsString( 'Set Up WPVibe', $markup );
		$this->assertStringNotContainsString( 'Manage WPVibe', $markup );
	}

	public function test_a_connected_wpvibe_replaces_the_set_up_button() {
		$this->activate_wpvibe();
		update_option( 'wpvibe_last_active', time() );

		// The fixture: WPVibe is active, and reports the site as connected.
		$this->assertTrue( $this->is_wpvibe_active() );
		$this->assertTrue( \WPVibe_White_Label::site_is_connected() );

		$markup = $this->render_wpvibe_button();

		$this->assertStringContainsString( 'Connected', $markup );
		$this->assertStringContainsString( 'Manage WPVibe', $markup );
		$this->assertStringNotContainsString( 'Set Up WPVibe', $markup );
	}

	/**
	 * Gets the cards the tab would render.
	 *
	 * @return array
	 */
	private function get_cards(): array {
		$method = new \ReflectionMethod( Ai::class, 'get_cards' );
		$method->setAccessible( true );

		return $method->invoke( new Ai() );
	}

	/**
	 * Renders the tab's WPVibe button and returns its markup.
	 *
	 * @return string
	 */
	private function render_wpvibe_button(): string {
		$method = new \ReflectionMethod( Ai::class, 'render_wpvibe_button' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( new Ai() );

		return (string) ob_get_clean();
	}

	/**
	 * Whether the tab considers WPVibe active.
	 *
	 * @return bool
	 */
	private function is_wpvibe_active(): bool {
		$method = new \ReflectionMethod( Ai::class, 'is_wpvibe_active' );
		$method->setAccessible( true );

		return (bool) $method->invoke( new Ai() );
	}

	/**
	 * Marks WPVibe active and stands in for the class the tab reads.
	 *
	 * WPVibe is not installed in the test environment, so the connection rule it
	 * exposes is reproduced here from vibe-ai/includes/class-wpvibe-white-label.php.
	 *
	 * @return void
	 */
	private function activate_wpvibe(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		update_option( 'active_plugins', array( Ai::WPVIBE_PLUGIN ) );

		if ( ! class_exists( '\WPVibe_White_Label' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declaring a stand-in for a class the test environment cannot install.
				'class WPVibe_White_Label {
					public static function site_is_connected() {
						$last_active = (int) get_option( "wpvibe_last_active", 0 );
						return $last_active > 0 && ( time() - $last_active ) < 30 * DAY_IN_SECONDS;
					}
				}'
			);
		}
	}

	/**
	 * Restores the plugin and connection state.
	 *
	 * @return void
	 */
	private function deactivate_wpvibe(): void {
		update_option( 'active_plugins', array() );
		delete_option( 'wpvibe_last_active' );
	}
}
