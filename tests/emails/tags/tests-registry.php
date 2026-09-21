<?php
namespace EDD\Tests\Emails\Tags;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Emails\Tags\Definitions\Tag;
use EDD\Emails\Tags\Registry as TagsRegistry;

/**
 * Tests for the Tags Registry class.
 *
 * @group edd_emails
 * @group edd_email_tags
 */
class Registry extends EDD_UnitTestCase {

	/**
	 * The core tag identifiers the registry is expected to provide.
	 *
	 * @var array
	 */
	private static $core_tags = array(
		'download_list',
		'file_urls',
		'name',
		'fullname',
		'username',
		'user_email',
		'billing_address',
		'date',
		'subtotal',
		'tax',
		'fees_total',
		'fees_list',
		'price',
		'payment_id',
		'receipt_id',
		'payment_method',
		'sitename',
		'receipt',
		'receipt_link',
		'discount_codes',
		'ip_address',
		'login_link',
		'refund_link',
		'order_details_link',
		'transaction_id',
		'password_link',
		'refund_amount',
		'refund_id',
		'phone',
		'company',
	);

	public function setUp(): void {
		parent::setUp();
		require_once EDD_PLUGIN_DIR . 'tests/helpers/stubs/email-tag.php';

		// Spend edd_add_email_tags before any test filter is added, so the singleton is never built with one attached.
		edd_load_email_tags();
		TagsRegistry::reset();
	}

	public function tearDown(): void {
		remove_all_filters( 'edd_registered_email_tags' );
		remove_all_filters( 'edd_email_tags' );
		TagsRegistry::reset();
		parent::tearDown();
	}

	// ---- get_registered_tags() ----

	/**
	 * Every core tag identifier is present in the registry.
	 */
	public function test_all_core_tag_identifiers_present() {
		$tag_keys = array_keys( TagsRegistry::get_registered_tags() );

		foreach ( self::$core_tags as $tag_name ) {
			$this->assertContains( $tag_name, $tag_keys, "Core tag '{$tag_name}' is missing from registry." );
		}
	}

	/**
	 * All registered tags are instances of the abstract Tag class.
	 */
	public function test_registered_tags_are_tag_instances() {
		foreach ( TagsRegistry::get_registered_tags() as $name => $tag ) {
			$this->assertInstanceOf( Tag::class, $tag, "Tag '{$name}' is not a Tag instance." );
		}
	}

	/**
	 * Registered tags are keyed by their tag identifier.
	 */
	public function test_registered_tags_keyed_by_tag_name() {
		foreach ( TagsRegistry::get_registered_tags() as $key => $tag ) {
			$this->assertSame( $key, $tag->get_tag(), "Key '{$key}' doesn't match tag name '{$tag->get_tag()}'." );
		}
	}

	/**
	 * get_registered_tags() caches the result on subsequent calls.
	 */
	public function test_get_registered_tags_caches_result() {
		$first  = TagsRegistry::get_registered_tags();
		$second = TagsRegistry::get_registered_tags();

		$this->assertSame( $first, $second );
	}

	/**
	 * reset() clears the cache so tags registered later are picked up.
	 */
	public function test_reset_clears_cached_tags() {
		$before = TagsRegistry::get_registered_tags();

		add_filter( 'edd_registered_email_tags', array( $this, 'add_stub_tag' ) );
		$this->assertArrayNotHasKey( 'custom_test', TagsRegistry::get_registered_tags() );

		TagsRegistry::reset();
		$this->assertArrayHasKey( 'custom_test', TagsRegistry::get_registered_tags() );
		$this->assertCount( count( $before ) + 1, TagsRegistry::get_registered_tags() );
	}

	// ---- Filter extensibility ----

	/**
	 * Extensions can add class-based tags via edd_registered_email_tags filter.
	 */
	public function test_filter_adds_custom_class_based_tag() {
		add_filter( 'edd_registered_email_tags', array( $this, 'add_stub_tag' ) );

		$tags = TagsRegistry::get_registered_tags();

		$this->assertArrayHasKey( 'custom_test', $tags );
		$this->assertInstanceOf( Tag::class, $tags['custom_test'] );
	}

	/**
	 * The filter rejects classes that don't extend Tag.
	 */
	public function test_filter_rejects_invalid_class() {
		add_filter(
			'edd_registered_email_tags',
			function ( $tags ) {
				$tags['invalid_tag'] = \stdClass::class;

				return $tags;
			}
		);

		$this->assertArrayNotHasKey( 'invalid_tag', TagsRegistry::get_registered_tags() );
	}

	/**
	 * The filter rejects non-existent classes.
	 */
	public function test_filter_rejects_nonexistent_class() {
		add_filter(
			'edd_registered_email_tags',
			function ( $tags ) {
				$tags['ghost'] = 'EDD\\Emails\\Tags\\Definitions\\NonExistentTag';

				return $tags;
			}
		);

		$this->assertArrayNotHasKey( 'ghost', TagsRegistry::get_registered_tags() );
	}

	// ---- register() populates Handler ----

	/**
	 * Registry::register() stores class-based tags in Handler's tag_objects.
	 */
	public function test_register_populates_handler_tag_objects() {
		$handler = $this->register_into_new_handler();

		$this->assertInstanceOf( Tag::class, $handler->get_tag_object( 'sitename' ) );
		$this->assertInstanceOf( Tag::class, $handler->get_tag_object( 'download_list' ) );
		$this->assertInstanceOf( Tag::class, $handler->get_tag_object( 'phone' ) );
	}

