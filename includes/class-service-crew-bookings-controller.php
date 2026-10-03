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
			'/calculate-price',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'calculate_price' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/available-dates',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_available_dates' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/booked-windows',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_booked_windows' ),
				'permission_callback' => '__return_true',
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

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/quote',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_quote' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_quote_price' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/quote-reject',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reject_quote' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/quote-deposit-link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_quote_deposit_link' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/suggestions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_suggestions' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/assignment',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_assignment' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_assignment' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/overtime',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overtime' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve_overtime' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_notes' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_note' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/reschedule',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reschedule_booking' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/balance/collect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_balance_collected' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/balance/link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_balance_payment_link' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings/(?P<id>\d+)/refund',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refund_booking' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
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
					b.quote_title, b.subtotal_amount, b.customer_total, b.deposit_amount, b.amount_paid,
					b.flag_address_review, b.flag_address_approximate, b.flag_outside_coverage,
					b.created_at, p.post_title AS service_title,
					( SELECT COUNT(*) FROM {$wpdb->prefix}sc_booking_services WHERE booking_id = b.id ) AS service_count
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
	 * GET handler: which arrival-window labels are already taken on a given
	 * date, so the Date & Time step can grey them out before the customer
	 * tries to submit. Public — the same booking flow that needs this is
	 * itself unauthenticated. See Service_Crew_Bookings::get_booked_windows().
	 *
	 * @param WP_REST_Request $request Request; 'date' query param, YYYY-MM-DD.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_booked_windows( $request ) {
		$date = sanitize_text_field( (string) $request->get_param( 'date' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'sc_invalid_date', __( 'Please provide a valid date.', 'service-crew' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'windows' => Service_Crew_Bookings::get_booked_windows( $date ) ) );
	}

	/**
	 * POST handler: the plan's "live price, time and quantity discount
	 * lines" preview — public, same as create_booking(), since a customer
	 * previewing a price is never logged in. See
	 * Service_Crew_Bookings::calculate_price_preview() for what this wraps.
	 *
	 * @param WP_REST_Request $request Request with an 'items' array in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function calculate_price( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$items  = is_array( $params['items'] ?? null ) ? $params['items'] : array();

		$result = Service_Crew_Bookings::calculate_price_preview( $items );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST handler: the plan's "available-dates" check — which dates in a
	 * range have enough pooled employee capacity for this cart. Public, same
	 * as create_booking()/calculate_price(). See
	 * Service_Crew_Bookings::get_available_dates() for what this wraps,
	 * including its scope note (single-day/single-crew only, matching
	 * today's fixed instant-booking values).
	 *
	 * @param WP_REST_Request $request Request with 'items', 'start_date' and optional 'days' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_available_dates( $request ) {
		$params     = $request->get_json_params();
		$params     = is_array( $params ) ? $params : array();
		$items      = is_array( $params['items'] ?? null ) ? $params['items'] : array();
		$start_date = sanitize_text_field( $params['start_date'] ?? '' );
		$days       = isset( $params['days'] ) ? absint( $params['days'] ) : 30;

		$result = Service_Crew_Bookings::get_available_dates( $items, $start_date, $days );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
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

	/**
	 * GET handler: the expanded quote view on the admin bookings screen
	 * (description, photos, current price fields) — admin-only, same as the
	 * list above. See Service_Crew_Quotes::get_quote_detail().
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_quote( $request ) {
		$quote = Service_Crew_Quotes::get_quote_detail( absint( $request['id'] ) );

		if ( ! $quote ) {
			return new WP_Error( 'sc_quote_not_found', __( 'Quote request not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $quote );
	}

	/**
	 * PUT handler: admin sets a quote's price/service/duration/crew and
	 * marks it `quoted`. See Service_Crew_Quotes::set_price().
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, price fields in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_quote_price( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$result = Service_Crew_Quotes::set_price( absint( $request['id'] ), $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * PUT handler: admin rejects a quote with a reason. See
	 * Service_Crew_Quotes::reject().
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, 'reason' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_quote( $request ) {
		$params = $request->get_json_params();
		$reason = is_array( $params ) ? ( $params['reason'] ?? '' ) : '';

		$result = Service_Crew_Quotes::reject( absint( $request['id'] ), $reason );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'status' => Service_Crew_Quotes::STATUS_REJECTED ) );
	}

	/**
	 * POST handler: admin sets the scheduled date + window and generates a
	 * single-use deposit pay-page link to send the customer. See
	 * Service_Crew_Quotes::create_deposit_link().
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, 'date'/'arrival_window' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_quote_deposit_link( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$result = Service_Crew_Quotes::create_deposit_link( absint( $request['id'] ), $params['date'] ?? '', $params['arrival_window'] ?? '' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET handler: ranked employee suggestions for a booking (nearest,
	 * qualifying, least-loaded). See Service_Crew_Matching::get_suggestions()
	 * for the calc and this method's own build_employee_candidates() for a
	 * known simplification: the "least-loaded" tiebreak isn't wired yet,
	 * every candidate ties at 0 load, so ranking today is distance-only.
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_suggestions( $request ) {
		$booking_id = absint( $request['id'] );

		global $wpdb;
		$booking = $wpdb->get_row(
			$wpdb->prepare( 'SELECT lat, lng, preferred_date FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id )
		);

		if ( ! $booking ) {
			return new WP_Error( 'sc_booking_not_found', __( 'Booking not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$suggestions = Service_Crew_Matching::get_suggestions(
			$this->build_employee_candidates(),
			null !== $booking->lat ? (float) $booking->lat : null,
			null !== $booking->lng ? (float) $booking->lng : null,
			(string) $booking->preferred_date
		);

		$suggestions = array_map(
			function ( $suggestion ) {
				$suggestion['name'] = get_the_title( $suggestion['id'] );
				return $suggestion;
			},
			$suggestions
		);

		return rest_ensure_response( $suggestions );
	}

	/**
	 * Every published `employee`-type crew member, shaped for
	 * Service_Crew_Matching — small, deliberate duplication of what
	 * Service_Crew_Crew_Controller::build_response() already fetches, same
	 * "same fields, can't share the private method" reasoning that class's
	 * own docblock already documents for Service_Crew_Wizard.
	 *
	 * @return array<int,array{id:int,lat:float|null,lng:float|null,radius:int|null,availability:array,time_off:array,load_hours:float}>
	 */
	private function build_employee_candidates() {
		$employee_ids = get_posts(
			array(
				'post_type'      => 'sc_crew',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Service_Crew_Crew::META_TYPE,
						'value' => 'employee',
					),
				),
			)
		);

		return array_map(
			function ( $crew_id ) {
				$lat          = get_post_meta( $crew_id, Service_Crew_Crew::META_LAT, true );
				$lng          = get_post_meta( $crew_id, Service_Crew_Crew::META_LNG, true );
				$radius       = get_post_meta( $crew_id, Service_Crew_Crew::META_RADIUS, true );
				$availability = get_post_meta( $crew_id, Service_Crew_Crew::META_AVAILABILITY, true );
				$time_off     = get_post_meta( $crew_id, Service_Crew_Crew::META_TIME_OFF, true );

				return array(
					'id'           => $crew_id,
					'lat'          => '' === $lat ? null : (float) $lat,
					'lng'          => '' === $lng ? null : (float) $lng,
					'radius'       => '' === $radius ? null : (int) $radius,
					'availability' => is_array( $availability ) ? $availability : array(),
					'time_off'     => is_array( $time_off ) ? $time_off : array(),
					// Not wired yet — would need summing every active
					// assignment's own job hours per employee per date.
					// Every candidate ties at 0, so get_suggestions() ranks
					// purely by distance today; a real tiebreak is a
					// follow-up, not something this task builds.
					'load_hours'   => 0,
				);
			},
			$employee_ids
		);
	}

	/**
	 * GET handler: a booking's current crew roster.
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route.
	 * @return WP_REST_Response
	 */
	public function get_assignment( $request ) {
		$booking_id = absint( $request['id'] );

		$crew = array_map(
			function ( $row ) {
				return array(
					'assignment_id' => (int) $row->id,
					'crew_id'       => (int) $row->crew_id,
					'name'          => get_the_title( (int) $row->crew_id ),
					'role'          => $row->role,
				);
			},
			array_values( Service_Crew_Assignments::get_active_assignments( $booking_id ) )
		);

		return rest_ensure_response(
			array(
				'crew'         => $crew,
				'lead_crew_id' => Service_Crew_Assignments::get_lead_crew_id( $booking_id ),
			)
		);
	}

	/**
	 * PUT handler: sets a booking's full crew roster. See
	 * Service_Crew_Assignments::assign_crew() — V1 scope: employee-only,
	 * immediately final, no accept/decline.
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, 'crew_ids'/'lead_crew_id'/optional 'reason' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_assignment( $request ) {
		$booking_id = absint( $request['id'] );
		$params     = $request->get_json_params();
		$params     = is_array( $params ) ? $params : array();

		$crew_ids = is_array( $params['crew_ids'] ?? null ) ? array_map( 'absint', $params['crew_ids'] ) : array();

		$result = Service_Crew_Assignments::assign_crew(
			$booking_id,
			$crew_ids,
			absint( $params['lead_crew_id'] ?? 0 ),
			sanitize_text_field( $params['reason'] ?? '' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'status' => Service_Crew_Bookings::STATUS_ASSIGNED ) );
	}

	/**
	 * GET handler: every overtime row already recorded for a booking, with
	 * the crew member's name for display. See Service_Crew_Overtime::get_for_booking().
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route.
	 * @return WP_REST_Response
	 */
	public function get_overtime( $request ) {
		$rows = array_map(
			function ( $row ) {
				return array(
					'id'                 => (int) $row->id,
					'crew_id'            => (int) $row->crew_id,
					'crew_name'          => get_the_title( (int) $row->crew_id ),
					'hours'              => (float) $row->hours,
					'surcharge_decision' => $row->surcharge_decision,
					'surcharge_amount'   => (float) $row->surcharge_amount,
					'note'               => $row->note,
					'approved_at'        => $row->approved_at,
				);
			},
			Service_Crew_Overtime::get_for_booking( absint( $request['id'] ) )
		);

		return rest_ensure_response( $rows );
	}

	/**
	 * POST handler: admin approves overtime for a specific assignment. See
	 * Service_Crew_Overtime::approve().
	 *
	 * @param WP_REST_Request $request Request; 'id' (booking, unused beyond routing) from the route, 'assignment_id'/'hours'/'surcharge_decision'/'surcharge_amount'/'note' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_overtime( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$result = Service_Crew_Overtime::approve(
			absint( $params['assignment_id'] ?? 0 ),
			$params['hours'] ?? 0,
			sanitize_key( $params['surcharge_decision'] ?? '' ),
			$params['surcharge_amount'] ?? 0,
			$params['note'] ?? ''
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET handler: a booking's notes timeline — the plan's "Notes UI on the
	 * board" (Phase 1e), the first real consumer of
	 * Service_Crew_Notes::get_notes_for_booking(). Covers every note type
	 * already being written: quote description/photos, quote-reject reason,
	 * and the employee job-status page's completion note/photos — plus
	 * whatever the admin adds here.
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route.
	 * @return WP_REST_Response
	 */
	public function get_notes( $request ) {
		$notes = array_map(
			function ( $note ) {
				$attachment_url = $note->attachment_id ? wp_get_attachment_url( (int) $note->attachment_id ) : '';
				$author         = $note->author_id ? get_userdata( (int) $note->author_id ) : false;

				return array(
					'id'          => (int) $note->id,
					'note_type'   => $note->note_type,
					'body'        => $note->body,
					'photo_url'   => $attachment_url ? $attachment_url : '',
					'author_name' => $author ? $author->display_name : '',
					'created_at'  => $note->created_at,
				);
			},
			Service_Crew_Notes::get_notes_for_booking( absint( $request['id'] ) )
		);

		return rest_ensure_response( $notes );
	}

	/**
	 * POST handler: admin adds a free-text note to a booking's timeline.
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, 'body' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_note( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$body = sanitize_textarea_field( trim( (string) ( $params['body'] ?? '' ) ) );
		if ( '' === $body ) {
			return new WP_Error( 'sc_note_body_required', __( 'Please enter a note.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$result = Service_Crew_Notes::add_note( absint( $request['id'] ), Service_Crew_Notes::TYPE_NOTE, $body, null, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'id' => $result ) );
	}

	/**
	 * PUT handler: admin reschedules a booking's date/arrival window. See
	 * Service_Crew_Bookings::reschedule() — conflicts are warnings, never
	 * blocks, per the plan.
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, 'date'/'arrival_window' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reschedule_booking( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$result = Service_Crew_Bookings::reschedule( absint( $request['id'] ), $params['date'] ?? '', $params['arrival_window'] ?? '' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST handler: admin marks the remaining balance collected outside the
	 * system (cash, check, etc). See Service_Crew_Bookings::mark_balance_collected().
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, optional 'note' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_balance_collected( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$result = Service_Crew_Bookings::mark_balance_collected( absint( $request['id'] ), $params['note'] ?? '' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST handler: admin generates a single-use pay-page link for the
	 * remaining balance. See Service_Crew_Bookings::create_balance_payment_link().
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_balance_payment_link( $request ) {
		$result = Service_Crew_Bookings::create_balance_payment_link( absint( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST handler: admin refunds part or all of a booking's paid amount. See
	 * Service_Crew_Bookings::refund_booking().
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route, 'amount'/'reason' in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refund_booking( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$result = Service_Crew_Bookings::refund_booking(
			absint( $request['id'] ),
			$params['amount'] ?? 0,
			$params['reason'] ?? ''
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
