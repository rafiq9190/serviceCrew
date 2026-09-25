<?php
/**
 * Admin-gated REST controller for sc_service — the only data path the
 * custom Services admin app (Service_Crew_Admin_App) uses. sc_service
 * deliberately has show_in_rest => false; every field returned or accepted
 * is explicit here, including computed ones (has_children, breadcrumb) core's
 * generic post REST controller doesn't provide for a hierarchical CPT.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Services_Controller {

	/**
	 * REST namespace shared with Service_Crew_Discounts.
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
	 * Registers /services and /services/{id}.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/services',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_services' ),
					'permission_callback' => array( $this, 'check_manage_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_service' ),
					'permission_callback' => array( $this, 'check_manage_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/services/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_service' ),
					'permission_callback' => array( $this, 'check_manage_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_service' ),
					'permission_callback' => array( $this, 'check_edit_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_service' ),
					'permission_callback' => array( $this, 'check_delete_permission' ),
				),
			)
		);
	}

	/**
	 * Permission check for list/create — same access level as the
	 * ServiceCrew admin menu itself.
	 *
	 * @return bool
	 */
	public function check_manage_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission check for updating a specific service, via the same custom
	 * meta capability the (now removed) meta-box save handler used.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	public function check_edit_permission( $request ) {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * Permission check for deleting a specific service.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	public function check_delete_permission( $request ) {
		return current_user_can( 'delete_post', (int) $request['id'] );
	}

	/**
	 * Fetches a post, verifying it's actually an sc_service.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|null
	 */
	private function get_service_post( $id ) {
		$post = get_post( $id );

		if ( ! $post || 'sc_service' !== $post->post_type ) {
			return null;
		}

		return $post;
	}

	/**
	 * Builds the REST representation of a service.
	 *
	 * @param WP_Post $post Service post.
	 * @return array<string,mixed>
	 */
	private function serialize( $post ) {
		$has_children = Service_Crew_Services::has_child_services( $post->ID );

		return array(
			'id'           => $post->ID,
			'title'        => Service_Crew_Services::get_plain_title( $post ),
			'parent_id'    => (int) $post->post_parent,
			'has_children' => $has_children,
			'breadcrumb'   => Service_Crew_Services::build_breadcrumb( $post ),
			'status'       => $post->post_status,
			'pricing'      => Service_Crew_Services::get_pricing_fields( $post->ID, $has_children ),
			'components'   => Service_Crew_Components::get_components( $post->ID, $has_children ),
		);
	}

	/**
	 * GET /services — every service, flattened (the client builds the tree
	 * from parent_id).
	 *
	 * @return WP_REST_Response
	 */
	public function list_services() {
		$posts = get_posts(
			array(
				'post_type'      => 'sc_service',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return rest_ensure_response( array_map( array( $this, 'serialize' ), $posts ) );
	}

	/**
	 * GET /services/{id}.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_service( $request ) {
		$post = $this->get_service_post( (int) $request['id'] );

		if ( ! $post ) {
			return new WP_Error( 'sc_service_not_found', __( 'Service not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->serialize( $post ) );
	}

	/**
	 * Validates a posted parent_id: 0 (no parent), or an existing sc_service
	 * that isn't the post itself or one of its own descendants (no cycles).
	 *
	 * @param mixed    $parent_id Posted parent ID.
	 * @param int|null $post_id   Current post ID, null when creating.
	 * @return int|WP_Error
	 */
	private function validate_parent_id( $parent_id, $post_id ) {
		$parent_id = absint( $parent_id );

		if ( 0 === $parent_id ) {
			return 0;
		}

		$excluded = null !== $post_id ? Service_Crew_Services::get_self_and_descendant_ids( $post_id ) : array();

		if ( in_array( $parent_id, $excluded, true ) ) {
			return new WP_Error(
				'sc_invalid_parent',
				__( 'A service cannot be its own parent or a parent of one of its own sub-services.', 'service-crew' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->get_service_post( $parent_id ) ) {
			return new WP_Error( 'sc_invalid_parent', __( 'Parent service not found.', 'service-crew' ), array( 'status' => 400 ) );
		}

		return $parent_id;
	}

	/**
	 * After a create/update sets $post_id's parent to $parent_id, makes sure
	 * that parent's own pricing/add-ons are cleared the moment it becomes a
	 * category — enforced here rather than only at read time, so a category
	 * never carries stale data even between saves.
	 *
	 * @param int $parent_id Parent id, 0 for none.
	 * @return void
	 */
	private function sync_parent_category_state( $parent_id ) {
		if ( 0 === $parent_id || ! Service_Crew_Services::has_child_services( $parent_id ) ) {
			return;
		}

		Service_Crew_Services::apply_pricing_meta( $parent_id, Service_Crew_Services::sanitize_pricing_fields( array(), true ) );
		Service_Crew_Components::apply( $parent_id, array(), true );
	}

	/**
	 * POST /services.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_service( $request ) {
		$params = $request->get_json_params();
		$title  = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'sc_missing_title', __( 'A service needs a name.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$parent_id = $this->validate_parent_id( $params['parent_id'] ?? 0, null );
		if ( is_wp_error( $parent_id ) ) {
			return $parent_id;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'sc_service',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_parent' => $parent_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// A brand-new post never has children yet, so it's always a leaf here.
		Service_Crew_Services::apply_pricing_meta(
			$post_id,
			Service_Crew_Services::sanitize_pricing_fields( $params['pricing'] ?? array(), false )
		);
		Service_Crew_Components::apply(
			$post_id,
			Service_Crew_Components::sanitize_component_list( $params['components'] ?? array() ),
			false
		);

		$this->sync_parent_category_state( $parent_id );

		return rest_ensure_response( $this->serialize( get_post( $post_id ) ) );
	}

	/**
	 * PUT/PATCH /services/{id}.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_service( $request ) {
		$post_id = (int) $request['id'];
		$post    = $this->get_service_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'sc_service_not_found', __( 'Service not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();

		if ( isset( $params['title'] ) ) {
			$title = sanitize_text_field( $params['title'] );

			if ( '' === $title ) {
				return new WP_Error( 'sc_missing_title', __( 'A service needs a name.', 'service-crew' ), array( 'status' => 400 ) );
			}

			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
				)
			);
		}

		$parent_id = $this->validate_parent_id( $params['parent_id'] ?? $post->post_parent, $post_id );
		if ( is_wp_error( $parent_id ) ) {
			return $parent_id;
		}

		if ( $parent_id !== (int) $post->post_parent ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_parent' => $parent_id,
				)
			);
		}

		$has_children = Service_Crew_Services::has_child_services( $post_id );

		Service_Crew_Services::apply_pricing_meta(
			$post_id,
			Service_Crew_Services::sanitize_pricing_fields( $params['pricing'] ?? array(), $has_children )
		);
		Service_Crew_Components::apply(
			$post_id,
			Service_Crew_Components::sanitize_component_list( $params['components'] ?? array() ),
			$has_children
		);

		$this->sync_parent_category_state( $parent_id );

		return rest_ensure_response( $this->serialize( get_post( $post_id ) ) );
	}

	/**
	 * DELETE /services/{id}. Refuses to delete a service that still has
	 * sub-services — those would be orphaned (still parented to a trashed
	 * post) rather than promoted or reassigned automatically.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_service( $request ) {
		$post_id = (int) $request['id'];
		$post    = $this->get_service_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'sc_service_not_found', __( 'Service not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		if ( Service_Crew_Services::has_child_services( $post_id ) ) {
			return new WP_Error(
				'sc_service_has_children',
				__( 'Remove or move this service\'s sub-services before deleting it.', 'service-crew' ),
				array( 'status' => 409 )
			);
		}

		if ( ! wp_trash_post( $post_id ) ) {
			return new WP_Error( 'sc_delete_failed', __( 'Could not delete this service.', 'service-crew' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
