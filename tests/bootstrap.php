<?php
namespace EDD\Tests;

use Yoast\WPTestUtils\WPIntegration;

$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SERVER_NAME'] = '';
$PHP_SELF = $GLOBALS['PHP_SELF'] = $_SERVER['PHP_SELF'] = '/index.php';

define( 'EDD_USE_PHP_SESSIONS', false );
define( 'WP_USE_THEMES', false );
define( 'EDD_DOING_TESTS', true );

$plugin_dir = dirname( dirname( __FILE__ ) );

require_once $plugin_dir . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

// Find WordPress.
$_tests_dir = WPIntegration\get_path_to_wp_test_dir();

// Find WordPress core.
$_core_dir = getenv( 'WP_CORE_DIR' );

if ( ! $_core_dir ) {
	$_core_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // WPCS: XSS ok.
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugins.
 *
 * @since 1.0.0
 */
tests_add_filter(
	'muplugins_loaded',
	function() use ( $plugin_dir, $_core_dir ) {
		require $plugin_dir . '/easy-digital-downloads.php';

		// Load EDD Recurring if it was requested via --extra recurring.
		if ( 'recurring' === getenv( 'TEST_EXTRA_PLUGINS' ) ) {
			$recurring_path = $_core_dir . '/wp-content/plugins/edd-recurring/edd-recurring.php';
			if ( file_exists( $recurring_path ) ) {
				echo "Loading EDD Recurring via muplugins_loaded...\n";
				require $recurring_path;
			}
		}

		// Load Elementor if it was requested via --extra elementor.
		if ( false !== strpos( (string) getenv( 'TEST_EXTRA_PLUGINS' ), 'elementor' ) ) {
			$elementor_path = $_core_dir . '/wp-content/plugins/elementor/elementor.php';
			if ( file_exists( $elementor_path ) ) {
				echo "Loading Elementor via muplugins_loaded...\n";
				require $elementor_path;

				// Several Elementor subsystems (Kits Manager, Global Classes, ...)
				// register before_delete_post / wp_trash_post handlers that dereference
				// Plugin::$instance (its kits manager, active kit, documents, ...).
				// During EDD's per-class teardown — which force-deletes download posts —
				// that singleton is not guaranteed to be initialized, so those handlers
				// fatally error and take down every test class. Strip every Elementor
				// delete handler at the front of each delete hook, so whichever handlers
				// Elementor has registered by then (they load on different hooks) never
				// run during a test deletion. This only removes hooks (both closures and
				// object method callbacks); it never re-registers Elementor's elements,
				// which would fatal on a double declaration.
				$edd_detach_elementor_delete_handlers = function () {
					$delete_hooks = array(
						'before_delete_post',
						'delete_post',
						'deleted_post',
						'after_delete_post',
						'wp_trash_post',
						'trashed_post',
					);

					foreach ( $delete_hooks as $hook ) {
						if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
							continue;
						}

						foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $priority => $callbacks ) {
							foreach ( $callbacks as $callback ) {
								$function = $callback['function'];

								try {
									if ( $function instanceof \Closure ) {
										$reflection = new \ReflectionFunction( $function );
									} elseif ( is_string( $function ) && false !== strpos( $function, '::' ) ) {
										$reflection = new \ReflectionMethod( $function );
									} elseif ( is_string( $function ) && function_exists( $function ) ) {
										$reflection = new \ReflectionFunction( $function );
									} elseif ( is_array( $function ) && isset( $function[0], $function[1] ) ) {
										$class      = is_object( $function[0] ) ? get_class( $function[0] ) : $function[0];
										$reflection = new \ReflectionMethod( $class, $function[1] );
									} else {
										continue;
									}
								} catch ( \ReflectionException $e ) {
									continue;
								}

								// Match by source file: any delete handler defined inside the
								// Elementor plugin is one of the singleton-dereferencing
								// handlers, whatever its callback shape or object binding.
								$file = $reflection->getFileName();
								if ( is_string( $file ) && false !== strpos( $file, '/plugins/elementor/' ) ) {
									remove_action( $hook, $function, $priority );
								}
							}
						}
					}
				};

				add_action( 'before_delete_post', $edd_detach_elementor_delete_handlers, PHP_INT_MIN );
				add_action( 'wp_trash_post', $edd_detach_elementor_delete_handlers, PHP_INT_MIN );
			}
		}
	}
);

WPIntegration\bootstrap_it();

activate_plugin( 'easy-digital-downloads-pro/easy-digital-downloads.php' );

if ( ! defined( 'EDD_VERSION' ) ) {
	echo "EDD could not be activated. Check your PHP and WordPress versions." . PHP_EOL;
	exit( 1 );
}

echo "Setting up Easy Digital Downloads...\n";

// Load the reusable Elementor fixture traits UNCONDITIONALLY (not gated on
// --extra elementor). The migrated Elementor test files `use` these traits at
// class-declaration time on every run, so the trait definitions must always be
// available — otherwise the default (non-Elementor) run fatals with
// "Trait ...RealElementorFixture not found" the moment PHPUnit links those
// classes. These files are pure trait definitions (their Elementor references
// are method-signature type hints resolved only at call time, and every method
// skips via edd_require_real_elementor() when Elementor is absent), so loading
// them without Elementor is safe. The Elementor plugin load/activate + singleton
// capture stay gated on --extra elementor below. Note: the widget-fixture trait
// `use`s the base fixture trait, so load the base first.
$edd_elementor_fixtures = array(
	$plugin_dir . '/tests/elementor/support/trait-real-elementor-fixture.php',
	$plugin_dir . '/tests/elementor/support/trait-real-elementor-widget-fixture.php',
);
foreach ( $edd_elementor_fixtures as $edd_elementor_fixture ) {
	if ( file_exists( $edd_elementor_fixture ) ) {
		require_once $edd_elementor_fixture;
	}
}

