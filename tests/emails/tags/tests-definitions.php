<?php
namespace EDD\Tests\Emails\Tags;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Payment;
use EDD\Emails\Tags\Definitions as Tags;
use EDD\Emails\Tags\Definitions\Tag;
use EDD\Emails\Tags\Registry as TagsRegistry;
use EDD\Tests\Helpers\EDD_Helper_Email;

/**
 * Tests for concrete Tag definitions and the abstract Tag class contract.
 *
 * @group edd_emails
 * @group edd_email_tags
 */
class Definitions extends EDD_UnitTestCase {

	/**
	 * Order fixture.
	 *
	 * @var int
	 */
	protected static $order_id;

	/**
	 * Order object fixture.
	 *
	 * @var \EDD\Orders\Order
	 */
	protected static $order;

	/**
	 * Refund fixture.
	 *
	 * @var int
	 */
	protected static $refund_id;

	/**
	 * Refund object fixture.
	 *
	 * @var \EDD\Orders\Order
	 */
	protected static $refund;

	/**
	 * User fixture.
	 *
	 * @var \WP_User
	 */
	protected static $user;

	public static function wpSetUpBeforeClass() {
		self::$order_id = EDD_Helper_Payment::create_simple_payment();
		edd_update_order_status( self::$order_id, 'complete' );

		edd_add_order_address(
			array(
				'order_id'    => self::$order_id,
				'address'     => '123 Main Street',
				'city'        => 'Omaha',
				'region'      => 'NE',
				'postal_code' => '68102',
				'country'     => 'US',
			)
		);

		self::$order = edd_get_order( self::$order_id );

		$refunded_order_id = EDD_Helper_Payment::create_simple_payment();
		edd_update_order_status( $refunded_order_id, 'complete' );

		self::$refund_id = edd_refund_order( $refunded_order_id );
		self::$refund    = edd_get_order( self::$refund_id );

		self::$user = get_userdata( 1 );
	}

	public function tearDown(): void {
		TagsRegistry::reset();
		parent::tearDown();
	}

	// ---- Abstract Tag contract ----

	/**
	 * get_tag() returns the tag identifier string.
	 */
	public function test_get_tag_returns_string() {
		$this->assertSame( 'sitename', ( new Tags\SiteName() )->get_tag() );
	}

	/**
	 * SiteName has empty contexts, so it is available in every context.
	 */
	public function test_sitename_has_empty_contexts() {
		$this->assertSame( array(), ( new Tags\SiteName() )->get_contexts() );
	}

	/**
	 * SiteName has null recipients, so it is available to every recipient.
	 */
	public function test_sitename_has_null_recipients() {
		$this->assertNull( ( new Tags\SiteName() )->get_recipients() );
	}

	/**
	 * TransactionId is restricted to order emails sent to the admin.
	 */
	public function test_transaction_id_context_and_recipient() {
		$tag = new Tags\TransactionId();

		$this->assertSame( array( 'order' ), $tag->get_contexts() );
		$this->assertSame( array( 'admin' ), $tag->get_recipients() );
	}

	/**
	 * RefundAmount is restricted to refund emails.
	 */
	public function test_refund_amount_has_refund_context() {
		$this->assertSame( array( 'refund' ), ( new Tags\RefundAmount() )->get_contexts() );
	}

	/**
	 * DownloadList is restricted to order emails.
	 */
	public function test_download_list_has_order_context() {
		$this->assertSame( array( 'order' ), ( new Tags\DownloadList() )->get_contexts() );
	}

	// ---- to_array() contract ----

