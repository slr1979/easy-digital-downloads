<?php
/**
 * Registered settings fixture for the settings sanitizer tests.
 *
 * @package     EDD\Tests\Helpers
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Helpers;

/**
 * Registered settings fixture for the settings sanitizer tests.
 *
 * @since 3.7.1
 */
class RegisteredSettings {

	/**
	 * A set of registered settings covering each way a shape is decided and each key a
	 * setting is indexed under.
	 *
	 * @since 3.7.1
	 *
	 * @return array
	 */
	public static function get() {
		return array(
			'general'  => array(
				'main' => array(
					'edd_multi_select'  => array(
						'id'       => 'edd_multi_select',
						'name'     => 'Multi Select',
						'type'     => 'select',
						'multiple' => true,
					),
					'edd_plain_select'  => array(
						'id'   => 'edd_plain_select',
						'type' => 'select',
					),
					'edd_own_field'     => array(
						'id'   => 'edd_own_field',
						'type' => 'level_picker',
					),
					'edd_multicheck'    => array(
						'id'       => 'edd_multicheck',
						'type'     => 'multicheck',
						'sortable' => true,
					),
					'edd_textarea'      => array(
						'id'   => 'edd_textarea',
						'type' => 'textarea',
					),
					'edd_html_textarea' => array(
						'id'         => 'edd_html_textarea',
						'type'       => 'textarea',
						'allow_html' => true,
					),
					'edd_color'         => array(
						'id'   => 'edd_color',
						'type' => 'color',
					),
					'edd_base_type'     => array(
						'id'   => 'edd_base_type',
						'type' => 'type',
					),
					// A field can be registered under an array key that is not the setting id.
					'renamed_key'       => array(
						'id'   => 'edd_renamed_field',
						'name' => 'Renamed Field',
						'type' => 'text',
					),
				),
			),
			'gateways' => array(
				// A setting registered against the tab sits where a section would.
				'edd_tab_level' => array(
					'id'   => 'edd_tab_level',
					'type' => 'text',
				),
			),
			'misc'     => array(
				// The button colors field as the blocks admin registers it, under the key it posts under.
				'button_text' => array(
					'button_colors' => array(
						'id'   => 'blocks_button_colors',
						'name' => 'Default Button Colors',
						'type' => 'hook',
					),
				),
			),
		);
	}

	/**
	 * Puts the fixture in place of the index the registry would build for itself.
	 *
	 * @since 3.7.1
	 *
	 * @return void
	 */
	public static function use_as_index() {
		self::index()->setValue( null, \EDD\Settings\Sanitize\Registry::build( self::get() ) );
	}

	/**
	 * Puts the registry back to building its index from the registered settings.
	 *
	 * @since 3.7.1
	 *
	 * @return void
	 */
	public static function reset_index() {
		self::index()->setValue( null, null );
	}

	/**
	 * Gets the registry's index property, open for writing.
	 *
	 * @since 3.7.1
	 *
	 * @return \ReflectionProperty
	 */
	private static function index() {
		$index = new \ReflectionProperty( \EDD\Settings\Sanitize\Registry::class, 'index' );
		$index->setAccessible( true );

		return $index;
	}
}
