<?php
/**
 * First-run setup wizard: business basics, scheduling (thin wrapper over the
 * existing scheduling-settings endpoint), the mandatory address-lookup
 * consent + test, a first service (thin wrapper over the existing services
 * endpoint), an optional first crew member, and a finish checklist.
 *
 * Step 4 (Payments) is intentionally absent — it's a separate, not-yet-built
 * Phase 1b-1 task ("Payments step of the setup wizard") that will insert
 * itself into STEP_KEYS once Stripe exists. Everything here is written
 * against that eventual insertion: step order and progress are driven by
 * STEP_KEYS rather than hardcoded numbers, so adding a step later doesn't
 * require renumbering anything.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Wizard {

	/**
	 * Hook suffix for the wizard's submenu page, captured from
	 * add_submenu_page()'s own return value in register_menu() rather than
	 * guessed — see the identical note on Service_Crew_Admin_App's
	 * $hook_services property.
	 *
	 * @var string
	 */
	private $hook_wizard;

	/**
	 * Whether this request should pop the wizard open as a modal on top of
	 * whatever admin page WordPress lands the admin on after activation (set
	 * once, in maybe_flag_modal_for_this_request(), and read by both the
	 * asset-enqueue and admin_footer hooks later in the same request).
	 *
	 * @var bool
	 */
	private $show_modal = false;

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Option name storing wizard progress (current step, completed steps,
	 * the mandatory address-consent/test flags, and whether the wizard has
	 * been finished/dismissed so the activation redirect never fires again).
	 *
	 * @var string
	 */
	const STATE_OPTION = 'sc_wizard_state';

	/**
	 * Option name storing step 1's fields. Public (not just wizard-internal)
	 * because later phases (emails, pricing display) will read the currency/
	 * timezone/admin-alert-email this step collects.
	 *
	 * @var string
	 */
	const GENERAL_OPTION = 'sc_general_settings';

	/**
	 * Transient set on activation, consumed by maybe_redirect_to_wizard().
	 *
	 * @var string
	 */
	const ACTIVATION_REDIRECT_TRANSIENT = 'sc_activation_redirect';

	/**
	 * Every wizard step, in order. 'address' cannot be skipped; 'first_crew'
	 * can. See the class docblock for why there's no 'payments' entry yet.
	 *
	 * @var string[]
	 */
	const STEP_KEYS = array( 'basics', 'scheduling', 'address', 'first_service', 'first_crew', 'finish' );

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_flag_modal_for_this_request' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_modal_assets' ) );
		add_action( 'admin_footer', array( $this, 'maybe_render_modal_markup' ) );
	}

	/**
	 * Marks a fresh activation to pop the wizard open as a dialog on whatever
	 * admin page WordPress lands the admin on (normally the Plugins list) —
	 * no page navigation, unlike a redirect. Skipped for bulk plugin
	 * activation (no single admin to show it to), AJAX/cron requests, and
	 * once consumed the transient never fires again, so this only ever
	 * happens once per activation.
	 *
	 * @return void
	 */
	public function maybe_flag_modal_for_this_request() {
		if ( ! get_transient( self::ACTIVATION_REDIRECT_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::ACTIVATION_REDIRECT_TRANSIENT );

		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) || isset( $_GET['activate-multi'] ) ) {
			return;
		}

		$this->show_modal = true;
	}

	/**
	 * Attaches the Setup Wizard submenu to the existing 'service-crew' top-
	 * level menu (created by Service_Crew_Services), so it's reachable any
	 * time from the plugin menu, not just right after activation.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_wizard = add_submenu_page(
			'service-crew',
			__( 'Setup Wizard', 'service-crew' ),
			__( 'Setup Wizard', 'service-crew' ),
			'manage_options',
			'service-crew-wizard',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the wizard container. Reuses #sc-app-root/app.css's design
	 * tokens and components (buttons, inputs, cards) via data-view="wizard",
	 * same convention as Service_Crew_Admin_App's four screens, rather than
	 * inventing a parallel set of base styles.
	 *
	 * @return void
	 */
	public function render_page() {
		echo '<div class="wrap"><div id="sc-app-root" data-view="wizard"></div></div>';
	}

	/**
	 * Enqueues the wizard's assets only on its own admin page (the "Setup
	 * Wizard" menu item, for reopening it after the fact) — a plain full-page
	 * render, not the modal maybe_enqueue_modal_assets() triggers elsewhere.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $this->hook_wizard !== $hook_suffix ) {
			return;
		}

		$this->enqueue_wizard_assets();
		$this->localize_wizard_script( false );
	}

	/**
	 * Enqueues the same wizard assets on whatever admin page a fresh
	 * activation landed on (see maybe_flag_modal_for_this_request()), so
	 * maybe_render_modal_markup() has something to mount into. Skipped on the
	 * wizard's own page — enqueue_assets() already covers that one, and
	 * loading both would double up #sc-app-root markup/scripts.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function maybe_enqueue_modal_assets( $hook_suffix ) {
		if ( ! $this->show_modal || $this->hook_wizard === $hook_suffix ) {
			return;
		}

		$this->enqueue_wizard_assets();
		$this->localize_wizard_script( true );
	}

	/**
	 * Registers/enqueues every script and style the wizard needs, shared by
	 * both the full-page (enqueue_assets()) and modal
	 * (maybe_enqueue_modal_assets()) entry points.
	 *
	 * @return void
	 */
	private function enqueue_wizard_assets() {
		wp_enqueue_style(
			'sc-admin-app',
			SERVICE_CREW_PLUGIN_URL . 'admin/css/app.css',
			array(),
			SERVICE_CREW_VERSION
		);

		wp_enqueue_style(
			'sc-admin-wizard',
			SERVICE_CREW_PLUGIN_URL . 'admin/css/wizard.css',
			array( 'sc-admin-app' ),
			SERVICE_CREW_VERSION
		);

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-api-fetch' );

		wp_enqueue_script(
			'sc-admin-app-core',
			SERVICE_CREW_PLUGIN_URL . 'admin/js/app-core.js',
			array( 'wp-api-fetch' ),
			SERVICE_CREW_VERSION,
			true
		);

		wp_add_inline_script(
			'sc-admin-app-core',
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) ); wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( %s ) );',
				wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
				wp_json_encode( esc_url_raw( rest_url() ) )
			),
			'before'
		);

		wp_enqueue_script(
			'sc-admin-app-wizard',
			SERVICE_CREW_PLUGIN_URL . 'admin/js/app-wizard.js',
			array( 'sc-admin-app-core', 'wp-color-picker', 'media-editor' ),
			SERVICE_CREW_VERSION,
			true
		);
	}

	/**
	 * @param bool $modal Whether app-wizard.js is running inside the
	 *                     activation-triggered modal rather than the full page.
	 * @return void
	 */
	private function localize_wizard_script( $modal ) {
		wp_localize_script(
			'sc-admin-app-wizard',
			'SC_WIZARD',
			array(
				// Both entry points finish the same way: back to the normal
				// WordPress dashboard, not a ServiceCrew screen — the admin
				// just installed the plugin and hasn't necessarily decided to
				// live in it yet.
				'dashboardUrl' => admin_url(),
				'modal'        => $modal,
			)
		);
	}

	/**
	 * Prints the modal overlay + dialog shell in the footer of whatever page
	 * maybe_enqueue_modal_assets() loaded the wizard's assets on. app-wizard.js
	 * finds #sc-app-root the same way regardless of whether it's here or on
	 * the dedicated page — see render_page().
	 *
	 * @return void
	 */
	public function maybe_render_modal_markup() {
		if ( ! $this->show_modal ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && $this->hook_wizard === $screen->id ) {
			return;
		}

		echo '<div id="sc-wizard-modal-overlay" class="sc-wizard-modal-overlay"><div class="sc-wizard-modal-dialog"><div id="sc-app-root" data-view="wizard"></div></div></div>';
	}

	/**
	 * Registers the wizard's own REST routes. Scheduling (step 2) and the
	 * first service (step 5) are deliberately NOT re-registered here — the
	 * wizard's JS talks to the existing /scheduling-settings and /services
	 * routes directly, so this class only owns what nothing else already
	 * exposes: progress state, business basics, the address test, a
	 * create-only first-crew-member endpoint, and the finish summary.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/wizard/state',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_state' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_state' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/wizard/business-basics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_business_basics_route' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_business_basics' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/wizard/test-address',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_address' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/wizard/crew-member',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_crew_member' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/wizard/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_summary' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission check shared by every wizard route — same access level as
	 * the ServiceCrew admin menu itself.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler for wizard progress.
	 *
	 * @return WP_REST_Response
	 */
	public function get_state() {
		return rest_ensure_response( self::get_saved_state() );
	}

	/**
	 * Reads the saved wizard-state option, merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_saved_state() {
		$saved = get_option( self::STATE_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array_merge( self::get_default_state(), $saved );
	}

	/**
	 * The wizard's own defaults: start at the first step, nothing completed,
	 * consent not yet given, no address test passed, not dismissed.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_default_state() {
		return array(
			'current_step'        => self::STEP_KEYS[0],
			'completed_steps'     => array(),
			'consent_accepted'    => false,
			'address_test_passed' => false,
			'dismissed'           => false,
		);
	}

	/**
	 * PUT handler for wizard progress — lets the wizard resume after the tab
	 * closes (per the plan's verification requirement) by persisting which
	 * step the admin is on and which steps are already complete.
	 *
	 * @param WP_REST_Request $request Request with the state shape in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_state( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$current_step = isset( $params['current_step'] ) ? sanitize_key( $params['current_step'] ) : self::STEP_KEYS[0];
		if ( ! in_array( $current_step, self::STEP_KEYS, true ) ) {
			$current_step = self::STEP_KEYS[0];
		}

		$completed_steps = array();
		if ( isset( $params['completed_steps'] ) && is_array( $params['completed_steps'] ) ) {
			$completed_steps = array_values( array_intersect( self::STEP_KEYS, array_map( 'sanitize_key', $params['completed_steps'] ) ) );
		}

		$sanitized = array(
			'current_step'        => $current_step,
			'completed_steps'     => $completed_steps,
			'consent_accepted'    => ! empty( $params['consent_accepted'] ),
			'address_test_passed' => ! empty( $params['address_test_passed'] ),
			'dismissed'           => ! empty( $params['dismissed'] ),
		);

		update_option( self::STATE_OPTION, $sanitized, false );

		return rest_ensure_response( $sanitized );
	}

	/**
	 * GET handler for step 1's fields.
	 *
	 * @return WP_REST_Response
	 */
	public function get_business_basics_route() {
		return rest_ensure_response( self::get_business_basics() );
	}

	/**
	 * Reads the saved business-basics option, merged over defaults. Public/
	 * static so email templates (Phase 1b-1) and any price display that wants
	 * the site's currency can read it without going through REST.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_business_basics() {
		$saved = get_option( self::GENERAL_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array_merge( self::get_business_basics_defaults(), $saved );
	}

	/**
	 * Defaults sourced from the site itself where a sensible one exists
	 * (site title, timezone, admin email) rather than a hardcoded guess.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_business_basics_defaults() {
		return array(
			'business_name'     => get_bloginfo( 'name' ),
			'timezone'          => wp_timezone_string(),
			'currency'          => 'USD',
			'logo_id'           => 0,
			'brand_color'       => '#4f46e5',
			'admin_alert_email' => get_option( 'admin_email' ),
		);
	}

	/**
	 * PUT handler for step 1: name, timezone, currency, logo, brand colour,
	 * admin alert email. Every field falls back to its own default rather
	 * than rejecting the whole request, same pattern as
	 * Service_Crew_Settings::update_settings().
	 *
	 * @param WP_REST_Request $request Request with the fields in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_business_basics( $request ) {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : array();
		$defaults = self::get_business_basics_defaults();

		$business_name = isset( $params['business_name'] ) ? sanitize_text_field( $params['business_name'] ) : '';
		if ( '' === $business_name ) {
			$business_name = $defaults['business_name'];
		}

		$timezone = isset( $params['timezone'] ) ? sanitize_text_field( $params['timezone'] ) : '';
		if ( '' === $timezone || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
			$timezone = $defaults['timezone'];
		}

		$currency = isset( $params['currency'] ) ? strtoupper( sanitize_text_field( $params['currency'] ) ) : '';
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$currency = $defaults['currency'];
		}

		$logo_id = absint( $params['logo_id'] ?? 0 );
		if ( $logo_id && ! wp_attachment_is_image( $logo_id ) ) {
			$logo_id = 0;
		}

		$brand_color = isset( $params['brand_color'] ) ? sanitize_hex_color( $params['brand_color'] ) : '';
		if ( ! $brand_color ) {
			$brand_color = $defaults['brand_color'];
		}

		$admin_alert_email = isset( $params['admin_alert_email'] ) ? sanitize_email( $params['admin_alert_email'] ) : '';
		if ( '' === $admin_alert_email || ! is_email( $admin_alert_email ) ) {
			$admin_alert_email = $defaults['admin_alert_email'];
		}

		$sanitized = array(
			'business_name'     => $business_name,
			'timezone'          => $timezone,
			'currency'          => $currency,
			'logo_id'           => $logo_id,
			'brand_color'       => $brand_color,
			'admin_alert_email' => $admin_alert_email,
		);

		update_option( self::GENERAL_OPTION, $sanitized, false );

		return rest_ensure_response( $sanitized );
	}

	/**
	 * POST handler for step 3's mandatory "test an address" action. Pure
	 * pass-through to the single geocoding choke point — this route exists
	 * only so the wizard can test a lookup without a crew record to attach it
	 * to.
	 *
	 * @param WP_REST_Request $request Request with 'address' and 'zip' in the JSON body.
	 * @return WP_REST_Response
	 */
	public function test_address( $request ) {
		$params  = $request->get_json_params();
		$address = isset( $params['address'] ) ? sanitize_text_field( $params['address'] ) : '';
		$zip     = isset( $params['zip'] ) ? sanitize_text_field( $params['zip'] ) : '';

		// A second instance only for its public geocode() method; the extra
		// save_post_sc_crew hook its constructor registers is harmless here —
		// this route never saves a crew post.
		$geocoding = new Service_Crew_Geocoding();
		$result    = $geocoding->geocode( $address, $zip );

		return rest_ensure_response( $result );
	}

	/**
	 * POST handler for step 6: creates exactly one sc_crew post from the
	 * wizard's simplified fields. Deliberately create-only and unversioned
	 * beyond that — the admin-gated, full CRUD Crew REST controller is a
	 * separate not-yet-built Phase 1a task; this route isn't it and shouldn't
	 * grow into it.
	 *
	 * @param WP_REST_Request $request Request with the crew fields in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_crew_member( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'sc_missing_name', __( 'A crew member needs a name.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$type = 'vendor' === ( $params['type'] ?? '' ) ? 'vendor' : 'employee';

		$radius = '';
		if ( isset( $params['radius'] ) && '' !== $params['radius'] ) {
			$radius = max( 0, absint( $params['radius'] ) );
		}

		$meta_input = array(
			Service_Crew_Crew::META_TYPE    => $type,
			Service_Crew_Crew::META_PHONE   => sanitize_text_field( $params['phone'] ?? '' ),
			Service_Crew_Crew::META_EMAIL   => sanitize_email( $params['email'] ?? '' ),
			Service_Crew_Crew::META_ADDRESS => sanitize_text_field( $params['address'] ?? '' ),
			Service_Crew_Crew::META_ZIP     => sanitize_text_field( $params['zip'] ?? '' ),
		);

		if ( '' !== $radius ) {
			$meta_input[ Service_Crew_Crew::META_RADIUS ] = $radius;
		}

		$availability = $this->sanitize_availability( $params['availability'] ?? array() );
		if ( ! empty( $availability ) ) {
			$meta_input[ Service_Crew_Crew::META_AVAILABILITY ] = $availability;
		}

		$time_off = $this->sanitize_time_off( $params['time_off'] ?? array() );
		if ( ! empty( $time_off ) ) {
			$meta_input[ Service_Crew_Crew::META_TIME_OFF ] = $time_off;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'sc_crew',
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => $meta_input,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$photo_id = absint( $params['photo_id'] ?? 0 );
		if ( $photo_id && wp_attachment_is_image( $photo_id ) ) {
			set_post_thumbnail( $post_id, $photo_id );
		}

		return rest_ensure_response( array( 'id' => $post_id ) );
	}

	/**
	 * Sanitizes the wizard's availability payload. Same shape and per-field
	 * rules as Service_Crew_Crew::save_availability(), duplicated rather than
	 * shared because that method reads straight from $_POST — see the
	 * identical duplication note on Service_Crew_Crew/Service_Crew_Settings's
	 * own sanitize_time().
	 *
	 * @param mixed $rows Raw posted value, expected keyed by weekday.
	 * @return array<string,array<string,string>>
	 */
	private function sanitize_availability( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$availability = array();

		foreach ( Service_Crew_Crew::WEEKDAYS as $day ) {
			if ( ! isset( $rows[ $day ] ) || ! is_array( $rows[ $day ] ) ) {
				continue;
			}

			$shifts = array(
				'shift1_start' => $this->sanitize_time( $rows[ $day ]['shift1_start'] ?? '' ),
				'shift1_end'   => $this->sanitize_time( $rows[ $day ]['shift1_end'] ?? '' ),
				'shift2_start' => $this->sanitize_time( $rows[ $day ]['shift2_start'] ?? '' ),
				'shift2_end'   => $this->sanitize_time( $rows[ $day ]['shift2_end'] ?? '' ),
			);

			if ( '' === $shifts['shift1_start'] && '' === $shifts['shift1_end'] && '' === $shifts['shift2_start'] && '' === $shifts['shift2_end'] ) {
				continue;
			}

			$availability[ $day ] = $shifts;
		}

		return $availability;
	}

	/**
	 * Sanitizes the wizard's time-off payload. Same shape/rules as
	 * Service_Crew_Crew::save_time_off() — see sanitize_availability()'s
	 * duplication note.
	 *
	 * @param mixed $rows Raw posted value.
	 * @return array<int,array{start:string,end:string,reason:string}>
	 */
	private function sanitize_time_off( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$time_off = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$start = $this->sanitize_date( $row['start'] ?? '' );
			if ( '' === $start ) {
				continue;
			}

			$end = $this->sanitize_date( $row['end'] ?? '' );
			if ( '' === $end || $end < $start ) {
				$end = $start;
			}

			$time_off[] = array(
				'start'  => $start,
				'end'    => $end,
				'reason' => sanitize_text_field( $row['reason'] ?? '' ),
			);
		}

		return $time_off;
	}

	/**
	 * Sanitizes a posted HH:MM time value, discarding anything that doesn't
	 * match the expected shape rather than trying to coerce it. Same rule as
	 * Service_Crew_Crew's identically-named private method.
	 *
	 * @param string $value Raw posted value.
	 * @return string Sanitized HH:MM value, or '' if invalid/empty.
	 */
	private function sanitize_time( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitizes a posted YYYY-MM-DD date value, discarding anything that
	 * doesn't match the expected shape rather than trying to coerce it. Same
	 * rule as Service_Crew_Crew's identically-named private method.
	 *
	 * @param string $value Raw posted value.
	 * @return string Sanitized YYYY-MM-DD value, or '' if invalid/empty.
	 */
	private function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * GET handler for step 7's finish checklist. Live counts for
	 * service/crew (rather than trusting wizard-state flags) so the
	 * checklist stays correct even if a service or crew member is deleted
	 * again after the wizard finishes and the admin reopens it later.
	 *
	 * @return WP_REST_Response
	 */
	public function get_summary() {
		$state    = self::get_saved_state();
		$basics   = self::get_business_basics();
		$defaults = self::get_business_basics_defaults();

		$has_service = (bool) get_posts(
			array(
				'post_type'      => 'sc_service',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$has_crew = (bool) get_posts(
			array(
				'post_type'      => 'sc_crew',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return rest_ensure_response(
			array(
				// A real business name means step 1 was actually visited and
				// saved, not just left at the site-title default.
				'business_basics_done' => $basics['business_name'] !== $defaults['business_name'] || in_array( 'basics', $state['completed_steps'], true ),
				'scheduling_done'      => in_array( 'scheduling', $state['completed_steps'], true ),
				'address_done'         => $state['consent_accepted'] && $state['address_test_passed'],
				'first_service_done'   => $has_service,
				'first_crew_done'      => $has_crew,
				// Stripe doesn't exist yet — Phase 1b-1 adds the payments step
				// and this flips to a real check once it does.
				'payments_done'        => false,
			)
		);
	}
}
