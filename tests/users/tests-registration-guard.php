<?php
/**
 * Front-end registration control tests.
 */

namespace EDD\Tests\Users;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Users\RegistrationGuard;
use EDD\Utils\Tokenizer;

/**
 * Tests the controls on the front-end registration handler.
 *
 * Registration is offered independently of WordPress's own setting on purpose, which is what the
 * shortcode and block exist for, so these cases assert origin and volume controls rather than a
 * registration policy. The policy case is here too, as the control that stops one being added.
 *
 * @group edd_login_register
 */
class RegistrationGuardTest extends EDD_UnitTestCase {

	/**
	 * The address every case submits from unless it changes it.
	 *
	 * @var string
	 */
	const CALLER = '203.0.113.7';

	/**
	 * A second address, for the per-caller case.
	 *
	 * @var string
	 */
	const OTHER_CALLER = '198.51.100.9';

	/**
	 * REMOTE_ADDR as the bootstrap left it.
	 *
	 * @var string|null
	 */
	private $original_remote_addr;

	/**
	 * The forwarded-for headers as they were found, keyed by superglobal name.
	 *
	 * @var array
	 */
	private $original_forwarded = array();

	public function setUp(): void {
		parent::setUp();

		$this->original_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;

		wp_set_current_user( 0 );
		edd_clear_errors();

		$_SERVER['REMOTE_ADDR'] = self::CALLER;

		// Absence and a restored value are different branches to anything reading these through
		// isset(), so they are captured rather than only cleared.
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ) as $header ) {
			$this->original_forwarded[ $header ] = isset( $_SERVER[ $header ] ) ? $_SERVER[ $header ] : null;
			unset( $_SERVER[ $header ] );
		}

		RegistrationGuard::limiter()->reset( self::CALLER );
	}

	public function tearDown(): void {
		foreach ( array( self::CALLER, self::OTHER_CALLER ) as $caller ) {
			RegistrationGuard::limiter()->reset( $caller );
		}

		foreach ( $this->original_forwarded as $header => $value ) {
			if ( is_null( $value ) ) {
				unset( $_SERVER[ $header ] );
			} else {
				$_SERVER[ $header ] = $value;
			}
		}

		if ( is_null( $this->original_remote_addr ) ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}

		edd_clear_errors();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * The premise: WordPress registration is off, and that must not by itself stop EDD from
	 * registering anyone, because decoupling the two is what the shortcode and block are for.
	 */
	public function test_registration_is_offered_while_wordpress_registration_is_disabled() {
		update_option( 'users_can_register', 0 );

		$this->assertFalse(
			(bool) get_option( 'users_can_register' ),
			'WordPress registration must be off, or this case does not test the decoupling.'
		);

		$this->submit( 'decoupled_user' );

		$this->assertNotFalse(
			get_user_by( 'login', 'decoupled_user' ),
			'EDD registration is offered independently of WordPress\'s own setting; that is what the shortcode is for.'
		);
	}

	/**
	 * The handler's requirement is only met if the form actually emits the token, so assert the
	 * emit side rather than only the verify side.
	 */
	public function test_the_form_emits_the_token_the_handler_requires() {
		$this->assertNotFalse(
			has_action( 'edd_register_form_fields_before_submit' ),
			'Something must be listening on the hook both registration surfaces fire.'
		);

		$form = edd_register_form();

		$this->assertStringContainsString( 'edd_register_timestamp', $form, 'The form must carry the timestamp.' );
		$this->assertStringContainsString( 'edd_register_token', $form, 'The form must carry the token.' );
	}

	/**
	 * A submission carrying no token was not made from a form this store rendered.
	 */
	public function test_registration_is_refused_without_a_token() {
		$this->submit( 'no_token_user', false );

		$this->assertFalse(
			get_user_by( 'login', 'no_token_user' ),
			'A submission with no token must not create an account.'
		);
		$this->assertNotEmpty( edd_get_errors(), 'The refusal must surface an error.' );
	}

	/**
	 * A token this store did not sign is not a token.
	 */
	public function test_registration_is_refused_with_an_unsigned_token() {
		$this->submit( 'unsigned_token_user', true, time(), str_repeat( 'a', 64 ) );

		$this->assertFalse(
			get_user_by( 'login', 'unsigned_token_user' ),
			'A token this store did not sign must not create an account.'
		);
	}

	/**
	 * The case the unsigned one above cannot reach: a token this store really did sign, but for
	 * something else. The signing key is shared and other surfaces publish signed values, so the
	 * payload has to be scoped to this form rather than to the timestamp alone.
	 */
	public function test_registration_is_refused_with_a_token_signed_for_another_purpose() {
		$timestamp = time();
		$elsewhere = Tokenizer::tokenize( $timestamp );

		$this->assertTrue(
			Tokenizer::is_token_valid( $elsewhere, $timestamp ),
			'Fixture: the token must genuinely be one this store signed, or this repeats the unsigned case.'
		);

		$this->submit( 'other_purpose_token_user', true, $timestamp, $elsewhere );

		$this->assertFalse(
			get_user_by( 'login', 'other_purpose_token_user' ),
			'A token signed for another purpose must not register an account.'
		);
	}

	/**
	 * The paired positive: a genuine token from this form is accepted.
	 */
	public function test_registration_is_accepted_with_a_genuine_token() {
		$this->submit( 'genuine_token_user' );

		$this->assertNotFalse(
			get_user_by( 'login', 'genuine_token_user' ),
			'A submission carrying a token this store signed for this form must still register.'
		);
	}

	/**
	 * Past the limit, further submissions are refused rather than creating accounts.
	 */
	public function test_registration_is_refused_past_the_rate_limit() {
		for ( $i = 0; $i < RegistrationGuard::MAX_ATTEMPTS; $i++ ) {
			$this->submit( 'within_limit_' . $i );
			edd_clear_errors();
		}

		$this->assertNotFalse(
			get_user_by( 'login', 'within_limit_0' ),
			'Submissions inside the limit must succeed, or the limit is set too tight to test.'
		);

		$this->submit( 'over_limit_user' );

		$this->assertFalse(
			get_user_by( 'login', 'over_limit_user' ),
			'A submission past the limit must not create an account.'
		);
		$this->assertNotEmpty( edd_get_errors(), 'The refusal must surface an error.' );
	}

	/**
	 * A submission rejected during field validation must not spend a slot.
	 *
	 * Registration is the form people fumble most, so a run of mismatched passwords cannot be
	 * what locks someone out of registering at all.
	 */
	public function test_a_rejected_submission_does_not_consume_a_slot() {
		for ( $i = 0; $i < RegistrationGuard::MAX_ATTEMPTS; $i++ ) {
			$this->submit( 'fumbled_' . $i, true, null, null, 'Mismatched!2026' );

			$this->assertNotEmpty( edd_get_errors(), 'Fixture: the fumbled submission must be rejected.' );
			$this->assertFalse( get_user_by( 'login', 'fumbled_' . $i ), 'Fixture: no account may exist yet.' );
			edd_clear_errors();
		}

		$this->submit( 'patient_user' );

		$this->assertNotFalse(
			get_user_by( 'login', 'patient_user' ),
			'Field validation failures must not exhaust the window.'
		);
	}

	/**
	 * The limit is per caller, so a different caller is not locked out by someone else's attempts.
	 */
	public function test_a_different_caller_is_not_locked_out() {
		for ( $i = 0; $i < RegistrationGuard::MAX_ATTEMPTS + 1; $i++ ) {
			$this->submit( 'first_caller_' . $i );
			edd_clear_errors();
		}

		$_SERVER['REMOTE_ADDR'] = self::OTHER_CALLER;
		RegistrationGuard::limiter()->reset( self::OTHER_CALLER );

		$this->submit( 'second_caller' );

		$this->assertNotFalse(
			get_user_by( 'login', 'second_caller' ),
			'One caller exhausting the limit must not refuse everybody else.'
		);
	}

	/**
	 * Checkout creates accounts through its own path, and it is held to the same window.
	 */
	public function test_checkout_registration_is_refused_past_the_rate_limit() {
		for ( $i = 0; $i < RegistrationGuard::MAX_ATTEMPTS; $i++ ) {
			$this->assertTrue(
				RegistrationGuard::check_rate_limit(),
				'Fixture: the window must not already be exhausted.'
			);
		}

		$user = $this->checkout_register( 'checkout_over_limit' );

		$this->assertFalse( $user, 'Checkout must refuse to create an account past the window.' );
		$this->assertFalse(
			get_user_by( 'login', 'checkout_over_limit' ),
			'No account may exist for a checkout registration past the window.'
		);
	}

	/**
	 * The paired positive: inside the window, checkout still registers.
	 */
	public function test_checkout_registration_is_accepted_inside_the_rate_limit() {
		$user = $this->checkout_register( 'checkout_within_limit' );

		$this->assertNotFalse( $user, 'Checkout must still create accounts inside the window.' );
		$this->assertNotFalse(
			get_user_by( 'login', 'checkout_within_limit' ),
			'An account must exist for a checkout registration inside the window.'
		);
	}

	/**
	 * A checkout registration refused during validation must not spend a slot either.
	 *
	 * The counterpart to test_a_rejected_submission_does_not_consume_a_slot on the other surface.
	 * Field validation runs before edd_get_purchase_form_user(), and sets its errors while still
	 * leaving need_new_user true, so the fumble reaches the limiter unless the call is gated.
	 */
	public function test_a_rejected_checkout_registration_does_not_consume_a_slot() {
		for ( $i = 0; $i < RegistrationGuard::MAX_ATTEMPTS; $i++ ) {
			// The error field validation would have set for a mismatched confirmation.
			edd_set_error( 'password_mismatch', 'Passwords do not match' );

			$user = $this->checkout_register( 'checkout_fumbled_' . $i );

			// With errors already set, edd_register_and_login_new_user() bails and reports -1,
			// which is what PurchaseData::start() then refuses the whole checkout on.
			$this->assertSame(
				-1,
				(int) $user['user_id'],
				'Fixture: the fumbled checkout submission must be refused.'
			);
			$this->assertFalse(
				get_user_by( 'login', 'checkout_fumbled_' . $i ),
				'Fixture: no account may exist for a refused checkout submission.'
			);

			edd_clear_errors();
		}

		$user = $this->checkout_register( 'checkout_patient_user' );

		$this->assertNotFalse( $user, 'Checkout validation failures must not exhaust the window.' );
		$this->assertNotFalse(
			get_user_by( 'login', 'checkout_patient_user' ),
			'A clean checkout registration must still succeed after a run of fumbles.'
		);
	}

	/**
	 * Both surfaces share one window, so the two cannot be used to double the total.
	 */
	public function test_both_surfaces_count_against_one_window() {
		for ( $i = 0; $i < RegistrationGuard::MAX_ATTEMPTS; $i++ ) {
			$this->submit( 'shared_bucket_' . $i );
			edd_clear_errors();
		}

		$this->assertNotFalse(
			get_user_by( 'login', 'shared_bucket_0' ),
			'Fixture: the form registrations must have succeeded, or the window was never filled.'
		);

		$user = $this->checkout_register( 'shared_bucket_checkout' );

		$this->assertFalse(
			$user,
			'A window filled through the registration form must also be spent for checkout.'
		);
	}

	/**
	 * Runs the checkout registration path as a purchase would.
	 *
	 * @param string $login Username to register.
	 * @return array|false What edd_get_purchase_form_user() returned.
	 */
	private function checkout_register( $login ) {
		wp_set_current_user( 0 );

		return edd_get_purchase_form_user(
			array(
				'need_new_user'   => true,
				'need_user_login' => false,
				'new_user_data'   => array(
					'user_id'    => 0,
					'user_first' => 'Checkout',
					'user_last'  => 'Registrant',
					'user_login' => $login,
					'user_email' => $login . '@example.test',
					'user_pass'  => 'Passphrase!2026',
				),
			),
			false
		);
	}

	/**
	 * Runs the registration handler as a request would.
	 *
	 * @param string      $login     Username to submit.
	 * @param bool        $token     Whether to include a token at all.
	 * @param int|null    $timestamp Timestamp to submit, defaults to now.
	 * @param string|null $signature Signature to submit, defaults to a genuine one.
	 * @param string|null $confirm   Password confirmation, defaults to matching.
	 */
	private function submit( $login, $token = true, $timestamp = null, $signature = null, $confirm = null ) {
		// The handler logs a new account in, and then bails on `is_user_logged_in()` for anything
		// after it. Each call has to start logged out or a sequence measures that bail instead.
		wp_set_current_user( 0 );

		$data = array(
			'edd_register_submit' => 1,
			'edd_user_login'      => $login,
			'edd_user_email'      => $login . '@example.test',
			'edd_user_pass'       => 'Passphrase!2026',
			'edd_user_pass2'      => is_null( $confirm ) ? 'Passphrase!2026' : $confirm,
		);

		if ( $token ) {
			$timestamp                      = is_null( $timestamp ) ? time() : $timestamp;
			$data['edd_register_timestamp'] = $timestamp;
			$data['edd_register_token']     = is_null( $signature )
				? Tokenizer::tokenize( 'edd-register-' . $timestamp )
				: $signature;
		}

		edd_process_register_form( $data );
	}
}
