<?php
/**
 * Advance-payment discount tiers ("pay at least X% now, get Y% off the whole
 * job") — the only discount concept in the plugin. Per the plan, a discount
 * always applies to the whole booking, never to an individual service or
 * add-on, so this class stores a single flat list of tiers with no
 * relationship to sc_service at all.
 *
 * Applying these tiers at checkout is a future Pricing/Payments-class task
 * (Phase 1a/1b-1) — this class only owns the admin-facing settings screen's
 * data.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Discounts {

	/**
	 * Option name storing the sanitized tiers array.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'sc_advance_discount_tiers';

	/**
	 * REST namespace shared with Service_Crew_Services_Controller.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * A handful of tiers is all the plan's example ("50% -> 2% off, 100% ->
	 * 5% off") calls for; caps the admin-facing repeater the same way
	 * Add-ons' old tier slots did.
	 *
	 * @var int
	 */
	const MAX_TIERS = 6;

	/**
	 * Registers the REST routes. Called once from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers GET/PUT /service-crew/v1/discount-tiers.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/discount-tiers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tiers' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_tiers' ),
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
	 * GET handler: returns the saved tiers.
	 *
	 * @return WP_REST_Response
	 */
	public function get_tiers() {
		return rest_ensure_response( self::get_saved_tiers() );
	}

	/**
	 * Reads the saved tiers option, used by REST and (later) the Pricing class.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_saved_tiers() {
		$tiers = get_option( self::OPTION_NAME, array() );

		return is_array( $tiers ) ? $tiers : array();
	}

	/**
	 * PUT handler: replaces the whole tiers list, sanitized and sorted by
	 * ascending minimum percent paid.
	 *
	 * @param WP_REST_Request $request Request with a `tiers` array in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_tiers( $request ) {
		$params = $request->get_json_params();
		$rows   = isset( $params['tiers'] ) && is_array( $params['tiers'] ) ? $params['tiers'] : array();

		$sanitized = array();

		foreach ( array_slice( $rows, 0, self::MAX_TIERS ) as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['min_percent_paid'] ?? '' ) ) ) {
				continue;
			}

			$discount_type = isset( $row['discount_type'] ) && 'fixed' === $row['discount_type'] ? 'fixed' : 'percent';

			$sanitized[] = array(
				'min_percent_paid' => max( 1, min( 100, absint( $row['min_percent_paid'] ) ) ),
				'discount_type'    => $discount_type,
				'discount_value'   => max( 0, (float) ( $row['discount_value'] ?? 0 ) ),
			);
		}

		usort(
			$sanitized,
			function ( $a, $b ) {
				return $a['min_percent_paid'] <=> $b['min_percent_paid'];
			}
		);

		update_option( self::OPTION_NAME, $sanitized, false );

		return rest_ensure_response( $sanitized );
	}
}
