<?php
namespace EDD\Tests\Reports;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Reports\Filters\RequestCache;

/**
 * Tests for the reports filters request cache.
 *
 * @group edd_reports
 * @group edd_reports_functions
 *
 * @coversDefaultClass \EDD\Reports\Filters\RequestCache
 */
class Filters_Request_Cache extends EDD_UnitTestCase {

	public function test_key_is_stable_for_an_unchanged_request() {
		$_GET['range'] = 'last_month';

		$this->assertSame( RequestCache::key( 'scope' ), RequestCache::key( 'scope' ) );
	}

	public function test_key_changes_when_a_persisted_filter_changes() {
		$_GET['range'] = 'last_month';
		$last_month    = RequestCache::key( 'scope' );

		$_GET['range'] = 'this_year';

		$this->assertNotSame( $last_month, RequestCache::key( 'scope' ) );
	}

	public function test_key_changes_when_a_registered_filter_changes() {
		$_GET['gateways'] = 'stripe';
		$stripe           = RequestCache::key( 'scope' );

		$_GET['gateways'] = 'paypal_commerce';

		$this->assertNotSame( $stripe, RequestCache::key( 'scope' ) );
	}

	public function test_key_changes_when_the_arguments_change() {
		$this->assertNotSame(
			RequestCache::key( 'scope', array( 'today' ) ),
			RequestCache::key( 'scope', array( 'yesterday' ) )
		);
	}

	public function test_stored_value_is_returned_for_the_same_key() {
		RequestCache::set( 'key', 'value' );

		$this->assertSame( 'value', RequestCache::get( 'key' ) );
	}

	public function test_stored_false_is_not_reported_as_a_miss() {
		RequestCache::set( 'key', false );

		$this->assertFalse( RequestCache::get( 'key' ) );
	}

	public function test_unset_key_returns_null() {
		$this->assertNull( RequestCache::get( 'never-set' ) );
	}

	public function test_reset_clears_stored_values() {
		RequestCache::set( 'key', 'value' );
		RequestCache::reset();

		$this->assertNull( RequestCache::get( 'key' ) );
	}

	public function test_reset_clears_the_signature_keys() {
		$before = RequestCache::key( 'scope' );

		add_filter(
			'edd_report_filters',
			function ( $filters ) {
				$filters['fake_filter'] = array( 'label' => 'Fake' );

				return $filters;
			}
		);
		RequestCache::reset();

		$_GET['fake_filter'] = 'yes';

		$this->assertNotSame( $before, RequestCache::key( 'scope' ) );
	}

	public function test_clone_dates_returns_copies_of_the_date_objects() {
		$dates  = array(
			'start' => EDD()->utils->date(),
			'end'   => EDD()->utils->date(),
			'range' => 'today',
		);
		$cloned = RequestCache::clone_dates( $dates );

		$this->assertNotSame( $dates['start'], $cloned['start'] );
		$this->assertSame( $dates['start']->format( 'c' ), $cloned['start']->format( 'c' ) );
		$this->assertSame( 'today', $cloned['range'] );
	}

	public function test_clone_dates_leaves_date_strings_alone() {
		$dates = RequestCache::clone_dates(
			array(
				'start' => '2024-03-01 00:00:00',
				'end'   => '2024-03-01 23:59:59',
			)
		);

		$this->assertSame( '2024-03-01 00:00:00', $dates['start'] );
	}
}
