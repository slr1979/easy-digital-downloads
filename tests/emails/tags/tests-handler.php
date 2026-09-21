<?php
namespace EDD\Tests\Emails\Tags;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Emails\Tags\Definitions\Tag;

/**
 * Tests for the Handler class with native Tag object support.
 *
 * @group edd_emails
 * @group edd_email_tags
 */
class Handler extends EDD_UnitTestCase {

	/**
	 * @var \EDD\Emails\Tags\Handler
	 */
	private $handler;

	public function setUp(): void {
		parent::setUp();
		$this->handler = new \EDD\Emails\Tags\Handler();
	}

	/**
	 * register() stores the tag object keyed by tag name.
	 */
	public function test_register_stores_tag_object() {
		$tag = new \EDD\Emails\Tags\Definitions\SiteName();

		$this->handler->register( $tag );

		$this->assertSame( $tag, $this->handler->get_tag_object( 'sitename' ) );
	}

	/**
	 * register() overwrites a previously registered tag of the same name.
	 */
	public function test_register_overwrites_same_tag() {
		$first  = new \EDD\Emails\Tags\Definitions\SiteName();
		$second = new \EDD\Emails\Tags\Definitions\SiteName();

		$this->handler->register( $first );
		$this->handler->register( $second );

		$this->assertSame( $second, $this->handler->get_tag_object( 'sitename' ) );
	}

	// ---- get_tag_object() ----

	/**
	 * get_tag_object() returns null for an unregistered tag.
	 */
	public function test_get_tag_object_returns_null_for_missing() {
		$this->assertNull( $this->handler->get_tag_object( 'nonexistent' ) );
	}

	/**
	 * get_tag_object() returns the Tag instance for a registered tag.
	 */
	public function test_get_tag_object_returns_instance() {
		$tag = new \EDD\Emails\Tags\Definitions\SiteName();
		$this->handler->register( $tag );

		$result = $this->handler->get_tag_object( 'sitename' );

		$this->assertInstanceOf( Tag::class, $result );
		$this->assertSame( 'sitename', $result->get_tag() );
	}

	// ---- get_tag_objects() ----

