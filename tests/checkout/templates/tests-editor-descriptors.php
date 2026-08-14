<?php
/**
 * Editor descriptor resolution tests.
 *
 * Verifies that EditorRegistry::descriptors() single-sources each editor's
 * label, availability, machine-readable reason, and translated unavailability
 * text. These run in the default suite where ELEMENTOR_VERSION is undefined,
 * so the Elementor 'missing' branch is reachable.
 *
 * @package     EDD\Tests\Checkout\Templates
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Checkout\Templates;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Checkout\Templates\Config\Constants;
use EDD\Checkout\Templates\Config\EditorRegistry;

/**
 * Descriptor resolution tests.
 *
 * @covers \EDD\Checkout\Templates\Config\EditorRegistry::descriptors
 *
 * @group checkout-templates
 *
 * @since 3.7.0
 */
class EditorDescriptorResolutionTest extends EDD_UnitTestCase {

	/**
	 * Every descriptor exposes the four fields, and reason/text track availability.
	 *
	 * An available editor has an empty reason and empty text; an unavailable one
	 * has both populated. This is the invariant the admin cards rely on.
	 *
	 * @since 3.7.0
	 * @return void
	 */
	public function test_descriptor_reason_tracks_availability() {
		foreach ( EditorRegistry::descriptors() as $descriptor ) {
			$this->assertArrayHasKey( 'available', $descriptor );
			$this->assertArrayHasKey( 'label', $descriptor );
			$this->assertArrayHasKey( 'reason', $descriptor );
			$this->assertArrayHasKey( 'unavailable_text', $descriptor );

			if ( $descriptor['available'] ) {
				$this->assertSame( '', $descriptor['reason'] );
				$this->assertSame( '', $descriptor['unavailable_text'] );
			} else {
				$this->assertNotSame( '', $descriptor['reason'] );
				$this->assertNotSame( '', $descriptor['unavailable_text'] );
			}
		}
	}

	/**
	 * The block editor resolves to its label and the coming-soon reason.
	 *
	 * Block import has no importer yet, so it is always unavailable with the
	 * 'missing' reason and the coming-soon copy.
	 *
	 * @since 3.7.0
	 * @return void
	 */
	public function test_block_editor_descriptor() {
		$descriptor = EditorRegistry::descriptors()[ Constants::EDITOR_BLOCKS ];

		$this->assertFalse( $descriptor['available'] );
		$this->assertSame( 'Block Editor', $descriptor['label'] );
		$this->assertSame( 'missing', $descriptor['reason'] );
		$this->assertSame( 'Block editor templates are currently unavailable.', $descriptor['unavailable_text'] );
	}

	/**
	 * With Elementor absent the descriptor cites the 'missing' reason and copy.
	 *
	 * @since 3.7.0
	 * @return void
	 */
	public function test_elementor_missing_descriptor() {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'ELEMENTOR_VERSION already defined — the missing branch is unreachable in this process.' );
		}

		$descriptor = EditorRegistry::descriptors()[ Constants::EDITOR_ELEMENTOR ];

		$this->assertFalse( $descriptor['available'] );
		$this->assertSame( 'Elementor', $descriptor['label'] );
		$this->assertSame( 'missing', $descriptor['reason'] );
		$this->assertSame(
			sprintf( 'Requires Elementor %s or higher.', EditorRegistry::MIN_ELEMENTOR_VERSION ),
			$descriptor['unavailable_text']
		);
	}
}