	/**
	 * Legacy filter tags are stored via add() not register().
	 */
	public function test_register_puts_legacy_filter_tags_in_legacy_storage() {
		add_filter(
			'edd_email_tags',
			function ( $tags ) {
				$tags[] = array(
					'tag'         => 'legacy_filter_tag',
					'description' => 'Added via legacy filter',
					'function'    => '__return_empty_string',
				);

				return $tags;
			}
		);

		$handler = $this->register_into_new_handler();

		$this->assertNull( $handler->get_tag_object( 'legacy_filter_tag' ) );
		$this->assertTrue( $handler->email_tag_exists( 'legacy_filter_tag' ) );
	}

	/**
	 * A core tag is registered only once, even when the legacy filter re-adds it by name.
	 */
	public function test_register_skips_duplicate_legacy_filter_tags() {
		add_filter(
			'edd_email_tags',
			function ( $tags ) {
				$tags[] = array(
					'tag'         => 'sitename',
					'description' => 'Duplicate sitename from legacy filter',
					'function'    => '__return_empty_string',
				);

				return $tags;
			}
		);

		$handler = $this->register_into_new_handler();

		$this->assertInstanceOf( \EDD\Emails\Tags\Definitions\SiteName::class, $handler->get_tag_object( 'sitename' ) );
		$this->assertCount( 1, $this->get_tags_named( $handler, 'sitename' ) );
	}

	// ---- Legacy `edd_email_tags` compatibility ----

	/**
	 * Core tags are passed into the legacy filter so extensions can inspect them.
	 */
	public function test_legacy_filter_receives_core_tags() {
		$received = array();
		add_filter(
			'edd_email_tags',
			function ( $tags ) use ( &$received ) {
				$received = $tags;

				return $tags;
			}
		);

		$this->register_into_new_handler();

		$this->assertSame( self::$core_tags, wp_list_pluck( $received, 'tag' ) );
		$this->assertIsCallable( $received[0]['function'] );
	}

	/**
	 * An extension swapping a core tag's callback keeps that callback at render time.
	 */
	public function test_legacy_filter_can_replace_a_core_tag_callback() {
		$this->setExpectedDeprecated( 'edd_email_tags' );

		add_filter(
			'edd_email_tags',
			function ( $tags ) {
				foreach ( $tags as $key => $tag ) {
					if ( 'download_list' === $tag['tag'] ) {
						$tags[ $key ]['function'] = '__return_true';
					}
				}

				return $tags;
			}
		);

		$handler = $this->register_into_new_handler();

		$this->assertNull( $handler->get_tag_object( 'download_list' ) );

		$replaced = $this->get_tags_named( $handler, 'download_list' );
		$this->assertCount( 1, $replaced );
		$this->assertSame( '__return_true', reset( $replaced )['func'] );
	}

	/**
	 * An extension relabeling or re-scoping a core tag keeps that metadata, even though
	 * it left the callback alone.
	 */
	public function test_legacy_filter_can_change_a_core_tag_label_and_contexts() {
		$this->setExpectedDeprecated( 'edd_email_tags' );

		add_filter(
			'edd_email_tags',
			function ( $tags ) {
				foreach ( $tags as $key => $tag ) {
					if ( 'sitename' === $tag['tag'] ) {
						$tags[ $key ]['label']      = 'Store Name';
						$tags[ $key ]['contexts']   = array( 'refund' );
						$tags[ $key ]['recipients'] = array( 'admin' );
					}
				}

				return $tags;
			}
		);

		$handler = $this->register_into_new_handler();

		$this->assertNull( $handler->get_tag_object( 'sitename' ) );

		$matches   = $this->get_tags_named( $handler, 'sitename' );
		$relabeled = reset( $matches );
		$this->assertSame( 'Store Name', $relabeled['label'] );
		$this->assertSame( array( 'refund' ), $relabeled['contexts'] );
		$this->assertSame( array( 'admin' ), $relabeled['recipients'] );
	}

	/**
	 * An extension removing a core tag from the filter removes it from the handler.
	 */
	public function test_legacy_filter_can_remove_a_core_tag() {
		add_filter(
			'edd_email_tags',
			function ( $tags ) {
				return array_values(
					array_filter(
						$tags,
						function ( $tag ) {
							return 'phone' !== $tag['tag'];
						}
					)
				);
			}
		);

		$handler = $this->register_into_new_handler();

		$this->assertNull( $handler->get_tag_object( 'phone' ) );
		$this->assertEmpty( $this->get_tags_named( $handler, 'phone' ) );
	}

	/**
	 * Adds the stub tag to the registered classes.
	 *
	 * @param array $tags The registered tag classes.
	 * @return array
	 */
	public function add_stub_tag( $tags ) {
		$tags['custom_test'] = Stubs\StubTag::class;

		return $tags;
	}

	/**
	 * Runs Registry::register() against a fresh handler, so the singleton is left alone.
	 *
	 * @return \EDD\Emails\Tags\Handler
	 */
	private function register_into_new_handler() {
		$handler          = new \EDD\Emails\Tags\Handler();
		$original         = EDD()->email_tags;
		EDD()->email_tags = $handler;

		try {
			( new TagsRegistry() )->register();
		} finally {
			EDD()->email_tags = $original;
		}

		return $handler;
	}

	/**
	 * Gets the legacy tag arrays from a handler which match a tag identifier.
	 *
	 * @param \EDD\Emails\Tags\Handler $handler The handler to search.
	 * @param string                   $name    The tag identifier.
	 * @return array
	 */
	private function get_tags_named( $handler, $name ) {
		return array_filter(
			$handler->get(),
			function ( $tag ) use ( $name ) {
				return $name === $tag['tag'];
			}
		);
	}
}
