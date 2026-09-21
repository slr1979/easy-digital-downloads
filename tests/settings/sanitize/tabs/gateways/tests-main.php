<?php

namespace EDD\Tests\Settings\Sanitize\Tabs\Gateways;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Admin\Settings\Sanitize\Tabs\Gateways\Main;

class MainSection extends EDD_UnitTestCase {

	/**
	 * Settings global as it stood before the test ran.
	 *
	 * @var array
	 */
	private $original_options;

	public function set_up(): void {
		parent::set_up();

		global $edd_options;

		$this->original_options = $edd_options;

		// The section boundary reads what is stored, so start from nothing stored for these keys.
		unset( $edd_options['gateways'], $edd_options['default_gateway'] );
	}

	public function tear_down(): void {
		global $edd_options;

		$edd_options = $this->original_options;

		parent::tear_down();
	}

	public function test_empty_default_gateway() {
		$this->assertSame(
			array(
				'default_gateway' => '',
			),
			Main::sanitize(
				array(
					'default_gateway' => '',
				)
			)
		);
	}

	public function test_no_gateways() {
		$this->assertSame(
			array(
				'gateways' => array(),
			),
			Main::sanitize(
				array(
					'gateways'        => '',
					'default_gateway' => 'paypal',
				)
			)
		);
	}

	/**
	 * The `-1` a list posts when nothing is checked is the empty list.
	 */
	public function test_negative_one_gateways() {
		$this->assertSame(
			array(
				'gateways' => array(),
			),
			Main::sanitize(
				array(
					'default_gateway' => 'paypal',
					'gateways'        => '-1',
				)
			)
		);
	}

	/**
	 * The enabled gateways are a list of keys, so a single value for them is
	 * refused at the boundary and there is no default to keep.
	 */
	public function test_default_gateway_is_dropped_when_the_gateways_are_not_a_list() {
		$this->assertSame(
			array(
				'gateways' => array(),
			),
			Main::sanitize(
				array(
					'default_gateway' => 'manual',
					'gateways'        => 'manual',
				)
			)
		);
	}

	/**
	 * The default gateway is one key, so a list posted for it is refused at the
	 * boundary. Nothing is stored under it, so the refusal yields an empty string,
	 * which additional_processing() then reads as no default at all.
	 */
	public function test_default_gateway_posted_as_a_list_is_refused() {
		$this->assertEmpty( edd_get_option( 'default_gateway' ), 'Fixture: nothing may be stored under the key, or the stored default is kept instead.' );

		$this->assertSame(
			array(
				'default_gateway' => '',
				'gateways'        => array(
					'manual' => '1',
				),
			),
			Main::sanitize(
				array(
					'default_gateway' => array( 'manual' ),
					'gateways'        => array(
						'manual' => '1',
					),
				)
			)
		);
	}

	public function test_default_gateway_not_enabled() {
		$this->assertSame(
			array(
				'default_gateway' => 'paypal',
				'gateways'        => array(
					'paypal' => '1',
					'manual' => '1',
				),
			),
			Main::sanitize(
				array(
					'default_gateway' => 'stripe',
					'gateways'        => array(
						'paypal' => '1',
						'manual' => '1',
					),
				)
			)
		);
	}

	public function test_default_gateway_is_enabled_is_first() {
		$this->assertSame(
			array(
				'default_gateway' => 'stripe',
				'gateways'        => array(
					'stripe' => '1',
					'paypal' => '1',
				),
			),
			Main::sanitize(
				array(
					'default_gateway' => 'stripe',
					'gateways'        => array(
						'stripe' => '1',
						'paypal' => '1',
					),
				)
			)
		);
	}

	public function test_default_gateway_is_enabled_is_not_first() {
		$this->assertSame(
			array(
				'default_gateway' => 'paypal',
				'gateways'        => array(
					'stripe' => '1',
					'paypal' => '1',
				),
			),
			Main::sanitize(
				array(
					'default_gateway' => 'paypal',
					'gateways'        => array(
						'stripe' => '1',
						'paypal' => '1',
					),
				)
			)
		);
	}
}
