<?php
/**
 * Tests for the EDD customer abilities.
 *
 * @package     EDD\Tests\Abilities
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Abilities;

use EDD\Abilities\Customers\Create;
use EDD\Abilities\Customers\NoteAdd;
use EDD\Abilities\Customers\NoteRead;
use EDD\Abilities\Customers\Read;
use EDD\Abilities\Customers\Update;
use EDD\Tests\PHPUnit\EDD_UnitTestCase;

/**
 * Customer ability tests.
 *
 * @since 3.7.1
 */
class Customers extends EDD_UnitTestCase {

	/**
	 * Set the current user to the site administrator, who holds all shop capabilities.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( 1 );
	}

	public function test_customer_read_single_by_id_includes_pii_for_admin() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'Ability Customer',
				'email' => 'ability-customer@edd.test',
			)
		);

		$ability = new Read();
		$result  = $ability->execute( array( 'id' => $customer_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['customers'] );

		$customer = $result['customers'][0];
		$this->assertSame( $customer_id, $customer['id'] );
		$this->assertSame( 'Ability Customer', $customer['name'] );
		$this->assertSame( 0, $customer['purchase_count'] );

		// The administrator holds view_shop_sensitive_data, so PII is included.
		$this->assertSame( 'ability-customer@edd.test', $customer['email'] );
		$this->assertContains( 'ability-customer@edd.test', $customer['emails'] );

		// Single lookups include the address key; this customer has none on file.
		$this->assertArrayHasKey( 'address', $customer );
		$this->assertNull( $customer['address'] );
	}

	public function test_customer_read_single_by_email() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'Email Lookup',
				'email' => 'email-lookup@edd.test',
			)
		);

		$ability = new Read();
		$result  = $ability->execute( array( 'email' => 'email-lookup@edd.test' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $customer_id, $result['customers'][0]['id'] );
	}

	public function test_customer_read_hides_pii_without_sensitive_data_capability() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'PII Customer',
				'email' => 'pii-customer@edd.test',
			)
		);

		// A user with only view_shop_reports may run the ability but never sees PII.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( 'view_shop_reports' );
		wp_set_current_user( $user_id );

		$ability = new Read();
		$this->assertTrue( $ability->check_permissions( array() ) );

		$result = $ability->execute( array( 'id' => $customer_id ) );

		$this->assertIsArray( $result );
		$customer = $result['customers'][0];
		$this->assertSame( $customer_id, $customer['id'] );
		$this->assertSame( 'PII Customer', $customer['name'] );
		$this->assertArrayNotHasKey( 'email', $customer );
		$this->assertArrayNotHasKey( 'emails', $customer );
		$this->assertArrayNotHasKey( 'address', $customer );

		// The administrator holds view_shop_sensitive_data and does get the email.
		wp_set_current_user( 1 );
		$result   = $ability->execute( array( 'id' => $customer_id ) );
		$customer = $result['customers'][0];
		$this->assertSame( 'pii-customer@edd.test', $customer['email'] );
		$this->assertContains( 'pii-customer@edd.test', $customer['emails'] );
	}

	public function test_customer_read_list_and_search() {
		$findable_id = parent::edd()->customer->create(
			array(
				'name'  => 'Findable Person',
				'email' => 'findable-person@edd.test',
			)
		);
		parent::edd()->customer->create(
			array(
				'name'  => 'Someone Else',
				'email' => 'someone-else@edd.test',
			)
		);
		parent::edd()->customer->create(
			array(
				'name'  => 'Third Customer',
				'email' => 'third-customer@edd.test',
			)
		);

		$ability = new Read();

		$result = $ability->execute( array( 'limit' => 2, 'offset' => 0 ) );
		$this->assertCount( 2, $result['customers'] );
		$this->assertSame( 2, $result['limit'] );
		$this->assertGreaterThanOrEqual( 3, $result['total'] );
		$this->assertTrue( $result['has_more'] );

		// List results never include the address key.
		$this->assertArrayNotHasKey( 'address', $result['customers'][0] );

		$search = $ability->execute( array( 'search' => 'Findable' ) );
		$this->assertGreaterThanOrEqual( 1, $search['total'] );
		$this->assertContains( $findable_id, wp_list_pluck( $search['customers'], 'id' ) );
	}

	public function test_customer_read_missing_customer_returns_not_found() {
		$ability = new Read();

		$result = $ability->execute( array( 'id' => 999999 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_customer_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		$result = $ability->execute( array( 'email' => 'nobody-here@edd.test' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_customer_not_found', $result->get_error_code() );
	}

	public function test_customer_create_happy_path() {
		$ability = new Create();
		$result  = $ability->execute(
			array(
				'email' => 'new-customer@edd.test',
				'name'  => 'New Customer',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'New Customer', $result['name'] );
		$this->assertSame( 'new-customer@edd.test', $result['email'] );
		$this->assertSame( 0, $result['purchase_count'] );

		// The customer record was written.
		$customer = edd_get_customer_by( 'email', 'new-customer@edd.test' );
		$this->assertNotEmpty( $customer );
		$this->assertSame( (int) $customer->id, $result['id'] );
	}

	public function test_customer_create_duplicate_email_conflicts() {
		parent::edd()->customer->create(
			array(
				'name'  => 'Existing Customer',
				'email' => 'dupe-customer@edd.test',
			)
		);

		$ability = new Create();
		$result  = $ability->execute( array( 'email' => 'dupe-customer@edd.test' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_customer_exists', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_customer_update_renames_customer() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'Original Name',
				'email' => 'rename-me@edd.test',
			)
		);

		$ability = new Update();
		$result  = $ability->execute(
			array(
				'customer_id' => $customer_id,
				'name'        => 'Renamed Customer',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Renamed Customer', $result['name'] );
		$this->assertSame( 'Renamed Customer', edd_get_customer( $customer_id )->name );
	}

	public function test_customer_update_email_in_use_conflicts() {
		parent::edd()->customer->create(
			array(
				'name'  => 'Customer A',
				'email' => 'customer-a@edd.test',
			)
		);
		$customer_b = parent::edd()->customer->create(
			array(
				'name'  => 'Customer B',
				'email' => 'customer-b@edd.test',
			)
		);

		$ability = new Update();
		$result  = $ability->execute(
			array(
				'customer_id' => $customer_b,
				'email'       => 'customer-a@edd.test',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_email_in_use', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_customer_update_missing_customer_returns_not_found() {
		$ability = new Update();
		$result  = $ability->execute(
			array(
				'customer_id' => 999999,
				'name'        => 'Nobody',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_customer_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_customer_update_requires_a_field() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'No Updates',
				'email' => 'no-updates@edd.test',
			)
		);

		$ability = new Update();
		$result  = $ability->execute( array( 'customer_id' => $customer_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_no_updates', $result->get_error_code() );
	}

	public function test_note_add_and_read_round_trip() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'Noted Customer',
				'email' => 'noted-customer@edd.test',
			)
		);

		$add    = new NoteAdd();
		$result = $add->execute(
			array(
				'customer_id' => $customer_id,
				'note'        => 'Called about a refund. <script>alert(1)</script>',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $customer_id, $result['customer_id'] );
		$this->assertStringNotContainsString( '<script>', $result['note']['content'] );
		$this->assertStringContainsString( 'Called about a refund.', $result['note']['content'] );
		$this->assertSame( 1, $result['note']['user_id'] );

		$read  = new NoteRead();
		$notes = $read->execute( array( 'customer_id' => $customer_id ) );

		$this->assertGreaterThanOrEqual( 1, $notes['total'] );
		$contents = wp_list_pluck( $notes['notes'], 'content' );
		$this->assertContains( $result['note']['content'], $contents );
	}

	public function test_permissions_split_between_read_and_write() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( 'view_shop_reports' );
		wp_set_current_user( $user_id );

		// Read abilities only require view_shop_reports.
		$this->assertTrue( ( new Read() )->check_permissions( array() ) );
		$this->assertTrue( ( new NoteRead() )->check_permissions( array() ) );

		// Write abilities require edit_shop_payments.
		$this->assertFalse( ( new Create() )->check_permissions( array() ) );
		$this->assertFalse( ( new Update() )->check_permissions( array() ) );
		$this->assertFalse( ( new NoteAdd() )->check_permissions( array() ) );
	}

	public function test_customer_read_honors_the_view_customers_role_filter() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( 'view_shop_reports' );
		wp_set_current_user( $user_id );

		// The fixture has to actually hold the default capability, or the
		// assertion below would pass for the wrong reason.
		$this->assertTrue( current_user_can( 'view_shop_reports' ) );
		$this->assertTrue( ( new Read() )->check_permissions( array() ) );

		// A store that narrows who may view customers narrows the ability too.
		$narrow = function () {
			return 'manage_shop_settings';
		};
		add_filter( 'edd_view_customers_role', $narrow );

		$this->assertFalse( current_user_can( 'manage_shop_settings' ) );
		$this->assertFalse( ( new Read() )->check_permissions( array() ) );
		$this->assertFalse( ( new NoteRead() )->check_permissions( array() ) );

		remove_filter( 'edd_view_customers_role', $narrow );
	}

	public function test_customer_write_honors_the_edit_customers_role_filter() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( 'edit_shop_payments' );
		wp_set_current_user( $user_id );

		// Write abilities carry a second gate, so it has to be open for this to
		// be measuring the capability rather than the store setting.
		edd_update_option( \EDD\Abilities\Loader::WRITE_SETTING, true );

		$this->assertTrue( current_user_can( 'edit_shop_payments' ) );
		$this->assertTrue( ( new Create() )->check_permissions( array() ) );

		$narrow = function () {
			return 'manage_shop_settings';
		};
		add_filter( 'edd_edit_customers_role', $narrow );

		$this->assertFalse( current_user_can( 'manage_shop_settings' ) );
		$this->assertFalse( ( new Create() )->check_permissions( array() ) );
		$this->assertFalse( ( new Update() )->check_permissions( array() ) );
		$this->assertFalse( ( new NoteAdd() )->check_permissions( array() ) );

		remove_filter( 'edd_edit_customers_role', $narrow );
		edd_delete_option( \EDD\Abilities\Loader::WRITE_SETTING );
	}

	public function test_customer_update_email_change_syncs_email_addresses() {
		$customer_id = parent::edd()->customer->create( array( 'email' => 'before@edd.test' ) );

		$ability = new Update();
		$result  = $ability->execute(
			array(
				'customer_id' => $customer_id,
				'email'       => 'after@edd.test',
			)
		);

		$this->assertIsArray( $result );

		// The new email resolves through the email-addresses lookup table.
		$found = edd_get_customer_by( 'email', 'after@edd.test' );
		$this->assertNotEmpty( $found );
		$this->assertSame( $customer_id, (int) $found->id );
		$this->assertSame( 'after@edd.test', $found->email );

		// The customer's email list includes the new primary address.
		$emails = (array) edd_get_customer( $customer_id )->emails;
		$this->assertContains( 'after@edd.test', $emails );
	}

	public function test_customer_update_to_an_email_owned_by_a_wp_user_conflicts() {
		$customer_id = parent::edd()->customer->create( array( 'email' => 'holder@edd.test' ) );

		// EDD_Customer::email_exists() also consults the WordPress users table,
		// so an address belonging to a user with no customer record still
		// blocks the write. The admin flow succeeds here, so this has to be a
		// stated conflict rather than an opaque failure.
		$user_id = self::factory()->user->create( array( 'user_email' => 'wpuser@edd.test' ) );
		$this->assertGreaterThan( 0, $user_id );
		$this->assertFalse( (bool) edd_get_customer_by( 'email', 'wpuser@edd.test' ) );

		$result = ( new Update() )->execute(
			array(
				'customer_id' => $customer_id,
				'email'       => 'wpuser@edd.test',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_email_in_use', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_customer_update_reports_a_change_it_made() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'Before',
				'email' => 'real-change@edd.test',
			)
		);

		$result = ( new Update() )->execute(
			array(
				'customer_id' => $customer_id,
				'name'        => 'After',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertCount( 1, $this->get_ability_notes( $customer_id ) );
	}

	public function test_customer_update_to_the_email_it_already_has_changes_nothing() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'Unchanged',
				'email' => 'already-mine@edd.test',
			)
		);

		// The fixture: the values really are the ones on file, so the ability is
		// being asked for what it already holds.
		$customer = edd_get_customer( $customer_id );
		$this->assertSame( 'already-mine@edd.test', $customer->email );
		$this->assertSame( 'Unchanged', $customer->name );
		$this->assertSame( 'active', $customer->status );

		$result = ( new Update() )->execute(
			array(
				'customer_id' => $customer_id,
				'email'       => 'already-mine@edd.test',
				'name'        => 'Unchanged',
				'status'      => 'active',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['updated'], 'A request which changed nothing reported a change.' );
		$this->assertEmpty(
			$this->get_ability_notes( $customer_id ),
			'A request which changed nothing recorded a note saying an assistant changed it.'
		);
	}

	public function test_customer_update_to_the_name_it_already_has_changes_nothing() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'Jane Doe',
				'email' => 'jane@edd.test',
			)
		);

		// The fixture: the name really is the one on file.
		$this->assertSame( 'Jane Doe', edd_get_customer( $customer_id )->name );

		$result = ( new Update() )->execute(
			array(
				'customer_id' => $customer_id,
				'name'        => 'Jane Doe',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['updated'], 'Restating the name reported a change.' );
		$this->assertEmpty(
			$this->get_ability_notes( $customer_id ),
			'Restating the name recorded a note saying an assistant changed it.'
		);
	}

	public function test_customer_note_add_reports_a_note_it_cannot_read_back() {
		$customer_id = parent::edd()->customer->create( array( 'email' => 'unreadable-note@edd.test' ) );

		// Delete the row between the write and the read back, which is the state
		// the guard exists for: the note saved but cannot be reported on.
		$delete_note = function ( $note_id ) {
			edd_delete_note( $note_id );
		};
		add_action( 'edd_note_added', $delete_note );

		$result = ( new NoteAdd() )->execute(
			array(
				'customer_id' => $customer_id,
				'note'        => 'A note which will not survive to be read.',
			)
		);

		remove_action( 'edd_note_added', $delete_note );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_saved_not_readable', $result->get_error_code() );
	}

	public function test_customer_update_with_no_input_returns_an_error() {
		$result = ( new Update() )->execute( null );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_missing_input', $result->get_error_code() );
		$this->assertStringContainsString( 'customer_id', $result->get_error_message() );
	}

	public function test_customer_create_rejects_a_non_string_email() {
		$before = edd_count_customers();

		$result = ( new Create() )->execute( array( 'email' => array( 'array@edd.test' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_invalid_input', $result->get_error_code() );
		$this->assertStringContainsString( 'email', $result->get_error_message() );
		$this->assertSame( $before, edd_count_customers() );
	}

	public function test_customer_create_rejects_a_malformed_email() {
		$before = edd_count_customers();

		$result = ( new Create() )->execute( array( 'email' => 'not-an-email' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_invalid_input', $result->get_error_code() );

		// The format failure is the one the validator reports without naming a field.
		$this->assertStringContainsString( 'email address', $result->get_error_message() );
		$this->assertSame( $before, edd_count_customers() );
	}

	public function test_customer_create_refuses_an_address_a_filter_empties() {
		$before = edd_count_customers();

		// The schema accepts this address, so the filter is the only thing emptying it.
		$empty_the_email = function () {
			return '';
		};
		add_filter( 'sanitize_email', $empty_the_email );

		$result = ( new Create() )->execute( array( 'email' => 'filtered-away@edd.test' ) );

		remove_filter( 'sanitize_email', $empty_the_email );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_invalid_email', $result->get_error_code() );
		$this->assertSame( $before, edd_count_customers() );
	}

	public function test_customer_update_refuses_an_address_a_filter_empties() {
		$customer_id = parent::edd()->customer->create( array( 'email' => 'keep-this@edd.test' ) );

		$empty_the_email = function () {
			return '';
		};
		add_filter( 'sanitize_email', $empty_the_email );

		$result = ( new Update() )->execute(
			array(
				'customer_id' => $customer_id,
				'email'       => 'filtered-away@edd.test',
			)
		);

		remove_filter( 'sanitize_email', $empty_the_email );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_invalid_email', $result->get_error_code() );
		$this->assertSame( 'keep-this@edd.test', edd_get_customer( $customer_id )->email );
	}

	public function test_customer_update_rejects_a_non_string_name() {
		$customer_id = parent::edd()->customer->create(
			array(
				'name'  => 'Original Name',
				'email' => 'non-string-name@edd.test',
			)
		);

		// The name the refusal has to leave alone is on the record to begin with.
		$this->assertSame( 'Original Name', edd_get_customer( $customer_id )->name );

		$result = ( new Update() )->execute(
			array(
				'customer_id' => $customer_id,
				'name'        => array( 'Replacement Name' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'edd_ability_invalid_input', $result->get_error_code() );
		$this->assertStringContainsString( 'name', $result->get_error_message() );
		$this->assertSame( 'Original Name', edd_get_customer( $customer_id )->name );
	}

	/**
	 * Gets only the notes an ability wrote on a customer.
	 *
	 * @param int $customer_id The customer ID.
	 * @return array
	 */
	private function get_ability_notes( int $customer_id ): array {
		$notes = edd_get_notes(
			array(
				'object_id'   => $customer_id,
				'object_type' => 'customer',
			)
		);

		return array_values(
			array_filter(
				$notes,
				function ( $note ) {
					return false !== strpos( $note->content, 'edd/customer-' );
				}
			)
		);
	}
}
