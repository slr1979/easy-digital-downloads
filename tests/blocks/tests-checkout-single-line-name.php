<?php
/**
 * Tests for the single-line name layout on the checkout personal-info fields.
 *
 * Covers two things: the description prints from two places depending on context, so the paired-name
 * gate must know which context it is in; and the layout is keyed to a wrapper class both the block
 * and the Elementor widget rely on, so the class must track the attribute.
 *
 * @package     EDD\Tests\Blocks\Checkout
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Blocks\Checkout;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Blocks\Checkout\Elements\PersonalInfo as Element;
use EDD\Forms\Checkout\PersonalInfo\FirstName;

/**
 * Single-line name coverage.
 *
 * @covers \EDD\Forms\Checkout\PersonalInfo\Field::render
 * @covers \EDD\Blocks\Checkout\Elements\PersonalInfo::render
 *
 * @group blocks
 */
class SingleLineName extends EDD_UnitTestCase {

	/**
	 * The wrapper modifier the block stylesheet keys the paired layout to.
	 *
	 * @var string
	 */
	private $modifier = Element::CLASS_NAME_SINGLE_LINE;

	/**
	 * Counts the descriptions a first-name field renders for a given context.
	 *
	 * @param array $data Field data, e.g. `is_block` and `name_single_line`.
	 * @return int
	 */
	private function count_descriptions( array $data ): int {
		$field = new FirstName( wp_parse_args( $data, array( 'first_name' => 'Chris' ) ) );

		ob_start();
		$field->render();
		$html = ob_get_clean();

		return preg_match_all( '/class="edd-description"/', $html );
	}

	/**
	 * Renders the personal-info element and returns its markup.
	 *
	 * @param array $block_attributes The block attributes.
	 * @return string
	 */
	private function render_element( array $block_attributes ): string {
		ob_start();
		Element::render( $block_attributes );

		return (string) ob_get_clean();
	}

	/**
	 * The block layout prints the description once, below the input.
	 */
	public function test_block_prints_one_description_by_default() {
		$this->assertSame( 1, $this->count_descriptions( array( 'is_block' => true ) ) );
	}

	/**
	 * Pairing the names suppresses the description, which is the point of the flag.
	 */
	public function test_block_suppresses_the_description_when_names_are_paired() {
		$this->assertSame(
			0,
			$this->count_descriptions(
				array(
					'is_block'         => true,
					'name_single_line' => true,
				)
			)
		);
	}

	/**
	 * The classic layout prints the description once, above the input.
	 *
	 * The paired-name gate must not double it: the classic branch already emits it before the input.
	 */
	public function test_classic_prints_one_description() {
		$this->assertSame( 1, $this->count_descriptions( array( 'is_block' => false ) ) );
	}

	/**
	 * The classic layout has no paired-name mode, so the flag must not change it.
	 */
	public function test_classic_ignores_the_paired_name_flag() {
		$this->assertSame(
			1,
			$this->count_descriptions(
				array(
					'is_block'         => false,
					'name_single_line' => true,
				)
			)
		);
	}

	/**
	 * The wrapper carries the modifier when the attribute is set, so the block
	 * stylesheet can pair the fields for either editor.
	 */
	public function test_wrapper_carries_the_modifier_when_paired() {
		$this->assertStringContainsString(
			$this->modifier,
			$this->render_element( array( 'name_single_line' => true ) )
		);
	}

	/**
	 * Without the attribute the wrapper keeps only its base class.
	 */
	public function test_wrapper_omits_the_modifier_by_default() {
		$html = $this->render_element( array() );

		$this->assertStringContainsString( 'edd-checkout-block__personal-info', $html );
		$this->assertStringNotContainsString( $this->modifier, $html );
	}

	/**
	 * The parent checkout block does not declare the attribute, so reading it must not warn.
	 */
	public function test_missing_attribute_does_not_warn() {
		$html = $this->render_element( array( 'show_register_form' => 'none' ) );

		$this->assertStringNotContainsString( 'Undefined array key', $html );
		$this->assertStringContainsString( 'edd-checkout-block__personal-info', $html );
	}
}
