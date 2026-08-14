<?php
/**
 * Real-Elementor widget test fixture.
 *
 * Extends the M1 RealElementorFixture (which restores the real, fully-initialized
 * \Elementor\Plugin singleton) with the pieces the checkout section widgets need to
 * run against REAL Elementor: constructing a real Widget_Base instance, reading its
 * real controls stack, driving Page::is_edit_mode(), and shimming the current
 * document for the plain-content marker-write gate — without ever mutating or
 * rebuilding the captured singleton.
 *
 * How it works:
 * - edd_make_widget() builds a FULL element instance (non-empty $data), so the
 *   native Widget_Base constructor takes the data path (no register_skins) and
 *   Controls_Stack::init() records the element id. The real controls stack, common
 *   controls (via widgets_manager) and the controls_manager are all resolved off the
 *   restored singleton, so register_controls() and render() exercise the real
 *   widget/controls pipeline (this is what clears the spike's Widget_Base::$preview /
 *   controls-stack null-offset errors — the real singleton has ->preview wired).
 * - edd_install_current_document() points the plain-content gate at a throwaway,
 *   uninitialized REAL Plugin whose only wired member is a documents manager (the
 *   gate reads nothing but Plugin::$instance->documents->get_current()). Using a
 *   throwaway means the captured singleton is never mutated; the next test's
 *   edd_boot_real_elementor() restores the healthy singleton.
 *
 * @package     EDD\Tests\Elementor\Support
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.0
 */

namespace EDD\Tests\Elementor\Support;

trait RealElementorWidgetFixture {

	use RealElementorFixture;

	/**
	 * The page id installed as the queried post for editor preview, if any.
	 *
	 * @var int|null
	 */
	private $edd_edit_mode_post = null;

	/**
	 * The current user id captured before editor preview was forced, if any.
	 *
	 * @var int|null
	 */
	private $edd_edit_mode_prev_user = null;

	/**
	 * Build a real, fully-constructed Elementor widget instance.
	 *
	 * Passing non-empty $data makes the native Widget_Base constructor treat this as
	 * a full element instance (not a registration type-instance), so Controls_Stack
	 * records the element id and the settings are parsed against the widget's real
	 * controls. The widget name (a fixed literal) is read off an
	 * unconstructed instance so no registration side effects run to discover it.
	 *
	 * @since 3.7.0
	 *
	 * Construction alone touches nothing on the singleton (a full instance skips
	 * the registration/skins path), so this does NOT boot the real singleton: the
	 * caller's setUp() restores it for get_controls()/render(), while the
	 * plain-content gate tests can build widgets and THEN swap in a throwaway
	 * documents Plugin without this clobbering it back.
	 *
	 * @param string $class    The widget class (e.g. Cart::class).
	 * @param string $id       The element id get_id() should report.
	 * @param array  $settings The element settings (parsed against the widget controls).
	 * @return \Elementor\Widget_Base The real widget instance.
	 */
	protected function edd_make_widget( string $class, string $id = 'edd-widget-id', array $settings = array() ): \Elementor\Widget_Base {
		$name = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor()->get_name();

		$data = array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $name,
			'settings'   => $settings,
			'elements'   => array(),
		);

		return new $class( $data, array( 'widgetType' => $name ) );
	}

	/**
	 * List the section-type control names registered on a widget's real controls stack.
	 *
	 * The real replacement for the old stub's $opened_sections recorder: it reads the
	 * controls straight off the real stack (which register_controls() populates via
	 * the native Controls_Stack) and returns the ids of the `section` controls.
	 *
	 * @since 3.7.0
	 *
	 * @param \Elementor\Widget_Base $widget The widget instance.
	 * @return array The registered section control ids.
	 */
	protected function edd_widget_section_ids( \Elementor\Widget_Base $widget ): array {
		$sections = array();

		foreach ( $widget->get_controls() as $name => $control ) {
			if ( isset( $control['type'] ) && 'section' === $control['type'] ) {
				$sections[] = $name;
			}
		}

		return $sections;
	}

	/**
	 * Point the plain-content marker-write gate at a document reporting the given tree.
	 *
	 * Installs a throwaway, uninitialized REAL \Elementor\Plugin whose documents
	 * manager returns a current document exposing $elements. Base::is_within_checkout_box()
	 * and Base::get_checkout_block_attributes() read only
	 * Plugin::$instance->documents->get_current()->get_elements_data(), so nothing more
	 * is wired. The captured singleton is untouched; the next setUp restores it.
	 *
	 * @since 3.7.0
	 *
	 * @param array $elements The elements-data array the current document reports.
	 * @return void
	 */
	protected function edd_install_current_document( array $elements ): void {
		$this->edd_require_real_elementor();

		$document = new class( $elements ) {
			/**
			 * The elements-data array.
			 *
			 * @var array
			 */
			private $elements;

			/**
			 * Store the elements data.
			 *
			 * @param array $elements The elements-data array.
			 */
			public function __construct( array $elements ) {
				$this->elements = $elements;
			}

			/**
			 * Return the stored elements data.
			 *
			 * @return array
			 */
			public function get_elements_data() {
				return $this->elements;
			}
		};

		$documents = new class( $document ) {
			/**
			 * The single current document.
			 *
			 * @var object
			 */
			private $document;

			/**
			 * Store the current document.
			 *
			 * @param object $document The document double.
			 */
			public function __construct( $document ) {
				$this->document = $document;
			}

			/**
			 * Return the stored current document.
			 *
			 * @return object
			 */
			public function get_current() {
				return $this->document;
			}
		};

		$plugin            = ( new \ReflectionClass( \Elementor\Plugin::class ) )->newInstanceWithoutConstructor();
		$plugin->documents = $documents;

		\Elementor\Plugin::$instance = $plugin;
	}

	/**
	 * Force Elementor editor preview mode on for the current request.
	 *
	 * Page::is_edit_mode() delegates to Elementor's Preview::is_preview_mode(),
	 * which only reports true for an edit-capable user viewing the preview iframe
	 * of a matching post: it requires the elementor-preview query param, a queried
	 * post the current user can edit, and a matching post id. This sets all three
	 * so a legitimate editor request reads as edit mode (a forged URL from a user
	 * without edit rights still does not), driving the editor branch of the section
	 * widgets without replacing the real editor object.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	protected function edd_force_edit_mode(): void {
		// Elementor only treats a post type flagged for editor support as previewable.
		add_post_type_support( 'page', 'elementor' );

		$this->edd_edit_mode_prev_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->edd_edit_mode_post = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		// is_preview_mode() reads get_the_ID() for the previewed post, so make it the current post.
		$GLOBALS['post'] = get_post( $this->edd_edit_mode_post );

		$_GET['elementor-preview'] = (string) $this->edd_edit_mode_post;
		$_SERVER['REQUEST_URI']    = '/?elementor-preview=' . $this->edd_edit_mode_post;
	}

	/**
	 * Restore a front-end (not-editing) request and undo the editor preview state.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	protected function edd_clear_edit_mode(): void {
		unset( $_GET['elementor-preview'] );
		$GLOBALS['post'] = null;

		if ( null !== $this->edd_edit_mode_prev_user ) {
			wp_set_current_user( $this->edd_edit_mode_prev_user );
			$this->edd_edit_mode_prev_user = null;
		}

		$this->edd_edit_mode_post = null;

		$_SERVER['REQUEST_URI'] = '/checkout/';
	}
}
