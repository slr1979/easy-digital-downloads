<?php
/**
 * Elementor documents-manager double that counts document fetches.
 *
 * Fetching the document is the step that leads to the json_decode of the whole element tree, so the
 * counter stands in for "the tree was decoded" in the page-data caching tests.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Elementor;

require_once __DIR__ . '/fakes-checkout-form-layer.php';

/**
 * A documents manager that records how many times a document was fetched.
 *
 * @since 3.7.1
 */
class CountingElementorDocuments extends FakeElementorDocuments {

	/**
	 * How many times get() was called.
	 *
	 * @since 3.7.1
	 * @var int
	 */
	public $get_calls = 0;

	/**
	 * Fetch the document for a post id, counting the call.
	 *
	 * @since 3.7.1
	 *
	 * @param int $post_id The post id.
	 * @return FakeElementorDocument|null
	 */
	public function get( $post_id ) {
		++$this->get_calls;

		return parent::get( $post_id );
	}
}