// Only look for/activate EDD Recurring if it was requested via --extra recurring.
if ( 'recurring' === getenv( 'TEST_EXTRA_PLUGINS' ) ) {
	$recurring_path = $_core_dir . '/wp-content/plugins/edd-recurring/edd-recurring.php';
	echo "Checking for EDD Recurring at: $recurring_path\n";
	if ( file_exists( $recurring_path ) ) {
		echo "EDD Recurring file found! Activating...\n";

		$activated = activate_plugin( 'edd-recurring/edd-recurring.php' );
		if ( is_wp_error( $activated ) ) {
			echo "Failed to activate EDD Recurring: " . $activated->get_error_message() . "\n";
			exit( 1 );
		}

		// Verify it loaded properly
		if ( ! class_exists( 'EDD_Recurring' ) ) {
			echo "WARNING: EDD_Recurring class does not exist after activation\n";
		} else {
			echo "EDD Recurring successfully activated\n";
		}
	} else {
		echo "EDD Recurring file not found at: $recurring_path\n";
		echo "EDD Recurring tests will be skipped\n";
	}
}

// Only look for/activate Elementor if it was requested via --extra elementor.
if ( false !== strpos( (string) getenv( 'TEST_EXTRA_PLUGINS' ), 'elementor' ) ) {
	$elementor_path = $_core_dir . '/wp-content/plugins/elementor/elementor.php';
	echo "Checking for Elementor at: $elementor_path\n";
	if ( file_exists( $elementor_path ) ) {
		echo "Elementor file found! Activating...\n";

		$activated = activate_plugin( 'elementor/elementor.php' );
		if ( is_wp_error( $activated ) ) {
			echo "Failed to activate Elementor: " . $activated->get_error_message() . "\n";
			exit( 1 );
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			echo "WARNING: \\Elementor\\Plugin class does not exist after activation\n";
		} else {
			echo "Elementor successfully activated\n";

			// Real-Elementor test fixture. By this point WP's `init`
			// hook has run Plugin::init_components(), which wires the singleton's
			// experiments / documents / kits_manager / controls_manager /
			// elements_manager. Capture that healthy singleton ONCE, before any
			// test can null \Elementor\Plugin::$instance (their teardowns drop the
			// static pointer, not the object). Tests restore it via the
			// RealElementorFixture trait. We only READ the static and set options —
			// we never re-fire plugins_loaded/init, which would fatally
			// re-register Elementor's elements.
			$elementor_plugin = \Elementor\Plugin::$instance;

			if ( $elementor_plugin && ! empty( $elementor_plugin->kits_manager ) ) {
				$GLOBALS['edd_elementor_real_plugin'] = $elementor_plugin;

				// Force the Container experiment active (composable path).
				if ( ! empty( $elementor_plugin->experiments ) ) {
					$elementor_plugin->experiments->set_feature_default_state(
						'container',
						\Elementor\Core\Experiments\Manager::STATE_ACTIVE
					);
					update_option(
						$elementor_plugin->experiments->get_feature_option_key( 'container' ),
						\Elementor\Core\Experiments\Manager::STATE_ACTIVE
					);
				}

				// Create a default active kit so the real Container ctor's
				// kits_manager->get_active_kit() resolves.
				\Elementor\Core\Kits\Manager::create_default_kit();

				// Pre-warm the widgets manager. A real section widget's
				// get_controls()/render() merges the common controls via
				// widgets_manager->get_widget_types(), whose first call initializes
				// every widget type (including Elementor's promotions module, which
				// emits a PHP 8.2 "false to array" deprecation). Triggering that init
				// ONCE here, before PHPUnit installs its deprecation-to-exception
				// handler, caches the widget types so the in-test merge reuses the
				// cache and never re-runs the deprecating init. Best-effort.
				if ( ! empty( $elementor_plugin->widgets_manager ) ) {
					try {
						$elementor_plugin->widgets_manager->get_widget_types();
					} catch ( \Throwable $e ) {
						echo 'NOTICE: Elementor widgets manager pre-warm skipped: ' . $e->getMessage() . "\n";
					}
				}

				echo "EDD real-Elementor fixture ready (singleton captured, container experiment active, default kit created).\n";
			} else {
				echo "WARNING: Elementor singleton not fully initialized; real-Elementor fixture unavailable.\n";
			}
		}
	} else {
		echo "Elementor file not found at: $elementor_path\n";
		echo "Elementor tests will be skipped\n";
	}
}

function _disable_reqs( $status = false, $args = array(), $url = '') {
}
add_filter( 'pre_http_request', function( $status = false, $args = array(), $url = '' ) {
	return new \WP_Error( 'no_reqs_in_unit_tests', __( 'HTTP Requests disabled for unit tests', 'easy-digital-downloads' ) );
} );

require_once 'helpers/shims.php';

remove_all_actions( 'send_headers' );
