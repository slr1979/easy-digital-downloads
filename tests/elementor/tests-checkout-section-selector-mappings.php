<?php
/**
 * Tests for the checkout section selector mappings.
 *
 * The personal-info widget's {{WRAPPER}} sits INSIDE the purchase form, so a section selector written
 * against `form#edd_purchase_form ` must have that ancestor stripped or it compiles to
 * `{{WRAPPER}} form#edd_purchase_form …` and matches nothing — no styling, no error, no failing test.
 * These assert the mapping covers every section, so the two lists cannot drift apart.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Elementor\Widgets\Config\Checkout\Styles\Sections;

/**
 * Coverage for the purchase-form ancestor mappings.
 *
 * @covers \EDD\Elementor\Widgets\Config\Checkout\Styles\Sections::get_form_ancestor_mappings
 *
 * @group elementor
 *
 * @since 3.7.0
 */
class CheckoutSectionSelectorMappings extends EDD_UnitTestCase {

	/**
	 * Every section selector carrying the form ancestor has a mapping that strips it.
	 *
	 * @since 3.7.0
	 */
	public function test_every_form_scoped_section_is_mapped() {
		$mappings = Sections::get_form_ancestor_mappings();

		$this->assertNotEmpty( $mappings, 'The section selectors are form-scoped, so mappings are required.' );

		foreach ( Sections::get_controls() as $section_style => $config ) {
			foreach ( $this->selectors_in( $config ) as $selector ) {
				if ( false === strpos( $selector, 'form#edd_purchase_form ' ) ) {
					continue;
				}
				$mapped = str_replace( array_keys( $mappings ), array_values( $mappings ), $selector );
				$this->assertStringNotContainsString(
					'form#edd_purchase_form',
					$mapped,
					"{$section_style} still carries the purchase-form ancestor after mapping, so its controls emit CSS that cannot match."
				);
			}
		}
	}

	/**
	 * A mapping strips the ancestor rather than rewriting the target.
	 *
	 * @since 3.7.0
	 */
	public function test_mapping_only_removes_the_ancestor() {
		foreach ( Sections::get_form_ancestor_mappings() as $full => $relative ) {
			$this->assertSame(
				'form#edd_purchase_form ' . $relative,
				$full,
				'The mapping should remove the ancestor and leave the target untouched.'
			);
		}
	}

	/**
	 * The personal-info section targets the wrapper, not the guest fieldset.
	 *
	 * EDD renders the guest, log in and register forms into one slot and swaps them by AJAX, so the
	 * wrapper is the only element present whichever form is showing. Keyed to the guest fieldset,
	 * the section's styling reached the guest form alone.
	 *
	 * @since 3.7.0
	 */
	public function test_personal_info_section_targets_the_swappable_wrapper() {
		$mappings = Sections::get_form_ancestor_mappings();

		$this->assertContains(
			'.edd-checkout-block__personal-info',
			$mappings,
			'The personal-info section must target the wrapper that survives the AJAX form swap.'
		);
		$this->assertNotContains(
			'#edd_checkout_user_info',
			$mappings,
			'Keyed to the guest fieldset, the section styling would not reach the log in or register forms.'
		);
	}

	/**
	 * Collect every selector in a control config, however deeply nested.
	 *
	 * @since 3.7.0
	 * @param array $config The control config.
	 * @return array
	 */
	private function selectors_in( array $config ): array {
		$found = array();
		array_walk_recursive(
			$config,
			function ( $value, $key ) use ( &$found ) {
				if ( is_string( $key ) && false !== strpos( $key, 'edd_purchase_form' ) ) {
					$found[] = $key;
				}
			}
		);

		return $found;
	}

	/**
	 * The personal-info title selector keeps an id, so it is not outranked.
	 *
	 * The section's box is styled on the swappable wrapper, but a wrapper class alone loses to the
	 * block stylesheet's `#edd_purchase_form .edd-blocks-form legend { margin: 0 }`, which silently
	 * dropped the section title back to the browser default on every template.
	 *
	 * @since 3.7.0
	 */
	public function test_personal_info_title_selector_outranks_the_legend_reset() {
		$controls = Sections::get_controls();
		$typography = $controls['personal_information_section_style']['controls']['personal_information_title_typography'];
		// A typography group carries a single `selector` string, not a `selectors` map.
		$selectors = (string) ( $typography['selector'] ?? '' );

		$this->assertStringContainsString( 'legend', $selectors, 'The title control must target a legend.' );
		foreach ( explode( ',', $selectors ) as $part ) {
			if ( '' === trim( $part ) ) {
				continue;
			}
			$this->assertMatchesRegularExpression(
				'/#edd_(checkout_user_info|register_fields|login_fields)/',
				$part,
				'Each title selector must name a fieldset id, or the block stylesheet outranks it.'
			);
		}
	}
}
