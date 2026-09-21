<?php
/**
 * Tests for the Discount Usage Chart Endpoint
 *
 * @package   EDD\Tests\Reports\Endpoints
 * @copyright Copyright (c) 2026, Easy Digital Downloads, LLC
 * @license   https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since     3.7.1
 */

namespace EDD\Tests\Reports\Endpoints;

use EDD\Reports\Endpoints\Charts\Discounts;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests for the Discount Usage chart endpoint.
 *
 * @group edd_reports
 * @group edd_endpoints
 * @group edd_discounts
 *
 * @since 3.7.1
 * @covers \EDD\Reports\Endpoints\Charts\Discounts
 */
class ChartDiscounts extends EDD_UnitTestCase {

	/**
	 * Request keys this class writes, with their original state.
	 *
	 * @since 3.7.1
	 * @var array
	 */
	private $original_request = array();

	/**
	 * Set up each test.
	 *
	 * @since 3.7.1
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'discounts', 'range' ) as $key ) {
			$this->original_request[ $key ] = array_key_exists( $key, $_GET ) ? $_GET[ $key ] : null;
		}
	}

	/**
	 * Tear down each test.
	 *
	 * @since 3.7.1
	 */
	public function tear_down() {
		foreach ( $this->original_request as $key => $value ) {
			if ( is_null( $value ) ) {
				unset( $_GET[ $key ] );
				continue;
			}

			$_GET[ $key ] = $value;
		}

		parent::tear_down();
	}

	/**
	 * Every character a code may contain is matched as part of the value.
	 *
	 * @since 3.7.1
	 */
	public function test_code_containing_a_quote_is_matched_as_a_value() {
		global $wpdb;

		$code        = "spring'sale";
		$discount_id = $this->seed_discount( $code );
		$this->seed_adjustment( $code );

		$_GET['discounts'] = $discount_id;

		$results = $this->get_query_results();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertCount( 1, $results );
		$this->assertSame( 1, (int) $results[0]->value );
	}

	/**
	 * An ordinary code still returns its usage count.
	 *
	 * @since 3.7.1
	 */
	public function test_alphanumeric_code_returns_its_usage_count() {
		global $wpdb;

		$code        = 'SPRINGSALE';
		$discount_id = $this->seed_discount( $code );
		$this->seed_adjustment( $code );

		$_GET['discounts'] = $discount_id;

		$results = $this->get_query_results();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertCount( 1, $results );
		$this->assertSame( 1, (int) $results[0]->value );
	}

	/**
	 * Selecting every discount leaves the clause off and counts all codes.
	 *
	 * @since 3.7.1
	 */
	public function test_all_discounts_counts_every_code() {
		$quoted_code = "summer'sale";
		$plain_code  = 'SUMMERSALE';

		$this->seed_discount( $quoted_code );
		$this->seed_discount( $plain_code );
		$this->seed_adjustment( $quoted_code );
		$this->seed_adjustment( $plain_code );

		$_GET['discounts'] = 'all';

		$results = $this->get_query_results();

		$this->assertCount( 1, $results );
		$this->assertSame( 2, (int) $results[0]->value );
	}

	/**
	 * Creates a discount and asserts the code was stored verbatim.
	 *
	 * @since 3.7.1
	 *
	 * @param string $code The discount code to store.
	 * @return int The discount ID.
	 */
	private function seed_discount( string $code ): int {
		$discount_id = edd_add_discount(
			array(
				'name'         => 'Chart fixture ' . $code,
				'code'         => $code,
				'status'       => 'active',
				'type'         => 'percent',
				'amount'       => 10,
				'product_reqs' => array(),
			)
		);

		$this->assertNotEmpty( $discount_id, 'The discount fixture was not created.' );

		$discount = edd_get_discount( $discount_id );

		$this->assertSame( $code, $discount->code, 'The write path altered the stored code, so this test would prove nothing.' );

		return (int) $discount_id;
	}

	/**
	 * Creates a discount adjustment in range and asserts it carries the code.
	 *
	 * @since 3.7.1
	 *
	 * @param string $code The code the adjustment records.
	 * @return int The adjustment ID.
	 */
	private function seed_adjustment( string $code ): int {
		$adjustment_id = edd_add_order_adjustment(
			array(
				'object_id'    => 1,
				'object_type'  => 'order',
				'type'         => 'discount',
				'description'  => $code,
				'subtotal'     => 10,
				'total'        => 10,
				'date_created' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->assertNotEmpty( $adjustment_id, 'The adjustment fixture was not created.' );

		$adjustment = edd_get_order_adjustment( $adjustment_id );

		$this->assertSame( $code, $adjustment->description, 'The adjustment row does not carry the code, so this test would prove nothing.' );

		return (int) $adjustment_id;
	}

	/**
	 * Runs the chart's query.
	 *
	 * @since 3.7.1
	 *
	 * @return array
	 */
	private function get_query_results(): array {
		$chart = new Discounts( $this->createMock( \EDD\Reports\Data\Report_Registry::class ) );

		$method = new \ReflectionMethod( $chart, 'get_query_results' );
		$method->setAccessible( true );

		return $method->invoke( $chart );
	}
}
