<?php

namespace EDD\Tests\Settings\Sanitize\Tabs\Taxes;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Admin\Settings\Sanitize\Tabs\Taxes\Main;

class VatSection extends EDD_UnitTestCase {

	/**
	 * Teardown after each test to ensure the tax rates are reset.
	 */
	public function tearDown(): void {
		$_POST = array();
		edd_update_option( 'enable_taxes', false );
		edd_update_option( 'vat_enable', false );

		// Saving the VAT settings schedules the initial rate fetch and marks the
		// import complete; clear both so the next test starts from a clean state.
		delete_option( 'edd_completed_upgrades' );
		\EDD\Cron\Events\SingleEvent::remove( 'edd_get_vat_rates' );
	}

	public function test_country_rate_fr_does_not_exist() {
		$this->assertEmpty(
			edd_get_tax_rate_by_location(
				array(
					'country' => 'FR',
				)
			)
		);
	}

	public function test_enable_taxes_empty_disables_vat() {
		$_POST['vat_enable'] = true;

		$this->assertSame(
			array(
				'vat_enable' => false,
			),
			Main::sanitize( $_POST )
		);
	}

	public function test_enable_taxes_does_not_enable_vat_if_disabled() {
		$_POST['enable_taxes'] = true;
		$_POST['vat_enable']   = false;

		$this->assertSame(
			array(
				'enable_taxes' => true,
				'vat_enable'   => false,
			),
			Main::sanitize( $_POST )
		);
	}

	public function test_enable_taxes_vat_enable_both_true() {
		$_POST['enable_taxes'] = true;
		$_POST['vat_enable']   = true;

		$this->assertSame( $_POST, Main::sanitize( $_POST ) );
	}

	public function test_enabling_vat_schedules_rate_fetch() {
		$_POST['enable_taxes'] = true;
		$_POST['vat_enable']   = true;

		Main::sanitize( $_POST );

		// Enabling VAT schedules a one-time event that fetches the current rates.
		$this->assertNotFalse(
			\EDD\Cron\Events\SingleEvent::next_scheduled( 'edd_get_vat_rates' )
		);
	}
}
