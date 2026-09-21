<?php
/**
 * Tests for the request-scoped memo and pre-decode gate in EDD\Elementor\Utils\Page.
 *
 * @package     EDD\Elementor\Utils
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Elementor;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Elementor\Support\RealElementorFixture;
use EDD\Elementor\Utils\Page;
use EDD\Elementor\Subscribers\CheckoutAssets;

require_once __DIR__ . '/support/fakes-counting-documents.php';

/**
 * Covers the memo and the gate that keep the element tree from being decoded
 * on every front-end pageview.
 *
 * @covers \EDD\Elementor\Utils\Page::get_page_data
 * @covers \EDD\Elementor\Utils\Page::get_widget_data
 * @covers \EDD\Elementor\Utils\Page::has_widget
 */
class PageDataCaching extends EDD_UnitTestCase {

	use RealElementorFixture;

	/**
	 * The documents manager installed for the current test.
	 *
	 * @var CountingElementorDocuments|null
	 */
	private $documents = null;

	/**
	 * Restore request and Elementor state between tests.
	 */
	public function tearDown(): void {
		unset( $_REQUEST['elementor-preview'] );
		$this->documents = null;

		if ( ! empty( $GLOBALS['edd_elementor_real_plugin'] ) ) {
			$this->edd_boot_real_elementor();
		} elseif ( class_exists( '\Elementor\Plugin', false ) ) {
			\Elementor\Plugin::$instance = null;
		}

		parent::tearDown();
	}

	/**
	 * A page is read from Elementor once, however many lookups the request makes.
	 *
	 * Without the memo each bare lookup re-fetches the document and re-decodes the
	 * tree, which is what made a single pageview pay for the decode repeatedly.
	 *
	 * @since 3.7.1
	 *
	 * @covers \EDD\Elementor\Utils\Page::get_page_data
	 */
	public function test_repeated_lookups_read_the_document_once() {
		$post_id = $this->stub_page( self::checkout_box_tree() );

		// Assert the fixture: the box really is reachable through this lookup.
		$this->assertTrue( Page::has_widget( 'edd-checkout-box' ), 'The seeded page must expose the box, or the call count below measures nothing.' );

		$first_read = $this->documents->get_calls;
		$this->assertSame( 1, $first_read, 'The first lookup must read the document exactly once.' );

		Page::has_widget( 'edd-checkout-box' );
		Page::has_widget( 'edd-checkout-personal-info' );
		Page::get_page_data();

		$this->assertSame(
			1,
			$this->documents->get_calls,
			'Three further lookups on the same page must be answered from the memo without re-reading the document.'
		);
	}

	/**
	 * Clearing the cache drops the memo, so the next lookup reads the document again.
	 *
	 * @since 3.7.1
	 *
	 * @covers \EDD\Elementor\Utils\Page::clear_cache
	 */
	public function test_clearing_the_cache_drops_the_memo() {
		$this->stub_page( self::checkout_box_tree() );

		// Assert the fixture: the memo has to be populated before clearing it can mean anything.
		$this->assertTrue( Page::has_widget( 'edd-checkout-box' ), 'The seeded page must expose the box.' );
		$this->assertSame( 1, $this->documents->get_calls, 'The first lookup must read the document once.' );

		Page::clear_cache();
		Page::has_widget( 'edd-checkout-box' );

		$this->assertSame(
			2,
			$this->documents->get_calls,
			'A lookup after clear_cache() must read the document again rather than answer from the memo.'
		);
	}

	/**
	 * A widget type absent from the raw element data is answered without a decode.
	 *
	 * This is the front-end case the issue reports: an Elementor page carrying no EDD
	 * content at all still paid for a full decode on every pageview.
	 *
	 * @since 3.7.1
	 *
	 * @covers \EDD\Elementor\Utils\Page::get_widget_data
	 */
	public function test_absent_widget_type_is_answered_without_reading_the_document() {
		$post_id = $this->stub_page( self::plain_tree() );

		$this->assertFalse( Page::has_widget( 'edd-checkout-box' ) );
		$this->assertSame(
			0,
			$this->documents->get_calls,
			'A page whose raw element data does not mention the type must never reach the document.'
		);
	}

	/**
	 * A widget type present in the raw element data still reaches the document.
	 *
	 * The companion to the test above: it proves the counter can move at all, so a
	 * zero there is the gate working rather than a fixture that never reads anything.
	 *
	 * @since 3.7.1
	 *
	 * @covers \EDD\Elementor\Utils\Page::get_widget_data
	 */
	public function test_present_widget_type_reads_the_document() {
		$post_id = $this->stub_page( self::checkout_box_tree() );

		$this->assertTrue( Page::has_widget( 'edd-checkout-box' ) );
		$this->assertSame(
			1,
			$this->documents->get_calls,
			'A page whose raw element data mentions the type must read the document to confirm it.'
		);
	}

