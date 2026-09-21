<?php
/**
 * Tests for EDD\Database\Table::reset().
 *
 * The per-class test teardown uses reset() in place of truncate(), so the
 * auto-increment restart it performs is what keeps factory-generated IDs
 * predictable from one test class to the next.
 *
 * @package     EDD\Tests\Database
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Database;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * @coversDefaultClass \EDD\Database\Table
 */
class TableReset extends EDD_UnitTestCase {

	/**
	 * Returns the customers table interface.
	 *
	 * @return \EDD\Database\Table
	 */
	private function table() {
		return edd_get_component_interface( 'customer', 'table' );
	}

	/**
	 * Adds a customer and returns its ID.
	 *
	 * @return int
	 */
	private function add_customer() {
		return (int) edd_add_customer(
			array(
				'email' => 'reset_' . uniqid( '', true ) . '@edd.test',
			)
		);
	}

	/**
	 * reset() removes every row in the table.
	 *
	 * @covers ::reset
	 */
	public function test_reset_empties_the_table() {
		$this->add_customer();
		$this->add_customer();

		// Assert the fixture before asserting the behavior: without rows to
		// remove, an empty table would satisfy the assertion below on its own.
		$this->assertGreaterThan( 0, $this->table()->count(), 'Fixture: the table should have rows to delete.' );

		$this->table()->reset();

		$this->assertSame( 0, (int) $this->table()->count() );
	}

	/**
	 * reset() restarts the auto-increment counter, so the next row inserted
	 * takes the first ID again. A plain DELETE leaves the counter advanced,
	 * which is what this asserts against.
	 *
	 * @covers ::reset
	 */
	public function test_reset_restarts_the_auto_increment_counter() {
		// Advance the counter past its starting value.
		$this->add_customer();
		$this->add_customer();
		$this->add_customer();

		$this->assertGreaterThan( 1, $this->add_customer(), 'Fixture: the counter should be advanced before reset.' );

		$this->table()->reset();

		$this->assertSame( 1, $this->add_customer() );
	}

	/**
	 * reset() reports success.
	 *
	 * @covers ::reset
	 */
	public function test_reset_returns_true() {
		$this->add_customer();

		$this->assertTrue( $this->table()->reset() );
	}
}
