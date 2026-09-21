<?php
/**
 * Command Palette tests.
 *
 * @package EDD\Tests\CommandPalette
 */

namespace EDD\Tests\CommandPalette;

use EDD\Tests\PHPUnit\EDD_UnitTestCase;
use EDD\CommandPalette\CoreSources;
use EDD\CommandPalette\Navigation;
use EDD\CommandPalette\Registry;
use EDD\REST\Controllers\CommandPalette as CommandPalette_Controller;

/**
 * Command Palette tests.
 *
 * @group edd_command_palette
 */
class CommandPalette extends EDD_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected static $admin_user_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static $subscriber_user_id;

	/**
	 * A download ID used for search assertions.
	 *
	 * @var int
	 */
	protected static $download_id;

	/**
	 * Set up fixtures once.
	 */
	public static function wpSetUpBeforeClass() {
		self::$admin_user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::$download_id        = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_title'  => 'Command Palette Test Download',
				'post_status' => 'publish',
			)
		);

		// Ensure the admin has EDD's full shop capabilities (granted to administrators on a real install).
		$admin = new \WP_User( self::$admin_user_id );
		$roles = new \EDD_Roles();
		foreach ( $roles->get_core_caps() as $cap_group ) {
			foreach ( $cap_group as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		foreach ( array( 'view_shop_reports', 'manage_shop_discounts', 'manage_shop_settings' ) as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Reset the current user after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Registering a source degrades gracefully without the Abilities API.
	 *
	 * The inverse of the guard helper: on WordPress versions without the
	 * Abilities API the registry must return empty rather than fataling, which
	 * is what lets the feature no-op below 6.9.
	 */
	public function test_sources_no_op_without_abilities_api() {
		if ( Registry::is_supported() ) {
			$this->markTestSkipped( 'The Abilities API is available; the no-op path does not apply.' );
		}

		$registered = Registry::register(
			'edd/search-nothing',
			array(
				'label'           => 'Nothing',
				'capability'      => 'manage_options',
				'search_callback' => '__return_empty_array',
			)
		);

		$this->assertNull( $registered );
		$this->assertSame( array(), Registry::get_sources() );
	}

	/**
	 * Core sources register as abilities flagged for the command palette.
	 */
	public function test_core_sources_registered() {
		$this->requires_abilities_api();

		$names = array_map(
			function ( $ability ) {
				return $ability->get_name();
			},
			Registry::get_sources()
		);

		$this->assertContains( 'edd/search-orders', $names );
		$this->assertContains( 'edd/search-customers', $names );
		$this->assertContains( 'edd/search-discounts', $names );
		$this->assertContains( 'edd/search-downloads', $names );
	}

	/**
	 * Add-ons can register a source on EDD's own action.
	 *
	 * Also pins the reason the action is fired from inside the abilities hook:
	 * wp_register_ability() refuses to register outside it.
	 */
	public function test_addons_can_register_a_source_on_the_edd_action() {
		$this->requires_abilities_api();

		$probe = function () {
			Registry::register(
				'edd-tests/search-widgets',
				array(
					'label'           => 'Widgets',
					'capability'      => 'manage_shop_settings',
					'search_callback' => '__return_empty_array',
				)
			);
		};

		// Abilities only register while wp_abilities_api_init is running, so drop
		// the registered sources and re-run it with the probe on EDD's action.
		foreach ( array_keys( Registry::get_sources() ) as $name ) {
			wp_unregister_ability( $name );
		}
		add_action( 'edd/command_palette/register_sources', $probe );
		do_action( 'wp_abilities_api_init' );
		remove_action( 'edd/command_palette/register_sources', $probe );

		$names = array_keys( Registry::get_sources() );
		wp_unregister_ability( 'edd-tests/search-widgets' );

		// Assert the fixture: EDD re-registered its own sources, so the chain ran.
		$this->assertContains( 'edd/search-orders', $names );
		$this->assertContains( 'edd-tests/search-widgets', $names );
	}

	/**
	 * Sources are read-only and exposed in REST.
	 */
	public function test_sources_are_readonly_and_in_rest() {
		$this->requires_abilities_api();

		$orders = wp_get_ability( 'edd/search-orders' );

		$this->assertNotNull( $orders );
		$this->assertTrue( (bool) $orders->get_meta_item( 'show_in_rest' ) );
		$this->assertTrue( (bool) $orders->get_meta_item( Registry::META_SOURCE ) );

		$annotations = $orders->get_meta_item( 'annotations' );
		$this->assertTrue( ! empty( $annotations['readonly'] ) );
	}

	/**
	 * The orders source is flagged for numeric searches; others are not.
	 */
	public function test_orders_source_flagged_numeric() {
		$this->requires_abilities_api();

		$this->assertTrue( (bool) wp_get_ability( 'edd/search-orders' )->get_meta_item( Registry::META_NUMERIC ) );
		$this->assertFalse( (bool) wp_get_ability( 'edd/search-downloads' )->get_meta_item( Registry::META_NUMERIC ) );
	}

	/**
	 * A subscriber cannot access privileged sources.
	 */
	public function test_source_permission_denied_for_subscriber() {
		$this->requires_abilities_api();

		wp_set_current_user( self::$subscriber_user_id );

		$orders = wp_get_ability( 'edd/search-orders' );
		$this->assertNotTrue( $orders->check_permissions( 'anything' ) );
	}

	/**
	 * An administrator can access the orders source.
	 */
	public function test_source_permission_granted_for_admin() {
		$this->requires_abilities_api();

		wp_set_current_user( self::$admin_user_id );

		$orders = wp_get_ability( 'edd/search-orders' );
		$this->assertTrue( $orders->check_permissions( 'anything' ) );
	}

	/**
	 * The downloads source finds a matching download by title.
	 */
	public function test_controller_returns_download_match_for_admin() {
		$this->requires_abilities_api();

		wp_set_current_user( self::$admin_user_id );

		$data = $this->search( 'Command Palette Test' );

		$this->assertArrayHasKey( 'results', $data );

		// The label leads with the brand and the type, so match on the title substring.
		$matched = array_filter(
			wp_list_pluck( $data['results'], 'label' ),
			function ( $label ) {
				return false !== strpos( $label, 'Command Palette Test Download' );
			}
		);
		$this->assertNotEmpty( $matched );
	}

	/**
	 * The controller returns no results for a subscriber (all sources skipped).
	 */
	public function test_controller_skips_unauthorized_sources() {
		$this->requires_abilities_api();

		wp_set_current_user( self::$subscriber_user_id );

		$this->assertSame( array(), $this->search( 'Command Palette Test' )['results'] );
	}

	/**
	 * A numeric search must not match digits inside a payment key.
	 *
	 * The generic order search covers the payment key, so a short number matches
	 * inside the random hash and surfaces an unrelated order.
	 */
	public function test_numeric_order_search_ignores_payment_key_matches() {
		$order = parent::edd()->order->create_and_get(
			array(
				'payment_key' => '98239b3ecc2213af712068844a15ba5f',
				'email'       => 'payment-key-probe@edd.test',
			)
		);

		// Assert the fixture: the key really does contain the digits searched for.
		$this->assertStringContainsString( '8239', $order->payment_key );
		$this->assertNotSame( '8239', (string) $order->id );

		// Positive control: the order is findable by its own ID, so an empty
		// result below means the payment key was ignored, not that search is broken.
		$this->assertNotEmpty(
			$this->labels_matching( CoreSources::search_orders( (string) $order->id ), 'payment-key-probe@edd.test' )
		);

		$this->assertEmpty(
			$this->labels_matching( CoreSources::search_orders( '8239' ), 'payment-key-probe@edd.test' )
		);
	}

	/**
	 * A numeric search still matches a bare sequential order number.
	 */
	public function test_numeric_order_search_matches_a_sequential_order_number() {
		$order = parent::edd()->order->create_and_get(
			array(
				'order_number' => '900123',
				'email'        => 'order-number-probe@edd.test',
			)
		);

		// Assert the fixture: the order number is set and differs from the ID.
		$this->assertSame( '900123', (string) $order->order_number );
		$this->assertNotSame( '900123', (string) $order->id );

		$this->assertNotEmpty(
			$this->labels_matching( CoreSources::search_orders( '900123' ), 'order-number-probe@edd.test' )
		);
	}

	/**
	 * A numeric search matches an order number carrying a sequential prefix.
	 *
	 * A store with a prefix stores `EDD-900124`, and `900124` is the number the
	 * customer reads off their receipt.
	 */
	public function test_numeric_order_search_matches_a_prefixed_order_number() {
		$order = parent::edd()->order->create_and_get(
			array(
				'order_number' => 'EDD-900124',
				'email'        => 'prefixed-number-probe@edd.test',
			)
		);

		// Assert the fixture: the stored number is prefixed, so it is not the term.
		$this->assertSame( 'EDD-900124', (string) $order->order_number );
		$this->assertNotSame( '900124', (string) $order->id );

		$this->assertNotEmpty(
			$this->labels_matching( CoreSources::search_orders( '900124' ), 'prefixed-number-probe@edd.test' )
		);
	}

	/**
	 * Results whose URL is not a usable URL are dropped.
	 */
	public function test_results_with_an_unusable_url_are_dropped() {
		$this->requires_abilities_api();
		wp_set_current_user( self::$admin_user_id );

		$probe = function () {
			Registry::register(
				'edd-tests/url-probe',
				array(
					'label'           => 'URL Probe',
					'capability'      => 'manage_shop_settings',
					'search_callback' => function () {
						return array(
							array(
								'label' => 'Probe blocked',
								'url'   => 'javascript:alert(1)',
							),
							array(
								'label' => 'Probe allowed',
								'url'   => admin_url( 'index.php' ),
							),
						);
					},
				)
			);
		};

		// Abilities only register on their own init action, so drop the already
		// registered sources and re-run it with the probe attached.
		foreach ( array_keys( Registry::get_sources() ) as $name ) {
			wp_unregister_ability( $name );
		}
		add_action( 'wp_abilities_api_init', $probe );
		do_action( 'wp_abilities_api_init' );
		remove_action( 'wp_abilities_api_init', $probe );

		$labels = wp_list_pluck( $this->search( 'probe' )['results'], 'label' );

		wp_unregister_ability( 'edd-tests/url-probe' );

		// Assert the fixture: the probe ran and its usable result came through.
		$this->assertContains( 'Probe allowed', $labels );
		$this->assertNotContains( 'Probe blocked', $labels );
	}

	/**
	 * Both hidden pages leave the menu before the palette reads it.
	 *
	 * WordPress builds its menu-derived commands on admin_enqueue_scripts, which
	 * runs before admin_head. A page hidden on admin_head is still in $submenu
	 * when the palette walks it, so it shows up as a navigable command. The
	 * registering code has to do the hooking here, or the assertion only sees
	 * what the test just added.
	 */
	public function test_hidden_pages_are_removed_before_the_palette_reads_the_menu() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		global $menu, $submenu;
		$original_menu    = $menu;
		$original_submenu = $submenu;

		$wizard = new \EDD\Admin\Onboarding\Wizard();
		$wizard->add_menu_item();
		\EDD\Admin\Menu\Pages::register();

		$menu    = $original_menu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu = $original_submenu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$removals = array(
			'Setup'        => array( $wizard, 'maybe_remove_menu_item' ),
			'EDD Upgrades' => array( \EDD\Admin\Menu\Pages::class, 'remove_upgrade_page_from_menu' ),
		);

		// Record where each removal landed and unhook everything this test
		// registered before asserting. A failing assertion would otherwise
		// leave these hooks, and the admin_head one add_menu_item() adds, in
		// place for the rest of the run.
		$hooked = array();
		foreach ( $removals as $page => $callback ) {
			$hooked[ $page ] = array(
				'admin_enqueue_scripts' => has_action( 'admin_enqueue_scripts', $callback ),
				'admin_head'            => has_action( 'admin_head', $callback ),
			);
			remove_action( 'admin_enqueue_scripts', $callback );
		}
		remove_action( 'admin_head', array( $wizard, 'adjust_menu_item_class' ) );

		foreach ( $hooked as $page => $hooks ) {
			$this->assertSame(
				9,
				$hooks['admin_enqueue_scripts'],
				"The {$page} item must be removed on admin_enqueue_scripts, before the palette reads the menu."
			);
			$this->assertFalse(
				$hooks['admin_head'],
				'admin_head is too late; the palette has already captured the menu.'
			);
		}
	}

	/**
	 * The Setup menu item is dropped once onboarding has been completed.
	 */
	public function test_setup_item_is_removed_once_onboarding_is_complete() {
		update_option( 'edd_onboarding_completed', true );
		$slugs = $this->submenu_slugs_after_removal(
			'edit.php?post_type=download',
			array( array( 'Setup', 'manage_shop_settings', 'edd-onboarding-wizard' ) ),
			array( new \EDD\Admin\Onboarding\Wizard(), 'maybe_remove_menu_item' )
		);
		delete_option( 'edd_onboarding_completed' );

		$this->assertNotContains( 'edd-onboarding-wizard', $slugs );
	}

	/**
	 * The Setup menu item stays while onboarding is still outstanding.
	 *
	 * The negative control for the test above: an unconditional removal would
	 * satisfy it while breaking the wizard for a new store.
	 */
	public function test_setup_item_remains_until_onboarding_is_complete() {
		delete_option( 'edd_onboarding_completed' );
		$slugs = $this->submenu_slugs_after_removal(
			'edit.php?post_type=download',
			array( array( 'Setup', 'manage_shop_settings', 'edd-onboarding-wizard' ) ),
			array( new \EDD\Admin\Onboarding\Wizard(), 'maybe_remove_menu_item' )
		);

		$this->assertContains( 'edd-onboarding-wizard', $slugs );
	}

	/**
	 * The upgrades page is dropped from the Dashboard menu.
	 */
	public function test_upgrades_item_is_removed_from_the_dashboard_menu() {
		$slugs = $this->submenu_slugs_after_removal(
			'index.php',
			array( array( 'EDD Upgrades', 'manage_shop_settings', 'edd-upgrades' ) ),
			array( \EDD\Admin\Menu\Pages::class, 'remove_upgrade_page_from_menu' )
		);

		$this->assertNotContains( 'edd-upgrades', $slugs );
	}

	/**
	 * Navigation commands are capability filtered.
	 */
	public function test_navigation_capability_filtering() {
		$navigation = new Navigation();

		wp_set_current_user( self::$subscriber_user_id );
		$this->assertSame( array(), $navigation->get() );

		wp_set_current_user( self::$admin_user_id );
		$commands = $navigation->get();
		$this->assertNotEmpty( $commands );

		$names = wp_list_pluck( $commands, 'name' );

		// A non-default settings sub-view is surfaced.
		$this->assertContains( 'edd/nav/settings-gateways', $names );

		// The default tab of each group is omitted (already a core menu command).
		$this->assertNotContains( 'edd/nav/settings-general', $names );
		$this->assertNotContains( 'edd/nav/tools-general', $names );
		$this->assertNotContains( 'edd/nav/emails-general', $names );
	}

	/**
	 * The emails commands are built from the screen's own tab list.
	 *
	 * A second hardcoded copy would silently stop matching the screen when a tab
	 * is added, so assert every non-default tab reaches the palette.
	 */
	public function test_emails_commands_cover_every_screen_tab() {
		wp_set_current_user( self::$admin_user_id );

		$tabs = \EDD\Admin\Emails\Screen::get_tabs();
		$this->assertNotEmpty( $tabs );

		$names = wp_list_pluck( ( new Navigation() )->get(), 'name' );
		foreach ( array_keys( $tabs ) as $slug ) {
			if ( \EDD\Admin\Emails\Screen::DEFAULT_TAB === $slug ) {
				continue;
			}
			$this->assertContains( 'edd/nav/emails-' . $slug, $names );
		}
	}

	/**
	 * Report commands use the label from the report's attribute array.
	 *
	 * The reports registry returns the full attribute array per report; guards
	 * against that array being stringified into the command label ("Array").
	 */
	public function test_report_command_label_is_a_string() {
		wp_set_current_user( self::$admin_user_id );
		$navigation = new Navigation();

		$filter = function ( $reports ) {
			$reports['cp_fake_report'] = array(
				'label' => 'Fake Report',
				'group' => 'core',
			);
			return $reports;
		};

		add_filter( 'edd_get_reports', $filter );
		$commands = $navigation->get();
		remove_filter( 'edd_get_reports', $filter );

		$labels = wp_list_pluck( $commands, 'label' );
		$this->assertContains( 'EDD Reports: Fake Report', $labels );

		foreach ( $labels as $label ) {
			$this->assertIsString( $label );
			$this->assertStringNotContainsString( 'Array', $label );
		}
	}

	/**
	 * A numeric search only runs the sources that can resolve an ID.
	 *
	 * The other sources match text with an unanchored LIKE, so any number also
	 * pulls in records whose code or title merely contains those digits.
	 */
	public function test_numeric_search_runs_only_sources_that_resolve_ids() {
		$this->requires_abilities_api();
		wp_set_current_user( self::$admin_user_id );

		$order = parent::edd()->order->create_and_get(
			array(
				'order_number' => '900131',
				'email'        => 'numeric-gate-probe@edd.test',
			)
		);
		parent::edd()->discount->create_and_get(
			array(
				'name'   => 'Numeric Gate Discount',
				'code'   => 'SAVE900131NOW',
				'status' => 'active',
			)
		);

		// Assert the fixture: the discount code really does contain the term, so
		// an absent discount below means it was skipped, not that it never matched.
		$this->assertNotEmpty( $this->labels_matching( CoreSources::search_discounts( '900131' ), 'SAVE900131NOW' ) );
		$this->assertSame( '900131', (string) $order->order_number );

		$results = $this->search( '900131' )['results'];

		$this->assertNotEmpty( $this->labels_matching( $results, 'numeric-gate-probe@edd.test' ) );
		$this->assertEmpty( $this->labels_matching( $results, 'SAVE900131NOW' ) );
	}

	/**
	 * A numeric search still reaches the other sources when no ID matches.
	 *
	 * Without the fallback, a download or discount with a number in its name
	 * would become unreachable by that number.
	 */
	public function test_numeric_search_falls_back_when_no_id_source_matches() {
		$this->requires_abilities_api();
		wp_set_current_user( self::$admin_user_id );

		parent::edd()->discount->create_and_get(
			array(
				'name'   => 'Fallback Discount',
				'code'   => 'FALLBACK987654321',
				'status' => 'active',
			)
		);

		// Assert the fixture: no order answers this term, so the fallback is the
		// only path that can return the discount.
		$this->assertEmpty( CoreSources::search_orders( '987654321' ) );

		$this->assertNotEmpty(
			$this->labels_matching( $this->search( '987654321' )['results'], 'FALLBACK987654321' )
		);
	}

	/**
	 * An order with no email renders without a trailing separator.
	 */
	public function test_order_without_an_email_renders_without_a_trailing_separator() {
		$order = parent::edd()->order->create_and_get( array( 'email' => 'blank-email-probe@edd.test' ) );
		edd_update_order( $order->id, array( 'email' => '' ) );
		$order = edd_get_order( $order->id );

		// Assert the fixture: the email really is empty.
		$this->assertEmpty( $order->email );

		$labels = wp_list_pluck( CoreSources::search_orders( (string) $order->id ), 'label' );

		$this->assertContains( 'EDD Order: ' . $order->get_number(), $labels );
	}

	/**
	 * A discount with no name renders without a trailing separator.
	 */
	public function test_discount_without_a_name_renders_without_a_trailing_separator() {
		$discount = parent::edd()->discount->create_and_get(
			array(
				'name'   => '',
				'code'   => 'NONAMEPROBE',
				'status' => 'active',
			)
		);

		// Assert the fixture: the name really is empty.
		$this->assertEmpty( $discount->name );

		$labels = wp_list_pluck( CoreSources::search_discounts( 'NONAMEPROBE' ), 'label' );

		$this->assertContains( 'EDD Discount: NONAMEPROBE', $labels );
	}

	/**
	 * Every navigation label leads with the EDD brand.
	 *
	 * The palette is one flat list shared with every other plugin, so a label
	 * naming only its sub-view cannot be attributed to EDD.
	 */
	public function test_navigation_labels_lead_with_the_edd_brand() {
		wp_set_current_user( self::$admin_user_id );

		$labels = wp_list_pluck( ( new Navigation() )->get(), 'label' );

		// Assert the fixture: the admin's capabilities really did produce commands.
		$this->assertNotEmpty( $labels );

		// The tools tab that carries its own label and off-page URL.
		$this->assertContains( 'EDD Tools: System Info', $labels );

		foreach ( $labels as $label ) {
			$this->assertStringStartsWith( 'EDD', $label );
		}
	}

	/**
	 * Sub-view commands are matched on their sub-view rather than on the brand.
	 *
	 * The palette scores a match at the start of the string highest, so a
	 * command matched on its displayed label loses a query like "tools" to
	 * every other plugin's rows.
	 */
	public function test_sub_view_commands_are_matched_on_the_sub_view() {
		wp_set_current_user( self::$admin_user_id );

		$commands = array();
		foreach ( ( new Navigation() )->get() as $command ) {
			if ( isset( $command['searchLabel'] ) ) {
				$commands[ $command['label'] ] = $command['searchLabel'];
			}
		}

		// Assert the fixture: sub-view commands really do carry a search label.
		$this->assertNotEmpty( $commands );
		$this->assertArrayHasKey( 'EDD Tools: System Info', $commands );
		$this->assertSame( 'Tools: System Info EDD', $commands['EDD Tools: System Info'] );

		foreach ( $commands as $label => $search_label ) {
			$this->assertStringStartsNotWith( 'EDD', $search_label, "Search label for {$label} still leads with the brand." );
			$this->assertStringContainsString( 'EDD', $search_label, "Search label for {$label} cannot be found by brand." );
		}
	}

	/**
	 * A command with no search label omits the key rather than sending an empty one.
	 *
	 * The palette matches on the search label whenever it is present, so an
	 * empty string would make the command unfindable.
	 */
	public function test_command_without_a_search_label_omits_the_key() {
		wp_set_current_user( self::$admin_user_id );

		$quick_actions = array_filter(
			( new Navigation() )->get(),
			function ( $command ) {
				return 'EDD: Add Order' === $command['label'];
			}
		);

		// Assert the fixture: the quick action is present for this user.
		$this->assertCount( 1, $quick_actions );

		$this->assertArrayNotHasKey( 'searchLabel', reset( $quick_actions ) );
	}

	/**
	 * Every core source label leads with the EDD brand.
	 */
	public function test_source_labels_lead_with_the_edd_brand() {
		// The download source reads the edit link, which is empty without edit capabilities.
		wp_set_current_user( self::$admin_user_id );

		edd_add_customer(
			array(
				'name'  => 'Breadcrumb Probe',
				'email' => 'breadcrumb-probe@edd.test',
			)
		);

		$customers = wp_list_pluck( CoreSources::search_customers( 'breadcrumb-probe@edd.test' ), 'label' );
		$downloads = wp_list_pluck( CoreSources::search_downloads( 'Command Palette Test Download' ), 'label' );

		// Assert the fixtures: both sources really returned a row.
		$this->assertNotEmpty( $customers );
		$this->assertNotEmpty( $downloads );

		foreach ( array_merge( $customers, $downloads ) as $label ) {
			$this->assertStringStartsWith( 'EDD ', $label );
		}
	}

	/**
	 * A term that sanitizes below the minimum length is rejected.
	 *
	 * WordPress validates before it sanitizes, so a value measured raw can pass
	 * the guard and still reach the sources as an empty term.
	 */
	public function test_search_term_that_sanitizes_below_the_minimum_is_rejected() {
		wp_set_current_user( self::$admin_user_id );

		$route = '/edd/v3/' . \EDD\REST\Routes\CommandPalette::BASE;

		// Assert the fixture: the route is registered, or every dispatch below
		// would 404 and prove nothing.
		$this->assertArrayHasKey( $route, rest_get_server()->get_routes() );

		// sanitize_text_field() strips percent-encoded octets, so this is three
		// characters going in and none by the time a source would see it.
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'search', '%41' );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );

		// A genuine two-character term is still accepted.
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'search', 'ab' );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * A term made only of wildcards is rejected.
	 *
	 * The query layer reads `*` as a wildcard, so "**" is long enough to pass a
	 * raw length check and then matches every row in every source.
	 */
	public function test_wildcard_only_search_term_is_rejected() {
		wp_set_current_user( self::$admin_user_id );

		$route = '/edd/v3/' . \EDD\REST\Routes\CommandPalette::BASE;

		// Assert the fixture: a bare wildcard really does match rows it was never
		// given a term for, which is what makes the short-term guard matter.
		parent::edd()->order->create_and_get( array( 'email' => 'wildcard-probe@edd.test' ) );
		$this->assertNotEmpty( $this->labels_matching( CoreSources::search_orders( '**' ), 'wildcard-probe@edd.test' ) );

		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'search', '**' );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );

		// A wildcard alongside a real term is still allowed.
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'search', 'ab*' );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * A search term that is not a string is rejected.
	 *
	 * A custom validate_callback replaces the type check WordPress would
	 * otherwise run, so the guard has to reject the shape itself.
	 */
	public function test_non_string_search_term_is_rejected() {
		wp_set_current_user( self::$admin_user_id );

		$route = '/edd/v3/' . \EDD\REST\Routes\CommandPalette::BASE;

		// Assert the fixture: the route is registered, and an empty term really
		// does return rows, which is what the length guard is there to stop.
		$this->assertArrayHasKey( $route, rest_get_server()->get_routes() );
		parent::edd()->order->create_and_get( array( 'email' => 'empty-term-probe@edd.test' ) );
		$this->assertNotEmpty( CoreSources::search_orders( '' ) );

		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'search', array( 'ab' ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );

		// The same term as a string is still accepted.
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'search', 'ab' );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * The skipped report is the filtered default, not whichever registered first.
	 *
	 * Report registration is priority-ordered and the landing view is separately
	 * filterable, so inferring one from the other omits the wrong report and
	 * adds a duplicate of the landing page.
	 */
	public function test_default_report_view_comes_from_its_filter() {
		wp_set_current_user( self::$admin_user_id );
		$navigation = new Navigation();

		$reports = function ( $reports ) {
			$reports['cp_first']   = array(
				'label' => 'First Report',
				'group' => 'core',
			);
			$reports['cp_landing'] = array(
				'label' => 'Landing Report',
				'group' => 'core',
			);

			return $reports;
		};
		$default = function () {
			return 'cp_landing';
		};

		add_filter( 'edd_get_reports', $reports );
		add_filter( 'edd_default_report_view', $default );
		$labels = wp_list_pluck( $navigation->get(), 'label' );
		remove_filter( 'edd_default_report_view', $default );
		remove_filter( 'edd_get_reports', $reports );

		// Assert the fixture: the first-registered report is present, so an
		// absent landing report is a skip and not an empty list.
		$this->assertContains( 'EDD Reports: First Report', $labels );

		// The landing view is already surfaced by core's menu command.
		$this->assertNotContains( 'EDD Reports: Landing Report', $labels );
	}

	/**
	 * The Add Order command is present and points at the add-order screen.
	 */
	public function test_add_order_command_requires_the_orders_capability() {
		wp_set_current_user( self::$admin_user_id );

		$commands = ( new Navigation() )->get();
		$names    = wp_list_pluck( $commands, 'name' );
		$this->assertContains( 'edd/nav/add-order', $names );

		$url = $commands[ array_search( 'edd/nav/add-order', $names, true ) ]['url'];
		$this->assertStringContainsString( 'page=edd-payment-history', $url );
		$this->assertStringContainsString( 'view=add-order', $url );

		wp_set_current_user( self::$subscriber_user_id );
		$this->assertNotContains( 'edd/nav/add-order', wp_list_pluck( ( new Navigation() )->get(), 'name' ) );
	}

	/**
	 * Every navigation command carries the keyword it is also matched on.
	 */
	public function test_navigation_commands_carry_a_keyword() {
		wp_set_current_user( self::$admin_user_id );

		$commands = ( new Navigation() )->get();
		$this->assertNotEmpty( $commands );

		foreach ( $commands as $command ) {
			$this->assertArrayHasKey( 'keywords', $command );
			$this->assertNotEmpty( $command['keywords'], "Command {$command['name']} has no keyword." );
		}
	}

	/**
	 * A source missing a required argument is rejected and says so.
	 */
	public function test_registering_a_source_without_a_search_callback_is_rejected() {
		$this->requires_abilities_api();
		$this->setExpectedIncorrectUsage( 'EDD\CommandPalette\Registry::register' );

		$this->assertNull(
			Registry::register(
				'edd-tests/incomplete',
				array(
					'label'      => 'Incomplete',
					'capability' => 'manage_shop_settings',
				)
			)
		);
	}

	/**
	 * Skip a test that needs the Abilities API.
	 *
	 * Search sources are registered as abilities, which are only available on
	 * WordPress 6.9+. The navigation commands do not depend on them, so they
	 * are covered on every supported WordPress version.
	 *
	 * @return void
	 */
	private function requires_abilities_api() {
		if ( ! Registry::is_supported() ) {
			$this->markTestSkipped( 'The Abilities API requires WordPress 6.9+.' );
		}
	}

	/**
	 * Run an aggregated search through the REST controller.
	 *
	 * @param string $term The search term.
	 * @return array The response data.
	 */
	private function search( $term ) {
		$request = new \WP_REST_Request( 'GET', '/edd/v3/command-palette/search' );
		$request->set_param( 'search', $term );

		return ( new CommandPalette_Controller() )->search( $request )->get_data();
	}

	/**
	 * Filter search result labels down to those containing a needle.
	 *
	 * @param array[] $results The search results.
	 * @param string  $needle  The substring to match.
	 * @return array The matching labels.
	 */
	private function labels_matching( array $results, $needle ) {
		return array_filter(
			wp_list_pluck( $results, 'label' ),
			function ( $label ) use ( $needle ) {
				return false !== strpos( $label, $needle );
			}
		);
	}

	/**
	 * Seed a submenu, run a removal callback, and report the slugs left behind.
	 *
	 * @param string   $parent   The parent menu slug.
	 * @param array[]  $items    The submenu items to seed.
	 * @param callable $callback The removal callback to run.
	 * @return array The remaining submenu slugs.
	 */
	private function submenu_slugs_after_removal( $parent, array $items, $callback ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		global $submenu;
		$original = $submenu;

		$submenu[ $parent ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Assert the fixture is really in place before asserting the behavior.
		$this->assertSame( $items[0][2], $submenu[ $parent ][0][2] );

		call_user_func( $callback );

		$slugs = wp_list_pluck( (array) ( $submenu[ $parent ] ?? array() ), 2 );

		$submenu = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return $slugs;
	}
}
