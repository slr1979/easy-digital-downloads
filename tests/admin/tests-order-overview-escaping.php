<?php
/**
 * Order Overview escaping tests.
 *
 * The order overview renders its item and adjustment rows from Underscore templates, fed by a
 * model localized for the browser. A value interpolated with the raw `{{{ }}}` delimiter is
 * written to the page unescaped, and a product name can reach that template already decoded by
 * the browser's HTML parser rather than as the character references that were stored.
 *
 * These tests assert that the templates escape every value they interpolate, that the model
 * does not then escape a second time, and that a name containing markup cannot be stored.
 *
 * @package     EDD\Tests\Admin
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Admin;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Order Overview escaping tests.
 *
 * @since 3.7.1
 * @group edd_orders
 */
class OrderOverviewEscaping extends EDD_UnitTestCase {

	/**
	 * A product name held as character references, which is the form kses permits.
	 *
	 * @var string
	 */
	const ENCODED_NAME = '&lt;img src=x onerror=alert(20002)&gt;MXP2';

	/**
	 * A legitimate product name carrying a character the model would escape.
	 *
	 * @var string
	 */
	const AMPERSAND_NAME = 'Widget & Gadget';

	/**
	 * A legitimate adjustment description carrying a character the model would escape.
	 *
	 * @var string
	 */
	const AMPERSAND_DESCRIPTION = 'Rush & Handling';

	/**
	 * The order overview templates, which the directory glob must reach.
	 *
	 * @var array
	 */
	const ORDER_OVERVIEW_TEMPLATES = array(
		'tmpl-order-item.php',
		'tmpl-order-adjustment.php',
		'tmpl-order-adjustment-discount.php',
		'tmpl-order-form-add-order-item.php',
	);

	/**
	 * The order overview view functions are only loaded for admin requests.
	 */
	public static function wpSetUpBeforeClass() {
		require_once EDD_PLUGIN_DIR . 'includes/admin/payments/orders.php';
	}

	public function setUp(): void {
		parent::setUp();

		// EDD's roles and their capabilities are installed rather than present by default, and
		// another test in the suite removes them; this class asserts against a role, so it
		// installs them. WP_Roles::add_cap() updates the stored roles but not the WP_Role
		// objects already built for this request, so the roles are reloaded afterwards.
		EDD()->roles->add_roles();
		EDD()->roles->add_caps();
		wp_roles()->for_site( get_current_blog_id() );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * A product writer without `unfiltered_html` can store a name held as character references.
	 *
	 * This is the premise the rest of this class rests on rather than a defect: kses removes a
	 * raw tag but leaves character references alone. It is asserted so that a future change
	 * which removes the premise surfaces here instead of leaving dead coverage behind.
	 */
	public function test_a_product_writer_can_store_a_name_held_as_character_references() {
		$user_id = $this->factory->user->create( array( 'role' => 'shop_vendor' ) );

		$this->assertTrue( user_can( $user_id, 'edit_products' ), 'A shop vendor must be able to write products.' );
		$this->assertFalse( user_can( $user_id, 'unfiltered_html' ), 'A shop vendor must not hold unfiltered_html.' );

		// Switching the user runs kses_init(), so the save filters behave as they do for a request.
		wp_set_current_user( $user_id );

		$encoded_id = $this->factory->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'draft',
				'post_title'  => self::ENCODED_NAME,
			)
		);

		$this->assertStringContainsString(
			'&lt;img',
			get_post_field( 'post_title', $encoded_id ),
			'Character references must survive the save.'
		);

