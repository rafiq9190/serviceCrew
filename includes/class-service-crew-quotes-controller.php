<?php
/**
 * REST route for the public quote-request form: POST /service-crew/v1/quotes.
 * Deliberately thin — all validation, hardening (rate limit, honeypot, photo
 * checks) and the DB writes live in class-service-crew-quotes.php; this
 * class only wires HTTP to it, same split as
 * Service_Crew_Bookings/Service_Crew_Bookings_Controller.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Quotes_Controller {

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
	 * Registers POST /quotes. Public — a customer requesting a quote is
	 * never logged in — with no capability check; every field is validated
	 * and every photo is independently re-checked server-side in
	 * Service_Crew_Quotes, and submissions are rate-limited there too.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/quotes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_quote' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST handler: validates, uploads any photos, and creates the quote
	 * request. Reads from $request's body params (multipart form fields) and
	 * file params (multipart file uploads) rather than JSON params, since
	 * photo upload requires multipart/form-data.
	 *
	 * @param WP_REST_Request $request Multipart request: form fields + optional 'photos' file(s).
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_quote( $request ) {
		$params = $request->get_body_params();
		$params = is_array( $params ) ? $params : array();

		$files = $request->get_file_params();
		$files = is_array( $files ) ? $files : array();

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line via sanitize_text_field().

		$result = Service_Crew_Quotes::create_quote_request( $params, $files, $ip );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
