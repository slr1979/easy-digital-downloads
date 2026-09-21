<?php
/**
 * Tests for the EDD store configuration abilities.
 *
 * @package     EDD\Tests\Abilities
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Abilities;

use EDD\Abilities\Pagination;
use EDD\Abilities\Store\TaxRates;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Store ability tests.
 *
 * @since 3.7.1
 */
class Store extends EDD_UnitTestCase {

	/**
	 * Set the current user to the site administrator, who holds all shop capabilities.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( 1 );
	}

	public function test_tax_rates_returns_the_shared_pagination_envelope() {
		$result = ( new TaxRates() )->execute( array() );

		$this->assertIsArray( $result );
		foreach ( array( 'enabled', 'rates', 'total', 'limit', 'offset', 'has_more' ) as $key ) {
			$this->assertArrayHasKey( $key, $result, "The tax rate response has no {$key} key." );
		}

		$this->assertSame( Pagination::DEFAULT_LIMIT, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
	}

	/**
	 * Retired rates are left out until the status asks for them.
	 */
	public function test_tax_rates_returns_active_rates_by_default() {
		$before_active   = ( new TaxRates() )->execute( array() )['total'];
		$before_inactive = ( new TaxRates() )->execute( array( 'status' => 'inactive' ) )['total'];

		$this->add_rates();

		// The fixture: two of the three rates added are active, because adding
		// the second US rate demoted the first.
		$active = ( new TaxRates() )->execute( array() );
		$this->assertSame( $before_active + 2, $active['total'] );

		foreach ( $active['rates'] as $rate ) {
			$this->assertSame( 'active', $rate['status'] );
		}

		$inactive = ( new TaxRates() )->execute( array( 'status' => 'inactive' ) );
		$this->assertSame( $before_inactive + 1, $inactive['total'] );

		$all = ( new TaxRates() )->execute( array( 'status' => 'all' ) );
		$this->assertSame( $active['total'] + $inactive['total'], $all['total'] );
	}

	public function test_tax_rates_pages_through_its_results() {
		$before = ( new TaxRates() )->execute( array( 'status' => 'all' ) )['total'];

		$this->add_rates();

		// The fixture: the three rates added are all there, whatever the store
		// already held, so the offset below really is the last page.
		$all = ( new TaxRates() )->execute( array( 'status' => 'all' ) );
		$this->assertSame( $before + 3, $all['total'] );

		$first = ( new TaxRates() )->execute(
			array(
				'status' => 'all',
				'limit'  => 1,
			)
		);

		$this->assertCount( 1, $first['rates'] );
		$this->assertSame( 1, $first['limit'] );
		$this->assertSame( $all['total'], $first['total'] );
		$this->assertTrue( $first['has_more'] );

		$last = ( new TaxRates() )->execute(
			array(
				'status' => 'all',
				'limit'  => 1,
				'offset' => $all['total'] - 1,
			)
		);

		$this->assertSame( $all['total'] - 1, $last['offset'] );
		$this->assertFalse( $last['has_more'] );
	}

	/**
	 * Adds three tax rates, one of which the next US rate demotes to inactive.
	 *
	 * @return void
	 */
	private function add_rates(): void {
		edd_add_tax_rate(
			array(
				'country' => 'US',
				'amount'  => 5,
			)
		);
		edd_add_tax_rate(
			array(
				'country' => 'US',
				'amount'  => 7,
			)
		);
		edd_add_tax_rate(
			array(
				'country' => 'GB',
				'amount'  => 20,
			)
		);
	}
}