	/**
	 * to_array() returns the keys Handler::add() expects.
	 */
	public function test_to_array_returns_expected_keys() {
		$data = ( new Tags\SiteName() )->to_array();

		foreach ( array( 'tag', 'label', 'description', 'func', 'contexts', 'recipients' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
	}

	/**
	 * to_array() 'func' is a callable which invokes render().
	 */
	public function test_to_array_func_invokes_render() {
		$data = ( new Tags\SiteName() )->to_array();

		$this->assertIsCallable( $data['func'] );
		$this->assertSame( $this->get_site_name(), call_user_func( $data['func'], 0 ) );
	}

	/**
	 * to_array() preserves contexts and recipients.
	 */
	public function test_to_array_preserves_contexts_and_recipients() {
		$data = ( new Tags\TransactionId() )->to_array();

		$this->assertSame( array( 'order' ), $data['contexts'] );
		$this->assertSame( array( 'admin' ), $data['recipients'] );
	}

	// ---- Every definition satisfies the contract ----

	/**
	 * Every registered definition has an identifier, a label and a description.
	 */
	public function test_all_definitions_are_described() {
		foreach ( TagsRegistry::get_registered_tags() as $tag ) {
			$class = get_class( $tag );

			$this->assertNotEmpty( $tag->get_tag(), "{$class} has an empty tag identifier." );
			$this->assertNotEmpty( $tag->get_label(), "{$class} has an empty label." );
			$this->assertNotEmpty( $tag->get_description(), "{$class} has an empty description." );
		}
	}

	/**
	 * Every registered definition produces a valid to_array().
	 */
	public function test_all_definitions_produce_valid_to_array() {
		$required_keys = array( 'tag', 'label', 'description', 'func', 'contexts', 'recipients' );

		foreach ( TagsRegistry::get_registered_tags() as $tag ) {
			$class = get_class( $tag );
			$data  = $tag->to_array();

			foreach ( $required_keys as $key ) {
				$this->assertArrayHasKey( $key, $data, "{$class}::to_array() is missing key '{$key}'." );
			}
			$this->assertIsCallable( $data['func'], "{$class}::to_array() func is not callable." );
		}
	}

	/**
	 * Every registered definition returns a string from render() in each of its own contexts.
	 *
	 * The `: string` return type makes a null return a TypeError, which is fatal in the middle of
	 * a send, so every definition needs to be exercised rather than only the ones with assertions
	 * of their own below.
	 */
	public function test_all_definitions_render_a_string() {
		foreach ( TagsRegistry::get_registered_tags() as $name => $tag ) {
			foreach ( $this->get_render_arguments( $tag ) as $context => $arguments ) {
				$this->assertIsString(
					$tag->render( ...$arguments ),
					"Tag '{$name}' did not render a string in the '{$context}' context."
				);
			}
		}
	}

	// ---- Order context ----

	public function test_download_list_renders_product_name() {
		$order_items = edd_get_order_items( array( 'order_id' => self::$order_id ) );

		$this->assertStringContainsString(
			$order_items[0]->product_name,
			( new Tags\DownloadList() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_file_urls_renders_a_url() {
		$this->assertStringContainsString(
			'http',
			( new Tags\FileUrls() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_name_renders_the_customer_first_name() {
		$this->assertSame(
			edd_email_tag_first_name( self::$order_id, self::$order, 'order' ),
			( new Tags\Name() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_name_renders_empty_string_when_the_order_has_no_stored_first_name() {
		$order_id = $this->seed_order_without_a_resolvable_first_name();
		$order    = edd_get_order( $order_id );

		$this->assertSame( '', ( new Tags\Name() )->render( $order_id, $order, 'order' ) );
	}

	public function test_fullname_renders_the_customer_name() {
		$this->assertSame(
			edd_get_customer( self::$order->customer_id )->name,
			( new Tags\FullName() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_username_renders_user_login_in_user_context() {
		$this->assertSame(
			self::$user->user_login,
			( new Tags\Username() )->render( self::$user->ID, self::$user, 'user' )
		);
	}

	public function test_user_email_renders_the_order_email() {
		$this->assertSame(
			self::$order->email,
			( new Tags\UserEmail() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_user_email_renders_empty_string_for_an_unsupported_context() {
		$this->assertSame( '', ( new Tags\UserEmail() )->render( self::$order_id, self::$order, 'refund' ) );
	}

	public function test_billing_address_renders_the_order_address() {
		$output = ( new Tags\BillingAddress() )->render( self::$order_id, self::$order, 'order' );

		$this->assertStringContainsString( '123 Main Street', $output );
		$this->assertStringContainsString( 'Omaha', $output );
		$this->assertStringContainsString( 'US', $output );
	}

	public function test_billing_address_renders_empty_string_without_an_address() {
		$this->assertSame( '', ( new Tags\BillingAddress() )->render( 0, null, 'order' ) );
	}

	/**
	 * The {billing_address} tag prints a stored order address into an HTML email body and the
	 * admin email preview, so it escapes what it prints. Rows stored before the write side
	 * sanitized them can still hold markup, so the reader is covered as well as the writer.
	 */
	public function test_billing_address_tag_escapes_stored_values() {
		$order_id = $this->seed_order_address_with_markup();

		$output = ( new Tags\BillingAddress() )->render( $order_id, null, 'order' );

		$this->assertStringNotContainsString( '<svg onload', $output, 'The tag must not print a live tag.' );
		$this->assertStringNotContainsString( '<img src=x', $output, 'The tag must not print a live tag.' );

		$this->assertStringContainsString(
			'&lt;svg onload=alert(&quot;CITY&quot;)&gt;',
			$output,
			'The city must be escaped, not dropped.'
		);
		$this->assertStringContainsString(
			'&lt;img src=x onerror=alert(&quot;ADDR2&quot;)&gt;',
			$output,
			'The second address line must be escaped, not dropped.'
		);
		$this->assertStringContainsString(
			'&lt;svg onload=alert(&quot;ZIP&quot;)&gt;',
			$output,
			'The postal code must be escaped, not dropped.'
		);
		$this->assertStringContainsString(
			'&lt;svg onload=alert(&quot;REGION&quot;)&gt;',
			$output,
			'The region must be escaped, not dropped.'
		);
	}

	/**
	 * A plain text store gets the address unescaped, because EDD\Emails\Base::build_email()
	 * strips tags from the whole message for that format. Escaping there would only put
	 * entities in front of the reader.
	 */
	public function test_billing_address_tag_is_not_escaped_for_a_plain_text_email() {
		$order_id = $this->seed_order_address_with_markup();

		add_filter( 'edd_email_template', array( $this, 'force_plain_text_template' ) );
		$output = ( new Tags\BillingAddress() )->render( $order_id, null, 'order' );
		remove_filter( 'edd_email_template', array( $this, 'force_plain_text_template' ) );

		$this->assertStringContainsString(
			'1 Market St & Sons',
			$output,
			'A plain text address must keep its own characters rather than showing entities.'
		);
		$this->assertStringNotContainsString( '&amp;', $output, 'A plain text address must not be HTML escaped.' );
	}

	/**
	 * The same store, rendered: the plain text message carries neither a live tag nor an entity.
	 */
	public function test_billing_address_in_a_plain_text_receipt_has_no_live_markup() {
		$order_id = $this->seed_order_address_with_markup();

		$email = EDD_Helper_Email::enable( 'order_receipt' );
		$this->assertNotEmpty( $email, 'The order receipt must be installed before it can be previewed.' );

		edd_update_email( $email->id, array( 'content' => 'Billing: {billing_address}' ) );

		add_filter( 'edd_email_template', array( $this, 'force_plain_text_template' ) );

		$receipt             = new \EDD\Emails\Types\OrderReceipt( edd_get_order( $order_id ) );
		$receipt->is_preview = true;
		$preview             = $receipt->get_preview();

		remove_filter( 'edd_email_template', array( $this, 'force_plain_text_template' ) );

		$this->assertStringContainsString( 'Billing:', $preview, 'The message must have rendered the tag.' );
		$this->assertStringNotContainsString( '<svg onload', $preview, 'The message must not carry a live tag.' );
		$this->assertStringNotContainsString( '<img src=x', $preview, 'The message must not carry a live tag.' );
		$this->assertStringContainsString(
			'1 Market St & Sons',
			$preview,
			'A plain text address must keep its own characters rather than showing entities.'
		);
	}

	/**
	 * A filtered content type is not the same question as the template. An HTML template still
	 * renders HTML in EDD\Emails\Base::build_email() when a plugin reports text/plain, so the
	 * address still has to be escaped.
	 */
	public function test_billing_address_tag_escapes_when_only_the_content_type_says_plain_text() {
		$order_id = $this->seed_order_address_with_markup();

		add_filter( 'edd_email_content_type', array( $this, 'force_plain_text_content_type' ) );
		$output = ( new Tags\BillingAddress() )->render( $order_id, null, 'order' );
		remove_filter( 'edd_email_content_type', array( $this, 'force_plain_text_content_type' ) );

		$this->assertStringNotContainsString( '<svg onload', $output, 'The tag must not print a live tag.' );
		$this->assertStringContainsString(
			'&lt;svg onload=alert(&quot;CITY&quot;)&gt;',
			$output,
			'The city must still be escaped when only the content type was filtered.'
		);
	}

	/**
	 * Filter callback: reports the store as sending plain text email.
	 *
	 * @return string
	 */
	public function force_plain_text_content_type() {
		return 'text/plain';
	}

	/**
	 * Filter callback: reports the store as using the template that has no HTML.
	 *
	 * @return string
	 */
	public function force_plain_text_template() {
		return 'none';
	}

	/**
	 * The same address, rendered through the receipt preview an administrator opens: nothing
	 * between the tag and the page escapes it.
	 */
	public function test_billing_address_tag_does_not_render_live_markup_in_a_receipt_preview() {
		$order_id = $this->seed_order_address_with_markup();

		$email = EDD_Helper_Email::enable( 'order_receipt' );
		$this->assertNotEmpty( $email, 'The order receipt must be installed before it can be previewed.' );

		edd_update_email( $email->id, array( 'content' => 'Billing: {billing_address}' ) );

		$receipt             = new \EDD\Emails\Types\OrderReceipt( edd_get_order( $order_id ) );
		$receipt->is_preview = true;
		$preview             = $receipt->get_preview();

		$this->assertStringContainsString( 'Billing:', $preview, 'The preview must have rendered the tag.' );
		$this->assertStringNotContainsString( '<svg onload', $preview, 'The preview must not carry a live tag.' );
		$this->assertStringNotContainsString( '<img src=x', $preview, 'The preview must not carry a live tag.' );
	}

	public function test_date_renders_the_localized_order_date() {
		$this->assertSame(
			date_i18n( get_option( 'date_format' ), strtotime( self::$order->date_created ) ),
			( new Tags\Date() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_subtotal_renders_formatted_currency() {
		$this->assertSame(
			$this->format_amount( self::$order->subtotal ),
			( new Tags\Subtotal() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_tax_renders_formatted_currency() {
		$this->assertSame(
			$this->format_amount( self::$order->tax ),
			( new Tags\Tax() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_price_renders_the_order_total() {
		$this->assertSame(
			$this->format_amount( self::$order->total ),
			( new Tags\Price() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_fees_total_renders_zero_without_fees() {
		$this->assertSame(
			edd_currency_filter( edd_format_amount( 0 ), self::$order->currency ),
			( new Tags\FeesTotal() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_fees_list_renders_empty_string_without_fees() {
		$this->assertSame( '', ( new Tags\FeesList() )->render( self::$order_id, self::$order, 'order' ) );
	}

	public function test_payment_id_renders_the_order_number() {
		$this->assertSame(
			(string) self::$order->get_number(),
			( new Tags\PaymentId() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_receipt_id_renders_the_payment_key() {
		$this->assertSame(
			self::$order->payment_key,
			( new Tags\ReceiptId() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_payment_method_renders_the_gateway_label() {
		$this->assertSame(
			edd_get_gateway_checkout_label( self::$order->gateway, self::$order ),
			( new Tags\PaymentMethod() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_sitename_renders_the_blog_name() {
		$this->assertSame( $this->get_site_name(), ( new Tags\SiteName() )->render( 0 ) );
	}

	public function test_receipt_renders_a_link_to_the_receipt_page() {
		$this->assertStringContainsString(
			'<a href="',
			( new Tags\Receipt() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_receipt_link_renders_a_view_in_browser_link() {
		$this->assertStringContainsString(
			'View it in your browser',
			( new Tags\ReceiptLink() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_discount_codes_renders_empty_string_without_a_discount() {
		$this->assertSame( '', ( new Tags\DiscountCodes() )->render( self::$order_id, self::$order, 'order' ) );
	}

	public function test_ip_address_renders_the_order_ip() {
		$this->assertSame(
			(string) self::$order->ip,
			( new Tags\IpAddress() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_login_link_renders_a_login_link() {
		$this->assertStringContainsString(
			wp_login_url(),
			( new Tags\LoginLink() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_order_details_link_renders_the_admin_order_url() {
		$expected = edd_get_admin_url(
			array(
				'page' => 'edd-payment-history',
				'view' => 'view-order-details',
				'id'   => self::$order_id,
			)
		);

		$this->assertSame(
			$expected,
			( new Tags\OrderDetailsLink() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	/**
	 * Outside an order the tag is returned untouched, so a later parsing pass can handle it.
	 */
	public function test_order_details_link_is_left_unparsed_outside_an_order() {
		$this->assertSame(
			'{order_details_link}',
			( new Tags\OrderDetailsLink() )->render( self::$refund_id, self::$refund, 'refund' )
		);
	}

	public function test_transaction_id_renders_the_transaction_id() {
		$this->assertSame(
			self::$order->get_transaction_id(),
			( new Tags\TransactionId() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	/**
	 * A gateway filter returning null renders empty rather than raising a TypeError.
	 */
	public function test_transaction_id_renders_empty_string_when_a_filter_returns_null() {
		$hook = 'edd_payment_details_transaction_id-' . self::$order->gateway;
		add_filter( $hook, '__return_null' );

		$this->assertSame( '', ( new Tags\TransactionId() )->render( self::$order_id, self::$order, 'order' ) );

		remove_filter( $hook, '__return_null' );
	}

	public function test_password_link_renders_a_link_in_user_context() {
		$this->assertStringContainsString(
			'<a href="',
			( new Tags\PasswordLink() )->render( self::$user->ID, self::$user, 'user' )
		);
	}

	public function test_password_link_renders_empty_string_for_an_unknown_user() {
		$this->assertSame( '', ( new Tags\PasswordLink() )->render( 0, null, 'user' ) );
	}

	public function test_phone_renders_empty_string_without_a_phone_number() {
		$this->assertSame( '', ( new Tags\Phone() )->render( self::$order_id, self::$order, 'order' ) );
	}

	public function test_phone_renders_the_order_phone_number() {
		edd_update_order_meta( self::$order_id, '_edd_phone', '(123) 456-7890' );

		$this->assertSame(
			'(123) 456-7890',
			( new Tags\Phone() )->render( self::$order_id, self::$order, 'order' )
		);

		edd_delete_order_meta( self::$order_id, '_edd_phone' );
	}

	public function test_company_renders_the_order_company_name() {
		edd_update_order_meta( self::$order_id, 'company_name', 'Acme Corp' );

		$this->assertSame(
			'Acme Corp',
			( new Tags\Company() )->render( self::$order_id, self::$order, 'order' )
		);

		edd_delete_order_meta( self::$order_id, 'company_name' );
	}

	public function test_company_renders_empty_string_outside_an_order() {
		$this->assertSame( '', ( new Tags\Company() )->render( self::$order_id, self::$order, 'user' ) );
	}

	public function test_company_accepts_an_email_object_as_its_context() {
		edd_update_order_meta( self::$order_id, 'company_name', 'Email Corp' );

		$this->assertSame(
			'Email Corp',
			( new Tags\Company() )->render( self::$order_id, self::$order, $this->get_email_mock( 'order' ) )
		);

		edd_delete_order_meta( self::$order_id, 'company_name' );
	}

	public function test_email_tags_no_company_returns_empty_string() {
		edd_delete_order_meta( self::$order_id, 'company_name' );
		$render = new \EDD\Emails\Tags\Render();
		$this->assertEquals( '', ( new Tags\Company() )->render( self::$order_id, self::$order, 'order' ) );
	}

	// ---- Refund context ----

	public function test_refund_link_renders_the_admin_refund_url() {
		$expected = edd_get_admin_url(
			array(
				'page' => 'edd-payment-history',
				'view' => 'view-refund-details',
				'id'   => self::$refund_id,
			)
		);

		$this->assertSame(
			$expected,
			( new Tags\RefundLink() )->render( self::$refund_id, self::$refund, 'refund' )
		);
	}

	public function test_refund_link_is_left_unparsed_outside_a_refund() {
		$this->assertSame(
			'{refund_link}',
			( new Tags\RefundLink() )->render( self::$order_id, self::$order, 'order' )
		);
	}

	public function test_refund_amount_renders_the_refunded_total() {
		$this->assertSame(
			edd_currency_filter( edd_format_amount( self::$refund->total * -1 ), self::$refund->currency ),
			( new Tags\RefundAmount() )->render( self::$refund_id, self::$refund, 'refund' )
		);
	}

	public function test_refund_amount_is_left_unparsed_for_a_sale_order() {
		$this->assertSame(
			'{refund_amount}',
			( new Tags\RefundAmount() )->render( self::$order_id, self::$order, 'refund' )
		);
	}

	public function test_refund_id_renders_the_refund_order_number() {
		$this->assertSame(
			self::$refund->order_number,
			( new Tags\RefundId() )->render( self::$refund_id, self::$refund, 'refund' )
		);
	}

	public function test_refund_id_is_left_unparsed_for_a_sale_order() {
		$this->assertSame(
			'{refund_id}',
			( new Tags\RefundId() )->render( self::$order_id, null, 'refund' )
		);
	}

	/**
	 * The arguments to render each tag with, keyed by the context they exercise.
	 *
	 * @param Tag $tag The tag definition.
	 * @return array
	 */
	private function get_render_arguments( Tag $tag ) {
		$fixtures = array(
			'order'  => array( self::$order_id, self::$order, 'order' ),
			'refund' => array( self::$refund_id, self::$refund, 'refund' ),
			'user'   => array( self::$user->ID, self::$user, 'user' ),
		);

		$contexts  = $tag->get_contexts() ?: array( 'order' );
		$arguments = array();
		foreach ( $contexts as $context ) {
			if ( isset( $fixtures[ $context ] ) ) {
				$arguments[ $context ] = $fixtures[ $context ];
			}
		}

		return $arguments;
	}

	/**
	 * Builds an email object which reports the given context.
	 *
	 * @param string $context The context the mock should report.
	 * @return \EDD\Emails\Types\Email
	 */
	private function get_email_mock( $context ) {
		$email = $this->getMockForAbstractClass(
			\EDD\Emails\Types\Email::class,
			array(),
			'',
			false,
			true,
			true,
			array( 'get_context' )
		);
		$email->method( 'get_context' )->willReturn( $context );

		return $email;
	}

	/**
	 * Formats an amount the way the currency email tags do.
	 *
	 * @param float $amount The amount to format.
	 * @return string
	 */
	private function format_amount( $amount ) {
		return html_entity_decode(
			edd_currency_filter( edd_format_amount( $amount ), self::$order->currency ),
			ENT_COMPAT,
			'UTF-8'
		);
	}

	/**
	 * The site name as the sitename tag renders it.
	 *
	 * @return string
	 */
	private function get_site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Writes an order address containing markup onto a real order, using $wpdb directly.
	 *
	 * The schema sanitizes on write, so a row holding markup cannot be created through the
	 * API. Writing it directly matches a row stored before that sanitizing existed.
	 *
	 * @return int The order ID.
	 */
	private function seed_order_address_with_markup() {
		global $wpdb;

		$order_id = EDD_Helper_Payment::create_simple_payment();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->edd_order_addresses} WHERE order_id = %d", $order_id ) );

		$wpdb->insert(
			$wpdb->edd_order_addresses,
			array(
				'order_id'      => $order_id,
				'type'          => 'billing',
				'name'          => 'Addr Test',
				'address'       => '1 Market St & Sons',
				'address2'      => 'Unit<img src=x onerror=alert("ADDR2")>',
				'city'          => 'Town<svg onload=alert("CITY")>',
				'region'        => 'CA<svg onload=alert("REGION")>',
				'postal_code'   => '9<svg onload=alert("ZIP")>',
				'country'       => 'US',
				'date_created'  => '2026-08-16 11:00:00',
				'date_modified' => '2026-08-16 11:00:00',
			)
		);

		wp_cache_flush();

		$address = edd_get_order_address_by( 'order_id', $order_id );
		$this->assertNotEmpty( $address, 'The order address fixture must exist.' );
		$this->assertStringContainsString(
			'<svg onload',
			$address->city,
			'The fixture must actually hold markup, or this case proves nothing.'
		);

		return $order_id;
	}

	/**
	 * Seed an order whose first name cannot be resolved from either the address or the customer.
	 *
	 * Deleting a customer while keeping its orders zeroes the order's customer_id and leaves
	 * user_id set, which is the shape that leaves the name unresolvable.
	 *
	 * @return int The order ID.
	 */
	private function seed_order_without_a_resolvable_first_name() {
		$order_id = EDD_Helper_Payment::create_simple_payment();

		edd_add_order_address(
			array(
				'order_id' => $order_id,
				'address'  => '123 Main Street',
				'country'  => 'US',
			)
		);

		edd_update_order( $order_id, array( 'customer_id' => 0 ) );

		wp_cache_flush();

		$order = edd_get_order( $order_id );

		$this->assertSame( 0, (int) $order->customer_id, 'The order must have no customer, or the name backfills from it.' );
		$this->assertGreaterThan( 0, (int) $order->user_id, 'The order must have a user, or the name falls back to the email.' );
		$this->assertNotEmpty( $order->get_address()->id, 'The order must have an address row, or first_name is an empty string.' );
		$this->assertNull( $order->get_address()->first_name, 'The address must carry no first name, or this case proves nothing.' );

		return $order_id;
	}
}
