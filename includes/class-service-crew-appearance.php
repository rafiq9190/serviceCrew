<?php
/**
 * Admin-configurable color palette for the public booking widget
 * ([service_crew_services] / class-service-crew-services-shortcode.php) —
 * lets the admin match the widget to their own site's theme instead of a
 * fixed color scheme. Pure option storage + REST, same shape as
 * class-service-crew-discounts.php: no CPT, no relationship to services.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Appearance {

	/**
	 * Option name storing the saved color tokens (a subset of DEFAULTS may be
	 * saved; get_colors() always merges over the full default set).
	 *
	 * @var string
	 */
	const OPTION_NAME = 'sc_widget_colors';

	/**
	 * REST namespace shared with the other ServiceCrew controllers.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Default palette, matching the admin app's own indigo design system
	 * (admin/css/app.css) so an admin who never visits Appearance still gets
	 * a coherent look. Grouped: general surface/text, interaction states,
	 * the Total column's own distinct styling, and the [service_crew_booking]
	 * stepper's own named controls (button/stepper/date-picker) — split out
	 * from the general `accent` token, by request, so an admin can give the
	 * stepper's buttons, step circles and selected date a different identity
	 * color than the rest of the widget instead of all three always matching
	 * `accent`.
	 *
	 * @var array<string,string>
	 */
	const DEFAULTS = array(
		'accent'         => '#4f46e5',
		'hover_bg'       => '#eef2ff',
		'selected_bg'    => '#4f46e5',
		'selected_text'  => '#ffffff',
		'background'     => '#f8fafc',
		'surface'        => '#ffffff',
		'border'         => '#e2e8f0',
		'text'           => '#0f172a',
		'muted_text'     => '#64748b',
		'total_bg'       => '#0f172a',
		'total_text'     => '#ffffff',
		'total_accent'   => '#818cf8',
		'button_color'   => '#4f46e5',
		'stepper_color'  => '#4f46e5',
		'calendar_color' => '#4f46e5',
	);

	/**
	 * Registers the REST routes. Called once from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers GET/PUT /service-crew/v1/widget-colors.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/widget-colors',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_colors_route' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_colors' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission check shared by both routes — same access level as the
	 * ServiceCrew admin menu itself.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler.
	 *
	 * @return WP_REST_Response
	 */
	public function get_colors_route() {
		return rest_ensure_response( self::get_colors() );
	}

	/**
	 * Reads the saved palette merged over DEFAULTS — the single source of
	 * truth both the Appearance screen and the public widget read from, so
	 * the default list is never duplicated.
	 *
	 * @return array<string,string>
	 */
	public static function get_colors() {
		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::DEFAULTS, array_intersect_key( $saved, self::DEFAULTS ) );
	}

	/**
	 * Builds a `{selector} { --sc-w-x: ...; }` block from the saved palette —
	 * shared by every front-end entry point that renders the widget's
	 * --sc-w-* custom properties (Service_Crew_Services_Shortcode and
	 * Service_Crew_Booking_Shortcode), so the token-name-to-CSS-variable
	 * mapping exists in exactly one place.
	 *
	 * @param string $selector CSS selector the declarations are scoped to.
	 * @return string CSS, safe to print (values are sanitize_hex_color()'d
	 *                 at save time by update_colors()).
	 */
	public static function build_color_css( $selector ) {
		$declarations = array();

		foreach ( self::get_colors() as $token => $value ) {
			$declarations[] = '--sc-w-' . str_replace( '_', '-', $token ) . ': ' . esc_html( $value ) . ';';
		}

		return $selector . '{' . implode( '', $declarations ) . '}';
	}

	/**
	 * PUT handler: sanitizes each posted token as a hex color, dropping
	 * anything unrecognized (falls back to that token's default rather than
	 * saving a bad value).
	 *
	 * @param WP_REST_Request $request Request with color tokens in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_colors( $request ) {
		$params    = $request->get_json_params();
		$sanitized = array();

		foreach ( self::DEFAULTS as $key => $default ) {
			$value            = isset( $params[ $key ] ) ? sanitize_hex_color( $params[ $key ] ) : '';
			$sanitized[ $key ] = $value ? $value : $default;
		}

		update_option( self::OPTION_NAME, $sanitized, false );

		return rest_ensure_response( $sanitized );
	}
}