	/**
	 * Rewriting a page's element data is not answered from the memo.
	 *
	 * The memo is keyed on the raw meta string rather than invalidated by the write, so a
	 * template import that rewrites the tree mid-request simply misses it.
	 *
	 * @since 3.7.1
	 *
	 * @covers \EDD\Elementor\Utils\Page::get_page_data
	 */
	public function test_rewriting_element_data_is_not_answered_from_the_memo() {
		$post_id = $this->stub_page( self::checkout_box_tree() );

		$this->assertTrue( Page::has_widget( 'edd-checkout-box' ), 'The seeded page must start with a box present.' );

		// Replace both the stored tree and the document the lookup would read.
		$plain = self::plain_tree();
		$this->documents->documents[ $post_id ] = new FakeElementorDocument( $plain );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $plain ) ) );

		$this->assertFalse(
			Page::has_widget( 'edd-checkout-box' ),
			'After the tree is rewritten the lookup must read the new data, not the memo from before the write.'
		);
	}

	/**
	 * A front-end pageview of an Elementor page with no EDD content decodes nothing.
	 *
	 * This is the reported path: get_id() resolves the page through its is_singular()
	 * fallback, so the styles subscriber ran on every pageview whether or not the page
	 * had any EDD content on it.
	 *
	 * @since 3.7.1
	 *
	 * @covers \EDD\Elementor\Subscribers\CheckoutAssets::enqueue_checkout_styles
	 */
	public function test_front_end_pageview_without_edd_content_does_not_read_the_document() {
		$post_id = $this->stub_page( self::plain_tree() );

		// Drop the preview param so the id resolves the way a real pageview does.
		unset( $_REQUEST['elementor-preview'] );
		$this->go_to( get_permalink( $post_id ) );

		// Assert the fixture: without the singular fallback engaging there is no work to avoid.
		$this->assertTrue( is_singular(), 'The pageview must resolve as singular for get_id() to reach this page.' );

		// The style registry is global and survives earlier tests, so clear it to assert on it.
		wp_dequeue_style( 'edd-checkout-style' );
		$this->assertFalse( wp_style_is( 'edd-checkout-style', 'enqueued' ), 'The stylesheet must start unenqueued for the assertion below to mean anything.' );

		( new CheckoutAssets() )->enqueue_checkout_styles();

		$this->assertSame(
			0,
			$this->documents->get_calls,
			'A front-end pageview of a page carrying no EDD content must not read the document at all.'
		);
		$this->assertFalse( wp_style_is( 'edd-checkout-style', 'enqueued' ), 'No checkout stylesheet belongs on a page with no checkout on it.' );
	}

	/**
	 * A front-end pageview of a page carrying a checkout box still enqueues its styles.
	 *
	 * The companion to the test above: it proves the skipped decode is the gate answering
	 * a page without a box, not the styles subscriber having stopped working.
	 *
	 * @since 3.7.1
	 *
	 * @covers \EDD\Elementor\Subscribers\CheckoutAssets::enqueue_checkout_styles
	 */
	public function test_front_end_pageview_with_checkout_box_enqueues_styles() {
		$post_id = $this->stub_page( self::checkout_box_tree() );

		unset( $_REQUEST['elementor-preview'] );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_singular() );

		wp_dequeue_style( 'edd-checkout-style' );

		( new CheckoutAssets() )->enqueue_checkout_styles();

		$this->assertTrue( wp_style_is( 'edd-checkout-style', 'enqueued' ), 'A page carrying a checkout box must still get the checkout stylesheet.' );
		$this->assertSame(
			1,
			$this->documents->get_calls,
			'The whole subscriber must read the document once, not once per widget lookup.'
		);
	}

	/**
	 * Install a page whose stored data and document both carry the given tree.
	 *
	 * @param array $elements Raw element-data arrays.
	 * @return int The post id the lookup resolves.
	 */
	private function stub_page( array $elements ): int {
		$this->edd_require_real_elementor();

		$post_id = self::factory()->post->create();

		$plugin            = ( new \ReflectionClass( \Elementor\Plugin::class ) )->newInstanceWithoutConstructor();
		$this->documents   = new CountingElementorDocuments();
		$plugin->documents = $this->documents;

		$this->documents->documents[ $post_id ] = new FakeElementorDocument( $elements );

		\Elementor\Plugin::$instance = $plugin;

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );

		// Page::get_id() resolves the current page from the elementor-preview request param.
		$_REQUEST['elementor-preview'] = (string) $post_id;

		return $post_id;
	}

	/**
	 * A tree carrying a checkout box and one section widget.
	 *
	 * @return array
	 */
	private static function checkout_box_tree(): array {
		return array(
			array(
				'id'       => 'box1',
				'elType'   => 'edd-checkout-box',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'sec1',
						'elType'     => 'widget',
						'widgetType' => 'edd-checkout-personal-info',
						'settings'   => array(),
						'elements'   => array(),
					),
				),
			),
		);
	}

	/**
	 * A tree with no EDD content on it.
	 *
	 * @return array
	 */
	private static function plain_tree(): array {
		return array(
			array(
				'id'       => 'con1',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'txt1',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array(),
						'elements'   => array(),
					),
				),
			),
		);
	}
}
