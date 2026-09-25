<?php
/**
 * REST routes for the real (non-preview) instant-booking checkout: create a
 * booking + Stripe Checkout session, let the returning customer poll whether
 * their payment has been confirmed yet, and let an admin list what's come
 * in (the Phase 1b-2 "minimal admin bookings list" — read-only, no crew
 * suggestion, since class-service-crew-matching.php doesn't exist yet).
 *
 * Deliberately thin — all validation and pricing recomputation lives in
 * class-service-crew-bookings.php; this class only wires HTTP to it, same
 * split as Service_Crew_Services/Service_Crew_Services_Controller.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Bookings_Controller {

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Row cap for the admin list — a "minimal" list per the plan, so this is
	 * the most-recent N bookings with no pagination UI, rather than
	 * unbounded.
	 *
	 * @var int
	 */
	const LIST_LIMIT = 100;

	/**
	 * Registers the REST routes. Called once from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers GET+POST /bookings and GET /bookings/{id}/payment-status.
	 * POST and the payment-status GET are public — a customer booking or
	 * checking on their own booking is never logged in — with no capability
	 * check; every price/date/contact field is independently re-validated
	 * and re-priced server-side in Service_Crew_Bookings, so nothing here
	 * trusts client-submitted amounts. The list GET is admin-gated, same
	 * access level as the rest of the custom admin app.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/bookings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_bookings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_booking' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_status' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/payment-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payment_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission check for the admin-only list route — same access level as
	 * the rest of the custom admin app.
	 *
	 * @return bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler: the most recent bookings, newest first, joined to the
	 * service post for a display title. No filters, no crew suggestion (that
	 * needs class-service-crew-matching.php, Phase 1c, not built) — the
	 * "minimal" list the plan calls for, not the full dispatch board.
	 *
	 * @return WP_REST_Response
	 */
	public function list_bookings() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.customer_name, b.customer_email, b.customer_phone,
					b.preferred_date, b.arrival_window, b.status, b.source, b.is_emergency,
					b.subtotal_amount, b.customer_total, b.deposit_amount, b.amount_paid,
					b.flag_address_review, b.flag_address_approximate, b.flag_outside_coverage,
					b.created_at, p.post_title AS service_title
				FROM {$wpdb->prefix}sc_bookings b
				LEFT JOIN {$wpdb->posts} p ON p.ID = b.service_id
				ORDER BY b.created_at DESC
				LIMIT %d",
				self::LIST_LIMIT
			)
		);

		return rest_ensure_response( $rows );
	}

	/**
	 * POST handler: validates, prices and creates the booking, then returns
	 * the Stripe Checkout URL to redirect the customer to.
	 *
	 * @param WP_REST_Request $request Request with the booking fields in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_booking( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$result = Service_Crew_Bookings::create_instant_booking( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * PUT handler: admin-only manual status override from the bookings list.
	 * See Service_Crew_Bookings::ADMIN_SETTABLE_STATUSES for the allowed set.
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, 'status' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_status( $request ) {
		$booking_id = absint( $request['id'] );
		$params     = $request->get_json_params();
		$status     = sanitize_key( is_array( $params ) ? ( $params['status'] ?? '' ) : '' );

		$result = Service_Crew_Bookings::update_status( $booking_id, $status );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'status' => $status ) );
	}

	/**
	 * GET handler for the return-from-Stripe page to poll while the webhook
	 * catches up. If an `email` query param is supplied it must match the
	 * booking's own customer_email — a light deterrent against enumerating
	 * booking statuses by id, not a real auth boundary (booking ids carry no
	 * sensitive data beyond a status string).
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, optional 'email' query param.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_payment_status( $request ) {
		$booking_id = absint( $request['id'] );
		$email      = sanitize_email( (string) $request->get_param( 'email' ) );

		global $wpdb;
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, customer_email FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d',
				$booking_id
			)
		);

		if ( ! $booking || ( '' !== $email && strtolower( $email ) !== strtolower( $booking->customer_email ) ) ) {
			return new WP_Error( 'sc_booking_not_found', __( 'Booking not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'status'    => $booking->status,
				'confirmed' => Service_Crew_Bookings::STATUS_CONFIRMED === $booking->status,
			)
		);
	}
}
