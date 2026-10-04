<?php
/**
 * Settings for the "Agent" rule-based chat sales agent (Phase A — see
 * C:\Users\fujitsu\.claude\plans\scalable-wondering-milner.md). One options
 * row, same get/merge-with-defaults/sanitize-on-save pattern as
 * class-service-crew-settings.php. suppress_legacy_widgets is stored here
 * now (Phase E reads/enforces it) so later phases need no further schema
 * change to this option.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_Settings {

	/**
	 * Option name storing the sanitized settings array.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'sc_agent_settings';

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Registers the REST routes. Called once from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers GET/PUT /service-crew/v1/agent-settings.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/agent-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission check shared by both routes.
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
	public function get_settings() {
		return rest_ensure_response( self::get_saved_settings() );
	}

	/**
	 * Reads the saved settings option, merged over defaults. Used by REST and
	 * by the public widget (Service_Crew_Agent_Widget) and matcher
	 * (Service_Crew_Agent) directly.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_saved_settings() {
		$saved = get_option( self::OPTION_NAME, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array_merge( self::get_defaults(), $saved );
	}

	/**
	 * Whether the legacy `[service_crew_services]`/`[service_crew_booking]`
	 * shortcodes should suppress their own output in favor of the chat
	 * agent being the one customer-facing entry point — Phase E. Only takes
	 * effect when the agent itself is also on (`mode_enabled`); turning the
	 * agent off without separately re-enabling this would otherwise leave a
	 * site with neither the legacy widgets nor a working agent.
	 *
	 * @return bool
	 */
	public static function is_legacy_widgets_suppressed() {
		$settings = self::get_saved_settings();

		return ! empty( $settings['mode_enabled'] ) && ! empty( $settings['suppress_legacy_widgets'] );
	}

	/**
	 * Confidence thresholds match Service_Crew_Agent_Matcher's own constants
	 * — kept here (not hardcoded in the matcher) so the admin screen can
	 * tune them without a code change.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_defaults() {
		return array(
			'mode_enabled'             => false,
			'suppress_legacy_widgets'  => false,
			'greeting'                 => __( "Hi! I'm here to help — what can I do for you today?", 'service-crew' ),
			'tone'                     => 'friendly',
			'confident_threshold'      => 0.55,
			'plausible_threshold'      => 0.30,
			'proactive_delay_seconds'  => 8,
			// Default off — an existing install shouldn't suddenly start
			// surfacing page/post content to chat visitors without an
			// explicit opt-in. See class-service-crew-agent-content-extractor.php.
			'learn_from_content'      => false,
			'sales_nudge_enabled'     => true,
			'sales_nudge_text'        => __( 'Want me to get you a quote or help you book this?', 'service-crew' ),
		);
	}

	/**
	 * PUT handler: replaces the whole settings object, sanitized field by
	 * field.
	 *
	 * @param WP_REST_Request $request Request with the settings shape in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : array();
		$defaults = self::get_defaults();

		$confident = max( 0.0, min( 1.0, (float) ( $params['confident_threshold'] ?? $defaults['confident_threshold'] ) ) );
		$plausible = max( 0.0, min( $confident, (float) ( $params['plausible_threshold'] ?? $defaults['plausible_threshold'] ) ) );

		$sanitized = array(
			'mode_enabled'            => ! empty( $params['mode_enabled'] ),
			'suppress_legacy_widgets' => ! empty( $params['suppress_legacy_widgets'] ),
			'greeting'                => sanitize_text_field( $params['greeting'] ?? $defaults['greeting'] ),
			'tone'                    => in_array( $params['tone'] ?? '', array( 'friendly', 'professional', 'concise' ), true ) ? $params['tone'] : $defaults['tone'],
			'confident_threshold'     => $confident,
			'plausible_threshold'     => $plausible,
			'proactive_delay_seconds' => max( 0, absint( $params['proactive_delay_seconds'] ?? $defaults['proactive_delay_seconds'] ) ),
			'learn_from_content'      => ! empty( $params['learn_from_content'] ),
			'sales_nudge_enabled'     => ! empty( $params['sales_nudge_enabled'] ),
			'sales_nudge_text'        => sanitize_text_field( $params['sales_nudge_text'] ?? $defaults['sales_nudge_text'] ),
		);

		update_option( self::OPTION_NAME, $sanitized );

		return rest_ensure_response( $sanitized );
	}
}
