<?php
/**
 * REST routes for the admin notification bell: the unread count + recent
 * feed, and marking everything read. Deliberately thin — same split as every
 * other controller this session — all real logic lives in
 * class-service-crew-notifications.php.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Notifications_Controller {

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
	 * Registers GET /notifications and POST /notifications/mark-all-read.
	 * Both admin-gated, same access level as the rest of the custom admin app.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notifications' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/notifications/mark-all-read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_all_read' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Permission check shared by both routes.
	 *
	 * @return bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler: unread count + the most recent notifications.
	 *
	 * @return WP_REST_Response
	 */
	public function get_notifications() {
		return rest_ensure_response(
			array(
				'count' => Service_Crew_Notifications::get_unread_count(),
				'items' => Service_Crew_Notifications::get_recent(),
			)
		);
	}

	/**
	 * POST handler: marks every notification read.
	 *
	 * @return WP_REST_Response
	 */
	public function mark_all_read() {
		Service_Crew_Notifications::mark_all_read();

		return rest_ensure_response( array( 'count' => 0 ) );
	}
}
