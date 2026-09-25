<?php
/**
 * Admin-gated REST controller for sc_crew — the Phase 1a "Crew REST
 * controller" task. Metadata-only, per the plan: login provisioning (the
 * "App access" box, wp_users account, Application Passwords) is Phase 1d and
 * will extend this same endpoint rather than replacing it.
 *
 * sc_crew keeps its native post-editor screen (show_ui => true in
 * class-service-crew-crew.php, unlike sc_service) — this controller doesn't
 * replace that UI, it exists for everything else that needs crew data over
 * HTTP: the Bookings admin screen's future "assign to employee" picker, and
 * later the crew/vendor PWA (Phase 1d).
 *
 * Field sanitization intentionally duplicates
 * Service_Crew_Crew::save_meta_box()'s rules rather than sharing code with
 * it — that method reads straight from $_POST, so it can't be called from a
 * JSON REST body. Same duplication, and the same reasoning, as
 * Service_Crew_Wizard::create_crew_member() already uses for the same
 * fields; see that method's docblock.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Crew_Controller {

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
	 * Registers GET+POST /crew and GET+PUT+DELETE /crew/{id}. Every route is
	 * admin-gated — same access level as the rest of the custom admin app;
	 * there is no public/customer-facing crew data (a customer only ever
	 * sees an assigned crew member's name/photo once assignments exist,
	 * Phase 1c/1d, and that will be a role-specific serializer, not this
	 * controller).
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/crew',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_crew' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type' => array(
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_crew' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/crew/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_crew' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_crew' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_crew' ),
					'permission_callback' => array( $this, 'check_permission' ),
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
	 * GET /crew — every crew member (any status), name-sorted, optionally
	 * filtered to one type. No pagination: the plan expects a small-ish crew
	 * list (individual employees/vendors, not a large directory), same
	 * assumption Service_Crew_Services_Shortcode makes for the service tree.
	 *
	 * @param WP_REST_Request $request Request, optionally with a 'type' query param ('employee'/'vendor').
	 * @return WP_REST_Response
	 */
	public function list_crew( $request ) {
		$args = array(
			'post_type'      => 'sc_crew',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$type = $request->get_param( 'type' );
		if ( in_array( $type, array( 'employee', 'vendor' ), true ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Service_Crew_Crew::META_TYPE,
					'value' => $type,
				),
			);
		}

		$posts = get_posts( $args );

		return rest_ensure_response( array_map( array( $this, 'build_response' ), $posts ) );
	}

	/**
	 * GET /crew/{id}.
	 *
	 * @param WP_REST_Request $request Request with 'id' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_crew( $request ) {
		$post = $this->find_crew_post( $request['id'] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return rest_ensure_response( $this->build_response( $post ) );
	}

	/**
	 * POST /crew — creates a crew member.
	 *
	 * @param WP_REST_Request $request Request with the crew fields in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_crew( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'sc_missing_name', __( 'A crew member needs a name.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$fields = $this->sanitize_fields( $params );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// wp_insert_post()'s meta_input skips a key entirely rather than
		// erroring on a null value, but a null here means "leave it unset",
		// not "store null" — filter them out rather than relying on that.
		$meta_input = array_filter(
			$fields['meta_input'],
			function ( $value ) {
				return null !== $value;
			}
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'sc_crew',
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => $meta_input,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->apply_photo( $post_id, $fields );

		return rest_ensure_response( $this->build_response( get_post( $post_id ) ) );
	}

	/**
	 * PUT /crew/{id} — replaces a crew member's fields. A field omitted from
	 * the request body clears the corresponding meta (full-replace, same
	 * convention as Service_Crew_Settings::update_settings()), except name
	 * and photo: an empty/omitted name keeps the existing title rather than
	 * erroring, and photo is only touched when 'photo_id' is actually present
	 * (see apply_photo()) so a client that doesn't manage photos can't
	 * accidentally clear one.
	 *
	 * @param WP_REST_Request $request Request with 'id' from the route, fields in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_crew( $request ) {
		$post = $this->find_crew_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$fields = $this->sanitize_fields( $params );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		if ( '' !== $name && $name !== $post->post_title ) {
			wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => $name,
				)
			);
		}

		foreach ( $fields['meta_input'] as $meta_key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post->ID, $meta_key );
			} else {
				update_post_meta( $post->ID, $meta_key, $value );
			}
		}

		$this->apply_photo( $post->ID, $fields );

		return rest_ensure_response( $this->build_response( get_post( $post->ID ) ) );
	}

	/**
	 * DELETE /crew/{id} — moves the crew member to trash (force delete only
	 * happens if it's already there), same as the native post-list "Delete"
	 * action, so an accidental delete is recoverable there.
	 *
	 * @param WP_REST_Request $request Request with 'id' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_crew( $request ) {
		$post = $this->find_crew_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = wp_delete_post( $post->ID );
		if ( ! $result ) {
			return new WP_Error( 'sc_crew_delete_failed', __( 'Could not delete this crew member.', 'service-crew' ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * @param mixed $id Raw route param.
	 * @return WP_Post|WP_Error
	 */
	private function find_crew_post( $id ) {
		$post = get_post( absint( $id ) );

		if ( ! $post || 'sc_crew' !== $post->post_type ) {
			return new WP_Error( 'sc_crew_not_found', __( 'Crew member not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return $post;
	}

	/**
	 * Sanitizes every crew field except name/photo (handled by their
	 * callers) into a meta_input-shaped array. A null value means "this
	 * field should be unset" (create_crew() filters those out; update_crew()
	 * deletes the meta key for them) — same null-means-delete convention as
	 * Service_Crew_Services::sanitize_pricing_fields().
	 *
	 * Enforces the plan's "vendors should always have a radius" rule
	 * strictly (rejects the write) — the meta box only warns, per its own
	 * docblock's forward-reference to this controller.
	 *
	 * @param array<string,mixed> $params Raw posted fields.
	 * @return array{meta_input:array<string,mixed>,photo_id:int,photo_provided:bool}|WP_Error
	 */
	private function sanitize_fields( array $params ) {
		$type = 'vendor' === ( $params['type'] ?? '' ) ? 'vendor' : 'employee';

		$radius_raw = array_key_exists( 'radius', $params ) ? trim( (string) $params['radius'] ) : '';
		$radius     = '' !== $radius_raw ? max( 0, absint( $radius_raw ) ) : null;

		if ( 'vendor' === $type && null === $radius ) {
			return new WP_Error( 'sc_vendor_radius_required', __( 'Vendors must have a coverage radius set.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$lat = array_key_exists( 'lat', $params ) && '' !== $params['lat'] ? (float) $params['lat'] : null;
		$lng = array_key_exists( 'lng', $params ) && '' !== $params['lng'] ? (float) $params['lng'] : null;

		$meta_input = array(
			Service_Crew_Crew::META_TYPE    => $type,
			Service_Crew_Crew::META_PHONE   => sanitize_text_field( $params['phone'] ?? '' ),
			Service_Crew_Crew::META_EMAIL   => sanitize_email( $params['email'] ?? '' ),
			Service_Crew_Crew::META_ADDRESS => sanitize_text_field( $params['address'] ?? '' ),
			Service_Crew_Crew::META_ZIP     => sanitize_text_field( $params['zip'] ?? '' ),
			Service_Crew_Crew::META_LAT     => $lat,
			Service_Crew_Crew::META_LNG     => $lng,
			Service_Crew_Crew::META_RADIUS  => $radius,
		);

		$availability = $this->sanitize_availability( $params['availability'] ?? array() );
		$meta_input[ Service_Crew_Crew::META_AVAILABILITY ] = empty( $availability ) ? null : $availability;

		$time_off = $this->sanitize_time_off( $params['time_off'] ?? array() );
		$meta_input[ Service_Crew_Crew::META_TIME_OFF ] = empty( $time_off ) ? null : $time_off;

		$photo_id = absint( $params['photo_id'] ?? 0 );
		if ( $photo_id && ! wp_attachment_is_image( $photo_id ) ) {
			$photo_id = 0;
		}

		return array(
			'meta_input'     => $meta_input,
			'photo_id'       => $photo_id,
			'photo_provided' => array_key_exists( 'photo_id', $params ),
		);
	}

	/**
	 * Sets or clears the featured image (this CPT's photo field) — only when
	 * the request actually included 'photo_id', so an update that doesn't
	 * mention photos leaves the existing one alone.
	 *
	 * @param int                  $post_id Crew post ID.
	 * @param array<string,mixed>  $fields  Result of sanitize_fields().
	 * @return void
	 */
	private function apply_photo( $post_id, array $fields ) {
		if ( ! $fields['photo_provided'] ) {
			return;
		}

		if ( $fields['photo_id'] ) {
			set_post_thumbnail( $post_id, $fields['photo_id'] );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}

	/**
	 * Sanitizes the availability payload. Same shape/rules as
	 * Service_Crew_Crew::save_availability() — see the class docblock's
	 * duplication note.
	 *
	 * @param mixed $rows Raw posted value, expected keyed by weekday.
	 * @return array<string,array<string,string>>
	 */
	private function sanitize_availability( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$availability = array();

		foreach ( Service_Crew_Crew::WEEKDAYS as $day ) {
			if ( ! isset( $rows[ $day ] ) || ! is_array( $rows[ $day ] ) ) {
				continue;
			}

			$shifts = array(
				'shift1_start' => $this->sanitize_time( $rows[ $day ]['shift1_start'] ?? '' ),
				'shift1_end'   => $this->sanitize_time( $rows[ $day ]['shift1_end'] ?? '' ),
				'shift2_start' => $this->sanitize_time( $rows[ $day ]['shift2_start'] ?? '' ),
				'shift2_end'   => $this->sanitize_time( $rows[ $day ]['shift2_end'] ?? '' ),
			);

			if ( '' === $shifts['shift1_start'] && '' === $shifts['shift1_end'] && '' === $shifts['shift2_start'] && '' === $shifts['shift2_end'] ) {
				continue;
			}

			$availability[ $day ] = $shifts;
		}

		return $availability;
	}

	/**
	 * Sanitizes the time-off payload. Same shape/rules as
	 * Service_Crew_Crew::save_time_off() — see the class docblock's
	 * duplication note.
	 *
	 * @param mixed $rows Raw posted value.
	 * @return array<int,array{start:string,end:string,reason:string}>
	 */
	private function sanitize_time_off( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$time_off = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$start = $this->sanitize_date( $row['start'] ?? '' );
			if ( '' === $start ) {
				continue;
			}

			$end = $this->sanitize_date( $row['end'] ?? '' );
			if ( '' === $end || $end < $start ) {
				$end = $start;
			}

			$time_off[] = array(
				'start'  => $start,
				'end'    => $end,
				'reason' => sanitize_text_field( $row['reason'] ?? '' ),
			);
		}

		return $time_off;
	}

	/**
	 * @param string $value Raw posted value.
	 * @return string Sanitized HH:MM value, or '' if invalid/empty.
	 */
	private function sanitize_time( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * @param string $value Raw posted value.
	 * @return string Sanitized YYYY-MM-DD value, or '' if invalid/empty.
	 */
	private function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Builds one crew member's REST response shape.
	 *
	 * @param WP_Post $post Crew post.
	 * @return array<string,mixed>
	 */
	private function build_response( $post ) {
		$radius       = get_post_meta( $post->ID, Service_Crew_Crew::META_RADIUS, true );
		$lat          = get_post_meta( $post->ID, Service_Crew_Crew::META_LAT, true );
		$lng          = get_post_meta( $post->ID, Service_Crew_Crew::META_LNG, true );
		$availability = get_post_meta( $post->ID, Service_Crew_Crew::META_AVAILABILITY, true );
		$time_off     = get_post_meta( $post->ID, Service_Crew_Crew::META_TIME_OFF, true );
		$type         = get_post_meta( $post->ID, Service_Crew_Crew::META_TYPE, true );
		$photo_id     = get_post_thumbnail_id( $post->ID );

		return array(
			'id'           => $post->ID,
			'name'         => get_the_title( $post ),
			'status'       => $post->post_status,
			'type'         => 'vendor' === $type ? 'vendor' : 'employee',
			'phone'        => (string) get_post_meta( $post->ID, Service_Crew_Crew::META_PHONE, true ),
			'email'        => (string) get_post_meta( $post->ID, Service_Crew_Crew::META_EMAIL, true ),
			'address'      => (string) get_post_meta( $post->ID, Service_Crew_Crew::META_ADDRESS, true ),
			'zip'          => (string) get_post_meta( $post->ID, Service_Crew_Crew::META_ZIP, true ),
			'lat'          => '' === $lat ? null : (float) $lat,
			'lng'          => '' === $lng ? null : (float) $lng,
			'radius'       => '' === $radius ? null : (int) $radius,
			'availability' => is_array( $availability ) ? $availability : array(),
			'time_off'     => is_array( $time_off ) ? $time_off : array(),
			'photo_id'     => $photo_id ? (int) $photo_id : 0,
			'photo_url'    => $photo_id ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '',
		);
	}
}
