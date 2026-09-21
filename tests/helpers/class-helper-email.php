<?php
/**
 * Email test helper.
 *
 * EDD's email rows are created by the installer, not by the test bootstrap, so a fresh
 * test database has no `order_receipt` row and anything that sends a receipt fails before
 * it reaches the behavior under test. This helper installs and enables an email, and
 * captures what EDD asks wp_mail() to send without handing anything to PHPMailer.
 *
 * @package EDD\Tests\Helpers
 */

namespace EDD\Tests\Helpers;

/**
 * Class EDD_Helper_Email.
 */
class EDD_Helper_Email {

	/**
	 * Messages captured since the last start_capturing_mail() call.
	 *
	 * @var array
	 */
	private static $captured = array();

	/**
	 * Installs the registered email templates if they are not present yet.
	 *
	 * Idempotent: EDD's own installer skips templates that already exist.
	 */
	public static function install() {
		if ( edd_get_email_by( 'email_id', 'order_receipt' ) ) {
			return;
		}

		( new \EDD\Upgrades\Emails\Registration() )->install();
	}

	/**
	 * Installs the email templates and enables one of them.
	 *
	 * @param string $email_id The email to enable, e.g. 'order_receipt'.
	 * @return \EDD\Emails\Email|false The enabled email, or false if it could not be found.
	 */
	public static function enable( $email_id ) {
		self::install();

		$email = edd_get_email_by( 'email_id', $email_id );
		if ( ! $email ) {
			return false;
		}

		if ( ! $email->status ) {
			edd_update_email( $email->id, array( 'status' => 1 ) );
			$email = edd_get_email_by( 'email_id', $email_id );
		}

		return $email;
	}

	/**
	 * Starts recording outbound mail and stops it from being delivered.
	 */
	public static function start_capturing_mail() {
		self::$captured = array();

		add_filter( 'wp_mail', array( __CLASS__, 'record' ) );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	/**
	 * Stops recording outbound mail.
	 */
	public static function stop_capturing_mail() {
		remove_filter( 'wp_mail', array( __CLASS__, 'record' ) );
		remove_filter( 'pre_wp_mail', '__return_true' );

		self::$captured = array();
	}

	/**
	 * The messages EDD asked wp_mail() to send, in order.
	 *
	 * @return array Each entry is the wp_mail() argument array: to, subject, message, headers.
	 */
	public static function captured_mail() {
		return self::$captured;
	}

	/**
	 * Records one message. Registered as a filter, so it must return its argument.
	 *
	 * @param array $args wp_mail() arguments.
	 * @return array
	 */
	public static function record( $args ) {
		self::$captured[] = $args;

		return $args;
	}
}
