<?php
/**
 * Note content escaping tests.
 *
 * @package   EDD\Tests\Notes
 * @copyright Copyright (c) 2026, Sandhills Development, LLC
 * @license   https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since     3.7.1
 */

namespace EDD\Tests\Notes;

use EDD\Tests\Helpers;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Note content escaping tests.
 *
 * Notes support limited markup, and note text can come from a gateway notification as well
 * as from store staff, so these tests cover both the write and the display path.
 *
 * @since 3.7.1
 *
 * @group edd_notes
 */
class NoteEscaping extends EDD_UnitTestCase {

	/**
	 * Note content with markup that notes do not allow.
	 *
	 * @var string
	 */
	const PAYLOAD = 'Case ID: PP-R-XSS<img src=x onerror=alert(1)>. Reason given: non_receipt<svg onload=alert(2)>.';

	/**
	 * The order the notes are attached to.
	 *
	 * @var int
	 */
	protected static $order_id;

	/**
	 * The request URI in place before the test replaced it.
	 *
	 * @var string|null
	 */
	protected $original_request_uri;

	/**
	 * Creates the order the notes hang off.
	 *
	 * @return void
	 */
	public static function wpSetUpBeforeClass() {
		self::$order_id = Helpers\EDD_Helper_Payment::create_simple_payment();
	}

	/**
	 * Loads the admin-only note renderer and gives it a request to build links from.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// The note renderer is only loaded in the admin.
		require_once EDD_PLUGIN_DIR . 'includes/admin/notes/note-functions.php';

		// A rendered note carries a delete link built from the current request.
		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$_SERVER['REQUEST_URI']     = '/wp-admin/edit.php?post_type=download&page=edd-payment-history';

		wp_cache_flush();
	}

	/**
	 * Restores the request URI the test replaced.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( is_null( $this->original_request_uri ) ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}

		parent::tearDown();
	}

	/**
	 * A note written through the documented helper does not keep the markup it was handed.
	 *
	 * @covers ::edd_add_note
	 * @covers ::edd_sanitize_note_content
	 */
	public function test_add_note_strips_script_attributes() {
		$note_id = edd_add_note(
			array(
				'object_id'   => self::$order_id,
				'object_type' => 'order',
				'content'     => self::PAYLOAD,
			)
		);

		$this->assertNotEmpty( $note_id, 'The note fixture was never written.' );

		$stored = $this->get_stored_content( $note_id );

		$this->assertNotEmpty( $stored, 'The note fixture stored no content at all.' );
		$this->assertStringNotContainsString( '<img', $stored );
		$this->assertStringNotContainsString( 'onerror', $stored );
		$this->assertStringNotContainsString( '<svg', $stored );
		$this->assertStringNotContainsString( 'onload', $stored );
		$this->assertStringContainsString( 'Case ID: PP-R-XSS', $stored );
	}

	/**
	 * A note keeps the text formatting store staff can already use, but not an image.
	 *
	 * Nothing EDD writes into a note contains an image.
	 *
	 * @covers ::edd_add_note
	 */
	public function test_add_note_drops_images_and_keeps_text_formatting() {
		$note_id = edd_add_note(
			array(
				'object_id'   => self::$order_id,
				'object_type' => 'order',
				'content'     => 'Refunded <strong>in full</strong>, see <a href="https://example.org/case">the case</a>. <img src="https://beacon.example/pixel.gif" alt="x">',
			)
		);

		$this->assertNotEmpty( $note_id, 'The note fixture was never written.' );

		$stored = $this->get_stored_content( $note_id );

		$this->assertStringContainsString( '<strong>in full</strong>', $stored );
		$this->assertStringContainsString( '<a href="https://example.org/case">', $stored );
		$this->assertStringNotContainsString( '<img', $stored );
		$this->assertStringNotContainsString( 'beacon.example', $stored );
	}

	/**
	 * A note row already in the table is escaped when the admin screen prints it.
	 *
	 * The fixture is inserted with $wpdb rather than through the note query, so it stands in
	 * for a row written to the table directly.
	 *
	 * @covers ::edd_admin_get_note_html
	 */
	public function test_note_html_escapes_stored_markup() {
		$note_id = $this->insert_raw_note( self::PAYLOAD );

		$this->assertNotEmpty( $note_id, 'The raw note fixture was never written.' );
		$this->assertStringContainsString(
			'<img',
			$this->get_stored_content( $note_id ),
			'The raw insert was filtered, so this fixture cannot exercise the renderer.'
		);

		$html = edd_admin_get_note_html( $note_id );

		$this->assertNotEmpty( $html, 'The note did not render.' );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertStringNotContainsString( 'onload', $html );
		$this->assertStringContainsString( 'Case ID: PP-R-XSS', $html );
	}

	/**
	 * Updating a note filters the new content, not just adding one.
	 *
	 * The `content` column's validate callback runs from update_item() as well as add_item().
	 *
	 * @covers ::edd_update_note
	 * @covers ::edd_sanitize_note_content
	 */
	public function test_note_schema_validates_updated_content() {
		$note_id = edd_add_note(
			array(
				'object_id'   => self::$order_id,
				'object_type' => 'order',
				'content'     => 'Nothing to see here.',
			)
		);

		$this->assertNotEmpty( $note_id, 'The note fixture was never written.' );
		$this->assertSame(
			'Nothing to see here.',
			$this->get_stored_content( $note_id ),
			'The note fixture did not store its starting content.'
		);

		edd_update_note( $note_id, array( 'content' => self::PAYLOAD ) );

		$stored = $this->get_stored_content( $note_id );

		$this->assertNotEmpty( $stored, 'The updated note stored no content at all.' );
		$this->assertStringNotContainsString( '<img', $stored );
		$this->assertStringNotContainsString( 'onerror', $stored );
	}

