<?php
/**
 * Customer Addresses list table tests.
 */

namespace EDD\Tests\Customers\Admin;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Tests the address column on the Customers -> Physical Addresses screen.
 *
 * The column prints stored address values, which can still hold markup on rows written
 * before those values were sanitized on the way in.
 *
 * @group edd_customers
 */
class CustomerAddressesTable extends EDD_UnitTestCase {

	/**
	 * The list table under test.
	 *
	 * @var \EDD_Customer_Addresses_Table
	 */
	private static $table;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once EDD_PLUGIN_DIR . 'includes/admin/customers/class-customer-addresses-table.php';

		self::$table = new \EDD_Customer_Addresses_Table();
	}

	/**
	 * A stored address must be printed as text, not as markup.
	 */
	public function test_addresses_table_escapes_address_fields() {
		$output = self::$table->column_address( $this->address_row_with_markup() );

		$this->assertNotEmpty( $output, 'The column must render something before it can be asserted on.' );

		$this->assertStringNotContainsString( '<img', $output, 'address2 must not render a tag.' );
		$this->assertStringNotContainsString( '<svg', $output, 'city and postal_code must not render a tag.' );

		$this->assertStringContainsString( '&lt;img src=x onerror=alert(&quot;ADDR2&quot;)&gt;', $output, 'address2 must be escaped, not dropped.' );
		$this->assertStringContainsString( '&lt;svg onload=alert(&quot;CITY&quot;)&gt;', $output, 'city must be escaped, not dropped.' );
		$this->assertStringContainsString( '&lt;svg onload=alert(&quot;ZIP&quot;)&gt;', $output, 'postal_code must be escaped, not dropped.' );
	}

	/**
	 * With only a city on file, the row must not carry the separator space that belongs
	 * between a city and a postal code.
	 */
	public function test_addresses_table_omits_the_separator_when_only_the_city_is_set() {
		$row                = $this->address_row_with_markup();
		$row['address2']    = '';
		$row['city']        = 'Town';
		$row['postal_code'] = '';

		$output = self::$table->column_address( $row );

		$this->assertStringContainsString( '<br>Town', $output, 'The city must be printed.' );
		$this->assertStringNotContainsString( '<br>Town ', $output, 'A city with no postal code must not trail a separator.' );
	}

	/**
	 * A postal code of "0" is discarded by the column's own empty check before the city and
	 * postal code are joined, so filtering the joined values cannot lose it. Asserted because
	 * it reads as though it could: array_filter() does drop a string zero, but never sees one.
	 */
	public function test_addresses_table_never_rendered_a_zero_postal_code() {
		$row                = $this->address_row_with_markup();
		$row['address2']    = '';
		$row['city']        = 'Town';
		$row['postal_code'] = '0';

		$output = self::$table->column_address( $row );

		$this->assertStringContainsString( '<br>Town', $output, 'The city must be printed.' );
		$this->assertStringNotContainsString( 'Town 0', $output, 'A zero postal code was never printed.' );
		$this->assertStringNotContainsString( 'Town0', $output, 'A zero postal code was never printed.' );
	}

	/**
	 * One address row, as the query hands it to the column, with markup in each field the
	 * column prints unescaped today.
	 *
	 * @return array
	 */
	private function address_row_with_markup() {
		return array(
			'id'          => 1,
			'customer_id' => 1,
			'type'        => 'billing',
			'status'      => 'verified',
			'is_primary'  => 1,
			'address'     => '1 Market St',
			'address2'    => 'Unit<img src=x onerror=alert("ADDR2")>',
			'city'        => 'Town<svg onload=alert("CITY")>',
			'region'      => 'CA',
			'postal_code' => '9<svg onload=alert("ZIP")>',
			'country'     => 'US',
		);
	}
}
