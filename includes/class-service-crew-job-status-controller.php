<?php
/**
 * REST routes for the employee job-status link: POST
 * /job-status/{token}/on-the-way, .../start, .../complete. Public — the
 * token itself is the access control, same pattern as
 * public/class-service-crew-pay-page.php's own routes on
 * Service_Crew_Payments. Deliberately thin: token lookup lives in
 * Service_Crew_Assignments, the status transition + photo/note handling at
 * Complete lives in Service_Crew_Bookings — this class only wires HTTP to
 * them.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Job_Status_Controller {

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
	 * Registers every job-status transition route. All public — a lead
	 * tapping their own status link is never logged in, same as every other
	 * token-gated route in this plugin.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/job-status/(?P<token>[^/]+)/on-the-way',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'on_the_way' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/job-status/(?P<token>[^/]+)/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/job-status/(?P<token>[^/]+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Resolves a route's token to the booking id it controls. Returns a
	 * WP_Error that deliberately doesn't distinguish "unknown" from
	 * "expired" from "assignment no longer active" — same "don't help
	 * enumerate valid tokens" rule as the token lookup itself.
	 *
	 * @param string $token Plaintext token from the route.
	 * @return int|WP_Error
	 */
	private function resolve_booking_id( $token ) {
		$assignment = Service_Crew_Assignments::find_active_assignment_by_status_token( (string) $token );

		if ( ! $assignment ) {
			return new WP_Error( 'sc_job_token_invalid', __( 'This link is invalid or has expired.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return (int) $assignment->booking_id;
	}

	/**
	 * POST handler: lead taps "On the way".
	 *
	 * @param WP_REST_Request $request Request; 'token' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function on_the_way( $request ) {
		$booking_id = $this->resolve_booking_id( $request['token'] );
		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}

		$result = Service_Crew_Bookings::update_job_status(
			$booking_id,
			Service_Crew_Bookings::STATUS_ON_THE_WAY,
			array( Service_Crew_Bookings::STATUS_ASSIGNED )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'status' => Service_Crew_Bookings::STATUS_ON_THE_WAY ) );
	}

	/**
	 * POST handler: lead taps "Start".
	 *
	 * @param WP_REST_Request $request Request; 'token' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( $request ) {
		$booking_id = $this->resolve_booking_id( $request['token'] );
		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}

		$result = Service_Crew_Bookings::update_job_status(
			$booking_id,
			Service_Crew_Bookings::STATUS_IN_PROGRESS,
			array( Service_Crew_Bookings::STATUS_ASSIGNED, Service_Crew_Bookings::STATUS_ON_THE_WAY )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'status' => Service_Crew_Bookings::STATUS_IN_PROGRESS ) );
	}

	/**
	 * POST handler: lead taps "Complete", with an optional note and photos.
	 * Multipart, same as the quote form's own photo upload — reads body/file
	 * params rather than JSON.
	 *
	 * @param WP_REST_Request $request Multipart request; 'token' from the route, optional 'note' field, optional 'photos' file(s).
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete( $request ) {
		$booking_id = $this->resolve_booking_id( $request['token'] );
		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}

		$params = $request->get_body_params();
		$params = is_array( $params ) ? $params : array();

		$files = $request->get_file_params();
		$files = is_array( $files ) ? $files : array();

		$result = Service_Crew_Bookings::complete_job( $booking_id, $params['note'] ?? '', $files );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'status' => Service_Crew_Bookings::STATUS_COMPLETED ) );
	}
}
