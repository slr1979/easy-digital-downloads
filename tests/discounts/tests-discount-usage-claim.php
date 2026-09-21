<?php
/**
 * Discount usage claim tests.
 *
 * A discount's usage cap was enforced by reading the count, incrementing it in PHP and writing
 * it back, so two redemptions could both read the same value and both write a claim the cap
 * only had room for once. These tests assert the increment refuses once the cap is reached, and
 * that reaching it still deactivates the code.
 *
 * What the suite cannot show is two requests racing: PHPUnit runs in one process, so there is
 * no concurrency here to observe. What it can show is that the cap is enforced by the write
 * itself rather than by a separate earlier read, which is what makes the race survivable.
 *
 * @package     EDD\Tests\Discounts
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Discounts;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\EDD_Helper_Discount;

/**
 * @group edd_discounts
 */
class DiscountUsageClaim extends EDD_UnitTestCase {

	/**
	 * Two objects for the same discount must not overwrite each other's claim.
	 */
	public function test_a_second_object_does_not_overwrite_the_first_claim() {
		$discount = $this->discount_at( 0, 0 );

		$first  = edd_get_discount( $discount->id );
		$second = edd_get_discount( $discount->id );

		$first->increase_usage();
		$second->increase_usage();

		$this->assertSame(
			2,
			(int) edd_get_discount( $discount->id )->use_count,
			'Both claims must be counted.'
		);
	}

	/**
	 * A discount already at its cap must refuse another claim.
	 */
	public function test_a_discount_at_its_cap_refuses_another_use() {
		$discount = $this->discount_at( 1, 1 );

		$this->assertFalse( $discount->increase_usage(), 'The claim must be refused.' );
		$this->assertSame(
			1,
			(int) edd_get_discount( $discount->id )->use_count,
			'The stored count must not move past the cap.'
		);
	}

	/**
	 * The last available use must still be claimable.
	 *
	 * The control: without it, refusing every claim would satisfy the test above.
	 */
	public function test_the_last_available_use_is_claimable() {
		$discount = $this->discount_at( 2, 1 );

		$this->assertSame( 2, (int) $discount->increase_usage(), 'The claim must succeed and report the new count.' );
		$this->assertSame(
			2,
			(int) edd_get_discount( $discount->id )->use_count,
			'The stored count must be incremented exactly once.'
		);
	}

	/**
	 * Reaching the cap must still deactivate the code.
	 *
	 * This is existing behavior and the only reason a sequential second attempt is refused
	 * today, so it is asserted to keep the new claim from replacing it.
	 */
	public function test_reaching_the_cap_deactivates_the_discount() {
		$discount = $this->discount_at( 2, 1 );

		$discount->increase_usage();

		$this->assertSame(
			'inactive',
			edd_get_discount( $discount->id )->status,
			'A discount which has reached its cap must be inactive.'
		);
	}

	/**
	 * The claiming object's own status must reflect the deactivation, not only a re-fetch.
	 *
	 * edd_update_discount() writes the new status to the row but does not touch the object
	 * that called increase_usage(), so this is the one place that has to update itself.
	 */
	public function test_reaching_the_cap_updates_the_same_objects_status() {
		$discount = $this->discount_at( 2, 1 );

		$discount->increase_usage();

		$this->assertSame( 'inactive', $discount->status, "The calling object's own status must be updated." );
	}

	/**
	 * A discount below its cap must stay active.
	 */
	public function test_a_discount_below_its_cap_stays_active() {
		$discount = $this->discount_at( 5, 1 );

		$discount->increase_usage();

		$this->assertSame( 'active', edd_get_discount( $discount->id )->status, 'It must remain usable.' );
	}

	/**
	 * A discount with no cap must keep incrementing.
	 */
	public function test_an_uncapped_discount_keeps_incrementing() {
		$discount = $this->discount_at( 0, 7 );

		$this->assertSame( 8, (int) $discount->increase_usage(), 'An uncapped discount must increment.' );
		$this->assertSame( 'active', edd_get_discount( $discount->id )->status, 'It must remain active.' );
	}

	/**
	 * The function the order flow calls must report a refused claim.
	 *
	 * `edd_complete_purchase()` calls this, so it is the surface that has to be able to tell
	 * that a use was not available.
	 */
	public function test_the_public_helper_reports_a_refused_claim() {
		$discount = $this->discount_at( 1, 1 );

		$this->assertFalse(
			edd_increase_discount_usage( $discount->code ),
			'The helper must report that the claim was refused.'
		);
	}
	/**
	 * Creates a discount with a usage cap and a starting count.
	 *
	 * @param int $max_uses  The cap. Zero means unlimited.
	 * @param int $use_count The count to start from.
	 * @return \EDD_Discount
	 */
	private function discount_at( $max_uses, $use_count ) {
		$discount_id = EDD_Helper_Discount::create_simple_percent_discount();
		$discount    = edd_get_discount( $discount_id );

		$discount->update(
			array(
				'max_uses'  => $max_uses,
				'use_count' => $use_count,
				'status'    => 'active',
			)
		);

		return edd_get_discount( $discount_id );
	}
}
