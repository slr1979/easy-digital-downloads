<?php
/**
 * E2E Testing Helpers
 *
 * Loaded as a mu-plugin by the E2E Docker setup script (bin/setup-e2e-site.sh).
 * NOT loaded by EDD plugin code — zero references to this file exist in src/.
 *
 * The EDD_E2E_TESTING constant gate (line 18) prevents execution if this file
 * is accidentally placed in mu-plugins on a non-test environment.
 *
 * @package EDD\Tests\E2E
 * @since   3.7.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EDD_E2E_TESTING' ) || ! EDD_E2E_TESTING ) {
	return;
}

// Force a real Lite runtime when the E2E lite-mode constant is set — mirrors the edd_is_pro filter EDD's PHPUnit uses.
if ( defined( 'EDD_E2E_LITE_MODE' ) && EDD_E2E_LITE_MODE ) {
	add_filter( 'edd_is_pro', '__return_false' );
}

/**
 * E2E License Simulation
 *
 * Allows E2E tests to simulate different license states by adding a query
 * parameter to the URL. This enables testing of:
 * - Lite user experience (UpgradePrompt)
 * - Pro user experience (Import button)
 * - Expired license UI
 * - Inactive license UI
 *
 * Usage in E2E tests:
 *   await page.goto( url + '&edd_e2e_license=lite' );
 *
 * Supported values:
 *   - lite: Simulates EDD Lite (free version)
 *   - pro: Simulates valid Pro license
 *   - expired: Simulates expired license
 *   - inactive: Simulates inactive license (key exists but not activated)
 *   - missing: Simulates no license key entered
 *   - invalid: Simulates invalid/revoked license
 */
class EDD_E2E_License_Simulation {

	/**
	 * The simulated license mode from query param.
	 *
	 * @var string|null
	 */
	private static $mode = null;

	/**
	 * Initialize the simulation.
	 *
	 * @return void
	 */
	public static function init() {
		// Resolve the simulated mode. Prefer the query param (set on admin page
		// loads) and fall back to the cookie so subsequent REST apiFetch calls
		// from the React app (which do not forward the query param) still see
		// the simulated state.
		$mode_from_query  = ! empty( $_GET['edd_e2e_license'] )    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['edd_e2e_license'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$mode_from_cookie = ! empty( $_COOKIE['edd_e2e_license'] )
			? sanitize_key( wp_unslash( $_COOKIE['edd_e2e_license'] ) )
			: '';
		$mode             = $mode_from_query ?: $mode_from_cookie;

		if ( empty( $mode ) ) {
			return;
		}

		self::$mode = $mode;

		// When the query param is explicitly present, persist the mode in a
		// session cookie so REST requests can read it back. Only set when the
		// headers have not already been sent.
		if ( ! empty( $mode_from_query ) && ! headers_sent() ) {
			setcookie( 'edd_e2e_license', $mode_from_query, 0, '/' );
		}

		// Exercise the REAL license gate. Lite mode forces the free runtime so
		// can_import() and the script data compute unlicensed for real. Pro mode
		// does nothing — the E2E site is already seeded with a valid Pro base
		// license, so the real gate computes true with the real key intact. The
		// negative states (expired/inactive/missing/invalid) are driven
		// client-side by mockLicenseInRestResponse in the spec.
		if ( 'lite' === self::$mode ) {
			add_filter( 'edd_is_pro', '__return_false' );
		}
	}

}

// Initialize the simulation.
EDD_E2E_License_Simulation::init();

/**
 * E2E Purchase Page Override
 *
 * The checkout-templates importer always writes to edd_get_option( 'purchase_page' ),
 * and restore rejects any other parent page — a spec can't just point elsewhere.
 * Instead, this hooks EDD's own edd_get_option_purchase_page filter (fired by
 * Setting::get()) per-request, keyed on the same query-param → cookie idiom as
 * EDD_E2E_License_Simulation above, so the checkout-templates integration spec's
 * import/restore requests resolve purchase_page to ITS OWN dedicated page. That
 * lets it run concurrently with the shared-checkout viewport specs without
 * colliding on the same page, and removes any need to depend on their pass/fail.
 *
 * Usage in E2E tests:
 *   await page.goto( url + '&edd_e2e_purchase_page=123' );
 */
class EDD_E2E_Purchase_Page_Override {

	/**
	 * The overridden purchase page ID from the query param or cookie.
	 *
	 * @var int|null
	 */
	private static $page_id = null;

	/**
	 * Initialize the override.
	 *
	 * @return void
	 */
	public static function init() {
		// Prefer the query param (set on admin page loads) and fall back to the
		// cookie so subsequent REST apiFetch calls from the React app (which do
		// not forward the query param) still see the overridden page.
		$id_from_query  = ! empty( $_GET['edd_e2e_purchase_page'] )    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? absint( wp_unslash( $_GET['edd_e2e_purchase_page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 0;
		$id_from_cookie = ! empty( $_COOKIE['edd_e2e_purchase_page'] )
			? absint( wp_unslash( $_COOKIE['edd_e2e_purchase_page'] ) )
			: 0;
		$id             = $id_from_query ?: $id_from_cookie;

		if ( $id <= 0 ) {
			return;
		}

		self::$page_id = $id;

		// When the query param is explicitly present, persist it in a session
		// cookie so REST requests can read it back. Only set when the headers
		// have not already been sent.
		if ( $id_from_query > 0 && ! headers_sent() ) {
			setcookie( 'edd_e2e_purchase_page', (string) $id_from_query, 0, '/' );
		}

		add_filter( 'edd_get_option_purchase_page', array( __CLASS__, 'filter_purchase_page' ) );
	}

	/**
	 * Filter the purchase_page option value.
	 *
	 * @return int The overridden purchase page ID.
	 */
	public static function filter_purchase_page() {
		return self::$page_id;
	}

}

// Initialize the purchase page override.
EDD_E2E_Purchase_Page_Override::init();
