<?php
/**
 * Checkout Templates License Tests
 *
 * @package EDD\Tests\Checkout\Templates
 * @since   3.7.0
 */

namespace EDD\Tests\Checkout\Templates;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Tests\Helpers\Licenses as LicenseData;
use EDD\Checkout\Templates\License;

/**
 * License tests.
 *
 * Tests the functionality of license validation for
 * the Checkout Template Imports feature.
 *
 * Covers use cases:
 * - Import with Valid Pro License
 * - Import Attempt with Inactive License
 * - Free User Browsing Templates
 * - Free User Import Attempt
 */
class LicenseTest extends EDD_UnitTestCase {

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		parent::tear_down();

		// Clean up license data.
		LicenseData::delete_pro_license();
		delete_option( 'edd_pro_license_key' );
		delete_option( 'edd_pro_license' );
		delete_option( 'edd_pass_licenses' );

		// Remove any filters we added.
		remove_all_filters( 'edd_is_pro' );
	}

	/*
	 * -------------------------------------------------------------------------
	 * get_status() Tests
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Test get_status returns 'missing' when no license key is set.
	 */
	public function test_get_status_missing_when_no_license_key() {
		delete_option( 'edd_pro_license_key' );
		delete_site_option( 'edd_pro_license_key' );

		$status = License::get_status();

		$this->assertSame( 'missing', $status );
	}

	/**
	 * Test get_status returns 'valid' for valid Pro license.
	 */
	public function test_get_status_valid_with_pro_license() {
		LicenseData::get_pro_license( array( 'license' => 'valid' ) );

		$status = License::get_status();

		$this->assertSame( 'valid', $status );
	}

	/**
	 * Test get_status returns 'expired' for expired license.
	 */
	public function test_get_status_expired_license() {
		LicenseData::get_pro_license(
			array(
				'license' => 'expired',
				'expires' => date( 'Y-m-d', strtotime( '-1 month' ) ),
			)
		);

		$status = License::get_status();

		$this->assertSame( 'expired', $status );
	}

	/**
	 * Test get_status returns 'expired' when the label is 'valid' but the expiry date has lapsed.
	 */
	public function test_get_status_expired_when_valid_label_but_past_expiry() {
		LicenseData::get_pro_license(
			array(
				'license' => 'valid',
				'expires' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		// A pass must be present so we know can_import is gated by the expiry, not a missing pass.
		$passes = array(
			'license_1' => array(
				'pass_id'      => \EDD\Admin\Pass_Manager::PERSONAL_PASS_ID,
				'time_checked' => time(),
			),
		);
		update_option( 'edd_pass_licenses', wp_json_encode( $passes ) );

		$this->assertSame( 'expired', License::get_status() );
		$this->assertFalse( License::can_import() );
	}

	/**
	 * Test get_status stays 'valid' when the label is 'valid' and the expiry date is in the future.
	 */
	public function test_get_status_valid_when_valid_label_and_future_expiry() {
		LicenseData::get_pro_license(
			array(
				'license' => 'valid',
				'expires' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 'valid', License::get_status() );
	}

	/**
	 * Test get_status stays 'valid' when the label is 'valid' and the license is lifetime.
	 */
	public function test_get_status_valid_when_valid_label_and_lifetime() {
		LicenseData::get_pro_license(
			array(
				'license' => 'valid',
				'expires' => 'lifetime',
			)
		);

		$this->assertSame( 'valid', License::get_status() );
	}

	/**
	 * Test get_status returns 'inactive' when license key exists but no status.
	 */
	public function test_get_status_inactive_when_key_exists_but_no_status() {
		// Set license key but no license data.
		update_site_option( 'edd_pro_license_key', 'test_license_key_123' );
		delete_site_option( 'edd_pro_license' );

		$status = License::get_status();

		$this->assertSame( 'inactive', $status );
	}

	/**
	 * Test get_status returns 'invalid' for disabled/revoked license.
	 */
	public function test_get_status_invalid_for_disabled_license() {
		LicenseData::get_pro_license( array( 'license' => 'disabled' ) );

		$status = License::get_status();

		$this->assertSame( 'invalid', $status );
	}

	/**
	 * Test get_status always returns a valid status string.
	 */
	public function test_get_status_returns_valid_status_string() {
		$valid_statuses = array( 'valid', 'invalid', 'expired', 'inactive', 'missing' );

		$status = License::get_status();

		$this->assertContains( $status, $valid_statuses );
	}

	/*
	 * -------------------------------------------------------------------------
	 * get_key() Tests
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Test get_key returns empty string when no license is set.
	 */
	public function test_get_key_empty_when_no_license() {
		delete_option( 'edd_pro_license_key' );
		delete_site_option( 'edd_pro_license_key' );

		$key = License::get_key();

		$this->assertSame( '', $key );
	}

	/**
	 * Test get_key returns license key when set.
	 */
	public function test_get_key_returns_key_when_set() {
		LicenseData::get_pro_license();

		$key = License::get_key();

		$this->assertNotEmpty( $key );
		$this->assertIsString( $key );
	}

	/*
	 * -------------------------------------------------------------------------
	 * can_import() Tests
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Test can_import returns true with valid Pro license.
	 */
	public function test_can_import_true_with_valid_pro_license() {
		// Set up valid Pro license with pass.
		LicenseData::get_pro_license( array( 'license' => 'valid' ) );

		// Set pass data so Pass_Manager recognizes it.
		$passes = array(
			'pro_license' => array(
				'pass_id'      => \EDD\Admin\Pass_Manager::PERSONAL_PASS_ID,
				'time_checked' => time(),
			),
		);
		update_option( 'edd_pass_licenses', wp_json_encode( $passes ) );

		$can_import = License::can_import();

		$this->assertTrue( $can_import );
	}

	/**
	 * Test can_import returns false without any license.
	 */
	public function test_can_import_false_without_license() {
		// Clear all license data.
		delete_option( 'edd_pass_licenses' );
		delete_option( 'edd_pro_license_key' );
		delete_site_option( 'edd_pro_license_key' );

		$can_import = License::can_import();

		$this->assertFalse( $can_import );
	}

	/**
	 * Test can_import returns false for Lite users.
	 */
	public function test_can_import_false_for_lite_users() {
		// Simulate Lite environment.
		add_filter( 'edd_is_pro', '__return_false' );

		// Clear pass data.
		delete_option( 'edd_pass_licenses' );

		$can_import = License::can_import();

		$this->assertFalse( $can_import );

		remove_filter( 'edd_is_pro', '__return_false' );
	}

	/**
	 * Test can_import with Personal pass.
	 */
	public function test_can_import_true_with_personal_pass() {
		LicenseData::get_pro_license( array( 'license' => 'valid' ) );

		$passes = array(
			'license_1' => array(
				'pass_id'      => \EDD\Admin\Pass_Manager::PERSONAL_PASS_ID,
				'time_checked' => time(),
			),
		);
		update_option( 'edd_pass_licenses', wp_json_encode( $passes ) );

		$can_import = License::can_import();

		$this->assertTrue( $can_import );
	}

	/**
	 * Test can_import with Professional pass.
	 */
	public function test_can_import_true_with_professional_pass() {
		LicenseData::get_pro_license( array( 'license' => 'valid' ) );

		$passes = array(
			'license_1' => array(
				'pass_id'      => \EDD\Admin\Pass_Manager::PROFESSIONAL_PASS_ID,
				'time_checked' => time(),
			),
		);
		update_option( 'edd_pass_licenses', wp_json_encode( $passes ) );

		$can_import = License::can_import();

		$this->assertTrue( $can_import );
	}

	/**
	 * Test can_import with All Access pass.
	 */
	public function test_can_import_true_with_all_access_pass() {
		LicenseData::get_pro_license( array( 'license' => 'valid' ) );

		$passes = array(
			'license_1' => array(
				'pass_id'      => \EDD\Admin\Pass_Manager::ALL_ACCESS_PASS_ID,
				'time_checked' => time(),
			),
		);
		update_option( 'edd_pass_licenses', wp_json_encode( $passes ) );

		$can_import = License::can_import();

		$this->assertTrue( $can_import );
	}

	/**
	 * Test can_import returns false when pass is expired (outside check window).
	 */
	public function test_can_import_false_when_pass_outside_check_window() {
		// Pass checked more than 2 months ago should be invalid.
		$passes = array(
			'license_1' => array(
				'pass_id'      => \EDD\Admin\Pass_Manager::PERSONAL_PASS_ID,
				'time_checked' => strtotime( '-1 year' ),
			),
		);
		update_option( 'edd_pass_licenses', wp_json_encode( $passes ) );

		$can_import = License::can_import();

		$this->assertFalse( $can_import );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Return Type Tests
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Test can_import always returns boolean.
	 */
	public function test_can_import_returns_boolean() {
		$result = License::can_import();

		$this->assertIsBool( $result );
	}

	/**
	 * Test get_key always returns string.
	 */
	public function test_get_key_returns_string() {
		$result = License::get_key();

		$this->assertIsString( $result );
	}
}
