<?php

namespace EDD\Pro\Tests\Checkout;

use EDD\Forms\Checkout\PersonalInfo\Email;
use EDD\Forms\Checkout\PersonalInfo\FirstName;
use EDD\Forms\Checkout\PersonalInfo\LastName;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\Forms\Handler;

/**
 * Personal Info field tests.
 *
 * @see \EDD\Forms\Checkout\PersonalInfo
 */
class PersonalInfo extends EDD_UnitTestCase {

	public function test_first_name_field_id() {
		$field = new FirstName( array( 'first_name' => 'John' ) );
		$this->assertSame( 'edd-first', $field->get_id() );
	}

	public function test_first_name_field_label() {
		$field = new FirstName( array( 'first_name' => 'John' ) );
		$this->assertSame( 'First Name', $field->get_label() );
	}

	public function test_last_name_field_id() {
		$field = new LastName( array( 'last_name' => 'Doe' ) );
		$this->assertSame( 'edd-last', $field->get_id() );
	}

	public function test_last_name_field_label() {
		$field = new LastName( array( 'last_name' => 'Doe' ) );
		$this->assertSame( 'Last Name', $field->get_label() );
	}

	public function test_email_field_id() {
		$field = new Email( array( 'email' => 'john.doe@example.com' ) );
		$this->assertSame( 'edd-email', $field->get_id() );
	}

	public function test_email_field_label() {
		$field = new Email( array( 'email' => 'john.doe@example.com' ) );
		$this->assertSame( 'Email', $field->get_label() );
	}

	/**
	 * Test that the edd_purchase_form_after_email action is fired.
	 */
	public function test_email_field_action_fires() {
		$fields_to_render = array(
			Email::class,
			FirstName::class,
			LastName::class,
		);
		$customer_data = array(
			'email'      => 'jane.doe@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
		);

		$before_action_count = did_action( 'edd_purchase_form_after_email' );

		ob_start();
		Handler::render_fields( $fields_to_render, $customer_data );
		ob_end_clean();

		$after_action_count = did_action( 'edd_purchase_form_after_email' );

		$this->assertGreaterThan(
			$before_action_count,
			$after_action_count,
			'Action edd_purchase_form_after_email did not fire during render_fields.'
		);
	}

	/**
	 * The fields read their values out of the customer session directly. A session written
	 * without every key, as an integration storing only an email leaves it, warns on the
	 * missing keys instead of rendering.
	 *
	 * PHPUnit converts warnings to exceptions, so an undefined-key warning fails this test.
	 */
	public function test_user_info_fields_render_partial_customer_session() {
		wp_set_current_user( 0 );
		EDD()->session->set( 'customer', array( 'email' => 'guest@example.com' ) );

		// Assert the fixture: the session holds only the email, as reported.
		$this->assertSame( array( 'email' => 'guest@example.com' ), EDD()->session->get( 'customer' ) );

		ob_start();
		edd_user_info_fields();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'guest@example.com', $output );
		$this->assertStringContainsString( 'edd-first', $output );
		$this->assertStringContainsString( 'edd-last', $output );
	}

	/**
	 * The same partial session reaching the fields through the block/Elementor path.
	 */
	public function test_render_fields_with_partial_customer_session() {
		wp_set_current_user( 0 );
		EDD()->session->set( 'customer', array( 'email' => 'guest@example.com' ) );

		ob_start();
		Handler::render_fields(
			array( Email::class, FirstName::class, LastName::class ),
			\EDD\Sessions\Customer::get()
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'guest@example.com', $output );
	}
}
