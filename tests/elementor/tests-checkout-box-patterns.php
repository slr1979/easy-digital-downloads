<?php
/**
 * Drift-guard for the Elementor checkout box's layout patterns.
 *
 * The editor-JS layout picker (assets/src/js/elementor/checkout-box.js) seeds one
 * of five block-parity patterns into the checkout box. Those patterns MUST match
 * the Gutenberg checkout block's server pattern set (src/Blocks/Checkout/Patterns
 * ::get_layout_patterns()) — same slugs, same section set + order, same per-column
 * widths, same cart-top nesting. This test parses the block's own
 * serialized pattern markup (via the public accessor, route (a)) and asserts the
 * canonical per-slug structure the JS PATTERNS table implements, so a change to the
 * block patterns that the JS picker does not mirror is caught here. The runtime
 * JS<->block DOM parity is additionally covered by the picker e2e.
 *
 * One section is exempt from the parity comparison: the discount form. The picker
 * seeds it immediately after the cart in every pattern, but the block has no discount
 * pattern node (it renders the discount via the cart's inline `show_discount_form`
 * attribute), so the discount slug is deep-stripped from both operands before the
 * structural assertion.
 *
 * Slug forms: the block side keys are namespaced (`edd-checkout/single-column`);
 * the Elementor picker uses BARE slugs (`single-column`). The comparison strips the
 * `edd-checkout/` prefix before matching.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Blocks\Checkout\Patterns;

/**
 * @covers \EDD\Blocks\Checkout\Patterns::get_layout_patterns
 *
 * @group elementor
 */
class CheckoutBoxPatterns extends EDD_UnitTestCase {

	/**
	 * The namespaced-slug prefix the block side uses (stripped for the bare picker slugs).
	 *
	 * @var string
	 */
	const SLUG_PREFIX = 'edd-checkout/';

	/**
	 * The canonical per-slug structure the JS PATTERNS table (checkout-box.js) seeds.
	 *
	 * Kept in sync with the JS PATTERNS constant: `topLevel` are sections created
	 * directly in the box, `columns` are the [width, sections] of each seeded column.
	 * A null width means no explicit flex-basis (the 50/50 / cart-top columns).
	 *
	 * @var array
	 */
	private $canonical = array(
		'single-column'       => array(
			'topLevel' => array( 'edd-checkout-cart', 'edd-checkout-discount-form', 'edd-checkout-personal-info', 'edd-checkout-payment-info' ),
			'columns'  => array(),
		),
		'two-column-50-50'    => array(
			'topLevel' => array(),
			'columns'  => array(
				array(
					'width'    => null,
					'sections' => array( 'edd-checkout-personal-info', 'edd-checkout-payment-info' ),
				),
				array(
					'width'    => null,
					'sections' => array( 'edd-checkout-cart', 'edd-checkout-discount-form' ),
				),
			),
		),
		'two-column-70-30'    => array(
			'topLevel' => array(),
			'columns'  => array(
				array(
					'width'    => '70%',
					'sections' => array( 'edd-checkout-personal-info', 'edd-checkout-payment-info' ),
				),
				array(
					'width'    => '30%',
					'sections' => array( 'edd-checkout-cart', 'edd-checkout-discount-form' ),
				),
			),
		),
		'two-column-80-20'    => array(
			'topLevel' => array(),
			'columns'  => array(
				array(
					'width'    => '80%',
					'sections' => array( 'edd-checkout-personal-info', 'edd-checkout-payment-info' ),
				),
				array(
					'width'    => '20%',
					'sections' => array( 'edd-checkout-cart', 'edd-checkout-discount-form' ),
				),
			),
		),
		'cart-top-two-column' => array(
			'topLevel' => array( 'edd-checkout-cart', 'edd-checkout-discount-form' ),
			'columns'  => array(
				array(
					'width'    => null,
					'sections' => array( 'edd-checkout-personal-info' ),
				),
				array(
					'width'    => null,
					'sections' => array( 'edd-checkout-payment-info' ),
				),
			),
		),
	);

	/**
	 * The public accessor exposes exactly the five namespaced pattern slugs.
	 *
	 * @since 3.7.0
	 */
	public function test_accessor_exposes_the_five_namespaced_slugs() {
		$patterns = ( new Patterns() )->get_layout_patterns();

		$this->assertSame(
			array(
				'edd-checkout/single-column',
				'edd-checkout/two-column-50-50',
				'edd-checkout/two-column-70-30',
				'edd-checkout/two-column-80-20',
				'edd-checkout/cart-top-two-column',
			),
			array_keys( $patterns ),
			'The public accessor must expose the five namespaced pattern slugs.'
		);

		// Slug-normalization: stripping the prefix yields the bare picker slugs.
		foreach ( $patterns as $slug => $pattern ) {
			$bare = substr( $slug, strlen( self::SLUG_PREFIX ) );
			$this->assertArrayHasKey(
				$bare,
				$this->canonical,
				"Bare slug {$bare} (from {$slug}) must map to a known picker pattern."
			);
		}
	}

