<?php
namespace EDD\Tests\API;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers;

/**
 * @group edd_api
 */
class API extends EDD_UnitTestCase {

	protected static $post;

	protected static $draft_id;

	protected static $private_id;

	/**
	 * @var EDD_API
	 */
	protected static $api;

	protected static $api_output;

	protected static $api_output_sales;

	protected static $user_id;

	protected static $payment_id;

	protected static $payment;

	/**
	 * Set up fixtures once.
	 * @expectedDeprecated edd_trigger_purchase_receipt
	 * @expectedDeprecated edd_admin_email_notice
	 */
	public static function wpSetUpBeforeClass() {
		global $wp_rewrite, $wp_query;
		$GLOBALS['wp_rewrite']->init();
		flush_rewrite_rules( false );

		self::$api = new \EDD_API();

		self::$user_id = self::factory()->user->create( array(
			'role' => 'administrator',
		) );
		EDD()->api->user_id = self::$user_id;
		$user = new \WP_User( self::$user_id );
		$user->add_cap( 'view_shop_reports' );
		$user->add_cap( 'view_shop_sensitive_data' );
		$user->add_cap( 'manage_shop_discounts' );

		$roles = new \EDD_Roles;
		$roles->add_roles();
		$roles->add_caps();

		wp_set_current_user( self::$user_id );

		self::$api->add_endpoint( $wp_rewrite );

		$post_id = self::factory()->post->create( array(
			'post_title' => 'Test Download',
			'post_type' => 'download',
			'post_status' => 'publish',
		) );

		$_variable_pricing = array(
			array(
				'name'   => 'Simple',
				'amount' => 20,
			),
			array(
				'name'   => 'Advanced',
				'amount' => 100,
			),
		);

		$_download_files = array(
			array(
				'name'      => 'File 1',
				'file'      => 'http://localhost/file1.jpg',
				'condition' => 0,
			),
			array(
				'name'      => 'File 2',
				'file'      => 'http://localhost/file2.jpg',
				'condition' => 'all',
			),
		);

		$meta = array(
			'edd_price'                      => '0.00',
			'_variable_pricing'              => 1,
			'_edd_price_options_mode'        => 'on',
			'edd_variable_prices'            => array_values( $_variable_pricing ),
			'edd_download_files'             => array_values( $_download_files ),
			'_edd_download_limit'            => 20,
			'_edd_hide_purchase_link'        => 1,
			'edd_product_notes'              => 'Purchase Notes',
			'_edd_product_type'              => 'default',
			'_edd_download_earnings'         => 129.43,
			'_edd_download_sales'            => 59,
			'_edd_download_limit_override_1' => 1,
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		self::$post = get_post( $post_id );

		self::$draft_id = self::factory()->post->create( array(
			'post_title'   => 'Unreleased Q4 Launch',
			'post_type'    => 'download',
			'post_status'  => 'draft',
			'post_excerpt' => 'Do not publish yet',
			'post_author'  => self::$user_id,
		) );

		update_post_meta( self::$draft_id, '_variable_pricing', 1 );
		update_post_meta( self::$draft_id, '_edd_price_options_mode', 'on' );
		update_post_meta( self::$draft_id, 'edd_variable_prices', array_values( array(
			array(
				'name'   => 'Insider Early Bird',
				'amount' => 29,
			),
			array(
				'name'   => 'Enterprise NDA Tier',
				'amount' => 1999,
			),
		) ) );

		self::$private_id = self::factory()->post->create( array(
			'post_title'  => 'Internal Client Bundle',
			'post_type'   => 'download',
			'post_status' => 'private',
			'post_author' => self::$user_id,
		) );

		update_post_meta( self::$private_id, '_variable_pricing', 1 );
		update_post_meta( self::$private_id, '_edd_price_options_mode', 'on' );
		update_post_meta( self::$private_id, 'edd_variable_prices', array_values( array(
			array(
				'name'   => 'Client Exclusive Tier',
				'amount' => 499,
			),
		) ) );

		$user = get_userdata( 1 );

		$user_info = array(
			'id'         => $user->ID,
			'email'      => $user->user_email,
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
			'discount'   => 'none',
		);

		$download_details = array(
			array(
				'id'      => self::$post->ID,
				'options' => array(
					'price_id' => 1,
				),
			),
		);

		$total      = 0;
		$prices     = get_post_meta( $download_details[0]['id'], 'edd_variable_prices', true );
		$item_price = $prices[1]['amount'];
		$total      += $item_price;

		$cart_details = array(
			array(
				'name'        => 'Test Download',
				'id'          => self::$post->ID,
				'item_number' => array(
					'id'      => self::$post->ID,
					'options' => array(
						'price_id' => 1,
					),
				),
				'item_price'  => 100,
				'subtotal'    => 100,
				'price'       => 100,
				'tax'         => 0,
				'quantity'    => 1,
			),
		);

		$purchase_data = array(
			'price'        => number_format( (float) $total, 2 ),
			'date'         => date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
			'purchase_key' => strtolower( md5( uniqid() ) ),
			'user_email'   => $user_info['email'],
			'user_info'    => $user_info,
			'currency'     => 'USD',
			'downloads'    => $download_details,
			'cart_details' => $cart_details,
			'status'       => 'pending',
		);

		$_SERVER['REMOTE_ADDR'] = '10.0.0.0';

		self::$payment_id = edd_insert_payment( $purchase_data );
		self::$payment    = edd_get_payment( self::$payment_id );

		// We don't want to trigger purchase receipts here.
		remove_action( 'edd_complete_purchase', 'edd_trigger_purchase_receipt', 999 );
		edd_update_payment_status( self::$payment_id, 'complete' );
		// Now add it back.
		add_action( 'edd_complete_purchase', 'edd_trigger_purchase_receipt', 999 );

		self::$api_output       = self::$api->get_products();
		self::$api_output_sales = self::$api->get_recent_sales();

		$_POST['edd_set_api_key'] = 1;
		EDD()->api->update_key( self::$user_id );

		// Generate a file download log.
		edd_record_download_in_log( self::$post->ID, 0, array(), '127.0.0.1', self::$payment_id, 'EDD\Tests' );
	}

	public function setup(): void {
		parent::setUp();

		wp_set_current_user( self::$user_id );

		add_filter( 'edd_api_output_format', function() {
			return 'override';
		} );

		self::$api->flush_api_output();
	}

	public function tearDown(): void {
		parent::tearDown();

		// Revoke key to ensure `update_key()` will generate a new one.
		EDD()->api->revoke_api_key( self::$user_id );

		self::$api->flush_api_output();

		// Restore the administrator for any test that modeled an unauthenticated visitor.
		wp_set_current_user( self::$user_id );
		self::$api->override = true;
		self::$api->user_id  = 0;
	}

	public static function tearDownAfterClass(): void {
		parent::tearDownAfterClass();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_endpoints() {
		global $wp_rewrite;

		$this->assertEquals( 'edd-api', $wp_rewrite->endpoints[0][1] );
	}

	public function test_query_vars() {
		global $wp_filter;

		foreach ( $wp_filter['query_vars'][10] as $arr ) :

			if ( 'query_vars' == $arr['function'][1] ) {
				$this->assertTrue( true );
			}

		endforeach;

		// API-specific vars are only registered for API requests.
		$original_uri           = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$_SERVER['REQUEST_URI'] = '/edd-api/v2/products';

		$out = self::$api->query_vars( array() );

		$_SERVER['REQUEST_URI'] = $original_uri;

		$expected = array( 'token', 'key', 'query', 'type', 'product', 'category', 'tag', 'term_relation', 'number', 'date', 'startdate', 'enddate', 'customer', 'format', 'discount' );
		foreach ( $expected as $var ) {
			$this->assertContains( $var, $out );
		}
	}

	public function test_get_versions() {
		$this->assertIsArray( self::$api->get_versions() );
		$this->assertArrayHasKey( 'v1', self::$api->get_versions() );
	}

	public function test_get_default_version() {

		$this->assertEquals( 'v2', self::$api->get_default_version() );

		define( 'EDD_API_VERSION', 'v1' );
		$this->assertEquals( 'v1', self::$api->get_default_version() );

	}

	public function test_get_queried_version() {

		global $wp_query;

		$_POST['edd_set_api_key'] = 1;
		EDD()->api->update_key( self::$user_id );

		$wp_query->query_vars['key']   = get_user_meta( self::$user_id, 'edd_user_public_key', true );
		$wp_query->query_vars['token'] = hash( 'md5', get_user_meta( self::$user_id, 'edd_user_secret_key', true ) . get_user_meta( self::$user_id, 'edd_user_public_key', true ) );

		$wp_query->query_vars['edd-api'] = 'v1/sales';

		try {
			self::$api->process_query();
		} catch ( \WPDieException $e ) {}
		$this->assertEquals( 'v1', self::$api->get_queried_version() );

		try {
			$wp_query->query_vars['edd-api'] = 'v2/sales';
			self::$api->process_query();
		} catch ( \WPDieException $e ) {}
		$this->assertEquals( 'v2', self::$api->get_queried_version() );
	}

	public function test_get_products() {
		$out = self::$api_output;
		$this->assertArrayHasKey( 'id', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'slug', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'title', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'create_date', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'modified_date', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'status', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'link', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'permalink', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'content', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'thumbnail', $out['products'][0]['info'] );

		$this->assertEquals( self::$post->ID, $out['products'][0]['info']['id'] );
		$this->assertEquals( 'test-download', $out['products'][0]['info']['slug'] );
		$this->assertEquals( 'Test Download', $out['products'][0]['info']['title'] );
		$this->assertEquals( 'publish', $out['products'][0]['info']['status'] );
		$this->assertEquals( self::$post->post_content, $out['products'][0]['info']['content'] );
		$this->assertEquals( '', $out['products'][0]['info']['thumbnail'] );
		$this->assertEquals( html_entity_decode( self::$post->guid ), $out['products'][0]['info']['link'] );
		$this->assertEquals( html_entity_decode( get_permalink( self::$post->ID ) ), $out['products'][0]['info']['permalink'] );
	}

	public function test_get_product_stats() {
		$out = self::$api_output;

		$this->assertArrayHasKey( 'stats', $out['products'][0] );
		$this->assertArrayHasKey( 'total', $out['products'][0]['stats'] );
		$this->assertArrayHasKey( 'sales', $out['products'][0]['stats']['total'] );
		$this->assertArrayHasKey( 'earnings', $out['products'][0]['stats']['total'] );
		$this->assertArrayHasKey( 'monthly_average', $out['products'][0]['stats'] );
		$this->assertArrayHasKey( 'sales', $out['products'][0]['stats']['monthly_average'] );
		$this->assertArrayHasKey( 'earnings', $out['products'][0]['stats']['monthly_average'] );

		$this->assertEquals( '1', $out['products'][0]['stats']['total']['sales'] );
		$this->assertEquals( 100.00, (float) $out['products'][0]['stats']['total']['earnings'] );
		$this->assertEquals( '1', $out['products'][0]['stats']['monthly_average']['sales'] );
		$this->assertEquals( 100.00, (float) $out['products'][0]['stats']['monthly_average']['earnings'] );
	}

	public function test_get_products_pricing() {
		$out = self::$api_output;
		$this->assertArrayHasKey( 'pricing', $out['products'][0] );
		$this->assertArrayHasKey( 'simple', $out['products'][0]['pricing'] );
		$this->assertArrayHasKey( 'advanced', $out['products'][0]['pricing'] );

		$this->assertEquals( '20.00', $out['products'][0]['pricing']['simple'] );
		$this->assertEquals( '100.00', $out['products'][0]['pricing']['advanced'] );
	}

	public function test_get_products_files() {
		$out = self::$api_output;
		$this->assertArrayHasKey( 'files', $out['products'][0] );

		foreach ( $out['products'][0]['files'] as $file ) {
			$this->assertArrayHasKey( 'name', $file );
			$this->assertArrayHasKey( 'file', $file );
			$this->assertArrayHasKey( 'condition', $file );
		}

		$this->assertEquals( 'File 1', $out['products'][0]['files'][0]['name'] );
		$this->assertEquals( 'http://localhost/file1.jpg', $out['products'][0]['files'][0]['file'] );
		$this->assertEquals( 0, $out['products'][0]['files'][0]['condition'] );
		$this->assertEquals( 'File 2', $out['products'][0]['files'][1]['name'] );
		$this->assertEquals( 'http://localhost/file2.jpg', $out['products'][0]['files'][1]['file'] );
		$this->assertEquals( 'all', $out['products'][0]['files'][1]['condition'] );
	}


	public function test_get_products_notes() {
		$out = self::$api_output;
		$this->assertArrayHasKey( 'notes', $out['products'][0] );
		$this->assertEquals( 'Purchase Notes', $out['products'][0]['notes'] );
	}

	/**
	 * Unauthenticated single-product lookup of a draft must not disclose it.
	 *
	 * Actor modeled directly: override cleared and user_id set to 0 on the
	 * shared API instance, rather than routed through process_query().
	 */
	public function test_get_products_single_product_respects_post_status() {
		self::$api->override = false;
		self::$api->user_id  = 0;
		wp_set_current_user( 0 );

		$out = self::$api->get_products( array( 'product' => self::$draft_id ) );

		$this->assertArrayHasKey( 'error', $out );
		$this->assertArrayNotHasKey( 'products', $out );

		$json = wp_json_encode( $out );
		$this->assertStringNotContainsString( 'Unreleased Q4 Launch', $json );
		$this->assertStringNotContainsString( 'unreleased-q4-launch', $json );
		$this->assertStringNotContainsString( 'Do not publish yet', $json );
		$this->assertStringNotContainsString( 'draft', $json );
	}

	/**
	 * Unauthenticated single-product lookup of a private product must not
	 * disclose it, including its pricing tiers.
	 *
	 * Actor modeled directly: override cleared and user_id set to 0.
	 */
	public function test_get_products_single_product_hides_private_products() {
		self::$api->override = false;
		self::$api->user_id  = 0;
		wp_set_current_user( 0 );

		$out = self::$api->get_products( array( 'product' => self::$private_id ) );

		$this->assertArrayHasKey( 'error', $out );
		$this->assertArrayNotHasKey( 'products', $out );

		$json = wp_json_encode( $out );
		$this->assertStringNotContainsString( 'Internal Client Bundle', $json );
		$this->assertStringNotContainsString( 'private', $json );
		$this->assertStringNotContainsString( 'clientexclusivetier', $json );
	}

	/**
	 * A real but unreadable id and an id with no post at all must produce
	 * the same "not found" wording, so the endpoint cannot be used to
	 * confirm that a hidden product exists. The message itself is
	 * parameterized by the id the caller already supplied (`sprintf(
	 * 'Product %s not found!', $args['product'] )`), so the two responses
	 * can never be literally identical strings; what must match is that
	 * both take the same code path and wording, rather than one saying
	 * "not found" and the other something that only a real post triggers.
	 *
	 * Actor modeled directly: override cleared and user_id set to 0.
	 */
	public function test_get_products_error_does_not_confirm_the_id() {
		self::$api->override = false;
		self::$api->user_id  = 0;
		wp_set_current_user( 0 );

		$no_post_id  = self::$draft_id + 100000;
		$draft_out   = self::$api->get_products( array( 'product' => self::$draft_id ) );
		$no_post_out = self::$api->get_products( array( 'product' => $no_post_id ) );

		$this->assertArrayHasKey( 'error', $draft_out );
		$this->assertArrayHasKey( 'error', $no_post_out );

		// The queried id is echoed back into the message; normalize it out
		// before comparing the two responses for identical wording.
		$normalize = static function ( $message ) {
			return preg_replace( '/\d+/', '#', $message );
		};

		$this->assertSame( $normalize( $no_post_out['error'] ), $normalize( $draft_out['error'] ) );
	}

	/**
	 * Control: an unauthenticated caller must still be able to read a
	 * published product. Must pass before and after the fix.
	 *
	 * Actor modeled directly: override cleared and user_id set to 0.
	 */
	public function test_get_products_single_product_serves_published_products() {
		self::$api->override = false;
		self::$api->user_id  = 0;
		wp_set_current_user( 0 );

		$out = self::$api->get_products( array( 'product' => self::$post->ID ) );

		$this->assertArrayHasKey( 'products', $out );
		$this->assertEquals( 'Test Download', $out['products'][0]['info']['title'] );
	}

	/**
	 * Control: a caller who can read the draft (its author, an administrator)
	 * must still get the product back. Guards the fix against blocking
	 * legitimate authenticated reads.
	 *
	 * Actor modeled directly: override cleared, user_id set to the
	 * administrator who owns the draft.
	 */
	public function test_get_products_single_product_serves_a_caller_with_read_rights() {
		self::$api->override = false;
		self::$api->user_id  = self::$user_id;
		wp_set_current_user( self::$user_id );

		$out = self::$api->get_products( array( 'product' => self::$draft_id ) );

		$this->assertArrayHasKey( 'products', $out );
		$this->assertEquals( 'Unreleased Q4 Launch', $out['products'][0]['info']['title'] );
	}

	/**
	 * A key authenticated request carries no cookie session, so the gate has to evaluate
	 * the user who owns the API key rather than the absent current user.
	 *
	 * Actor modeled directly: override cleared, no current user, user_id set to the
	 * administrator who authored the draft.
	 */
	public function test_get_products_serves_a_key_authenticated_caller_with_no_session() {
		self::$api->override = false;
		self::$api->user_id  = self::$user_id;
		wp_set_current_user( 0 );

		// Fixture: only the key user leg of the gate can carry this test.
		$this->assertTrue( user_can( self::$user_id, 'read_post', self::$draft_id ) );
		$this->assertFalse( current_user_can( 'read_post', self::$draft_id ) );

		$out = self::$api->get_products( array( 'product' => self::$draft_id ) );

		$this->assertArrayHasKey( 'products', $out );
		$this->assertEquals( 'Unreleased Q4 Launch', $out['products'][0]['info']['title'] );
	}

	/**
	 * The key user leg is not a blanket allow: a key owner without read rights on the
	 * draft still gets the "not found" response.
	 *
	 * Actor modeled directly: override cleared, no current user, user_id set to a
	 * subscriber who neither authored the draft nor can edit downloads.
	 */
	public function test_get_products_denies_a_key_authenticated_caller_without_read_rights() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		self::$api->override = false;
		self::$api->user_id  = $subscriber;
		wp_set_current_user( 0 );

		// Fixture: the subscriber really cannot read the draft.
		$this->assertFalse( user_can( $subscriber, 'read_post', self::$draft_id ) );

		$out = self::$api->get_products( array( 'product' => self::$draft_id ) );

		$this->assertArrayHasKey( 'error', $out );
		$this->assertArrayNotHasKey( 'products', $out );
		$this->assertStringNotContainsString( 'Unreleased Q4 Launch', wp_json_encode( $out ) );
	}

	/**
	 * An internal caller that never ran request validation keeps its override, as it does
	 * for the other capability checks in the class.
	 *
	 * Actor modeled directly: override left set, no current user, user_id 0.
	 */
	public function test_get_products_serves_an_internal_caller_with_override() {
		self::$api->override = true;
		self::$api->user_id  = 0;
		wp_set_current_user( 0 );

		// Fixture: neither capability leg of the gate can carry this test.
		$this->assertFalse( user_can( 0, 'read_post', self::$draft_id ) );
		$this->assertFalse( current_user_can( 'read_post', self::$draft_id ) );

		$out = self::$api->get_products( array( 'product' => self::$draft_id ) );

		$this->assertArrayHasKey( 'products', $out );
		$this->assertEquals( 'Unreleased Q4 Launch', $out['products'][0]['info']['title'] );
	}

	/**
	 * EDD_API_V2 fully overrides get_products(), so its own copy of the gate is checked
	 * directly for the key user leg rather than only through the base class.
	 *
	 * Actor modeled directly on a fresh EDD_API_V2: override cleared, no current user,
	 * user_id set to the administrator who authored the draft.
	 */
	public function test_get_products_v2_serves_a_key_authenticated_caller_with_no_session() {
		$api           = new \EDD_API_V2();
		$api->override = false;
		$api->user_id  = self::$user_id;
		wp_set_current_user( 0 );

		// Fixture: only the key user leg of the gate can carry this test.
		$this->assertTrue( user_can( self::$user_id, 'read_post', self::$draft_id ) );
		$this->assertFalse( current_user_can( 'read_post', self::$draft_id ) );

		$out = $api->get_products( array( 'product' => self::$draft_id ) );

		$this->assertArrayHasKey( 'products', $out );
		$this->assertEquals( 'Unreleased Q4 Launch', $out['products'][0]['info']['title'] );
	}

	/**
	 * EDD_API_V2's own copy of the gate, internal caller: a fresh instance has never
	 * validated a request, so its override still applies.
	 */
	public function test_get_products_v2_serves_an_internal_caller_with_override() {
		$api          = new \EDD_API_V2();
		$api->user_id = 0;
		wp_set_current_user( 0 );

		// Fixture: a fresh instance is an internal caller, and neither capability leg holds.
		$this->assertTrue( $api->override );
		$this->assertFalse( user_can( 0, 'read_post', self::$draft_id ) );
		$this->assertFalse( current_user_can( 'read_post', self::$draft_id ) );

		$out = $api->get_products( array( 'product' => self::$draft_id ) );

		$this->assertArrayHasKey( 'products', $out );
		$this->assertEquals( 'Unreleased Q4 Launch', $out['products'][0]['info']['title'] );
	}

	/**
	 * Control: the collection query, unauthenticated, must only ever return
	 * published products. Passes today only as a side effect of get_posts()
	 * defaulting post_status to publish; this is what makes the explicit
	 * 'post_status' => 'publish' addition safe to rely on.
	 *
	 * Actor modeled directly: override cleared and user_id set to 0.
	 */
	public function test_get_products_collection_pins_published_status() {
		self::$api->override = false;
		self::$api->user_id  = 0;
		wp_set_current_user( 0 );

		$out = self::$api->get_products();

		$this->assertArrayHasKey( 'products', $out );

		$ids = array();
		foreach ( $out['products'] as $product ) {
			$this->assertEquals( 'publish', $product['info']['status'] );
			$ids[] = $product['info']['id'];
		}

		$this->assertNotContains( self::$draft_id, $ids );
		$this->assertNotContains( self::$private_id, $ids );
	}

	/**
	 * The status gate must apply through the public request path for both
	 * shipped API versions. EDD_API_V1 inherits get_products() from the base
	 * class untouched, but EDD_API_V2 fully overrides it with its own
	 * single-product branch, so both loci need the fix for this to pass.
	 *
	 * Actor modeled via process_query(), as the class's other version tests do.
	 */
	public function test_get_products_status_gate_applies_to_v1_and_v2() {
		global $wp_query;

		wp_set_current_user( 0 );

		foreach ( array( 'v1', 'v2' ) as $version ) {
			$wp_query->query_vars['edd-api'] = $version . '/products';
			$wp_query->query_vars['product'] = self::$draft_id;
			unset( $wp_query->query_vars['key'] );
			unset( $wp_query->query_vars['token'] );

			try {
				self::$api->process_query();
			} catch ( \WPDieException $e ) {}

			$out  = self::$api->get_output();
			$json = wp_json_encode( $out );

			// A bail before dispatch would leave an empty array, which the absence check below
			// would satisfy without the gate ever running.
			$this->assertArrayHasKey( 'error', $out, "Nothing was dispatched for {$version}." );
			$this->assertStringContainsString(
				(string) self::$draft_id,
				$out['error'],
				"The refusal for {$version} has to name the product, so it is the status gate answering and not authentication."
			);

			$this->assertStringNotContainsString( 'Unreleased Q4 Launch', $json, "Draft title leaked via {$version}" );
		}
	}

	/**
	 * Control, EDD_API_V2's own collection branch: unauthenticated, no
	 * `product` argument, every returned status is publish. EDD_API_V2
	 * duplicates the collection query rather than inheriting it, so this is
	 * checked directly against that class, not just through process_query().
	 *
	 * Actor modeled directly: override cleared and user_id set to 0.
	 */
	public function test_get_products_v2_collection_pins_published_status() {
		$api           = new \EDD_API_V2();
		$api->override = false;
		$api->user_id  = 0;
		wp_set_current_user( 0 );

		$out = $api->get_products( array( 'order' => 'DESC', 'orderby' => 'date' ) );

		$this->assertArrayHasKey( 'products', $out );

		$ids = array();
		foreach ( $out['products'] as $product ) {
			$this->assertEquals( 'publish', $product['info']['status'] );
			$ids[] = $product['info']['id'];
		}

		$this->assertNotContains( self::$draft_id, $ids );
		$this->assertNotContains( self::$private_id, $ids );
	}

	public function test_get_recent_sales() {
		$out = self::$api_output_sales;
		$this->assertArrayHasKey( 'sales', $out );
		$this->assertArrayHasKey( 'ID', $out['sales'][0] );
		$this->assertArrayHasKey( 'key', $out['sales'][0] );
		$this->assertArrayHasKey( 'subtotal', $out['sales'][0] );
		$this->assertArrayHasKey( 'tax', $out['sales'][0] );
		$this->assertArrayHasKey( 'fees', $out['sales'][0] );
		$this->assertArrayHasKey( 'total', $out['sales'][0] );
		$this->assertArrayHasKey( 'gateway', $out['sales'][0] );
		$this->assertArrayHasKey( 'email', $out['sales'][0] );
		$this->assertArrayHasKey( 'date', $out['sales'][0] );
		$this->assertArrayHasKey( 'products', $out['sales'][0] );
		$this->assertArrayHasKey( 'name', $out['sales'][0]['products'][0] );
		$this->assertArrayHasKey( 'price', $out['sales'][0]['products'][0] );
		$this->assertArrayHasKey( 'price_name', $out['sales'][0]['products'][0] );

		$this->assertEquals( 100.00, $out['sales'][0]['subtotal'] );
		$this->assertEquals( 0, $out['sales'][0]['tax'] );
		$this->assertEquals( 100.00, $out['sales'][0]['total'] );
		$this->assertEquals( '', $out['sales'][0]['gateway'] );
		$this->assertEquals( 'admin@example.org', $out['sales'][0]['email'] );
		$this->assertEquals( 'Test Download', $out['sales'][0]['products'][0]['name'] );
		$this->assertEquals( 100, $out['sales'][0]['products'][0]['price'] );
		$this->assertEquals( 'Advanced', $out['sales'][0]['products'][0]['price_name'] );
	}

	public function test_get_recent_sales_invalid_payment_id() {
		global $wp_query;
		$wp_query->query_vars['id'] = 0;
		$recent_sales               = self::$api->get_recent_sales();

		$this->assertEquals( 0, $recent_sales['sales'][0]['ID'] );
	}

	public function test_get_customers() {
		try {
			$out = EDD()->api->get_customers();

			$this->assertArrayHasKey( 'customers', $out );
			$this->assertArrayHasKey( 'info', $out['customers'][0] );
			$this->assertArrayHasKey( 'id', $out['customers'][0]['info'] );
			$this->assertArrayHasKey( 'username', $out['customers'][0]['info'] );
			$this->assertArrayHasKey( 'display_name', $out['customers'][0]['info'] );
			$this->assertArrayHasKey( 'first_name', $out['customers'][0]['info'] );
			$this->assertArrayHasKey( 'last_name', $out['customers'][0]['info'] );
			$this->assertArrayHasKey( 'email', $out['customers'][0]['info'] );
			$this->assertArrayHasKey( 'stats', $out['customers'][0] );
			$this->assertArrayHasKey( 'total_purchases', $out['customers'][0]['stats'] );
			$this->assertArrayHasKey( 'total_spent', $out['customers'][0]['stats'] );
			$this->assertArrayHasKey( 'total_downloads', $out['customers'][0]['stats'] );

			$this->assertEquals( 1, $out['customers'][0]['info']['id'] );
			$this->assertEquals( 'admin', $out['customers'][0]['info']['username'] );
			$this->assertEquals( 'admin@example.org', $out['customers'][0]['info']['email'] );
			$this->assertEquals( 1, $out['customers'][0]['stats']['total_purchases'] );
			$this->assertEquals( 100.0, $out['customers'][0]['stats']['total_spent'] );
			$this->assertEquals( 1, $out['customers'][0]['stats']['total_downloads'] );
		} catch ( \WPDieException $e ) {}
	}

	public function test_missing_auth() {
		global $wp_query;

		$wp_query->query_vars['key']      = '';
		$wp_query->query_vars['token']    = '';
		$wp_query->query_vars['edd-api'] = 'sales';

		try {
			self::$api->process_query();
		} catch ( \WPDieException $e ) {}

		$out = self::$api->get_output();

		$this->assertArrayHasKey( 'error', $out );
		$this->assertEquals( 'You must specify both a token and API key!', $out['error'] );
	}

	public function test_invalid_auth() {

		global $wp_query;

		$_POST['edd_set_api_key'] = 1;
		EDD()->api->update_key( self::$user_id );

		$wp_query->query_vars['key']     = self::$api->get_user_public_key( self::$user_id );
		$wp_query->query_vars['token']   = 'bad-token-val';
		$wp_query->query_vars['edd-api'] = 'sales';

		try {
			self::$api->process_query();
		} catch ( \WPDieException $e ) {}

		$out = self::$api->get_output();

		$this->assertArrayHasKey( 'error', $out );
		$this->assertEquals( 'Your request could not be authenticated!', $out['error'] );
	}

	public function test_invalid_key() {
		global $wp_query;

		$_POST['edd_set_api_key'] = 1;
		EDD()->api->update_key( self::$user_id );
		$wp_query->query_vars['key']   = 'bad-key-val';
		$wp_query->query_vars['token'] = hash( 'md5', get_user_meta( self::$user_id, 'edd_user_secret_key', true ) . get_user_meta( self::$user_id, 'edd_user_public_key', true ) );

		$wp_query->query_vars['edd-api'] = 'sales';

		try {
			self::$api->process_query();
		} catch ( \WPDieException $e ) {}

		$out = self::$api->get_output();

		$this->assertArrayHasKey( 'error', $out );
		$this->assertEquals( 'Invalid API key!', $out['error'] );
	}

	public function test_info() {
		$out = EDD()->api->get_info();

		$this->assertArrayHasKey( 'info', $out );
		$this->assertArrayHasKey( 'site', $out['info'] );
		$this->assertArrayHasKey( 'currency', $out['info']['site'] );
		$this->assertArrayHasKey( 'currency_position', $out['info']['site'] );
		$this->assertArrayHasKey( 'decimal_separator', $out['info']['site'] );
		$this->assertArrayHasKey( 'thousands_separator', $out['info']['site'] );
		$this->assertArrayNotHasKey( 'integrations', $out['info'] ); // By default we shouldn't have any integrations

		$this->assertArrayHasKey( 'permissions', $out['info'] );
		$this->assertTrue( $out['info']['permissions']['view_shop_reports'] );
		$this->assertTrue( $out['info']['permissions']['view_shop_sensitive_data'] );
		$this->assertTrue( $out['info']['permissions']['manage_shop_discounts'] );

	}

	public function test_process_query() {
		global $wp_query;

		$_POST['edd_set_api_key'] = 1;
		self::$api->update_key( self::$user_id );

		$wp_query->query_vars['edd-api'] = 'products';
		$wp_query->query_vars['key']     = get_user_meta( self::$user_id, 'edd_user_public_key', true );
		$wp_query->query_vars['token']   = hash( 'md5', get_user_meta( self::$user_id, 'edd_user_secret_key', true ) . get_user_meta( self::$user_id, 'edd_user_public_key', true ) );

		try {
			self::$api->process_query();
		} catch ( \WPDieException $e ) {}

		$out = self::$api->get_output();

		$this->assertArrayHasKey( 'info', $out['products'][0] );
		$this->assertArrayHasKey( 'id', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'slug', $out['products'][0]['info'] );
		$this->assertEquals( 'test-download', $out['products'][0]['info']['slug'] );
		$this->assertArrayHasKey( 'title', $out['products'][0]['info'] );
		$this->assertEquals( 'Test Download', $out['products'][0]['info']['title'] );
		$this->assertArrayHasKey( 'create_date', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'modified_date', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'status', $out['products'][0]['info'] );
		$this->assertEquals( 'publish', $out['products'][0]['info']['status'] );
		$this->assertArrayHasKey( 'link', $out['products'][0]['info'] );
		$this->assertArrayHasKey( 'content', $out['products'][0]['info'] );
		$this->assertEquals( self::$post->post_content, $out['products'][0]['info']['content'] );
		$this->assertArrayHasKey( 'thumbnail', $out['products'][0]['info'] );

		$this->assertArrayHasKey( 'stats', $out['products'][0] );
		$this->assertArrayHasKey( 'total', $out['products'][0]['stats'] );
		$this->assertArrayHasKey( 'sales', $out['products'][0]['stats']['total'] );
		$this->assertEquals( 1, $out['products'][0]['stats']['total']['sales'] );
		$this->assertArrayHasKey( 'earnings', $out['products'][0]['stats']['total'] );
		$this->assertEquals( 100.00, $out['products'][0]['stats']['total']['earnings'] );
		$this->assertArrayHasKey( 'monthly_average', $out['products'][0]['stats'] );
		$this->assertArrayHasKey( 'sales', $out['products'][0]['stats']['monthly_average'] );
		$this->assertEquals( 1, $out['products'][0]['stats']['monthly_average']['sales'] );
		$this->assertArrayHasKey( 'earnings', $out['products'][0]['stats']['monthly_average'] );
		$this->assertEquals( 100.00, $out['products'][0]['stats']['monthly_average']['earnings'] );

		$this->assertArrayHasKey( 'pricing', $out['products'][0] );
		$this->assertArrayHasKey( 'simple', $out['products'][0]['pricing'] );
		$this->assertEquals( 20, $out['products'][0]['pricing']['simple'] );
		$this->assertArrayHasKey( 'advanced', $out['products'][0]['pricing'] );
		$this->assertEquals( 100, $out['products'][0]['pricing']['advanced'] );

		$this->assertArrayHasKey( 'files', $out['products'][0] );
		$this->assertArrayHasKey( 'name', $out['products'][0]['files'][0] );
		$this->assertArrayHasKey( 'file', $out['products'][0]['files'][0] );
		$this->assertArrayHasKey( 'condition', $out['products'][0]['files'][0] );
		$this->assertArrayHasKey( 'name', $out['products'][0]['files'][1] );
		$this->assertArrayHasKey( 'file', $out['products'][0]['files'][1] );
		$this->assertArrayHasKey( 'condition', $out['products'][0]['files'][1] );
		$this->assertEquals( 'File 1', $out['products'][0]['files'][0]['name'] );
		$this->assertEquals( 'http://localhost/file1.jpg', $out['products'][0]['files'][0]['file'] );
		$this->assertEquals( 0, $out['products'][0]['files'][0]['condition'] );
		$this->assertEquals( 'File 2', $out['products'][0]['files'][1]['name'] );
		$this->assertEquals( 'http://localhost/file2.jpg', $out['products'][0]['files'][1]['file'] );
		$this->assertEquals( 'all', $out['products'][0]['files'][1]['condition'] );

		$this->assertArrayHasKey( 'notes', $out['products'][0] );
		$this->assertEquals( 'Purchase Notes', $out['products'][0]['notes'] );
	}

	/**
	 * Ensures the correct discount amount is included in the recent sales endpoint.
	 *
	 * @link https://github.com/easydigitaldownloads/easy-digital-downloads/issues/8246
	 *
	 * @covers EDD_API_V2::get_recent_sales
	 * @covers EDD_Cart::get_item_discount_amount
	 */
	public function test_recent_sales_contains_correct_discount_amount() {
		// Create a 20% off discount code with code `20OFF`.
		Helpers\EDD_Helper_Discount::create_simple_percent_discount();

		// Update the payment information.
		$payment                    = edd_get_payment( self::$payment_id );
		$payment->discounted_amount = 20;
		$payment->total             = 80;
		$payment->discounts         = '20OFF';
		$payment->save();

		$api_v2       = new \EDD_API_V2();
		$sales_output = $api_v2->get_recent_sales();

		$this->assertEquals( 20, $sales_output['sales'][0]['discounts']['20OFF'] );
	}

	public function test_file_download_logs_generic() {
		try {
			$out = EDD()->api->get_download_logs();

			$this->assertArrayHasKey( 'download_logs', $out );

			$logs = $out['download_logs'];

			$this->assertEquals( 1, count( $logs ) );

			$this->assertArrayHasKey( 'ID', $logs[0] );
			$this->assertArrayHasKey( 'user_id', $logs[0] );
			$this->assertArrayHasKey( 'product_id', $logs[0] );
			$this->assertArrayHasKey( 'product_name', $logs[0] );
			$this->assertArrayHasKey( 'customer_id', $logs[0] );
			$this->assertArrayHasKey( 'payment_id', $logs[0] );
			$this->assertArrayHasKey( 'file', $logs[0] );
			$this->assertArrayHasKey( 'ip', $logs[0] );
			$this->assertArrayHasKey( 'date', $logs[0] );

		} catch ( \WPDieException $e ) {}
	}

	public function test_file_download_logs_by_customer_id() {
		try {
			$out = EDD()->api->get_download_logs( self::$payment->customer_id );

			$this->assertArrayHasKey( 'download_logs', $out );

			$logs = $out['download_logs'];

			$this->assertEquals( 1, count( $logs ) );

			$this->assertArrayHasKey( 'ID', $logs[0] );
			$this->assertArrayHasKey( 'user_id', $logs[0] );
			$this->assertArrayHasKey( 'product_id', $logs[0] );
			$this->assertArrayHasKey( 'product_name', $logs[0] );
			$this->assertArrayHasKey( 'customer_id', $logs[0] );
			$this->assertArrayHasKey( 'payment_id', $logs[0] );
			$this->assertArrayHasKey( 'file', $logs[0] );
			$this->assertArrayHasKey( 'ip', $logs[0] );
			$this->assertArrayHasKey( 'date', $logs[0] );

		} catch ( \WPDieException $e ) {}
	}

	public function test_file_download_logs_by_customer_email() {
		try {
			$out = EDD()->api->get_download_logs( self::$payment->email );

			$this->assertArrayHasKey( 'download_logs', $out );

			$logs = $out['download_logs'];

			$this->assertEquals( 1, count( $logs ) );

			$this->assertArrayHasKey( 'ID', $logs[0] );
			$this->assertArrayHasKey( 'user_id', $logs[0] );
			$this->assertArrayHasKey( 'product_id', $logs[0] );
			$this->assertArrayHasKey( 'product_name', $logs[0] );
			$this->assertArrayHasKey( 'customer_id', $logs[0] );
			$this->assertArrayHasKey( 'payment_id', $logs[0] );
			$this->assertArrayHasKey( 'file', $logs[0] );
			$this->assertArrayHasKey( 'ip', $logs[0] );
			$this->assertArrayHasKey( 'date', $logs[0] );

		} catch ( \WPDieException $e ) {}
	}

	public function test_file_download_logs_by_invalid_customer_id() {
		try {
			$out = EDD()->api->get_download_logs( 99999 );

			$this->assertArrayHasKey( 'error', $out );
			$this->assertSame( 'No download logs found!', $out['error'] );

		} catch ( \WPDieException $e ) {}
	}

}
