<?php
/**
 * Shared Elementor test doubles for the checkout form-layer test suite.
 *
 * FakeElementorDocument / FakeElementorDocuments stand in for Elementor's saved
 * document and documents-manager, exposing only the members
 * EDD\Elementor\Utils\Page::get_page_data() reads. Shared by the form-layer and
 * form-layer-runtime test classes, which build a Plugin double around them.
 *
 * @package     EDD\Tests\Elementor
 * @copyright   Copyright (c) 2025, Sandhills Development, LLC
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor;

/**
 * A fake Elementor document carrying saved element data.
 *
 * Provides only the members EDD\Elementor\Utils\Page::get_page_data() touches:
 * is_built_with_elementor() and get_elements_data().
 */
class FakeElementorDocument {

	/**
	 * The saved element data this document returns.
	 *
	 * @var array
	 */
	private $elements;

	/**
	 * Build a fake document around a raw element-data array.
	 *
	 * @param array $elements Raw element-data arrays (elType/widgetType/elements).
	 */
	public function __construct( array $elements ) {
		$this->elements = $elements;
	}

	/**
	 * Report the document as built with Elementor.
	 *
	 * @return bool
	 */
	public function is_built_with_elementor() {
		return true;
	}

	/**
	 * Return the saved element data.
	 *
	 * @return array
	 */
	public function get_elements_data() {
		return $this->elements;
	}
}

/**
 * A fake Elementor documents manager keyed by post id.
 */
class FakeElementorDocuments {

	/**
	 * Documents keyed by post id.
	 *
	 * @var array
	 */
	public $documents = array();

	/**
	 * Fetch the document for a post id.
	 *
	 * @param int $post_id The post id.
	 * @return FakeElementorDocument|null
	 */
	public function get( $post_id ) {
		return $this->documents[ $post_id ] ?? null;
	}
}