	/**
	 * Each block pattern's parsed structure matches the JS picker's seed table.
	 *
	 * Guards against the block patterns drifting from the Elementor picker without the
	 * picker being updated (section set/order, column widths, cart-top nesting).
	 *
	 * Discount-form exemption: the Elementor picker seeds a discount form immediately
	 * after the cart in every pattern, but the Gutenberg block has no discount pattern
	 * node — the block renders the discount via the cart's inline `show_discount_form`
	 * attribute, not a standalone section. So the discount slug is deep-stripped from
	 * both operands (topLevel AND every column's sections) before the comparison: the
	 * block-parsed side never carries it (the strip is a no-op there) and the canonical
	 * picker side carries it, so stripping keeps the two sides comparable on the section
	 * set/order/width/nesting the block genuinely mirrors.
	 *
	 * @since 3.7.0
	 */
	public function test_block_patterns_match_the_picker_seed_table() {
		$patterns = ( new Patterns() )->get_layout_patterns();

		foreach ( $patterns as $slug => $pattern ) {
			$bare     = substr( $slug, strlen( self::SLUG_PREFIX ) );
			$expected = $this->strip_discount( $this->canonical[ $bare ] );
			$actual   = $this->strip_discount( $this->structure_from_markup( $pattern['content'] ) );

			$this->assertSame(
				$expected,
				$actual,
				"Block pattern {$slug} must match the Elementor picker's seed structure for {$bare}."
			);
		}
	}

	/**
	 * Deep-strip the discount-form slug from a canonical structure.
	 *
	 * Removes 'edd-checkout-discount-form' from `topLevel` and from every column's
	 * `sections`, reindexing so the strict assertSame comparison is unaffected by
	 * gaps. The discount form is the one picker-seeded section the block has no
	 * pattern node for, so both operands are stripped symmetrically before comparing.
	 *
	 * @param array $structure The { topLevel, columns } structure.
	 * @return array The structure without the discount-form slug.
	 */
	private function strip_discount( array $structure ): array {
		$discount = 'edd-checkout-discount-form';

		$structure['topLevel'] = array_values(
			array_filter(
				$structure['topLevel'],
				static function ( $slug ) use ( $discount ) {
					return $slug !== $discount;
				}
			)
		);

		foreach ( $structure['columns'] as $index => $column ) {
			$structure['columns'][ $index ]['sections'] = array_values(
				array_filter(
					$column['sections'],
					static function ( $slug ) use ( $discount ) {
						return $slug !== $discount;
					}
				)
			);
		}

		return $structure;
	}

	/**
	 * Parse a pattern's serialized block markup into the canonical structure.
	 *
	 * Top-level `edd/checkout-*` blocks become `topLevel` sections; a `core/columns`
	 * block becomes `columns`, each `core/column`'s `width` attribute and its
	 * `edd/checkout-*` sections captured in order. Block namespaces are mapped to the
	 * bare Elementor widget slugs (`edd/checkout-cart` -> `edd-checkout-cart`).
	 *
	 * @param string $content The serialized block markup.
	 * @return array The parsed { topLevel, columns } structure.
	 */
	private function structure_from_markup( string $content ): array {
		$structure = array(
			'topLevel' => array(),
			'columns'  => array(),
		);

		foreach ( parse_blocks( $content ) as $block ) {
			$name = $block['blockName'] ?? '';
			if ( '' === $name ) {
				continue;
			}

			if ( 'core/columns' === $name ) {
				foreach ( $block['innerBlocks'] as $column ) {
					$structure['columns'][] = array(
						'width'    => $column['attrs']['width'] ?? null,
						'sections' => $this->sections_in( $column['innerBlocks'] ),
					);
				}
				continue;
			}

			if ( $this->is_section_block( $name ) ) {
				$structure['topLevel'][] = $this->to_widget_slug( $name );
			}
		}

		return $structure;
	}

	/**
	 * Collect the section widget slugs from a list of inner blocks, in order.
	 *
	 * @param array $blocks The inner blocks.
	 * @return array The bare section widget slugs.
	 */
	private function sections_in( array $blocks ): array {
		$sections = array();

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			if ( $this->is_section_block( $name ) ) {
				$sections[] = $this->to_widget_slug( $name );
			}
		}

		return $sections;
	}

	/**
	 * Whether a block name is an EDD checkout section block.
	 *
	 * @param string $name The block name.
	 * @return bool
	 */
	private function is_section_block( string $name ): bool {
		return 0 === strpos( $name, 'edd/checkout-' );
	}

	/**
	 * Map a section block name to its bare Elementor widget slug.
	 *
	 * @param string $name The block name (e.g. `edd/checkout-cart`).
	 * @return string The widget slug (e.g. `edd-checkout-cart`).
	 */
	private function to_widget_slug( string $name ): string {
		return str_replace( 'edd/', 'edd-', $name );
	}
}