		$raw_id = $this->factory->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'draft',
				'post_title'  => '<img src=x onerror=alert(1)>MXRAWTITLE',
			)
		);

		$this->assertStringNotContainsString(
			'<img',
			get_post_field( 'post_title', $raw_id ),
			'A raw tag must be removed, which is why the encoded form is the one that matters.'
		);
	}

	/**
	 * The item template must escape every value it interpolates.
	 */
	public function test_the_order_item_template_escapes_the_values_it_interpolates() {
		$template = $this->get_template( 'tmpl-order-item.php' );

		$this->assertStringNotContainsString(
			'{{{ data.productName }}}',
			$template,
			'The product name must not be interpolated with the raw delimiter.'
		);
		$this->assertStringNotContainsString(
			'{{{ data.statusLabel }}}',
			$template,
			'The status label must not be interpolated with the raw delimiter.'
		);
	}

	/**
	 * The adjustment template must escape every value it interpolates.
	 */
	public function test_the_order_adjustment_template_escapes_the_values_it_interpolates() {
		$this->assertStringNotContainsString(
			'{{{ data.type }}}',
			$this->get_template( 'tmpl-order-adjustment.php' ),
			'The adjustment type must not be interpolated with the raw delimiter.'
		);
	}

	/**
	 * No template in the admin views directory may interpolate with the raw delimiter.
	 *
	 * The two assertions above name the values this change fixed; this one holds the invariant
	 * for the directory, so a template added later cannot reintroduce the raw delimiter and a
	 * whitespace variant such as `{{{data.productName}}}` cannot sidestep a literal comparison.
	 */
	public function test_no_admin_view_template_interpolates_with_the_raw_delimiter() {
		$templates = glob( EDD_PLUGIN_DIR . 'includes/admin/views/*.php' );
		$found     = array_map( 'basename', (array) $templates );

		// The glob has to reach the templates this class is about, or it passes over nothing.
		foreach ( self::ORDER_OVERVIEW_TEMPLATES as $expected ) {
			$this->assertContains( $expected, $found, "The glob must cover {$expected}." );
		}

		foreach ( $templates as $template ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\{\{\{.+?\}\}\}/s',
				file_get_contents( $template ),
				basename( $template ) . ' interpolates a value with the raw delimiter, which does not escape.'
			);
		}
	}

	/**
	 * The localized model must not escape a name the template will escape again.
	 *
	 * Without this, switching the template to the escaping delimiter displays a legitimate name
	 * as `Widget &amp;amp; Gadget`, so this is what keeps the template change from breaking
	 * display. A raw character is required to show it: `esc_html()` does not re-encode existing
	 * character references, which is the same property that lets the encoded name through.
	 */
	public function test_the_localized_item_model_does_not_escape_a_name_the_template_escapes() {
		$model = $this->get_localized_overview( $this->create_order_with_item_name( self::AMPERSAND_NAME ) );

		$this->assertNotEmpty( $model['items'], 'The order must localize its items.' );
		$this->assertSame(
			self::AMPERSAND_NAME,
			$model['items'][0]['productName'],
			'The model must carry the stored name without escaping it.'
		);
	}

	/**
	 * A name stored as character references must reach the model unchanged.
	 *
	 * The control for the fix: it must neither decode the references into markup nor escape them
	 * a second time, both of which would be ways to "fix" the display and break something else.
	 */
	public function test_the_localized_item_model_leaves_character_references_alone() {
		$model = $this->get_localized_overview( $this->create_order_with_item_name( self::ENCODED_NAME ) );

		$this->assertSame(
			self::ENCODED_NAME,
			$model['items'][0]['productName'],
			'Character references must reach the model exactly as stored.'
		);
	}

	/**
	 * The localized adjustment model must not escape a description the template escapes.
	 *
	 * `tmpl-order-adjustment.php` and `tmpl-order-adjustment-discount.php` both interpolate the
	 * description with the escaping delimiter, so escaping it here as well displays a legitimate
	 * fee as `Rush &amp; Handling`. The order screen builds the item level and order level
	 * adjustments in separate loops, so both are asserted.
	 */
	public function test_the_localized_adjustment_model_does_not_escape_a_description_the_template_escapes() {
		$model = $this->get_localized_overview( $this->create_order_with_fee_description( self::AMPERSAND_DESCRIPTION ) );

		$item_adjustment  = $this->find_adjustment( $model['adjustments'], 'order_item' );
		$order_adjustment = $this->find_adjustment( $model['adjustments'], 'order' );

		$this->assertNotNull( $item_adjustment, 'The order must localize its order item adjustment.' );
		$this->assertNotNull( $order_adjustment, 'The order must localize its order level adjustment.' );

		$this->assertSame(
			self::AMPERSAND_DESCRIPTION,
			$item_adjustment['description'],
			'The model must carry the order item adjustment description without escaping it.'
		);
		$this->assertSame(
			self::AMPERSAND_DESCRIPTION,
			$order_adjustment['description'],
			'The model must carry the order level adjustment description without escaping it.'
		);
	}

	/**
	 * The refund modal's model must not escape a name its template will escape again.
	 *
	 * The refund screen builds its own copy of this model for the same two templates, so it
	 * needs the same treatment; fixing only the order screen would leave the modal showing
	 * `Widget &amp;amp; Gadget`.
	 */
	public function test_the_refund_model_does_not_escape_a_name_the_template_escapes() {
		require_once EDD_PLUGIN_DIR . 'includes/admin/payments/refunds.php';

		$order     = $this->create_order_with_item_name( self::AMPERSAND_NAME );
		$refund_id = edd_refund_order( $order->id );

		$this->assertIsInt( $refund_id, 'The order must be refundable for this test to mean anything.' );

		$model = $this->read_localized_model(
			function () use ( $refund_id ) {
				edd_refund_details_items( edd_get_order( $refund_id ) );
			}
		);

		$this->assertNotEmpty( $model['items'], 'The refund must localize its items.' );
		$this->assertSame(
			self::AMPERSAND_NAME,
			$model['items'][0]['productName'],
			'The refund model must carry the stored name without escaping it.'
		);
	}

	/**
	 * The refund modal's model must not escape a description its template will escape again.
	 *
	 * The modal renders the same two adjustment templates from its own copy of the model, so it
	 * needs the same treatment as the order screen.
	 */
	public function test_the_refund_model_does_not_escape_a_description_the_template_escapes() {
		require_once EDD_PLUGIN_DIR . 'includes/admin/payments/refunds.php';

		$order     = $this->create_order_with_fee_description( self::AMPERSAND_DESCRIPTION );
		$refund_id = edd_refund_order( $order->id );

		$this->assertIsInt( $refund_id, 'The order must be refundable for this test to mean anything.' );

		$model = $this->read_localized_model(
			function () use ( $refund_id ) {
				edd_refund_details_items( edd_get_order( $refund_id ) );
			}
		);

		$this->assertNotEmpty( $model['adjustments'], 'The refund must localize its adjustments.' );

		foreach ( $model['adjustments'] as $adjustment ) {
			$this->assertSame(
				self::AMPERSAND_DESCRIPTION,
				$adjustment['description'],
				'The refund model must carry the stored description without escaping it.'
			);
		}
	}

	/**
	 * An order item name must be stored exactly as given.
	 *
	 * Escaping belongs at output, not at storage. A sanitizer on this column looks like cheap
	 * defense in depth but `sanitize_text_field()` runs `strip_tags()`, which truncates a
	 * legitimate name at the first `<` followed by a letter: `Plugin <Pro>` becomes `Plugin`.
	 * This asserts the column stays faithful so that hardening is not added here later.
	 */
	public function test_an_order_item_name_is_stored_without_alteration() {
		$name     = 'Plugin <Pro> & More';
		$order_id = edd_add_order(
			array(
				'status'   => 'complete',
				'email'    => 'overview-escaping@example.test',
				'currency' => 'USD',
				'total'    => 20.00,
			)
		);

		$item_id = edd_add_order_item(
			array(
				'order_id'     => $order_id,
				'product_id'   => 1,
				'product_name' => $name,
				'status'       => 'complete',
				'quantity'     => 1,
				'amount'       => 20.00,
				'subtotal'     => 20.00,
				'total'        => 20.00,
			)
		);

		$this->assertSame(
			$name,
			edd_get_order_item( $item_id )->product_name,
			'The stored name must not be altered.'
		);

		edd_destroy_order( $order_id );
	}

	/**
	 * Reads one of the order overview templates from disk.
	 *
	 * @param string $file The template file name.
	 * @return string
	 */
	private function get_template( $file ) {
		$path = EDD_PLUGIN_DIR . 'includes/admin/views/' . $file;

		$this->assertFileExists( $path, 'The template must exist for this assertion to mean anything.' );

		return file_get_contents( $path );
	}

	/**
	 * Creates an order carrying one item with the given name.
	 *
	 * @param string $product_name The order item name to store.
	 * @return \EDD\Orders\Order
	 */
	private function create_order_with_item_name( $product_name ) {
		$order_id = edd_add_order(
			array(
				'status'   => 'complete',
				'email'    => 'overview-encoded@example.test',
				'currency' => 'USD',
				'total'    => 20.00,
			)
		);

		edd_add_order_item(
			array(
				'order_id'     => $order_id,
				'product_id'   => 1,
				'product_name' => $product_name,
				'status'       => 'complete',
				'quantity'     => 1,
				'amount'       => 20.00,
				'subtotal'     => 20.00,
				'total'        => 20.00,
			)
		);

		return edd_get_order( $order_id );
	}

	/**
	 * Creates an order carrying one fee on its item and one on the order itself.
	 *
	 * The two live in separate loops in the localized model, so both are needed to cover it.
	 *
	 * @param string $description The adjustment description to store.
	 * @return \EDD\Orders\Order
	 */
	private function create_order_with_fee_description( $description ) {
		$order_id = edd_add_order(
			array(
				'status'   => 'complete',
				'email'    => 'overview-adjustment@example.test',
				'currency' => 'USD',
				'total'    => 25.00,
			)
		);

		$item_id = edd_add_order_item(
			array(
				'order_id'     => $order_id,
				'product_id'   => 1,
				'product_name' => 'Adjustment Fixture',
				'status'       => 'complete',
				'quantity'     => 1,
				'amount'       => 20.00,
				'subtotal'     => 20.00,
				'total'        => 20.00,
			)
		);

		$objects = array(
			'order_item' => $item_id,
			'order'      => $order_id,
		);

		foreach ( $objects as $object_type => $object_id ) {
			edd_add_order_adjustment(
				array(
					'object_id'   => $object_id,
					'object_type' => $object_type,
					'type'        => 'fee',
					'description' => $description,
					'subtotal'    => 2.50,
					'total'       => 2.50,
				)
			);
		}

		return edd_get_order( $order_id );
	}

	/**
	 * Finds the first localized adjustment belonging to the given object type.
	 *
	 * @param array  $adjustments The localized adjustments.
	 * @param string $object_type The object type to look for.
	 * @return array|null
	 */
	private function find_adjustment( array $adjustments, $object_type ) {
		foreach ( $adjustments as $adjustment ) {
			if ( $object_type === $adjustment['objectType'] ) {
				return $adjustment;
			}
		}

		return null;
	}

	/**
	 * Runs the order overview screen and returns the model it localizes for the browser.
	 *
	 * @param \EDD\Orders\Order $order The order to render.
	 * @return array
	 */
	private function get_localized_overview( $order ) {
		return $this->read_localized_model(
			function () use ( $order ) {
				edd_order_details_overview( $order );
			}
		);
	}

	/**
	 * Runs a screen which localizes the order overview model and returns that model.
	 *
	 * @param callable $render The screen to run.
	 * @return array
	 */
	private function read_localized_model( callable $render ) {
		// Localized data accumulates on the handle, so start from a clean registration.
		wp_deregister_script( 'edd-admin-orders' );
		wp_register_script( 'edd-admin-orders', '' );

		ob_start();
		$render();
		ob_end_clean();

		$data = wp_scripts()->get_data( 'edd-admin-orders', 'data' );

		$this->assertNotEmpty( $data, 'The overview must localize its model.' );

		// wp_localize_script stores the model as a JS assignment, so unwrap it back to an array.
		$json = substr( $data, strpos( $data, '=' ) + 1 );

		return json_decode( trim( rtrim( trim( $json ), ';' ) ), true );
	}
}
