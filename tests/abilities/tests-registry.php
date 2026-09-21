<?php
/**
 * Tests for the abilities registry.
 *
 * @package EDD\Tests\Abilities
 */

namespace EDD\Tests\Abilities;

use EDD\Abilities\Registry;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Registry tests.
 */
class RegistryTests extends EDD_UnitTestCase {

	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}
	}

	public function test_registry_returns_ability_instances_keyed_by_slug() {
		$abilities = Registry::get();

		$this->assertNotEmpty( $abilities );

		foreach ( $abilities as $slug => $ability ) {
			$this->assertInstanceOf( \EDD\Abilities\Ability::class, $ability );
			$this->assertSame( 'edd/' . $slug, $ability->get_name() );
		}
	}

	public function test_core_abilities_are_registered() {
		$this->assertTrue( Registry::is_registered( 'order-read' ) );
		$this->assertTrue( Registry::is_registered( 'discount-delete' ) );
		$this->assertFalse( Registry::is_registered( 'not-a-real-ability' ) );
	}

	public function test_an_extension_can_add_an_ability() {
		$callback = function ( $abilities ) {
			$abilities['test-extension-read'] = TestExtensionAbility::class;

			return $abilities;
		};

		add_filter( 'edd/abilities/registered', $callback );

		$this->assertTrue( Registry::is_registered( 'test-extension-read' ) );
		$this->assertInstanceOf( TestExtensionAbility::class, Registry::get_ability( 'test-extension-read' ) );

		remove_filter( 'edd/abilities/registered', $callback );

		$this->assertFalse( Registry::is_registered( 'test-extension-read' ) );
	}

	public function test_classes_which_are_not_edd_abilities_are_skipped() {
		$callback = function ( $abilities ) {
			$abilities['not-an-ability'] = \stdClass::class;
			$abilities['does-not-exist'] = '\EDD\Abilities\NoSuchClass';

			return $abilities;
		};

		add_filter( 'edd/abilities/registered', $callback );

		// Registered, but neither can be built into an ability.
		$this->assertTrue( Registry::is_registered( 'not-an-ability' ) );
		$this->assertFalse( Registry::get_ability( 'not-an-ability' ) );
		$this->assertFalse( Registry::get_ability( 'does-not-exist' ) );

		$this->assertArrayNotHasKey( 'not-an-ability', Registry::get() );
		$this->assertArrayNotHasKey( 'does-not-exist', Registry::get() );

		remove_filter( 'edd/abilities/registered', $callback );
	}

	public function test_unavailable_abilities_are_not_returned() {
		$callback = function ( $abilities ) {
			$abilities['test-unavailable-read'] = TestUnavailableAbility::class;

			return $abilities;
		};

		add_filter( 'edd/abilities/registered', $callback );

		$this->assertTrue( Registry::is_registered( 'test-unavailable-read' ) );
		$this->assertArrayNotHasKey( 'test-unavailable-read', Registry::get() );

		remove_filter( 'edd/abilities/registered', $callback );
	}

	public function test_an_extension_write_ability_inherits_the_write_gate() {
		$callback = function ( $abilities ) {
			$abilities['test-extension-write'] = TestExtensionWriteAbility::class;

			return $abilities;
		};

		add_filter( 'edd/abilities/registered', $callback );
		wp_set_current_user( 1 );

		$original = edd_get_option( \EDD\Abilities\Loader::WRITE_SETTING, false );
		$ability  = Registry::get_ability( 'test-extension-write' );

		edd_delete_option( \EDD\Abilities\Loader::WRITE_SETTING );
		$this->assertFalse( $ability->check_permissions( array() ) );

		edd_update_option( \EDD\Abilities\Loader::WRITE_SETTING, true );
		$this->assertTrue( $ability->check_permissions( array() ) );

		if ( false === $original ) {
			edd_delete_option( \EDD\Abilities\Loader::WRITE_SETTING );
		} else {
			edd_update_option( \EDD\Abilities\Loader::WRITE_SETTING, $original );
		}

		remove_filter( 'edd/abilities/registered', $callback );
	}

	public function test_registry_applies_its_filter_once_per_call() {
		$calls   = 0;
		$counter = function ( $abilities ) use ( &$calls ) {
			++$calls;

			return $abilities;
		};

		add_filter( 'edd/abilities/registered', $counter );
		$abilities = Registry::get();
		remove_filter( 'edd/abilities/registered', $counter );

		// The count only means something if the registry actually returned a
		// surface to iterate.
		$this->assertNotEmpty( $abilities );
		$this->assertSame( 1, $calls );
	}
}

/**
 * A read ability standing in for one an extension would add.
 */
class TestExtensionAbility extends \EDD\Abilities\ReadAbility {
	protected function get_slug(): string {
		return 'test-extension-read';
	}
	protected function get_label(): string {
		return 'Test Extension Read';
	}
	protected function get_description(): string {
		return 'Reads nothing at all.';
	}
	protected function get_capability(): string {
		return 'manage_shop_settings';
	}
	protected function get_output_schema(): array {
		return array( 'type' => 'object' );
	}
	protected function read_data( array $input ) {
		return array();
	}
}

/**
 * A write ability standing in for one an extension would add.
 */
class TestExtensionWriteAbility extends \EDD\Abilities\WriteAbility {
	protected function get_slug(): string {
		return 'test-extension-write';
	}
	protected function get_label(): string {
		return 'Test Extension Write';
	}
	protected function get_description(): string {
		return 'Writes nothing at all.';
	}
	protected function get_capability(): string {
		return 'manage_shop_settings';
	}
	protected function get_output_schema(): array {
		return array( 'type' => 'object' );
	}
	protected function write_data( array $input ) {
		return array();
	}
}

/**
 * An ability whose feature is switched off.
 */
class TestUnavailableAbility extends TestExtensionAbility {
	protected function get_slug(): string {
		return 'test-unavailable-read';
	}
	public function is_available(): bool {
		return false;
	}

}
