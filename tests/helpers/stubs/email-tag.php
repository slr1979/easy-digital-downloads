<?php
/**
 * Email tag stubs for testing.
 *
 * Provides a minimal concrete Tag so the registry's class-based extensibility
 * can be exercised without depending on a core tag definition.
 */

namespace EDD\Tests\Emails\Tags\Stubs;

use EDD\Emails\Tags\Definitions\Tag;

class StubTag extends Tag {

	protected $tag = 'custom_test';

	protected $contexts = array();

	public function get_label(): string {
		return 'Custom Test';
	}

	public function get_description(): string {
		return 'A stub tag for testing.';
	}

	public function render( $object_id, $email_object = null, $context = '' ): string {
		return 'stub_output';
	}
}