	/**
	 * Text that only looks like markup is kept, rather than being read as a tag and dropped.
	 *
	 * "</" is covered separately: kses leaves it alone, and a browser reads it as a comment.
	 *
	 * @covers ::edd_sanitize_note_content
	 * @covers ::edd_admin_get_note_html
	 */
	public function test_note_keeps_text_that_only_looks_like_markup() {
		$note_id = edd_add_note(
			array(
				'object_id'   => self::$order_id,
				'object_type' => 'order',
				'content'     => 'PayPal Dispute Message: charged a < b and c > d, so 2 </ 3 > 1 of it',
			)
		);

		$this->assertNotEmpty( $note_id, 'The note fixture was never written.' );

		$stored = $this->get_stored_content( $note_id );

		foreach ( array( 'charged a', 'b and c', 'd, so 2', '3', '1 of it' ) as $fragment ) {
			$this->assertStringContainsString( $fragment, $stored );
		}

		// Neither form reaches the rendered note as markup.
		$html = edd_admin_get_note_html( $note_id );

		$this->assertStringNotContainsString( '< b', $html );
		$this->assertStringNotContainsString( '</ 3', $html );
	}

	/**
	 * The allowed list for notes can be adjusted without touching edd_get_allowed_tags().
	 *
	 * @covers ::edd_sanitize_note_content
	 */
	public function test_note_allowed_tags_are_filterable() {
		$restore_images = function ( $tags ) {
			$tags['img'] = array( 'src' => array() );

			return $tags;
		};

		add_filter( 'edd/notes/allowed_html_tags', $restore_images );

		$note_id = edd_add_note(
			array(
				'object_id'   => self::$order_id,
				'object_type' => 'order',
				'content'     => 'Receipt scan <img src="https://example.org/scan.png">',
			)
		);

		remove_filter( 'edd/notes/allowed_html_tags', $restore_images );

		$this->assertNotEmpty( $note_id, 'The note fixture was never written.' );
		$this->assertStringContainsString( '<img', $this->get_stored_content( $note_id ) );
	}

	/**
	 * EDD_Customer::add_note() returns the note it stored, not a differently filtered copy.
	 *
	 * @covers \EDD_Customer::add_note
	 */
	public function test_customer_add_note_returns_what_it_stored() {
		$customer = new \EDD_Customer( 'note-escaping@example.org', false );
		$customer->create(
			array(
				'email' => 'note-escaping@example.org',
				'name'  => 'Note Escaping',
			)
		);

		$this->assertNotEmpty( $customer->id, 'The customer fixture was never created.' );

		$returned = $customer->add_note( self::PAYLOAD );
		$notes    = edd_get_notes(
			array(
				'object_type' => 'customer',
				'object_id'   => $customer->id,
				'number'      => 1,
			)
		);

		$this->assertNotEmpty( $notes, 'The customer note fixture was never written.' );
		$this->assertSame( $notes[0]->content, $returned );
		$this->assertStringNotContainsString( '<img', $returned );
	}

	/**
	 * A note made only of markup that notes do not allow is refused, not stored blank.
	 *
	 * @covers ::edd_insert_payment_note
	 */
	public function test_note_of_only_disallowed_markup_is_refused() {
		$before = $this->count_notes( 'order', self::$order_id );

		$this->assertFalse(
			edd_insert_payment_note( self::$order_id, '<img src="https://beacon.example/pixel.gif">' )
		);
		$this->assertSame( $before, $this->count_notes( 'order', self::$order_id ) );
	}

	/**
	 * A customer note made only of disallowed markup is refused, and writes no row.
	 *
	 * The empty check runs before the sanitizer, so this would otherwise store a blank row.
	 *
	 * @covers \EDD_Customer::add_note
	 */
	public function test_customer_note_of_only_disallowed_markup_is_refused() {
		$customer = new \EDD_Customer( 'note-blank@example.org', false );
		$customer->create(
			array(
				'email' => 'note-blank@example.org',
				'name'  => 'Note Blank',
			)
		);

		$this->assertNotEmpty( $customer->id, 'The customer fixture was never created.' );

		$before = $this->count_notes( 'customer', $customer->id );

		$this->assertFalse( $customer->add_note( '<img src="https://beacon.example/pixel.gif">' ) );
		$this->assertSame( $before, $this->count_notes( 'customer', $customer->id ) );
	}

	/**
	 * Counts the notes on an object.
	 *
	 * @param string $object_type Note object type.
	 * @param int    $object_id   Note object ID.
	 * @return int
	 */
	private function count_notes( $object_type, $object_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->edd_notes} WHERE object_type = %s AND object_id = %d",
				$object_type,
				$object_id
			)
		);
	}

	/**
	 * Reads a note's content column directly, bypassing getters and the object cache.
	 *
	 * @param int $note_id Note ID.
	 * @return string
	 */
	private function get_stored_content( $note_id ) {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT content FROM {$wpdb->edd_notes} WHERE id = %d", $note_id )
		);
	}

	/**
	 * Inserts a note row without going through the note query.
	 *
	 * @param string $content Note content.
	 * @return int
	 */
	private function insert_raw_note( $content ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->edd_notes,
			array(
				'object_id'     => self::$order_id,
				'object_type'   => 'order',
				'user_id'       => 0,
				'content'       => $content,
				'date_created'  => current_time( 'mysql', true ),
				'date_modified' => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}
}
