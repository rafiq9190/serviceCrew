<?php
/**
 * Sitewide chat launcher for the Agent chat sales agent (Phase A — see
 * C:\Users\fujitsu\.claude\plans\scalable-wondering-milner.md). Hooked on
 * wp_footer so it appears on every front-end page, not just one shortcode's
 * page — enqueues nothing at all when Service_Crew_Agent_Settings'
 * mode_enabled is off, so a site that hasn't turned the agent on pays zero
 * asset cost.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_Widget {

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap (service_crew_init() in service-crew.php).
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'maybe_render_container' ) );
	}

	/**
	 * @return bool
	 */
	private function is_enabled() {
		$settings = Service_Crew_Agent_Settings::get_saved_settings();

		return ! is_admin() && ! empty( $settings['mode_enabled'] );
	}

	/**
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'sc-agent-widget',
			SERVICE_CREW_PLUGIN_URL . 'public/css/agent-widget.css',
			array(),
			SERVICE_CREW_VERSION
		);

		wp_enqueue_script(
			'sc-agent-widget',
			SERVICE_CREW_PLUGIN_URL . 'public/js/agent-widget.js',
			array(),
			SERVICE_CREW_VERSION,
			true
		);

		wp_add_inline_style( 'sc-agent-widget', Service_Crew_Appearance::build_color_css( '.sc-agent-widget' ) );

		$settings = Service_Crew_Agent_Settings::get_saved_settings();

		wp_localize_script(
			'sc-agent-widget',
			'SC_AGENT_WIDGET',
			array(
				'restUrl'              => esc_url_raw( rest_url( 'service-crew/v1/' ) ),
				'nonce'                => wp_create_nonce( 'wp_rest' ),
				'greeting'             => $settings['greeting'],
				'proactiveDelaySeconds' => (int) $settings['proactive_delay_seconds'],
			)
		);
	}

	/**
	 * Renders the bare launcher container — all markup past this point is
	 * built client-side by public/js/agent-widget.js, same
	 * "server renders a container div, JS owns the UI" pattern as the admin
	 * app's #sc-app-root.
	 *
	 * @return void
	 */
	public function maybe_render_container() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		echo '<div id="sc-agent-widget-root" class="sc-agent-widget"></div>';
	}
}
