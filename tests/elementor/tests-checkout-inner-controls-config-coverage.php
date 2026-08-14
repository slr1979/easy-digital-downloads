<?php
/**
 * Coverage tests for the re-homed checkout-inner billing-details controls config.
 *
 * The billing title toggle and section style live on the Personal Info widget (which
 * owns the billing address); the Payment Info widget keeps only its own two title
 * toggles. These assert the static control-config arrays, so they have no Elementor
 * dependency.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Widgets\Config\CheckoutInner\Controls;

/**
 * Coverage for the re-homed billing-details controls: the billing title toggle and
 * section style now live on the Personal Info widget (which owns the billing
 * address), and the Payment Info widget keeps only its own two title toggles.
 *
 * @covers \EDD\Elementor\Widgets\Config\CheckoutInner\Controls::get_personal_info_controls
 * @covers \EDD\Elementor\Widgets\Config\CheckoutInner\Controls::get_payment_info_controls
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class CheckoutInnerControlsConfigCoverage extends EDD_UnitTestCase {

	/**
	 * The Personal Info widget owns the billing-details title toggle and section style.
	 *
	 * @since 3.7.0
	 */
	public function test_personal_info_controls_include_billing_title_and_style() {
		$personal     = Controls::get_personal_info_controls();
		$control_keys = $this->control_keys( $personal );

		$this->assertContains(
			'show_billing_details_title',
			$control_keys,
			'The billing-details title toggle must live on the Personal Info widget, which now owns the billing address.'
		);
		$this->assertArrayHasKey(
			'billing_details_section_style',
			$personal,
			'The billing-details section style must live on the Personal Info widget.'
		);
	}

	/**
	 * Every fieldset the Personal Info widget renders has a section style.
	 *
	 * The login and register fieldsets render only for a guest whose store does not auto-register, so
	 * a checkout built against one registration setting never showed them.
	 *
	 * @since 3.7.0
	 */
	public function test_personal_info_covers_every_fieldset_it_renders() {
		$personal = Controls::get_personal_info_controls();

		foreach ( array( 'personal_information', 'billing_details', 'login_fields', 'register_fields' ) as $section ) {
			$this->assertArrayHasKey(
				$section . '_section_style',
				$personal,
				"The {$section} fieldset renders in this widget, so it needs a section style."
			);
		}
	}

	/**
	 * The Payment Info widget excludes the personal/billing titles and billing style,
	 * keeping only its own two title toggles (payment method + card info).
	 *
	 * @since 3.7.0
	 */
	public function test_payment_info_controls_exclude_billing_title_and_style() {
		$payment      = Controls::get_payment_info_controls();
		$control_keys = $this->control_keys( $payment );

		$this->assertNotContains(
			'show_billing_details_title',
			$control_keys,
			'The Payment Info widget must not own the billing-details title toggle.'
		);
		$this->assertNotContains(
			'show_personal_info_title',
			$control_keys,
			'The Payment Info widget must not own the personal-info title toggle.'
		);
		$this->assertArrayNotHasKey(
			'billing_details_section_style',
			$payment,
			'The Payment Info widget must not own the billing-details section style.'
		);

		// It keeps exactly its own two title toggles: payment method + card info.
		$title_controls = array_values(
			array_filter(
				$control_keys,
				static function ( $key ) {
					return in_array(
						$key,
						array( 'show_personal_info_title', 'show_billing_details_title', 'show_payment_method_title', 'show_cc_details_title' ),
						true
					);
				}
			)
		);
		sort( $title_controls );

		$this->assertSame(
			array( 'show_cc_details_title', 'show_payment_method_title' ),
			$title_controls,
			'The Payment Info widget keeps only its own two title toggles (payment method + card info).'
		);
	}

	/**
	 * Flatten a control-config array to the list of control ids across its sections.
	 *
	 * @since 3.7.0
	 *
	 * @param array $config The per-widget control config (section_id => section).
	 * @return array The control ids across every section.
	 */
	private function control_keys( array $config ): array {
		$keys = array();
		foreach ( $config as $section ) {
			if ( empty( $section['controls'] ) || ! is_array( $section['controls'] ) ) {
				continue;
			}
			foreach ( array_keys( $section['controls'] ) as $control_id ) {
				$keys[] = $control_id;
			}
		}

		return $keys;
	}
}
