<?php

namespace EDD\Tests;

use EDD\Telemetry\Data;
use EDD\Telemetry\Stats;
use EDD\Utils\ListHandler;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Telemetry Tests
 * @group edd_telemetry
 */
class Telemetry extends EDD_UnitTestCase {

	/**
	 * The data to send to the server.
	 *
	 * @var array
	 */
	private static $data;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		update_option( 'blogname', 'WordPress' );

		$telemetry_data = new Data();

		self::$data = $telemetry_data->get();
	}

	public function test_data_is_array() {
		$this->assertIsArray( self::$data );
	}

	public function test_data_is_not_empty() {
		$this->assertNotEmpty( self::$data );
	}

	public function test_data_contains_id() {
		$this->assertArrayHasKey( 'id', self::$data );
	}

	public function test_data_contains_environment() {
		$this->assertArrayHasKey( 'environment', self::$data );
	}

	public function test_data_contains_integrations() {
		$this->assertArrayHasKey( 'integrations', self::$data );
	}

	public function test_data_contains_licenses() {
		$this->assertArrayHasKey( 'licenses', self::$data );
	}

	public function test_data_contains_sales() {
		$this->assertArrayHasKey( 'sales', self::$data );
	}

	public function test_data_contains_refunds() {
		$this->assertArrayHasKey( 'refunds', self::$data );
	}

	public function test_data_contains_settings() {
		$this->assertArrayHasKey( 'settings', self::$data );
	}

	public function test_data_contains_stats() {
		$this->assertArrayHasKey( 'stats', self::$data );
	}

	public function test_data_contains_products() {
		$this->assertArrayHasKey( 'products', self::$data );
	}

	public function test_environment_contains_php_version() {
		$this->assertEquals( phpversion(), self::$data['environment']['php_version'] );
	}

	public function test_environment_contains_wp_version() {
		$version = get_bloginfo( 'version' );
		$version = explode( '-', $version );

		$this->assertEquals( reset( $version ), self::$data['environment']['wp_version'] );
	}

	public function test_environment_contains_edd_version() {
		$this->assertEquals( EDD_VERSION, self::$data['environment']['edd_version'] );
	}

	public function test_environment_contains_multisite() {
		$this->assertEquals( is_multisite(), self::$data['environment']['multisite'] );

		if ( is_multisite() ) {
			$this->assertEquals( 'subdirectory', self::$data['environment']['multisite_mode'] );
			$this->assertEquals( 0, self::$data['environment']['network_activated'] );
			$this->assertEquals( 1, self::$data['environment']['network_sites'] );
			$this->assertEquals( 0, self::$data['environment']['domain_mapping'] );
			$this->assertEquals( 1, self::$data['environment']['is_main_site'] );
		}
	}

	public function test_environment_contains_architecture() {
		// We're going to hard code this at 64bit, because our automated testing only runs on 64bit.
		$this->assertEquals( '64', self::$data['environment']['php_arch'] );
	}

	public function test_no_data_includes_admin_email() {
		$list_handler = new ListHandler( self::$data );
		$emails       = $list_handler->search( get_bloginfo( 'admin_email' ) );

		$this->assertFalse( $emails );
	}

	public function test_settings_currency_matches_store_currency() {
		$this->assertEquals( edd_get_currency(), self::$data['settings']['currency'] );
	}

	public function test_environment_theme_name_is_anonymized() {
		$this->assertEquals( 'WordPress', get_bloginfo( 'name' ) );
		$this->assertFalse( strpos( self::$data['environment']['active_theme'], 'WordPress' ) );
	}

	public function test_deprecated_class_instance_matches() {
		$tracking = new \EDD_Tracking();

		$this->assertTrue( $tracking instanceof \EDD\Telemetry\Tracking );
	}

	public function test_email_template_order_receipt_is_enabled() {
		$this->assertEquals( 1, self::$data['settings']['email_template_order_receipt'] );
	}

	public function test_environment_checkout_type_default_is_block() {
		$this->assertArrayHasKey( 'checkout_type', self::$data['environment'] );
		$this->assertEquals( 'block', self::$data['environment']['checkout_type'] );
	}

	public function test_stats_contains_customer_count() {
		$this->assertArrayHasKey( 'customer_count', self::$data['stats'] );
	}

	public function test_stats_contains_median_orders_per_customer() {
		$this->assertArrayHasKey( 'median_orders_per_customer', self::$data['stats'] );
	}

	public function test_customer_count_matches_number_of_customers() {
		$this->seed_customers( array( 3, 1, 1 ) );

		$stats = ( new Stats() )->get();

		$this->assertEquals( 3, $stats['customer_count'] );
	}

	public function test_median_orders_per_customer_is_zero_with_no_customers() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->edd_customers}" );

		$stats = ( new Stats() )->get();

		$this->assertEquals( 0, $stats['median_orders_per_customer'] );
	}

	public function test_median_orders_per_customer_uses_median_not_mean() {
		// Mean of these is 25.75, but the median is 1.
		$this->seed_customers( array( 1, 1, 1, 100 ) );

		$stats = ( new Stats() )->get();

		$this->assertEquals( 1, $stats['median_orders_per_customer'] );
	}

	public function test_median_orders_per_customer_with_odd_count() {
		// Sorted: 1, 4, 100. Middle value is 4.
		$this->seed_customers( array( 100, 1, 4 ) );

		$stats = ( new Stats() )->get();

		$this->assertEquals( 4, $stats['median_orders_per_customer'] );
	}

	public function test_median_orders_per_customer_with_even_count_averages_middle() {
		// Sorted: 1, 2, 4, 100. Median is the average of 2 and 4 = 3.
		$this->seed_customers( array( 100, 1, 4, 2 ) );

		$stats = ( new Stats() )->get();

		$this->assertEquals( 3, $stats['median_orders_per_customer'] );
	}

	/**
	 * Replaces all customers with a known set, each having the given purchase_count.
	 *
	 * @param int[] $purchase_counts One customer is created per entry, with that purchase_count.
	 */
	private function seed_customers( array $purchase_counts ) {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->edd_customers}" );

		foreach ( $purchase_counts as $i => $count ) {
			edd_add_customer(
				array(
					'email'          => "median-test-{$i}@example.com",
					'purchase_count' => $count,
				)
			);
		}
	}

	public function test_settings_includes_stripe_elements_mode() {
		// The setting is only registered for stores with legacy card elements access,
		// so telemetry must always backfill it from the helper function.
		$this->assertTrue( function_exists( 'edds_get_elements_mode' ) );
		$this->assertArrayHasKey( 'stripe_elements_mode', self::$data['settings'] );
		$this->assertContains( self::$data['settings']['stripe_elements_mode'], array( 'card-elements', 'payment-elements' ) );
	}

	public function test_settings_stripe_elements_mode_matches_helper() {
		$this->assertEquals( edds_get_elements_mode(), self::$data['settings']['stripe_elements_mode'] );
	}
}
