<?php
/**
 * REST surface for the Agent chat sales agent (Phase A — see
 * C:\Users\fujitsu\.claude\plans\scalable-wondering-milner.md). Public
 * session/message/history routes for the chat widget, plus admin-gated KB
 * CRUD — Service_Crew_Agent_Settings already owns its own GET/PUT route.
 * Deliberately thin — class-service-crew-agent.php and
 * class-service-crew-agent-kb.php own the actual logic/DB writes, same split
 * as Service_Crew_Quotes/Service_Crew_Quotes_Controller.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_Controller {

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Coarse per-IP rate limit on the public routes — same transient-based
	 * shape as Service_Crew_Quotes::check_rate_limit(), tuned higher since a
	 * real conversation is many short messages, not a handful of form
	 * submissions.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX    = 60;
	const RATE_LIMIT_WINDOW = 600; // 10 minutes.

	/**
	 * Hard cap on a single message's length — generous for a real
	 * conversational message, not for someone pasting a document.
	 *
	 * @var int
	 */
	const MAX_MESSAGE_LENGTH = 2000;

	/**
	 * Registers the REST routes. Called once from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/agent/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/message',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_message' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_history' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/kb',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_kb' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_kb' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/kb/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_kb' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_kb' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_photo' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/escalations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_escalations' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/escalations/(?P<id>\d+)/answer',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'answer_escalation' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/sessions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_sessions' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/sessions/(?P<id>\d+)/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_session_messages' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/escalation-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_escalation_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @return bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return string
	 */
	private function get_request_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line via sanitize_text_field().
	}

	/**
	 * Same transient-based coarse limiter as Service_Crew_Quotes::check_rate_limit(),
	 * duplicated rather than shared since the two classes have no other
	 * coupling and the limiter is a few lines either way.
	 *
	 * @param string $bucket Distinguishes the session-create limiter from the per-message one.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( $bucket ) {
		$ip = trim( $this->get_request_ip() );

		if ( '' === $ip ) {
			return true;
		}

		$key   = 'sc_agent_rl_' . $bucket . '_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new WP_Error( 'sc_agent_rate_limited', __( 'Too many messages from this connection. Please try again in a few minutes.', 'service-crew' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * POST /agent/session — creates a new visitor conversation. No input.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_session() {
		$rate_limit_error = $this->check_rate_limit( 'session' );
		if ( is_wp_error( $rate_limit_error ) ) {
			return $rate_limit_error;
		}

		return rest_ensure_response( Service_Crew_Agent::create_session() );
	}

	/**
	 * POST /agent/message — logs a visitor's message and returns the agent's
	 * reply. { token: string, message: string }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_message( $request ) {
		$rate_limit_error = $this->check_rate_limit( 'message' );
		if ( is_wp_error( $rate_limit_error ) ) {
			return $rate_limit_error;
		}

		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$token   = isset( $params['token'] ) ? sanitize_text_field( $params['token'] ) : '';
		$message = isset( $params['message'] ) ? substr( (string) $params['message'], 0, self::MAX_MESSAGE_LENGTH ) : '';

		if ( '' === trim( $message ) ) {
			return new WP_Error( 'sc_agent_empty_message', __( 'Message cannot be empty.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$session = Service_Crew_Agent::find_session_by_token( $token );

		if ( ! $session ) {
			return new WP_Error( 'sc_agent_session_not_found', __( 'This conversation has expired. Please refresh and start a new one.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( Service_Crew_Agent::handle_message( (int) $session->id, $message ) );
	}

	/**
	 * GET /agent/history?token=... — the full transcript for a session, for
	 * the widget to repaint on page load/reload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_history( $request ) {
		$token   = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$session = Service_Crew_Agent::find_session_by_token( $token );

		if ( ! $session ) {
			return new WP_Error( 'sc_agent_session_not_found', __( 'This conversation has expired. Please refresh and start a new one.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$messages = array_map(
			function ( $message ) {
				return array(
					'sender'       => $message->sender,
					'message_type' => $message->message_type,
					'body'         => $message->body,
					'created_at'   => $message->created_at,
				);
			},
			Service_Crew_Agent::get_history( (int) $session->id )
		);

		return rest_ensure_response( $messages );
	}

	/**
	 * GET /agent/kb — every KB entry, admin-gated.
	 *
	 * @return WP_REST_Response
	 */
	public function list_kb() {
		return rest_ensure_response( array_map( array( $this, 'format_kb_entry' ), Service_Crew_Agent_KB::get_entries() ) );
	}

	/**
	 * @param object $entry Raw sc_agent_kb_entries row.
	 * @return array<string,mixed>
	 */
	private function format_kb_entry( $entry ) {
		return array(
			'id'                  => (int) $entry->id,
			'question'            => $entry->question,
			'answer'              => $entry->answer,
			'keywords'            => $entry->keywords,
			'related_service_id'  => $entry->related_service_id ? (int) $entry->related_service_id : null,
			'source'              => $entry->source,
			'is_active'           => (bool) $entry->is_active,
			'hit_count'           => (int) $entry->hit_count,
		);
	}

	/**
	 * Shared sanitize for the KB create/update body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	private function sanitize_kb_input( $request ) {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : array();
		$question = sanitize_text_field( $params['question'] ?? '' );
		$answer   = sanitize_textarea_field( $params['answer'] ?? '' );

		if ( '' === $question || '' === $answer ) {
			return new WP_Error( 'sc_agent_kb_invalid', __( 'Both a question and an answer are required.', 'service-crew' ), array( 'status' => 400 ) );
		}

		return array(
			'question'            => $question,
			'answer'              => $answer,
			'keywords'            => sanitize_text_field( $params['keywords'] ?? '' ),
			'related_service_id'  => ! empty( $params['related_service_id'] ) ? absint( $params['related_service_id'] ) : null,
			'source'              => Service_Crew_Agent_KB::SOURCE_MANUAL,
			'is_active'           => ! isset( $params['is_active'] ) || ! empty( $params['is_active'] ),
		);
	}

	/**
	 * POST /agent/kb.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_kb( $request ) {
		$fields = $this->sanitize_kb_input( $request );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$id = Service_Crew_Agent_KB::create_entry( $fields );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return rest_ensure_response( $this->format_kb_entry( Service_Crew_Agent_KB::get_entry( $id ) ) );
	}

	/**
	 * PUT /agent/kb/{id}. Preserves the entry's existing source (editing a
	 * learned entry doesn't turn it back into a manual one) rather than
	 * always forcing SOURCE_MANUAL like create_kb() does.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_kb( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		$existing = Service_Crew_Agent_KB::get_entry( $id );

		if ( ! $existing ) {
			return new WP_Error( 'sc_agent_kb_not_found', __( 'Knowledge-base entry not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$fields = $this->sanitize_kb_input( $request );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$fields['source'] = $existing->source;

		Service_Crew_Agent_KB::update_entry( $id, $fields );

		return rest_ensure_response( $this->format_kb_entry( Service_Crew_Agent_KB::get_entry( $id ) ) );
	}

	/**
	 * DELETE /agent/kb/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_kb( $request ) {
		Service_Crew_Agent_KB::delete_entry( absint( $request->get_param( 'id' ) ) );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /agent/upload — multipart, { token: string, photo: <file> }.
	 * Public, but gated to a session currently in Phase B's FLOW_QUOTE (see
	 * class-service-crew-agent-flows.php) — a visitor can't upload arbitrary
	 * attachments outside the quote flow's own 'photos' step context.
	 * Reuses Service_Crew_Uploads::handle_photo_uploads() verbatim, same
	 * hardened validation (extension+MIME sniff, getimagesize() re-check,
	 * size cap) the public quote form's own photo upload already goes
	 * through.
	 *
	 * @param WP_REST_Request $request Multipart request: 'token' field + 'photo' file.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_photo( $request ) {
		$rate_limit_error = $this->check_rate_limit( 'upload' );
		if ( is_wp_error( $rate_limit_error ) ) {
			return $rate_limit_error;
		}

		$params = $request->get_body_params();
		$token  = isset( $params['token'] ) ? sanitize_text_field( $params['token'] ) : '';

		$session = Service_Crew_Agent::find_session_by_token( $token );
		if ( ! $session ) {
			return new WP_Error( 'sc_agent_session_not_found', __( 'This conversation has expired. Please refresh and start a new one.', 'service-crew' ), array( 'status' => 404 ) );
		}

		if ( Service_Crew_Agent_Flows::FLOW_QUOTE !== $session->pending_flow ) {
			return new WP_Error( 'sc_agent_not_collecting_photos', __( "I'm not currently collecting photos — let's continue our conversation.", 'service-crew' ), array( 'status' => 400 ) );
		}

		$files     = $request->get_file_params();
		$photo_ids = Service_Crew_Uploads::handle_photo_uploads( $files['photo'] ?? array(), 1, Service_Crew_Quotes::MAX_PHOTO_BYTES );

		if ( is_wp_error( $photo_ids ) ) {
			return $photo_ids;
		}

		if ( empty( $photo_ids ) ) {
			return new WP_Error( 'sc_agent_upload_failed', __( 'Could not process that photo.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$count = Service_Crew_Agent_Flows::attach_photo( $session, $photo_ids[0] );

		return rest_ensure_response( array( 'attached' => true, 'count' => $count ) );
	}

	/**
	 * GET /agent/sessions — admin-gated conversations log, newest-active
	 * first.
	 *
	 * @return WP_REST_Response
	 */
	public function list_sessions() {
		$items = array_map(
			function ( $session ) {
				return array(
					'id'            => (int) $session->id,
					'visitor_name'  => $session->visitor_name,
					'visitor_email' => $session->visitor_email,
					'message_count' => (int) $session->message_count,
					'last_message'  => $session->last_message,
					'updated_at'    => $session->updated_at,
				);
			},
			Service_Crew_Agent::get_recent_sessions( 50 )
		);

		return rest_ensure_response( $items );
	}

	/**
	 * GET /agent/sessions/{id}/messages — admin-gated full transcript, by
	 * session id (not token — the admin never has a session's plaintext
	 * token, see Service_Crew_Agent::get_recent_sessions()'s own docblock).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_session_messages( $request ) {
		$messages = array_map(
			function ( $message ) {
				return array(
					'sender'     => $message->sender,
					'body'       => $message->body,
					'created_at' => $message->created_at,
				);
			},
			Service_Crew_Agent::get_history( absint( $request->get_param( 'id' ) ) )
		);

		return rest_ensure_response( $messages );
	}

	/**
	 * GET /agent/escalations — every pending escalation, admin-gated, for
	 * the Agent screen's "Pending escalations" card.
	 *
	 * @return WP_REST_Response
	 */
	public function list_escalations() {
		$items = array_map(
			function ( $escalation ) {
				return array(
					'id'         => (int) $escalation->id,
					'session_id' => (int) $escalation->session_id,
					'question'   => $escalation->question,
					'created_at' => $escalation->created_at,
				);
			},
			Service_Crew_Agent_Escalations::get_pending()
		);

		return rest_ensure_response( $items );
	}

	/**
	 * POST /agent/escalations/{id}/answer — { answer: string }. Saves the
	 * answer, logs it into the originating session's transcript, learns it
	 * into the KB, and (if the visitor left an email) queues the follow-up
	 * email — all inside Service_Crew_Agent_Escalations::answer().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function answer_escalation( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$answer = sanitize_textarea_field( $params['answer'] ?? '' );

		if ( '' === $answer ) {
			return new WP_Error( 'sc_agent_escalation_answer_empty', __( 'An answer is required.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$id      = absint( $request->get_param( 'id' ) );
		$success = Service_Crew_Agent_Escalations::answer( $id, $answer );

		if ( ! $success ) {
			return new WP_Error( 'sc_agent_escalation_not_found', __( 'This question was not found, or has already been answered.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'answered' => true ) );
	}

	/**
	 * GET /agent/escalation-status?token=...&escalation_id=... — public, but
	 * verifies the token's own session actually owns the escalation before
	 * returning anything, so one visitor can never poll another's answer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_escalation_status( $request ) {
		$token         = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$escalation_id = absint( $request->get_param( 'escalation_id' ) );

		$session = Service_Crew_Agent::find_session_by_token( $token );
		if ( ! $session ) {
			return new WP_Error( 'sc_agent_session_not_found', __( 'This conversation has expired. Please refresh and start a new one.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$escalation = Service_Crew_Agent_Escalations::get_for_session( $escalation_id, (int) $session->id );
		if ( ! $escalation ) {
			return new WP_Error( 'sc_agent_escalation_not_found', __( 'Question not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'status' => $escalation->status,
				'answer' => Service_Crew_Agent_Escalations::STATUS_ANSWERED === $escalation->status ? $escalation->answer : null,
			)
		);
	}
}