	/**
	 * get_tag_objects() returns all class-based tags when no filters provided.
	 */
	public function test_get_tag_objects_returns_all_unfiltered() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );
		$this->handler->register( new \EDD\Emails\Tags\Definitions\Date() );

		$objects = $this->handler->get_tag_objects();

		$this->assertCount( 2, $objects );
		$this->assertArrayHasKey( 'sitename', $objects );
		$this->assertArrayHasKey( 'date', $objects );
	}

	/**
	 * get_tag_objects() filters by context.
	 */
	public function test_get_tag_objects_filters_by_context() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );    // contexts = []
		$this->handler->register( new \EDD\Emails\Tags\Definitions\RefundAmount() ); // contexts = ['refund']

		$order_tags = $this->handler->get_tag_objects( 'order' );

		// SiteName has empty contexts (matches all), RefundAmount has ['refund'] (doesn't match 'order').
		$this->assertArrayHasKey( 'sitename', $order_tags );
		$this->assertArrayNotHasKey( 'refund_amount', $order_tags );
	}

	/**
	 * get_tag_objects() filters by recipient.
	 */
	public function test_get_tag_objects_filters_by_recipient() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );      // recipients = null
		$this->handler->register( new \EDD\Emails\Tags\Definitions\TransactionId() ); // recipients = ['admin']

		$customer_tags = $this->handler->get_tag_objects( '', 'customer' );

		$this->assertArrayHasKey( 'sitename', $customer_tags );
		$this->assertArrayNotHasKey( 'transaction_id', $customer_tags );
	}

	// ---- email_tag_exists() ----

	/**
	 * email_tag_exists() finds class-based tags.
	 */
	public function test_email_tag_exists_finds_class_based_tag() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );

		$this->assertTrue( $this->handler->email_tag_exists( 'sitename' ) );
	}

	/**
	 * email_tag_exists() finds legacy tags.
	 */
	public function test_email_tag_exists_finds_legacy_tag() {
		$this->handler->add( 'my_legacy_tag', 'A legacy tag', '__return_empty_string' );

		$this->assertTrue( $this->handler->email_tag_exists( 'my_legacy_tag' ) );
	}

	/**
	 * email_tag_exists() returns false for a missing tag.
	 */
	public function test_email_tag_exists_returns_false_for_missing() {
		$this->assertFalse( $this->handler->email_tag_exists( 'nonexistent' ) );
	}

	/**
	 * email_tag_exists() respects context filtering for class-based tags.
	 */
	public function test_email_tag_exists_respects_context() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\RefundAmount() ); // contexts = ['refund']

		$this->assertTrue( $this->handler->email_tag_exists( 'refund_amount', 'refund' ) );
		$this->assertFalse( $this->handler->email_tag_exists( 'refund_amount', 'order' ) );
	}

	/**
	 * email_tag_exists() respects recipient filtering for class-based tags.
	 */
	public function test_email_tag_exists_respects_recipient() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\TransactionId() ); // recipients = ['admin']

		$this->assertTrue( $this->handler->email_tag_exists( 'transaction_id', '', 'admin' ) );
		$this->assertFalse( $this->handler->email_tag_exists( 'transaction_id', '', 'customer' ) );
	}

	// ---- get() merges both sources ----

	/**
	 * get() returns class-based tags as flat arrays.
	 */
	public function test_get_returns_class_based_tags_as_flat_arrays() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );

		$tags = $this->handler->get();

		$this->assertNotEmpty( $tags );
		$found = false;
		foreach ( $tags as $data ) {
			if ( 'sitename' === $data['tag'] ) {
				$found = true;
				$this->assertArrayHasKey( 'tag', $data );
				$this->assertArrayHasKey( 'label', $data );
				$this->assertArrayHasKey( 'description', $data );
				$this->assertArrayHasKey( 'func', $data );
				$this->assertArrayHasKey( 'contexts', $data );
				$this->assertArrayHasKey( 'recipients', $data );
				break;
			}
		}
		$this->assertTrue( $found, 'sitename tag not found in get() result' );
	}

	/**
	 * get() merges class-based and legacy tags.
	 */
	public function test_get_merges_class_and_legacy_tags() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );
		$this->handler->add( 'legacy_tag', 'Legacy description', '__return_empty_string' );

		$tags = $this->handler->get();

		$tag_names = wp_list_pluck( $tags, 'tag' );
		$this->assertContains( 'sitename', $tag_names );
		$this->assertContains( 'legacy_tag', $tag_names );
	}

	/**
	 * get() filters by context.
	 */
	public function test_get_filters_by_context() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );      // contexts = [] (all)
		$this->handler->register( new \EDD\Emails\Tags\Definitions\RefundAmount() );   // contexts = ['refund']

		$order_tags = $this->handler->get( 'order' );
		$tag_names  = wp_list_pluck( $order_tags, 'tag' );

		$this->assertContains( 'sitename', $tag_names );
		$this->assertNotContains( 'refund_amount', $tag_names );
	}

	/**
	 * Class-based tags are not overwritten by legacy tags with the same unique key.
	 */
	public function test_class_based_tags_take_priority_in_get() {
		$class_tag = new \EDD\Emails\Tags\Definitions\SiteName();
		$this->handler->register( $class_tag );

		// Add a legacy tag with the same name. It shouldn't overwrite.
		$this->handler->add( 'sitename', 'Legacy sitename', '__return_empty_string', 'Legacy Site' );

		$tags = $this->handler->get();

		// Find the sitename entry - should be the class-based one.
		foreach ( $tags as $data ) {
			if ( 'sitename' === $data['tag'] ) {
				$this->assertIsCallable( $data['func'] );
				$this->assertSame( $class_tag->get_description(), $data['description'] );
				break;
			}
		}
	}

	// ---- remove() ----

	/**
	 * remove() removes a class-based tag.
	 */
	public function test_remove_class_based_tag() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );

		$this->handler->remove( 'sitename' );

		$this->assertNull( $this->handler->get_tag_object( 'sitename' ) );
		$this->assertFalse( $this->handler->email_tag_exists( 'sitename' ) );
	}

	/**
	 * remove() removes a legacy tag.
	 */
	public function test_remove_legacy_tag() {
		$this->handler->add( 'legacy_tag', 'Legacy description', '__return_empty_string' );

		$this->handler->remove( 'legacy_tag' );

		$this->assertFalse( $this->handler->email_tag_exists( 'legacy_tag' ) );
	}

	/**
	 * remove() accepts an array of tag names.
	 */
	public function test_remove_array_of_tags() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );
		$this->handler->add( 'legacy_tag', 'Legacy description', '__return_empty_string' );

		$this->handler->remove( array( 'sitename', 'legacy_tag' ) );

		$this->assertNull( $this->handler->get_tag_object( 'sitename' ) );
		$this->assertFalse( $this->handler->email_tag_exists( 'legacy_tag' ) );
	}

	// ---- do_tag() / do_tags() ----

	/**
	 * do_tags() replaces class-based tags in content.
	 */
	public function test_do_tags_replaces_class_based_tag() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );

		$result = $this->handler->do_tags( 'Welcome to {sitename}!', 0 );

		$expected_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$this->assertSame( "Welcome to {$expected_name}!", $result );
	}

	/**
	 * do_tags() replaces legacy tags in content.
	 */
	public function test_do_tags_replaces_legacy_tag() {
		$this->handler->add( 'greeting', 'A greeting', function() {
			return 'Hello World';
		} );

		$result = $this->handler->do_tags( 'Say: {greeting}', 0 );

		$this->assertSame( 'Say: Hello World', $result );
	}

	/**
	 * do_tags() leaves unrecognized tags untouched.
	 */
	public function test_do_tags_leaves_unknown_tags() {
		$result = $this->handler->do_tags( 'Hello {unknown_tag}', 0 );

		$this->assertSame( 'Hello {unknown_tag}', $result );
	}

	/**
	 * do_tags() prefers class-based tags over legacy tags with the same name.
	 */
	public function test_do_tags_prefers_class_based_over_legacy() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\SiteName() );
		$this->handler->add( 'sitename', 'Legacy', function() {
			return 'LEGACY_VALUE';
		} );

		$result = $this->handler->do_tags( '{sitename}', 0 );

		$expected = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$this->assertSame( $expected, $result );
		$this->assertNotSame( 'LEGACY_VALUE', $result );
	}

	/**
	 * do_tags() respects context when rendering class-based tags.
	 */
	public function test_do_tags_respects_context_for_class_based() {
		$this->handler->register( new \EDD\Emails\Tags\Definitions\RefundAmount() ); // contexts = ['refund']

		// In 'order' context, tag shouldn't render.
		$result = $this->handler->do_tags( '{refund_amount}', 0, null, 'order' );
		$this->assertSame( '{refund_amount}', $result );
	}
}
