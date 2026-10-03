<?php
/**
 * Admin-gated REST controller for sc_customers — the Phase 1b-2 "Customers
 * REST controller" task. Read-only by design: a customer row is only ever
 * created/updated via Service_Crew_Customers::find_or_create() as a booking
 * or quote comes in — the plan has no admin-editable customer record — so
 * this exposes list/detail/booking-history only, no create/update/delete.
 *
 * admin/js/app-customers.js (Phase 1e) is this controller's consumer: list +
 * search/opt-in filter, expanding a row to its booking history via
 * get_customer_bookings() below.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Customers_Controller {

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Row cap for the list route — same "minimal list, no pagination UI yet"
	 * convention as Service_Crew_Bookings_Controller::LIST_LIMIT.
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
	 * Registers GET /customers and GET /customers/{id}. Admin-gated — same
	 * access level as the rest of the custom admin app; there is no public/
	 * customer-facing read of this data.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_customers' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'search'   => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'opted_in' => array(
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/customers/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customer' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/customers/(?P<id>\d+)/bookings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customer_bookings' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission check shared by every route on this class.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler: the most recent customers, newest first, optionally
	 * filtered by a name/email search and/or marketing opt-in status.
	 *
	 * @param WP_REST_Request $request Request; optional 'search'/'opted_in' query params.
	 * @return WP_REST_Response
	 */
	public function list_customers( $request ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'sc_customers';
		$conditions = array();
		$values     = array();

		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$conditions[] = '(name LIKE %s OR email LIKE %s)';
			$like         = '%' . $wpdb->esc_like( $search ) . '%';
			$values[]     = $like;
			$values[]     = $like;
		}

		if ( null !== $request->get_param( 'opted_in' ) && rest_sanitize_boolean( $request->get_param( 'opted_in' ) ) ) {
			$conditions[] = 'marketing_opt_in = 1';
		}

		$where    = $conditions ? ( 'WHERE ' . implode( ' AND ', $conditions ) ) : '';
		$values[] = self::LIST_LIMIT;

		$sql  = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		return rest_ensure_response( array_map( array( $this, 'build_response' ), $rows ) );
	}

	/**
	 * GET handler: a single customer by id.
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customer( $request ) {
		$customer = Service_Crew_Customers::get_customer( absint( $request['id'] ) );

		if ( ! $customer ) {
			return new WP_Error( 'sc_customer_not_found', __( 'Customer not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->build_response( $customer ) );
	}

	/**
	 * GET handler: a customer's booking history — the plan's "customer
	 * detail" screen, narrowed to what's already on the booking row itself
	 * (date, service/quote title, status, totals). Doesn't join
	 * assignments/payments/notes — who fulfilled each job, a separate
	 * payment-by-payment ledger, and the notes timeline are all a bigger,
	 * separate follow-up (the plan's full "booking history with fulfiller,
	 * payment history, notes timeline including attachments").
	 *
	 * @param WP_REST_Request $request Request; 'id' from the route.
	 * @return WP_REST_Response
	 */
	public function get_customer_bookings( $request ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.preferred_date, b.arrival_window, b.status, b.source,
					b.customer_total, b.amount_paid, b.quote_title, p.post_title AS service_title
				FROM {$wpdb->prefix}sc_bookings b
				LEFT JOIN {$wpdb->posts} p ON p.ID = b.service_id
				WHERE b.customer_id = %d
				ORDER BY b.created_at DESC",
				absint( $request['id'] )
			)
		);

		return rest_ensure_response( $rows );
	}

	/**
	 * @param object $row sc_customers row.
	 * @return array<string,mixed>
	 */
	private function build_response( $row ) {
		return array(
			'id'               => (int) $row->id,
			'name'             => $row->name,
			'email'            => $row->email,
			'phone'            => $row->phone,
			'marketing_opt_in' => (bool) $row->marketing_opt_in,
			'created_at'       => $row->created_at,
			'updated_at'       => $row->updated_at,
		);
	}
}
